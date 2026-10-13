<?php

namespace App\Models\Plataforma;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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

    public const PATRON_SLUG = '/^[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])$/';

    /** Nombre de base válido para PostgreSQL sin comillas especiales (se usa al crearla). */
    public const PATRON_BASE_DATOS = '/^[a-z][a-z0-9_]{2,62}$/';

    public const PATRON_ALMACENAMIENTO = '/^instituciones\/[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])$/';

    /** Subdominios reservados para la plataforma. */
    public const SLUGS_RESERVADOS = ['admin', 'api', 'app', 'plataforma', 'www', 'soporte', 'static', 'assets', 'mail'];

    protected $connection = 'plataforma';

    protected $table = 'instituciones';

    protected $fillable = ['nombre', 'slug', 'estado', 'base_datos', 'almacenamiento', 'aprovisionada_en', 'suspendida_en', 'motivo_suspension', 'dias_gracia'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['aprovisionada_en' => 'datetime', 'suspendida_en' => 'datetime', 'dias_gracia' => 'integer'];
    }

    public function periodos(): HasMany
    {
        return $this->hasMany(PeriodoServicio::class, 'institucion_id');
    }

    /**
     * Estado del tiempo de uso pagado, a la fecha de hoy en PLATAFORMA_ZONA_HORARIA (no en UTC: un servicio pagado
     * hasta el 31/12 dura todo ese día en la hora local). Los periodos consecutivos o superpuestos se encadenan.
     * - sin_periodos: nunca se registró un pago, ni siquiera anulado; no se controla (instituciones anteriores al
     *   control de pagos). Desde el primer pago registrado, el control queda activo.
     * - vigente: hoy está cubierto; vence_el es el fin de la cobertura continua.
     * - en_gracia: venció hace no más de dias_gracia días; funciona, con aviso.
     * - vencido: sin cobertura hoy (incluye pagos que aún no empiezan); la institución no opera.
     *
     * @return array{estado: string, vence_el: ?string, gracia_hasta: ?string, dias_restantes: ?int, proximo_desde: ?string}
     */
    public function servicio(): array
    {
        $hoy = Carbon::now((string) config('plataforma.zona_horaria'))->startOfDay();
        $periodos = $this->periodos->where('anulado', false)->sortBy(fn (PeriodoServicio $p) => $p->desde->toDateString())
            ->map(fn (PeriodoServicio $p): array => [Carbon::parse($p->desde->toDateString(), $hoy->tz), Carbon::parse($p->hasta->toDateString(), $hoy->tz)])->values();
        $resultado = ['estado' => 'sin_periodos', 'vence_el' => null, 'gracia_hasta' => null, 'dias_restantes' => null,
            'proximo_desde' => ($periodos->first(fn (array $p): bool => $p[0]->gt($hoy))[0] ?? null)?->toDateString()];
        // Nunca se registró un pago (ni anulado): sin control. Anular todos los pagos no libera a la institución.
        if ($this->periodos->isEmpty()) {
            return $resultado;
        }
        $fin = $periodos->first(fn (array $p): bool => $p[0]->lte($hoy) && $p[1]->gte($hoy))[1] ?? null;
        if ($fin !== null) {
            // Cobertura continua: se encadenan los que empiezan a más tardar el día siguiente al fin.
            do {
                $extendido = $periodos->first(fn (array $p): bool => $p[0]->lte($fin->copy()->addDay()) && $p[1]->gt($fin));
                $fin = $extendido[1] ?? $fin;
            } while ($extendido !== null);

            return [...$resultado, 'estado' => 'vigente', 'vence_el' => $fin->toDateString(), 'dias_restantes' => (int) $hoy->diffInDays($fin)];
        }
        $ultimo = $periodos->filter(fn (array $p): bool => $p[1]->lt($hoy))->max(fn (array $p) => $p[1]->toDateString());
        if ($ultimo === null) {
            return [...$resultado, 'estado' => 'vencido'];
        }
        $gracia = Carbon::parse($ultimo, $hoy->tz)->addDays($this->dias_gracia ?? 0);

        return [...$resultado, 'estado' => $hoy->lte($gracia) ? 'en_gracia' : 'vencido', 'vence_el' => $ultimo,
            'gracia_hasta' => ($this->dias_gracia ?? 0) > 0 ? $gracia->toDateString() : null];
    }

    /** Opera (atiende peticiones y trabajos en cola): activa y con el servicio no vencido. */
    public function operativa(): bool
    {
        return $this->estado === self::ACTIVA && $this->servicio()['estado'] !== 'vencido';
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
            'dias_gracia' => $this->dias_gracia ?? 0,
            'servicio' => $this->servicio(),
            // Solo identificando por subdominio (ResolverInstitucion) la suspensión bloquea el acceso.
            'suspension_bloquea_acceso' => config('plataforma.modo') === 'subdominio',
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
