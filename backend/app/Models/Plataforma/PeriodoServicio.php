<?php

namespace App\Models\Plataforma;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Periodo de uso pagado por una institución (de «desde» a «hasta», ambos incluidos). No se edita: se anula. */
class PeriodoServicio extends Model
{
    use HasUuids;

    protected $connection = 'plataforma';

    protected $table = 'periodos_servicio';

    protected $fillable = ['institucion_id', 'desde', 'hasta', 'monto', 'comprobante', 'observacion', 'registrado_por', 'anulado', 'motivo_anulacion', 'anulado_por', 'anulado_en'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['desde' => 'date:Y-m-d', 'hasta' => 'date:Y-m-d', 'monto' => 'decimal:2', 'anulado' => 'boolean', 'anulado_en' => 'datetime'];
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(AdministradorPlataforma::class, 'registrado_por');
    }

    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(AdministradorPlataforma::class, 'anulado_por');
    }

    /** @return array<string, mixed> */
    public function datos(): array
    {
        return [...$this->only(['id', 'monto', 'comprobante', 'observacion', 'anulado', 'motivo_anulacion']),
            'desde' => $this->desde->toDateString(), 'hasta' => $this->hasta->toDateString(),
            'registrado_por' => $this->registradoPor?->nombre, 'anulado_por' => $this->anuladoPor?->nombre,
            'anulado_en' => $this->anulado_en?->toIso8601String(), 'created_at' => $this->created_at?->toIso8601String()];
    }
}
