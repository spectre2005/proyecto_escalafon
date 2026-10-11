<?php

namespace App\Support;

use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Illuminate\Support\Collection;

/**
 * Qué tipos de documento se ofrecen para cargar a una persona (aplicabilidad). Es distinto de lo exigido
 * (PerfilDocumental): un tipo puede no exigirse y aun así corresponder a la persona.
 *
 * - Tipo de personal: tipos_documento.aplica_tipo_personal (ambos, docente o administrativo).
 * - Régimen: aplica_carrera / aplica_plazo_determinado / aplica_designado.
 * - Fuente: todos los vínculos no anulados, también los cesados (el legajo guarda la historia y al digitalizarlo se
 *   cargan documentos de vínculos anteriores); sin vínculos, los datos de la persona.
 * - Si no se puede saber (sin tipo de personal, o con un régimen que no es carrera, plazo determinado ni designado),
 *   el tipo se ofrece: nunca se impide cargar algo legítimo por falta de datos.
 * - El cargo (puesto) no se usa: el catálogo no relaciona documentos con puestos (pendiente U4).
 */
final class AplicacionDocumental
{
    /** @param list<string> $tiposPersonal @param list<string> $regimenes */
    private function __construct(private readonly array $tiposPersonal, private readonly array $regimenes) {}

    /** @param Collection<int, VinculoLaboral> $vinculos */
    public static function de(Usuario $persona, Collection $vinculos): self
    {
        $vigentes = $vinculos->reject(fn (VinculoLaboral $vinculo): bool => (bool) $vinculo->anulado);
        $origen = $vigentes->isEmpty() ? collect([$persona]) : $vigentes;

        return new self(
            $origen->pluck('tipo_personal')->filter()->map(fn (string $tipo): string => mb_strtolower($tipo))->unique()->values()->all(),
            $origen->pluck('regimen_laboral')->filter()->unique()->values()->all(),
        );
    }

    public static function para(Usuario $persona): self
    {
        return self::de($persona, $persona->vinculosLaborales()->get());
    }

    /** Motivo por el que el tipo no corresponde a la persona, o null si corresponde o no se puede saber. */
    public function noAplica(TipoDocumento $tipo): ?string
    {
        $solo = $tipo->aplica_tipo_personal ?: 'ambos';
        if ($solo !== 'ambos' && $this->tiposPersonal !== [] && ! in_array($solo, $this->tiposPersonal, true)) {
            return "Solo para personal {$solo}.";
        }
        $aplica = array_keys(array_filter(PerfilDocumental::APLICA, fn (string $columna): bool => (bool) $tipo->{$columna}));
        $conocidos = array_keys(PerfilDocumental::APLICA);
        $todosConocidos = $this->regimenes !== [] && array_diff($this->regimenes, $conocidos) === [];
        if (count($aplica) < count($conocidos) && $todosConocidos && array_intersect($this->regimenes, $aplica) === []) {
            return 'No corresponde a su régimen laboral.';
        }

        return null;
    }

    /**
     * Tipos activos que no corresponden a la persona (con id y código: los paneles identifican sus tipos por uno u otro).
     *
     * @return list<array{id: string, codigo: string, motivo: string}>
     */
    public function noAplicables(): array
    {
        return TipoDocumento::query()->where('activo', true)->orderBy('seccion_legajo_id')->orderBy('orden')->get()
            ->map(fn (TipoDocumento $tipo): array => ['id' => $tipo->id, 'codigo' => $tipo->codigo, 'motivo' => $this->noAplica($tipo)])
            ->filter(fn (array $tipo): bool => $tipo['motivo'] !== null)->values()->all();
    }
}
