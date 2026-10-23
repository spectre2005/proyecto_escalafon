<?php

namespace App\Support;

use App\Models\DocumentoUsuario;
use App\Models\Plataforma\Institucion;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Comprobaciones técnicas; nunca emiten una conclusión administrativa de autenticidad. */
final class VerificacionFiscalizacion
{
    public static function firmaConfigurada(): bool
    {
        $slug = app()->bound(Institucion::class) ? app(Institucion::class)->slug : 'unica';
        $url = (string) config('fiscalizacion.firma_peru.url');

        return config('fiscalizacion.firma_peru.institucion') === $slug
            && (bool) config('fiscalizacion.firma_peru.credential')
            && filter_var($url, FILTER_VALIDATE_URL)
            && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_QUERY) && ! parse_url($url, PHP_URL_FRAGMENT);
    }

    public static function apistedConfigurada(): bool
    {
        $slug = app()->bound(Institucion::class) ? app(Institucion::class)->slug : 'unica';

        return config('fiscalizacion.apisted.institucion') === $slug
            && trim((string) config('fiscalizacion.apisted.token')) !== '';
    }

    public static function servicios(): array
    {
        return [
            ['codigo' => 'apisted_minedu', 'nombre' => 'Institutos · APIsTED / MINEDU', 'estado' => self::apistedConfigurada() ? 'configurado' : 'no_configurado', 'descripcion' => 'Consulta manual por DNI: 25 créditos. Intermediario APIsTED; contrasta cada título con el documento. No incluye títulos universitarios.'],
            ['codigo' => 'apisted_sunedu', 'nombre' => 'Universitarios · APIsTED / SUNEDU', 'estado' => self::apistedConfigurada() ? 'configurado' : 'no_configurado', 'descripcion' => 'Consulta manual por DNI: 60 créditos. Intermediario APIsTED; no figurar en el registro no acredita falsedad.'],
            ['codigo' => 'consistencia', 'nombre' => 'Consistencia de datos', 'estado' => 'disponible', 'descripcion' => 'Detecta datos faltantes, fechas incoherentes y diferencias con la identidad registrada. No comprueba autenticidad.'],
            ['codigo' => 'integridad', 'nombre' => 'Integridad del archivo', 'estado' => 'disponible', 'descripcion' => 'Contrasta el archivo actual con la huella conservada al abrir el proceso. No acredita autenticidad.'],
            ['codigo' => 'firma_peru', 'nombre' => 'Firma Perú', 'estado' => self::firmaConfigurada() ? 'configurado' : 'no_configurado', 'descripcion' => 'Valida firmas digitales con el módulo autorizado para esta institución.', 'url' => 'https://www.gob.pe/22274'],
            ['codigo' => 'sunedu', 'nombre' => 'SUNEDU / PIDE', 'estado' => 'consulta_asistida', 'descripcion' => 'Registra el contraste y su evidencia. La conexión automática requiere habilitación y especificación institucional.', 'url' => 'https://enlinea.sunedu.gob.pe/'],
            ['codigo' => 'minedu', 'nombre' => 'MINEDU', 'estado' => 'consulta_asistida', 'descripcion' => 'Consulta el registro oficial y conserva el sustento. Sin coincidencias requiere revisión adicional.', 'url' => 'https://titulosinstitutos.minedu.gob.pe/'],
            ['codigo' => 'rnssc', 'nombre' => 'SERVIR / RNSSC', 'estado' => 'consulta_asistida', 'descripcion' => 'Consulta oficial de sanciones e impedimentos; registra la evidencia y fecha de corte.', 'url' => 'https://www.gob.pe/818'],
        ];
    }

    public static function ejecutar(DocumentoUsuario $documento, string $fuente, ?string $hashEsperado): array
    {
        if ($fuente === 'consistencia') {
            return self::consistencia($documento);
        }
        $archivo = $documento->archivo;
        $disco = (string) config('legajo.disco_documentos');
        if (! $archivo || $archivo->almacenamiento !== $disco || $archivo->estado_antivirus === 'infectado') {
            return self::resultado('bloqueado', 'no_ejecutada', ['mensaje' => 'Archivo no disponible o bloqueado.']);
        }
        try {
            $stream = Storage::disk($disco)->readStream($archivo->storage_path);
            if (! is_resource($stream)) {
                return self::resultado('no_disponible', 'no_ejecutada', ['mensaje' => 'El archivo no está disponible en el almacenamiento institucional.']);
            }
            try {
                $hash = hash_init('sha256');
                hash_update_stream($hash, $stream);
                $actual = hash_final($hash);
            } finally {
                fclose($stream);
            }
        } catch (Throwable) {
            return self::resultado('no_disponible', 'no_ejecutada', ['mensaje' => 'No se pudo leer el archivo institucional.']);
        }
        if (! $hashEsperado || ! hash_equals($hashEsperado, $actual)) {
            return self::resultado('completada', $hashEsperado ? 'integridad_no_coincide' : 'sin_huella', ['mensaje' => 'No se puede confirmar la integridad respecto del alcance original.', 'hash_actual' => $actual]);
        }
        if ($fuente === 'integridad') {
            return self::resultado('completada', 'integridad_coincide', ['hash_actual' => $actual, 'antivirus' => $archivo->estado_antivirus, 'mensaje' => 'La huella coincide. Esta comprobación no verifica la veracidad del contenido ni reemplaza el antivirus.']);
        }
        if (in_array($fuente, ['apisted_minedu', 'apisted_sunedu'], true)) {
            return self::consultarApisted($documento, $fuente);
        }
        if (! self::firmaConfigurada()) {
            return self::resultado('no_configurado', 'no_ejecutada', ['mensaje' => 'TI debe configurar el validador autorizado para esta institución.']);
        }
        try {
            $stream = Storage::disk($disco)->readStream($archivo->storage_path);
            if (! is_resource($stream)) {
                return self::resultado('no_disponible', 'no_ejecutada', ['mensaje' => 'No se pudo abrir el archivo para validación.']);
            }
            try {
                $response = Http::acceptJson()->connectTimeout(3)->timeout((int) config('fiscalizacion.firma_peru.timeout', 20))
                    ->withoutRedirecting()->attach('signed', $stream, $documento->id.'.pdf')
                    ->post(rtrim(config('fiscalizacion.firma_peru.url'), '/').'/api/validation', [
                        'param' => json_encode(['documentExtension' => 'pdf']),
                        'credential' => config('fiscalizacion.firma_peru.credential'),
                    ]);
            } finally {
                fclose($stream);
            }
            if (! $response->successful()) {
                return self::resultado('no_disponible', 'no_ejecutada', ['mensaje' => 'El validador no completó la consulta. Revisa su disponibilidad con TI.', 'http_estado' => $response->status()]);
            }
            $json = $response->json();
            $resultados = ['RESULTADO VÁLIDO' => 'firma_valida', 'RESULTADO INDETERMINADO' => 'firma_indeterminada', 'RESULTADO NO VÁLIDO' => 'firma_no_valida', 'SIN FIRMAS DIGITALES' => 'sin_firmas'];
            if (! is_array($json) || ! isset($resultados[$json['result'] ?? ''])) {
                return self::resultado('no_disponible', 'respuesta_no_reconocida', ['mensaje' => 'El servicio devolvió una respuesta que requiere revisión técnica.']);
            }
            $firmantes = collect(is_array($json['listSignatures'] ?? null) ? $json['listSignatures'] : [])->take(50)
                ->filter(fn ($firma) => is_array($firma))->map(fn (array $firma): array => collect($firma)
                ->only(['signer', 'status', 'date', 'format', 'rootInTsl'])->map(fn ($valor) => is_string($valor) ? mb_substr($valor, 0, 500) : (is_bool($valor) ? $valor : null))->all())->values()->all();

            return self::resultado('completada', $resultados[$json['result']], [
                'resultado' => $json['result'], 'fecha_fuente' => mb_substr((string) ($json['validationDate'] ?? ''), 0, 100),
                'firmantes' => $firmantes, 'hash_actual' => $actual,
                'mensaje' => 'Validación de firmas digitales. La veracidad del contenido y la competencia del firmante requieren evaluación.',
            ]);
        } catch (Throwable) {
            return self::resultado('no_disponible', 'no_ejecutada', ['mensaje' => 'No se pudo confirmar la consulta al validador. Puedes reintentar o registrar una verificación asistida.']);
        }
    }

    private static function consultarApisted(DocumentoUsuario $documento, string $fuente): array
    {
        if (! self::apistedConfigurada()) {
            return self::resultado('no_configurado', 'no_ejecutada', ['mensaje' => 'Configura APIsTED para esta institución en el backend.']);
        }
        $usuario = $documento->usuario;
        $dni = (string) $usuario?->numero_doc_identidad;
        if ($documento->seccion_legajo_id !== 3 || strtolower((string) $usuario?->tipo_doc_identidad) !== 'dni' || ! preg_match('/^[0-9]{8}$/', $dni)
            || ($documento->titular_documento && $documento->titular_documento !== $dni)) {
            return self::resultado('bloqueado', 'no_ejecutada', ['mensaje' => 'La consulta requiere un documento de formación académica del servidor y su DNI válido de ocho dígitos.']);
        }
        $ruta = $fuente === 'apisted_minedu' ? 'titulos' : 'universitarios';
        try {
            $response = Http::acceptJson()->asJson()->withToken((string) config('fiscalizacion.apisted.token'))
                ->connectTimeout(3)->timeout((int) config('fiscalizacion.apisted.timeout', 20))->withoutRedirecting()
                ->post('https://apisted.com/api/minedu/'.$ruta, ['dni' => $dni]);
            if (! $response->successful()) {
                $mensaje = match ($response->status()) {
                    401, 403 => 'Revisa el token y sus permisos de MINEDU.',
                    402 => 'Revisa el saldo o plan de APIsTED.',
                    429 => 'Se alcanzó el límite de consultas. Espera antes de reintentar.',
                    422 => 'El proveedor no aceptó los datos de consulta.',
                    default => 'El proveedor no completó la consulta. No se puede concluir sobre el título.',
                };

                return self::resultado('no_disponible', 'no_ejecutada', ['mensaje' => $mensaje, 'http_estado' => $response->status()]);
            }
            $json = $response->json();
            if (! is_array($json) || ! is_bool($json['encontrado'] ?? null) || ! is_array($json['registros'] ?? null)
                || ! is_int($json['total'] ?? null) || $json['total'] !== count($json['registros'])
                || $json['encontrado'] !== ($json['total'] > 0)
                || ($json['consultado_por'] ?? null) !== 'dni'
                || ($ruta === 'titulos' ? ($json['criterio'] ?? null) : ($json['numero_documento'] ?? null)) !== $dni) {
                return self::resultado('no_disponible', 'respuesta_no_reconocida', ['mensaje' => 'La respuesta no permite confirmar el criterio y la estructura de la consulta.']);
            }
            $campos = ['apellidos_nombres', 'tipo_documento', 'numero_documento', 'grado_o_titulo', 'nivel', 'carrera', 'institucion_educativa', 'region', 'fecha_emision', 'codigo_minedu', 'ambito', 'tipo', 'universidad', 'facultad', 'fecha_diploma', 'resolucion', 'es_revalida'];
            $registros = [];
            foreach (array_slice($json['registros'], 0, 100) as $registro) {
                if (! is_array($registro) || ! is_string($registro['grado_o_titulo'] ?? null)
                    || (isset($registro['numero_documento']) && $registro['numero_documento'] !== $dni)) {
                    return self::resultado('no_disponible', 'respuesta_no_reconocida', ['mensaje' => 'Un registro tiene datos incompatibles con la consulta.']);
                }
                $registros[] = collect($registro)->only($campos)->map(fn ($valor) => is_string($valor) ? mb_substr($valor, 0, 500) : (is_bool($valor) ? $valor : null))->all();
            }
            $fuentes = collect(is_array($json['fuentes'] ?? null) ? $json['fuentes'] : [])->only(['tecnologicos', 'pedagogicos', 'escuelas_universidades'])
                ->map(fn ($valor) => is_string($valor) ? mb_substr($valor, 0, 100) : null)->all();

            return self::resultado('completada', $json['encontrado'] ? 'registros_encontrados' : 'sin_coincidencias', [
                'proveedor' => 'APIsTED', 'ruta' => '/minedu/'.$ruta, 'dni_consultado' => $dni,
                'fecha_fuente' => is_string($json['fecha_consulta'] ?? null) ? mb_substr($json['fecha_consulta'], 0, 100) : null,
                'total' => $json['total'], 'registros' => $registros, 'fuentes' => $fuentes,
                'mensaje' => 'Compara identidad, institución, título y fechas con el documento. La respuesta puede estar en caché o contener fuentes sin resultados o no disponibles. No encontrar un registro no acredita falsedad ni ausencia de estudios.',
            ]);
        } catch (Throwable) {
            return self::resultado('no_disponible', 'no_ejecutada', ['mensaje' => 'No se pudo confirmar la consulta a APIsTED. Revisa el historial del proveedor antes de repetir: puede haber consumido créditos.']);
        }
    }

    private static function consistencia(DocumentoUsuario $documento): array
    {
        $hallazgos = [];
        if (! trim((string) $documento->emisor)) {
            $hallazgos[] = 'Falta registrar la entidad emisora.';
        }
        if (! $documento->fecha_emision) {
            $hallazgos[] = 'Falta registrar la fecha de emisión.';
        } elseif ($documento->fecha_emision->format('Y-m-d') > now('America/Lima')->toDateString()) {
            $hallazgos[] = 'La fecha de emisión es posterior a la fecha de consulta.';
        }
        if ($documento->titular_documento && $documento->relacion_titular === 'servidor' && $documento->titular_documento !== $documento->usuario?->numero_doc_identidad) {
            $hallazgos[] = 'El documento de identidad del titular no coincide con el servidor registrado.';
        }
        if ($documento->seccion_legajo_id === 4) {
            foreach ($documento->experienciaLaboral()->where('anulado', false)->get() as $periodo) {
                if ($periodo->fecha_fin && $periodo->fecha_fin->lt($periodo->fecha_inicio)) {
                    $hallazgos[] = 'Un periodo de experiencia termina antes de su inicio.';
                }
            }
        }

        return self::resultado('completada', $hallazgos === [] ? 'sin_alertas_internas' : 'datos_con_observaciones', [
            'mensaje' => $hallazgos === [] ? 'No se detectaron alertas en las reglas internas comprobadas. No acredita autenticidad ni cumplimiento del puesto.' : 'Revisa los datos registrados antes de concluir.',
            'hallazgos' => $hallazgos,
            'reglas' => ['emisor_registrado', 'fecha_emision', 'identidad_del_titular', 'orden_de_periodos'],
        ]);
    }

    private static function resultado(string $estado, string $resultado, array $detalle): array
    {
        return ['estado_tecnico' => $estado, 'resultado_fuente' => $resultado, 'detalle' => $detalle];
    }
}
