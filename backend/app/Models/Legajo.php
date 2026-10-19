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
            'fecha_apertura' => 'date',
            'fecha_pasivo' => 'date',
            'fecha_transferencia_archivo_central' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id', 'id');
    }

    /** Cada palabra puede pertenecer al nombre, documento o número de expediente. */
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
}
