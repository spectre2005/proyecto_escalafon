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
 * Las banderas (tres estados: Sí, No, Sin responder) las decide AplicacionDocumental, la única fuente.
 */
final class PerfilDocumental
{
    /** Régimen laboral (enum del esquema) => columna aplica_* de tipos_documento. */
    public const APLICA = ['carrera' => 'aplica_carrera', 'plazo_determinado' => 'aplica_plazo_determinado', 'designado' => 'aplica_designado'];

    /**
     * @param  list<string>  $regimenes
     * @param  list<string>  $tiposPersonal
     * @param  array<string, ?bool>  $banderas  para exigir (AplicacionDocumental)
     * @param  list<string>  $puestos
     */
    private function __construct(public readonly array $regimenes, public readonly array $tiposPersonal, public readonly array $banderas, public readonly array $puestos,
        public readonly AplicacionDocumental $aplicacion) {}

    /** @param Collection<int, VinculoLaboral> $vinculos */
    public static function de(Usuario $usuario, Collection $vinculos): self
    {
        // Los vínculos anulados no cuentan para el perfil.
        $vigentes = $vinculos->whereNull('fecha_cese')->reject(fn (VinculoLaboral $vinculo): bool => (bool) $vinculo->anulado);
        $origen = $vigentes->isEmpty() ? collect([$usuario]) : $vigentes;
        $aplicacion = AplicacionDocumental::de($usuario, $vinculos);

        return new self(
            $origen->pluck('regimen_laboral')->filter()->unique()->values()->all(),
            $origen->pluck('tipo_personal')->filter()->unique()->values()->all(),
            $aplicacion->banderas,
            $origen->pluck('nombre_puesto')->filter()->unique()->values()->all(),
            $aplicacion,
        );
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
        // Banderas: No no se exige; Sin responder queda por determinar (no cuenta en la completitud).
        if (($porBandera = $this->aplicacion->exigencia($tipo)) !== null) {
            return $porBandera;
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
