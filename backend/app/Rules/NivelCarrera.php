<?php

namespace App\Rules;

use App\Models\EscalaNivelCarrera;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Nivel de carrera de un perfil administrativo: debe estar en la escala de su ley y grupo ocupacional, tal como la
 * registró la institución en Configuración → Catálogos del vínculo (escalas_nivel_carrera, solo niveles activos).
 * En CAS no existe nivel de carrera. Una ley sin escala registrada (p. ej. la 30057, hasta que se defina) deja el campo
 * vacío. Un nivel ya guardado que no se cambia se acepta aunque ya no esté en la escala: los registros anteriores se
 * pueden seguir corrigiendo en sus otros campos.
 */
class NivelCarrera implements DataAwareRule, ValidationRule
{
    /** Leyes sin nivel de carrera por norma (no se les puede registrar escala). */
    public const SIN_NIVEL = ['1057' => 'En CAS (D.L. 1057) no hay nivel de carrera: déjalo vacío.'];

    private array $datos = [];

    public function __construct(private ?string $anterior = null, private ?string $codigoLey = null) {}

    public function setData(array $data): static
    {
        $this->datos = $data;

        return $this;
    }

    /** @return list<string> Niveles activos de esa ley y grupo, del ingreso al máximo (vacía si no hay). */
    public static function niveles(?string $codigoLey, ?string $grupo): array
    {
        if (! $codigoLey || ! $grupo) {
            return [];
        }

        return EscalaNivelCarrera::query()->where('activo', true)->where('grupo_ocupacional', $grupo)
            ->whereHas('regimenNormativo', fn ($ley) => $ley->where('codigo', $codigoLey))
            ->orderBy('orden')->orderBy('codigo')->pluck('codigo')->all();
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $nivel = mb_strtoupper(trim((string) $value));
        if ($this->anterior !== null && $nivel === mb_strtoupper(trim($this->anterior))) {
            return;
        }
        if (isset(self::SIN_NIVEL[$this->codigoLey])) {
            $fail(self::SIN_NIVEL[$this->codigoLey]);

            return;
        }
        if (! $this->codigoLey) {
            $fail('Define primero la ley (régimen normativo) del personal: el nivel de carrera depende de ella.');

            return;
        }
        $conEscala = EscalaNivelCarrera::query()->where('activo', true)->whereHas('regimenNormativo', fn ($ley) => $ley->where('codigo', $this->codigoLey))->exists();
        if (! $conEscala) {
            $fail('Esta ley no tiene escala de niveles registrada: déjalo vacío por ahora.');

            return;
        }
        $grupo = $this->datos['grupo_ocupacional'] ?? null;
        if (! is_string($grupo) || $grupo === '') {
            $fail('Elige primero el grupo ocupacional.');

            return;
        }
        $niveles = self::niveles($this->codigoLey, $grupo);
        if (! in_array($nivel, $niveles, true)) {
            $fail($niveles === [] ? 'Esta ley no tiene niveles registrados para el grupo '.$grupo.': déjalo vacío por ahora.'
                : 'El nivel de carrera no corresponde al grupo '.$grupo.' de esta ley: elige uno de '.implode(', ', $niveles).'.');
        }
    }
}
