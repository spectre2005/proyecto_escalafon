<?php

namespace App\Models\Plataforma;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Institución cliente de la plataforma. Cada una tendrá su base de datos (base_datos) y su almacenamiento
 * (almacenamiento) propios; esta entrega solo los registra: las rutas institucionales todavía no cambian de base.
 *
 * Estados: registrada (sin aprovisionar: no opera), activa (aprovisionada y habilitada) y suspendida (sin acceso; sus
 * datos y archivos se conservan). Una institución solo pasa a activa con aprovisionada_en, que fija el comando
 * plataforma:verificar-aprovisionamiento tras comprobar su base y su almacenamiento.
 */
class Institucion extends Model
{
    use HasUuids;

    public const REGISTRADA = 'registrada';

    public const ACTIVA = 'activa';

    public const SUSPENDIDA = 'suspendida';

    public const ESTADOS = [self::REGISTRADA, self::ACTIVA, self::SUSPENDIDA];

    /** Subdominios reservados para la plataforma. */
    public const SLUGS_RESERVADOS = ['admin', 'api', 'app', 'plataforma', 'www', 'soporte', 'static', 'assets', 'mail'];

    protected $connection = 'plataforma';

    protected $table = 'instituciones';

    protected $fillable = ['nombre', 'slug', 'estado', 'base_datos', 'almacenamiento', 'aprovisionada_en', 'suspendida_en', 'motivo_suspension'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['aprovisionada_en' => 'datetime', 'suspendida_en' => 'datetime'];
    }

    public function aprovisionada(): bool
    {
        return $this->aprovisionada_en !== null;
    }

    /** Mientras no esté aprovisionada se pueden corregir su identificador, su base y su almacenamiento. */
    public function ubicacionEditable(): bool
    {
        return $this->estado === self::REGISTRADA && ! $this->aprovisionada();
    }

    /** Motivo por el que no se puede activar (primera vez o reactivación), o null si se puede. */
    public function motivoNoActivable(): ?string
    {
        return match (true) {
            $this->estado === self::ACTIVA => 'La institución ya está activa.',
            ! $this->aprovisionada() => 'La institución aún no está aprovisionada: su base de datos, su almacenamiento y su configuración deben estar listos y verificados.',
            default => null,
        };
    }

    public function motivoNoSuspendible(): ?string
    {
        return match ($this->estado) {
            self::SUSPENDIDA => 'La institución ya está suspendida.',
            self::REGISTRADA => 'Una institución aún no activa no se suspende: todavía no opera.',
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public function datos(): array
    {
        return [
            ...$this->only(['id', 'nombre', 'slug', 'estado', 'base_datos', 'almacenamiento', 'motivo_suspension']),
            'aprovisionada' => $this->aprovisionada(),
            'aprovisionada_en' => $this->aprovisionada_en?->toIso8601String(),
            'suspendida_en' => $this->suspendida_en?->toIso8601String(),
            'ubicacion_editable' => $this->ubicacionEditable(),
            'motivo_no_activable' => $this->motivoNoActivable(),
            'motivo_no_suspendible' => $this->motivoNoSuspendible(),
            // Solo identificando por subdominio (ResolverInstitucion) la suspensión bloquea el acceso.
            'suspension_bloquea_acceso' => config('plataforma.modo') === 'subdominio',
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
