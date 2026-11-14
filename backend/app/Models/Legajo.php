<?php

namespace App\Models;

use Database\Factories\LegajoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Legajo extends Model
{
    /** @use HasFactory<LegajoFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'legajos';

    protected $hidden = ['datos_al_cierre'];

    protected $fillable = [
        'usuario_id',
        'numero_legajo',
        'estado_legajo',
        'formato_legajo',
        'ubicacion',
        'fecha_apertura',
        'fecha_pasivo',
        'fecha_transferencia_archivo_central',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'datos_al_cierre' => 'encrypted:array',
            'fecha_apertura' => 'date',
            'fecha_pasivo' => 'date',
            'fecha_transferencia_archivo_central' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $legajo): void {
            if (! $legajo->exists || $legajo->getOriginal('estado_legajo') !== 'activo' || ! $legajo->isDirty('estado_legajo') || $legajo->estado_legajo !== 'pasivo' || $legajo->datos_al_cierre !== null) {
                return;
            }
            $persona = Usuario::query()->with(['ubigeo', 'regimenNormativo', 'unidadOrganica'])->findOrFail($legajo->usuario_id);
            $datos = $persona->only(['tipo_doc_identidad', 'numero_doc_identidad', 'nombres', 'apellido_paterno', 'apellido_materno',
                'email_institucional', 'telefono', 'tipo_personal', 'nacionalidad', 'estado_civil', 'domicilio',
                'tiene_derechohabientes', 'con_discapacidad']);
            $datos['fecha_nacimiento'] = $persona->fecha_nacimiento?->format('Y-m-d');
            foreach (['region', 'provincia', 'distrito'] as $campo) {
                $datos[$campo] = $persona->ubigeo?->{$campo};
            }
            $datos['ley'] = $persona->regimenNormativo?->nombre;
            $vinculo = VinculoLaboral::query()->delLegajo($legajo->id)->where('usuario_id', $persona->id)
                ->where('anulado', false)->with('unidadOrganica')->orderByDesc('fecha_inicio')->orderByDesc('id')->first();
            $datos['puesto'] = $vinculo?->nombre_puesto ?? $persona->nombre_puesto;
            $datos['unidad_organica'] = $vinculo?->unidadOrganica?->nombre ?? $persona->unidadOrganica?->nombre;
            $legajo->datos_al_cierre = $datos;
        });
    }

    /** Datos históricos; null indica que el cierre fue anterior a la conservación de la ficha. */
    public function datosHistoricos(bool $sensibles): ?array
    {
        $datos = $this->datos_al_cierre;
        if ($datos === null || $sensibles) {
            return $datos;
        }

        return array_diff_key($datos, array_flip(['fecha_nacimiento', 'nacionalidad', 'estado_civil', 'domicilio', 'region',
            'provincia', 'distrito', 'telefono', 'tiene_derechohabientes', 'con_discapacidad']));
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id', 'id');
    }

    public static function paraConsulta(string $usuario): ?self
    {
        return self::query()->where('usuario_id', $usuario)
            ->orderByRaw("CASE WHEN estado_legajo = 'activo' THEN 0 ELSE 1 END")
            ->orderByDesc('fecha_apertura')->orderByDesc('created_at')->orderByDesc('id')->first();
    }

    public function legajoSecciones(): HasMany
    {
        return $this->hasMany(LegajoSeccion::class, 'legajo_id', 'id');
    }

    public function documentosUsuario(): HasMany
    {
        return $this->hasMany(DocumentoUsuario::class, 'legajo_id', 'id');
    }

    public function compromisosEntregaOriginales(): HasMany
    {
        return $this->hasMany(CompromisoEntregaOriginal::class, 'legajo_id', 'id');
    }

    public function referenciasLegajoPasivo(): HasMany
    {
        return $this->hasMany(ReferenciaLegajoPasivo::class, 'legajo_id', 'id');
    }

    public function solicitudesAccesoLegajo(): HasMany
    {
        return $this->hasMany(SolicitudAccesoLegajo::class, 'legajo_id', 'id');
    }

    public function movimientosCustodiaLegajo(): HasMany
    {
        return $this->hasMany(MovimientoCustodiaLegajo::class, 'legajo_id', 'id');
    }

    public function procesosFiscalizacion(): HasMany
    {
        return $this->hasMany(ProcesoFiscalizacion::class, 'legajo_id', 'id');
    }

    public function bitacoraAccesosLegajo(): HasMany
    {
        return $this->hasMany(BitacoraAccesoLegajo::class, 'legajo_id', 'id');
    }

    /** Cada palabra debe aparecer en el número de legajo, el nombre completo o el documento de identidad. */
    public function scopeBuscar(Builder $query, ?string $texto): Builder
    {
        foreach (preg_split('/\s+/u', mb_strtolower(trim($texto ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $palabra) {
            $patron = '%'.$palabra.'%';
            $query->where(fn (Builder $coincidencia) => $coincidencia->whereRaw('LOWER(numero_legajo) LIKE ?', [$patron])
                ->orWhereHas('usuario', fn (Builder $persona) => $persona->whereRaw("LOWER(nombres || ' ' || apellido_paterno || ' ' || COALESCE(apellido_materno, '')) LIKE ?", [$patron])
                    ->orWhere('numero_doc_identidad', 'like', $patron)));
        }

        return $query;
    }
}
