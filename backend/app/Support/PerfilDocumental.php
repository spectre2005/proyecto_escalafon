<?php

namespace App\Support;

use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Illuminate\Support\Collection;

/**
 * Perfil de una persona por dos ejes (régimen laboral y banderas) y su cruce con las columnas
 * aplica_* y requiere_* de tipos_documento para saber qué documentos se le exigen.
 *
 * Fuente: los vínculos laborales vigentes (sin fecha_cese); si no hay ninguno, los datos de la persona.
 * Las banderas que existen en ambos lados valen si están en la persona o en algún vínculo vigente.
 */
final class PerfilDocumental
{
    /** Régimen laboral (enum del esquema) => columna aplica_* de tipos_documento. */
    public const APLICA = ['carrera' => 'aplica_carrera', 'plazo_determinado' => 'aplica_plazo_determinado', 'designado' => 'aplica_designado'];

    /** Columna requiere_* => [bandera del perfil, texto cuando no aplica]. */
    private const REQUIERE = [
        'requiere_estudios_basicos' => ['exige_estudios_basicos', 'solo si el puesto exige estudios básicos'],
        'requiere_djbr' => ['obligado_djbr', 'solo para obligados a la declaración jurada de bienes y rentas'],
        'requiere_colegiatura' => ['exige_colegiatura', 'solo si el puesto exige colegiatura'],
        'requiere_derechohabientes' => ['tiene_derechohabientes', 'solo si tiene derechohabientes'],
        'requiere_periodo_prueba' => ['tiene_periodo_prueba', 'solo si tiene período de prueba'],
        'requiere_discapacidad' => ['con_discapacidad', 'solo para personas con discapacidad'],
    ];

    /**
     * @param  list<string>  $regimenes
     * @param  list<string>  $tiposPersonal
     * @param  array<string, bool>  $banderas
     * @param  list<string>  $puestos
     */
    private function __construct(public readonly array $regimenes, public readonly array $tiposPersonal, public readonly array $banderas, public readonly array $puestos) {}

    /** @param Collection<int, VinculoLaboral> $vinculos */
    public static function de(Usuario $usuario, Collection $vinculos): self
    {
        // Los vínculos anulados no cuentan para el perfil.
        $vigentes = $vinculos->whereNull('fecha_cese')->reject(fn (VinculoLaboral $vinculo): bool => (bool) $vinculo->anulado);
        $origen = $vigentes->isEmpty() ? collect([$usuario]) : $vigentes;
        $banderas = [];
        foreach (['obligado_djbr', 'exige_colegiatura', 'exige_estudios_basicos', 'tiene_periodo_prueba'] as $bandera) {
            $banderas[$bandera] = (bool) $usuario->{$bandera} || $vigentes->contains(fn (VinculoLaboral $vinculo): bool => (bool) $vinculo->{$bandera});
        }
        foreach (['tiene_derechohabientes', 'con_discapacidad'] as $bandera) {
            $banderas[$bandera] = (bool) $usuario->{$bandera};
        }

        return new self(
            $origen->pluck('regimen_laboral')->filter()->unique()->values()->all(),
            $origen->pluck('tipo_personal')->filter()->unique()->values()->all(),
            $banderas,
            $origen->pluck('nombre_puesto')->filter()->unique()->values()->all(),
        );
    }

    public function tienePeriodoPrueba(): bool
    {
        return $this->banderas['tiene_periodo_prueba'];
    }

    /** Regímenes que son del enum del esquema; los demás (p. ej. códigos normativos) no se pueden cruzar. */
    public function regimenesClasificados(): array
    {
        return array_values(array_intersect($this->regimenes, array_keys(self::APLICA)));
    }

    /**
     * ¿Se le exige este tipo? exigido = null cuando no se puede saber con los datos registrados.
     *
     * @return array{exigido: ?bool, motivo: string}
     */
    public function exigencia(TipoDocumento $tipo): array
    {
        // Lo que la institución desactivó no se exige (config/funcionalidades.php); lo ya registrado no se toca.
        if (! ConfiguracionFuncionalidades::tipoActivo((string) $tipo->codigo, (int) $tipo->seccion_legajo_id)) {
            return ['exigido' => false, 'motivo' => 'No se exige: desactivado en la configuración de la institución.'];
        }
        if (! $tipo->obligatorio) {
            return ['exigido' => false, 'motivo' => 'Opcional: se incorpora solo si existe.'];
        }
        foreach (['docente' => 'requiere_docente', 'administrativo' => 'requiere_administrativo'] as $tipoPersonal => $columna) {
            if ($tipo->{$columna} && ! in_array($tipoPersonal, $this->tiposPersonal, true)) {
                return ['exigido' => false, 'motivo' => "No se exige: solo para personal {$tipoPersonal}."];
            }
        }
        foreach (self::REQUIERE as $columna => [$bandera, $texto]) {
            if ($tipo->{$columna} && ! $this->banderas[$bandera]) {
                return ['exigido' => false, 'motivo' => "No se exige: {$texto}."];
            }
        }
        $aplica = array_filter(self::APLICA, fn (string $columna): bool => (bool) $tipo->{$columna});
        if (count($aplica) < count(self::APLICA)) {
            $clasificados = $this->regimenesClasificados();
            if ($clasificados === []) {
                return ['exigido' => null, 'motivo' => 'No se puede determinar: el régimen laboral registrado no es carrera, plazo determinado ni designado.'];
            }
            if (array_intersect($clasificados, array_keys($aplica)) === []) {
                return ['exigido' => false, 'motivo' => 'No se exige para su régimen laboral.'];
            }
        }

        return ['exigido' => true, 'motivo' => 'Se exige según su régimen y banderas.'];
    }
}
