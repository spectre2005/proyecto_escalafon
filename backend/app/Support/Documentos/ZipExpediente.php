<?php

namespace App\Support\Documentos;

use App\Http\Controllers\Api\V1\Legajos\DocumentoLegajoController;
use App\Models\BitacoraAccesoLegajo;
use App\Models\DeclaracionVerificacion;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\Usuario;
use App\Support\Auditoria;
use App\Support\MensajeRequerimiento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * ZIP del expediente: secciones 01 a 04 siempre y, si el usuario los elige, los grupos de ExpedienteImprimible::OPCIONALES; los PDF originales que la vista previa marca «se imprime»,
 * con los nombres que ella propone, y un índice HTML imprimible. Se arma a partir de ExpedienteImprimible::vistaPrevia,
 * así que comparte sus reglas de alcance, configuración, permisos y folios.
 *
 * - Orden: por folio, de menor a mayor (pendiente de confirmar con la ORH el sentido de la carpeta física, num. 9.3).
 * - Anulados y archivos no disponibles no se copian: el índice los lista con su motivo, para que los folios no tengan
 *   huecos sin explicación.
 * - Lo restringido no aparece ni en el ZIP ni en el índice.
 * - Cada documento copiado deja una fila en bitacora_accesos_legajo; la exportación, un evento de auditoría.
 */
final class ZipExpediente
{
    public const ORDEN = 'asc';

    /**
     * @param  list<string>  $incluir  grupos opcionales elegidos (ExpedienteImprimible::OPCIONALES); por defecto, ninguno
     * @return array{ruta: string, nombre: string} archivo temporal (se borra al enviarlo) y nombre de descarga
     */
    public static function generar(Request $request, Usuario $persona, Legajo $legajo, array $incluir = []): array
    {
        $vista = ExpedienteImprimible::vistaPrevia($request->user(), $persona, $legajo, self::ORDEN, $incluir);
        $disco = (string) config('legajo.disco_documentos');
        $ruta = tempnam(sys_get_temp_dir(), 'expediente_');
        $zip = new ZipArchive;
        if ($ruta === false || $zip->open($ruta, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo preparar el ZIP del expediente.');
        }
        $incluidos = [];
        try {
            foreach ($vista['secciones'] as $seccion) {
                $ids = collect($seccion['documentos'] ?? [])->where('inclusion', 'imprimible')->pluck('id');
                $registros = DocumentoUsuario::query()->with('archivo')->whereKey($ids)->get()->keyBy('id');
                foreach ($seccion['documentos'] ?? [] as $documento) {
                    if ($documento['inclusion'] === 'imprimible' && ($registro = $registros->get($documento['id']))) {
                        $zip->addFromString($documento['nombre_zip'], self::leer($disco, $registro->archivo->storage_path));
                        $incluidos[] = ['documento_id' => $registro->id];
                    }
                }
                $antiguos = DeclaracionVerificacion::query()->whereKey(collect($seccion['sin_foliar'] ?? [])->where('inclusion', 'imprimible')->pluck('id'))->get()->keyBy('id');
                foreach ($seccion['sin_foliar'] ?? [] as $documento) {
                    if ($documento['inclusion'] === 'imprimible' && ($antiguo = $antiguos->get($documento['id']))) {
                        $zip->addFromString($documento['nombre_zip'], self::leer('local', $antiguo->pdf_path));
                        $incluidos[] = ['documento_id' => null];
                    }
                }
            }
            $zip->addFromString('00_INDICE.html', self::indice($vista, $persona, $request->user()));
            if (! $zip->close()) {
                throw new RuntimeException('No se pudo cerrar el ZIP del expediente.');
            }
        } catch (\Throwable $e) {
            @unlink($ruta);
            throw $e;
        }
        DB::transaction(function () use ($request, $legajo, $persona, $vista, $incluidos): void {
            foreach ($incluidos as $incluido) {
                BitacoraAccesoLegajo::query()->create([...$incluido, 'legajo_id' => $legajo->id, 'usuario_id' => $request->user()->getAuthIdentifier(),
                    'accion' => 'descargar', 'ip_origen' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 300), 'fecha_acceso' => now()]);
            }
            // Sin datos personales: cuántos documentos, el alcance y los indicadores de la vista.
            Auditoria::registrar($request, 'expediente.exportado', 'legajos', $legajo->id, $persona->id, null, ['formato' => 'zip',
                'secciones' => array_column($vista['secciones'], 'id'), 'incluidos' => $vista['incluidos'], 'orden' => self::ORDEN, 'documentos' => count($incluidos),
                'acceso_completo' => $vista['acceso_completo'], 'integro' => $vista['integro']]);
        });

        $nombre = Str::slug(($legajo->numero_legajo ?: 'legajo').' '.$vista['persona']['nombre'], '_');

        return ['ruta' => $ruta, 'nombre' => $nombre.($vista['incluidos'] ? '_expediente_ampliado.zip' : '_secciones_01-04.zip')];
    }

    /** Un archivo que la vista dio por disponible y ya no se puede leer detiene la copia: nunca se incluye vacío. */
    private static function leer(string $disco, string $ruta): string
    {
        $contenido = Storage::disk($disco)->get($ruta);
        if ($contenido === null || $contenido === '') {
            throw new RuntimeException('Un archivo dejó de estar disponible mientras se preparaba el ZIP. Vuelve a intentarlo.');
        }

        return $contenido;
    }

    private const MOTIVOS = ['por_confirmar' => 'No incluido: anulado (pendiente de confirmar con la ORH).', 'no_disponible' => 'No incluido: archivo no disponible.'];

    /** @param  array<string, mixed>  $vista */
    private static function indice(array $vista, Usuario $persona, Usuario $actor): string
    {
        $institucion = MensajeRequerimiento::institucion()['nombre'];
        // El DNI es un dato de identidad de la sección 01: solo va en la carátula si quien genera la copia puede
        // consultar esa sección (la misma regla del visor). Si no, la sección figura como omitida y no se describe.
        $dni = DocumentoLegajoController::restriccion($actor, 1) === null && $persona->numero_doc_identidad ? $persona->numero_doc_identidad : null;
        $secciones = '';
        foreach ($vista['secciones'] as $seccion) {
            $titulo = sprintf('%02d. %s', $seccion['id'], $seccion['nombre']);
            if ($seccion['estado'] === 'omitida_permisos') {
                $secciones .= '<h2>'.e($titulo).'</h2><p class="nota">Omitida por permisos: no se incluye en esta copia.</p>';

                continue;
            }
            $filas = '';
            foreach ([...($seccion['documentos'] ?? []), ...($seccion['sin_foliar'] ?? [])] as $d) {
                $r = $d['registro'];
                $datos = array_filter(['Título' => $r['titulo'] ?? null, 'Emisor' => $r['emisor'] ?? null, 'Emisión' => $r['fecha_emision'] ?? ($r['fecha'] ?? null),
                    'Incorporación' => $r['fecha_incorporacion'] ?? null, 'N.º' => $r['num_resolucion'] ?? null]);
                $folios = isset($d['folio_inicio']) ? ($d['folio_inicio'] === $d['folio_fin'] ? 'F'.$d['folio_inicio'] : 'F'.$d['folio_inicio'].'–'.$d['folio_fin']) : 'Sin foliar';
                $estado = isset($r['estado']) ? $r['estado'].(! empty($r['subsanado']) ? ' · subsanado por uno posterior' : '').(! empty($r['subsana_a_otro']) ? ' · subsana a uno anterior' : '') : '';
                $filas .= '<tr><td>'.e($folios).'</td><td>'.e($d['tipo']['nombre']).'<br><small>'.e($d['tipo']['codigo']).'</small></td><td>'
                    .collect($datos)->map(fn ($v, $k) => e($k).': '.e($v))->implode('<br>').'</td><td>'.e($estado).'</td><td>'
                    .($d['inclusion'] === 'imprimible' ? '<code>'.e($d['nombre_zip']).'</code>' : e(self::MOTIVOS[$d['inclusion']] ?? $d['inclusion'])).'</td></tr>';
            }
            $estadoSeccion = ['disponible' => 'Con documentos', 'sin_documentos' => 'Funcionalidad activa sin documentos incorporados', 'desactivada' => 'Funcionalidad desactivada en la institución'][$seccion['estado']] ?? $seccion['estado'];
            $secciones .= '<h2>'.e($titulo).'</h2><p class="nota">'.e($estadoSeccion).' · '.e((string) $seccion['folios']).' de '.e((string) $seccion['ultimo_folio']).' folio(s)</p>'
                .($filas ? '<table><thead><tr><th>Folios</th><th>Documento</th><th>Datos del registro</th><th>Estado</th><th>Archivo en el ZIP</th></tr></thead><tbody>'.$filas.'</tbody></table>' : '');
        }
        // Alcance según lo elegido al descargar: lo de siempre, lo añadido y lo que quedó fuera.
        $nombreDe = fn (string $clave): string => ($numero = (int) substr($clave, 8)) && str_starts_with($clave, 'seccion_')
            ? sprintf('%02d. %s', $numero, collect($vista['secciones'])->firstWhere('id', $numero)['nombre'] ?? 'Sección '.$numero)
            : ExpedienteImprimible::OPCIONALES[$clave][2];
        $anadidos = array_map($nombreDe, $vista['incluidos']);
        $fuera = array_map(fn (string $clave): string => str_starts_with($clave, 'seccion_') ? 'sección '.substr($clave, 8) : mb_strtolower(ExpedienteImprimible::OPCIONALES[$clave][2]),
            array_values(array_diff(array_keys(ExpedienteImprimible::OPCIONALES), $vista['incluidos'])));
        $alcance = 'Siempre incluye las secciones 01 a 04 (identidad, declaraciones, incorporación, formación y experiencia).'
            .($anadidos ? ' Añadido al descargar: '.implode('; ', $anadidos).'.' : '')
            .($fuera ? ' No se incluyó: '.implode(', ', $fuera).'. Por eso no representa necesariamente el legajo físico completo.' : '')
            .' Los documentos de funcionalidades desactivadas en la institución no se incorporan.';
        $avisos = collect($vista['advertencias'])->map(fn (array $a): string => '<li>'.e($a['mensaje']).'</li>')->implode('');
        $acceso = $vista['acceso_completo'] ? 'Acceso completo: se incluyen todas las secciones de esta versión.' : 'Acceso parcial: el perfil que generó la copia no puede consultar todas las secciones; las omitidas no se incluyen.';
        $integridad = $vista['integro'] ? 'Sin incidencias: todos los documentos registrados incluidos tienen su archivo. No significa que el legajo físico esté completo.'
            : $vista['resumen']['incidencias'].' incidencia(s): hay documentos registrados que no se pudieron incluir. No es una copia íntegra.';

        return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Índice del expediente</title><style>'
            .'body{font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#1e293b;margin:24px}h1{font-size:18px;margin:0}h2{font-size:14px;margin:24px 0 4px}'
            .'.caratula{border:1px solid #cbd5e1;padding:16px;margin-bottom:16px}.nota{color:#475569;margin:2px 0 8px}table{width:100%;border-collapse:collapse}'
            .'th,td{border:1px solid #cbd5e1;padding:4px 6px;text-align:left;vertical-align:top}th{background:#f1f5f9}code{font-size:11px;word-break:break-all}'
            .'@media print{body{margin:0}h2{break-after:avoid}tr{break-inside:avoid}}</style></head><body>'
            .'<div class="caratula">'.($institucion !== '' ? '<p>'.e($institucion).'</p>' : '').'<h1>LEGAJO DE PERSONAL (CONFIDENCIAL)</h1>'
            .'<p>'.e($vista['persona']['nombre']).($dni ? ' · DNI '.e($dni) : '').' · Legajo '.e((string) ($vista['legajo']['numero'] ?: 'sin número')).'</p>'
            .'<p class="nota">Generado el '.e(now('America/Lima')->format('d/m/Y H:i')).' por '.e(trim($actor->nombres.' '.$actor->apellido_paterno)).'</p></div>'
            .'<p><strong>Alcance:</strong> '.e($alcance).'</p>'
            .'<p><strong>Acceso:</strong> '.e($acceso).'</p><p><strong>Integridad documental:</strong> '.e($integridad).'</p>'
            .'<p><strong>Orden:</strong> por folio de menor a mayor en cada sección. Según la Directiva (num. 9.3), en la carpeta física el documento más reciente va encima; falta confirmarlo con la ORH.</p>'
            .'<p><strong>Resumen:</strong> '.e((string) $vista['resumen']['imprimibles']).' documento(s) incluidos de '.e((string) $vista['resumen']['documentos']).' registrados en esta vista · '.e((string) $vista['resumen']['folios']).' folio(s).</p>'
            .($avisos ? '<h2>Advertencias</h2><ul>'.$avisos.'</ul>' : '').$secciones.'</body></html>';
    }
}
