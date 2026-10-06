<?php

namespace App\Support\Documentos;

use App\Models\Afiliacion;
use App\Models\CertificadoDiscapacidad;
use App\Models\Controversia;
use App\Models\DeclaracionVerificacion;
use App\Models\Desplazamiento;
use App\Models\Desvinculacion;
use App\Models\DocumentoUsuario;
use App\Models\EvaluacionDesempeno;
use App\Models\Familiar;
use App\Models\GoceVacacional;
use App\Models\Induccion;
use App\Models\LicenciaPermiso;
use App\Models\PeriodoPrueba;
use App\Models\ProgresionCarrera;
use App\Models\RatificacionDocente;
use App\Models\TipoDocumento;
use App\Models\VinculoAdenda;
use App\Models\VinculoLaboral;
use App\Support\Seccion01;
use App\Support\Seccion05;
use App\Support\Seccion07;
use App\Support\Seccion09;
use App\Support\Seccion11;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Enlace entre un documento y el registro de datos que acredita (tipos_documento.formulario_datos).
 *
 * vinculos_laborales no tiene documento_id: el enlace va en documentos_usuario.vinculo_laboral_id. Las demás
 * tablas guardan documento_id. El enlace se escribe una sola vez (de vacío a un valor): no se cambia después.
 * Otras secciones agregan aquí sus formularios.
 *
 * Sección 01: el registro debe ser compatible con el tipo del documento (App\Support\Seccion01) y un documento
 * acredita un solo registro vigente de familiares, declaraciones_verificaciones o certificados_discapacidad.
 *
 * Sección 07: un documento acredita un solo registro vigente; un documento de 7.3 solo acredita los tipos de
 * desplazamiento de su literal (App\Support\Seccion07), y la resolución de 7.2 acredita una progresión
 * (administrativos) o una ratificación docente (ALTERNATIVOS).
 *
 * Sección 09: a) solo acredita controversias individuales, b) solo colectivas y c) solo afiliaciones a sindicato
 * (App\Support\Seccion09); un documento acredita un solo registro vigente.
 *
 * Sección 11: todo documento enlazado a una desvinculación queda agrupado por su vínculo (documentos_usuario.vinculo_laboral_id,
 * escrito una sola vez). Los del término (a, b, c, g y aceptación de renuncia) además acreditan el registro (documento_id,
 * uno por registro) y solo si el motivo de cese corresponde (App\Support\Seccion11); los complementarios (d, e, f) solo se agrupan.
 */
final class FormulariosDatos
{
    private const MODELOS = [
        'vinculos_laborales' => VinculoLaboral::class,
        'vinculo_adendas' => VinculoAdenda::class,
        'inducciones' => Induccion::class,
        'periodos_prueba' => PeriodoPrueba::class,
        'familiares' => Familiar::class,
        'declaraciones_verificaciones' => DeclaracionVerificacion::class,
        'certificados_discapacidad' => CertificadoDiscapacidad::class,
        'licencias_permisos' => LicenciaPermiso::class,
        'goces_vacacionales' => GoceVacacional::class,
        'evaluaciones_desempeno' => EvaluacionDesempeno::class,
        'progresiones_carrera' => ProgresionCarrera::class,
        'ratificaciones_docentes' => RatificacionDocente::class,
        'desplazamientos' => Desplazamiento::class,
        'controversias' => Controversia::class,
        'afiliaciones' => Afiliacion::class,
        'desvinculaciones' => Desvinculacion::class,
    ];

    /**
     * formulario_datos del tipo => otros formularios que su documento también puede acreditar.
     * TODO pendiente de confirmar con el cliente: la ratificación o promoción docente se acredita con el tipo de 7.2 a).
     */
    private const ALTERNATIVOS = ['progresiones_carrera' => ['ratificaciones_docentes']];

    /** Formularios en los que un documento acredita un solo registro vigente. */
    private const UN_REGISTRO_POR_DOCUMENTO = ['familiares', 'declaraciones_verificaciones', 'certificados_discapacidad',
        'evaluaciones_desempeno', 'progresiones_carrera', 'ratificaciones_docentes', 'desplazamientos', 'controversias', 'afiliaciones', 'desvinculaciones'];

    public static function soportado(?string $formulario): bool
    {
        return $formulario !== null && isset(self::MODELOS[$formulario]);
    }

    /** Enlaza el documento recién subido con un registro ya existente de su formulario (de la misma persona). */
    public static function enlazarRegistro(DocumentoUsuario $documento, string $formulario, string $registroId): void
    {
        if ($formulario === 'desvinculaciones') {
            self::enlazarDesvinculacion($documento, $registroId);

            return;
        }
        $registro = null;
        foreach (self::familia($formulario) as $opcion) {
            $registro ??= self::registro($opcion, $documento->usuario_id, $registroId);
        }
        if (! $registro) {
            throw ValidationException::withMessages(['enlace_id' => 'Selecciona un registro de esta persona que no esté anulado y (salvo los vínculos) que aún no tenga documento.']);
        }
        // Sección 05: cada tipo de documento acredita solo ciertos tipos de movimiento (p. ej. b) solo goces).
        if (! Seccion05::admite($documento->tipoDocumento?->codigo, $registro->getAttribute('tipo'))) {
            throw ValidationException::withMessages(['enlace_id' => 'Este tipo de documento no acredita ese tipo de movimiento.']);
        }
        if (! self::compatible($documento->tipoDocumento, $registro)) {
            throw ValidationException::withMessages(['enlace_id' => 'El registro no corresponde a este tipo de documento.']);
        }
        if ($formulario === 'vinculos_laborales') {
            $documento->update(['vinculo_laboral_id' => $registro->getKey()]);
        } else {
            $registro->update(['documento_id' => $documento->id]);
        }
    }

    /** Documento de la persona, del tipo que abre este formulario, al que se enlaza un registro nuevo. */
    public static function documentoPara(string $usuario, string $documentoId, string $formulario): DocumentoUsuario
    {
        // Tipos cuyo formulario es este o lo admite como alternativo (7.2 a: progresión o ratificación docente).
        $abren = [$formulario, ...array_keys(array_filter(self::ALTERNATIVOS, fn (array $otros): bool => in_array($formulario, $otros, true)))];
        $documento = DocumentoUsuario::query()->where('usuario_id', $usuario)->whereKey($documentoId)
            ->whereHas('tipoDocumento', fn ($query) => $query->whereIn('formulario_datos', $abren))->first();
        $enlazado = in_array($formulario, self::UN_REGISTRO_POR_DOCUMENTO, true) && $documento
            && collect(self::familia($documento->tipoDocumento->formulario_datos))
                ->contains(fn (string $opcion): bool => (self::MODELOS[$opcion])::query()->where('documento_id', $documento->id)->where('anulado', false)->exists());
        if (! $documento || ($formulario === 'vinculos_laborales' && $documento->vinculo_laboral_id !== null) || $enlazado) {
            throw ValidationException::withMessages(['documento_id' => 'El documento no es de esta persona, no corresponde a este formulario o ya está enlazado.']);
        }

        return $documento;
    }

    /**
     * Crea un registro de datos (secciones 01, 07, 09 y 11) enlazado, si se indica, al documento recién subido que lo acredita.
     * En una transacción con el documento bloqueado: dos registros simultáneos no toman el mismo documento.
     */
    public static function crearEnlazado(string $formulario, string $usuario, ?string $documentoId, Model $registro): Model
    {
        return DB::transaction(function () use ($formulario, $usuario, $documentoId, $registro): Model {
            if ($documentoId !== null) {
                DocumentoUsuario::query()->whereKey($documentoId)->lockForUpdate()->first();
                $documento = self::documentoPara($usuario, $documentoId, $formulario);
                if (! self::compatible($documento->tipoDocumento, $registro)) {
                    throw ValidationException::withMessages(['documento_id' => 'El registro no corresponde al tipo del documento que acredita.']);
                }
                $registro->documento_id = $documento->id;
            }
            $registro->save();
            if ($registro instanceof Desvinculacion && $registro->documento_id !== null) {
                self::agruparPorVinculo($registro->documento_id, $registro->vinculo_laboral_id);
            }

            return $registro->refresh();
        });
    }

    /**
     * Sección 11: enlaza un documento recién subido con una desvinculación vigente de la persona. Los del término la
     * acreditan (si aún no tiene documento); los complementarios solo se agrupan por el vínculo que terminó.
     */
    private static function enlazarDesvinculacion(DocumentoUsuario $documento, string $registroId): void
    {
        $registro = Desvinculacion::query()->whereKey($registroId)->where('usuario_id', $documento->usuario_id)->where('anulado', false)->with('vinculoLaboral')->first();
        if (! $registro) {
            throw ValidationException::withMessages(['enlace_id' => 'Selecciona una desvinculación de esta persona que no esté anulada.']);
        }
        $codigo = $documento->tipoDocumento?->codigo;
        if (! Seccion11::admite($codigo, $registro->motivo_cese, $registro->vinculoLaboral?->regimen_laboral)) {
            throw ValidationException::withMessages(['enlace_id' => 'Este tipo de documento no corresponde al motivo de cese o al régimen del vínculo de esa desvinculación.']);
        }
        if (Seccion11::principal($codigo)) {
            if (Desvinculacion::query()->whereKey($registro->id)->whereNull('documento_id')->update(['documento_id' => $documento->id]) !== 1) {
                throw ValidationException::withMessages(['enlace_id' => 'Esa desvinculación ya tiene su documento de término. Súbelo sin enlace como documento adicional.']);
            }
        }
        self::agruparPorVinculo($documento->id, $registro->vinculo_laboral_id);
    }

    /** Agrupa el documento por el vínculo que terminó; se escribe una sola vez (de vacío a un valor). */
    private static function agruparPorVinculo(string $documentoId, string $vinculoId): void
    {
        DocumentoUsuario::query()->whereKey($documentoId)->whereNull('vinculo_laboral_id')->update(['vinculo_laboral_id' => $vinculoId, 'updated_at' => now()]);
    }

    /** El formulario del tipo y los alternativos que su documento también acredita. */
    private static function familia(string $formulario): array
    {
        return [$formulario, ...(self::ALTERNATIVOS[$formulario] ?? [])];
    }

    /** ¿El registro es del tipo que acredita el documento? (secciones 01, 07, 09 y 11; los demás no se restringen). */
    private static function compatible(?TipoDocumento $tipo, Model $registro): bool
    {
        if ($registro instanceof Controversia || $registro instanceof Afiliacion) {
            return $tipo !== null && Seccion09::admite($tipo->codigo, $registro->tipo);
        }
        if ($registro instanceof Desvinculacion) {
            return $tipo !== null && Seccion11::principal($tipo->codigo)
                && Seccion11::admite($tipo->codigo, $registro->motivo_cese, VinculoLaboral::query()->whereKey($registro->vinculo_laboral_id)->value('regimen_laboral'));
        }
        if ($registro instanceof Desplazamiento) {
            return Seccion07::admiteDesplazamiento($tipo?->codigo, $registro->tipo);
        }

        return $tipo === null || Seccion01::compatible($tipo, $registro);
    }

    /** Registro sin documento enlazado (los vínculos admiten varios documentos: contrato, renovaciones). */
    private static function registro(string $formulario, string $usuario, string $id): ?Model
    {
        $query = (self::MODELOS[$formulario])::query()->whereKey($id);

        return match ($formulario) {
            'vinculos_laborales' => $query->where('usuario_id', $usuario)->where('anulado', false)->first(),
            'vinculo_adendas' => $query->where('anulado', false)->whereNull('documento_id')->whereHas('vinculoLaboral', fn ($q) => $q->where('usuario_id', $usuario))->first(),
            default => $query->where('usuario_id', $usuario)->where('anulado', false)->whereNull('documento_id')->first(),
        };
    }
}
