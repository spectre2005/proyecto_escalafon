<?php

namespace App\Models;

use Database\Factories\MovimientoCustodiaLegajoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimientoCustodiaLegajo extends Model
{
    /** @use HasFactory<MovimientoCustodiaLegajoFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'movimientos_custodia_legajo';

    public const UPDATED_AT = null;

    protected $fillable = [
        'legajo_id',
        'tipo_movimiento',
        'base_autorizacion',
        'autorizado_por',
        'entidad_destino',
        'numero_documento_sustento',
        'sustento_archivo_id',
        'formato_legajo',
        'numero_inventario',
        'fecha_movimiento',
        'fecha_retorno',
        'servidor_comunicado',
        'fecha_comunicacion_servidor',
        'observaciones',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha_movimiento' => 'date',
            'fecha_retorno' => 'date',
            'servidor_comunicado' => 'boolean',
            'fecha_comunicacion_servidor' => 'date',
            'created_at' => 'datetime',
        ];
    }

    public function legajo(): BelongsTo
    {
        return $this->belongsTo(Legajo::class, 'legajo_id', 'id');
    }

    public function autorizadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'autorizado_por', 'id');
    }

    public function sustentoArchivo(): BelongsTo
    {
        return $this->belongsTo(ArchivoDocumento::class, 'sustento_archivo_id', 'id');
    }
}
