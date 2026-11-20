<?php

namespace App\Jobs;

use App\Models\LecturaIa;
use App\Models\Plataforma\Institucion;
use App\Support\Auditoria;
use App\Support\Documentos\EscanerSecciones;
use App\Support\Documentos\TitularDocumento;
use App\Support\Plataforma\ConexionInstitucion;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Lectura de un PDF con IA, después de responder al navegador (dispatchAfterResponse): no ocupa un worker de la cola ni
 * se corta por el tiempo de la petición. La pantalla consulta el avance (LecturaIaController::mostrar).
 *
 * Con varias instituciones, ResolverInstitucion sale del contexto de la institución al armar la respuesta, antes de que
 * esto corra: por eso se guarda la institución al crear el trabajo y se vuelve a entrar en ella (base, archivos y caché)
 * con ConexionInstitucion::usar. Sin eso, la lectura y su resultado irían a la base y la caché de la plataforma.
 *
 * El resultado (con valores y citas del documento) va cifrado a la caché por RESULTADO_MINUTOS y solo lo ve quien
 * escaneó; en lecturas_ia y en la auditoría quedan solo estados, páginas y nombres de campos.
 */
class LeerDocumentoConIa
{
    use Dispatchable;

    public const RESULTADO_MINUTOS = 30;

    /** Ollama atiende una lectura a la vez: las demás esperan su turno hasta este tiempo (ocupan un proceso de PHP). */
    private const ESPERA_TURNO_SEGUNDOS = 300;

    /** Duración máxima de un turno: si el proceso muere, el turno se libera solo pasado este tiempo. */
    private const TURNO_SEGUNDOS = 1800;

    /**
     * @param  list<string>  $campos
     * @param  array{dni: ?string, nombres: ?string, apellido_paterno: ?string, apellido_materno: ?string}  $persona
     */
    public function __construct(
        public string $lecturaId,
        public string $rutaPdf,
        public string $tipoNombre,
        public array $campos,
        public array $persona,
        // false en documentos de familiares (DNI de cónyuge o hijos, partida de matrimonio): su titular no es el servidor.
        public bool $compararTitular = true,
        public ?string $institucionId = null,
    ) {
        $this->institucionId ??= app()->bound(Institucion::class) ? app(Institucion::class)->id : null;
    }

    public function handle(EscanerSecciones $escaner): void
    {
        // Solo cuenta el tiempo de CPU de PHP, pero el OCR de muchas páginas no debe quedar a medias por el límite.
        set_time_limit(0);
        ignore_user_abort(true);
        // El turno se toma ANTES de entrar a la institución: con la caché de la plataforma, es uno solo para todas (Ollama
        // es uno solo). Dentro de ConexionInstitucion::usar cada institución tendría su propio turno.
        $bloqueo = Cache::lock('escaner-documentos-local', self::TURNO_SEGUNDOS);
        $sinTurno = null;
        try {
            $bloqueo->block(self::ESPERA_TURNO_SEGUNDOS);
        } catch (LockTimeoutException $error) {
            $sinTurno = $error;
        }
        try {
            $institucion = $this->institucionId === null ? null : Institucion::query()->find($this->institucionId);
            if ($this->institucionId !== null && ! $institucion) {
                return;
            }
            $institucion ? ConexionInstitucion::usar($institucion, fn () => $this->leer($escaner, $sinTurno)) : $this->leer($escaner, $sinTurno);
        } finally {
            if (! $sinTurno) {
                $bloqueo->release();
            }
            @unlink($this->rutaPdf);
        }
    }

    private function leer(EscanerSecciones $escaner, ?Throwable $sinTurno): void
    {
        $lectura = LecturaIa::query()->find($this->lecturaId);
        if (! $lectura) {
            return;
        }
        $inicio = microtime(true);
        $campos = [];
        $titular = null;
        try {
            if ($sinTurno) {
                throw $sinTurno;
            }
            $lectura->update(['estado' => 'leyendo']);
            $texto = $escaner->leer(new UploadedFile($this->rutaPdf, 'documento.pdf', 'application/pdf', null, true),
                fn (int $pagina, int $total) => $lectura->update(['pagina_actual' => $pagina, 'paginas_leer' => $total]));
            $lectura->update(['estado' => 'interpretando', 'paginas_documento' => $escaner->paginasDocumento ?: null]);
            $nombre = trim(implode(' ', array_filter([$this->persona['nombres'] ?? null, $this->persona['apellido_paterno'] ?? null, $this->persona['apellido_materno'] ?? null])));
            $campos = $escaner->interpretar($texto, $this->tipoNombre, $this->campos, $nombre);
            // ¿Es de esta persona? Con el texto de todas las páginas leídas, sin modelo.
            $titular = $this->compararTitular ? TitularDocumento::comparar($texto, $this->persona) : null;
            $confianzas = array_filter(array_column($escaner->paginas, 'confianza'), fn (?float $confianza): bool => $confianza !== null);
            Cache::put($lectura->claveResultado(), Crypt::encryptString(json_encode([
                'titular' => $titular,
                'campos' => $campos,
                'descartados' => $escaner->descartados,
                'sugerencias' => $escaner->sugerencias,
                'lectura_dudosa' => $confianzas !== [] && min($confianzas) < 70,
            ], JSON_THROW_ON_ERROR)), now()->addMinutes(self::RESULTADO_MINUTOS));
            $lectura->update([
                'estado' => 'lista', 'titular' => $titular['estado'] ?? null, 'segundos' => round(microtime(true) - $inicio, 1),
                'campos_propuestos' => array_keys($campos), 'campos_sugeridos' => array_keys($escaner->sugerencias),
            ]);
        } catch (Throwable $error) {
            $lectura->update(['estado' => 'error', 'error' => mb_substr(self::mensaje($error), 0, 300), 'segundos' => round(microtime(true) - $inicio, 1)]);
            if (! $error instanceof ValidationException && ! $error instanceof HttpExceptionInterface && ! $error instanceof ConnectionException && ! $error instanceof LockTimeoutException) {
                report($error);
            }
        } finally {
            // Sin valores ni citas: el contenido del documento no se copia a la auditoría.
            Auditoria::registrar(request(), 'documento.lectura_ia', 'usuarios', $lectura->usuario_id, $lectura->usuario_id, null, [
                'tipo_documento_id' => $lectura->tipo_documento_id, 'resultado' => $lectura->estado === 'lista' ? 'correcto' : 'error',
                'modelo' => $lectura->modelo, 'segundos' => round(microtime(true) - $inicio, 1), 'paginas' => $escaner->paginas,
                'paginas_documento' => $escaner->paginasDocumento ?: null, 'titular' => $titular['estado'] ?? null,
                'campos_pedidos' => $this->campos, 'campos_propuestos' => array_keys($campos), 'campos_sugeridos' => array_keys($escaner->sugerencias),
                'descartados' => $escaner->descartados,
            ]);
        }
    }

    /** Qué ver en pantalla. Un corte por tiempo no es un problema de conexión: se dice cuál fue. */
    public static function mensaje(Throwable $error): string
    {
        return match (true) {
            $error instanceof LockTimeoutException => 'Hay otras lecturas en curso desde hace mucho. Vuelve a intentarlo en unos minutos.',
            $error instanceof ConnectionException && preg_match('/cURL error 28|timed out/i', $error->getMessage()) === 1 => 'La IA tardó más de '.(int) config('escaner.timeout').' s en responder y la lectura se canceló. '
                .'Vuelve a intentarlo; si se repite, el equipo es lento para este documento: completa los campos manualmente.',
            $error instanceof ConnectionException => 'No se pudo conectar con Ollama. Inicia el servicio y verifica el modelo local.',
            $error instanceof ValidationException => (string) collect($error->errors())->flatten()->first(),
            $error instanceof HttpExceptionInterface => $error->getMessage() ?: 'No se pudo completar la lectura.',
            default => 'No se pudo completar la lectura. Puedes completar los campos manualmente.',
        };
    }
}
