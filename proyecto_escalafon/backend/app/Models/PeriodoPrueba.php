<?php

namespace App\Models;

use Database\Factories\PeriodoPruebaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PeriodoPrueba extends Model
{
    /** @use HasFactory<PeriodoPruebaFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'periodos_prueba';

    protected $fillable = [
        'usuario_id',
        'documento_id',
        'vinculo_laboral_id',
        'fecha_inicio',
        'fecha_fin',
        'resultado',
        'fecha_resultado',
        'registrado_por',
        'anulado',
        'motivo_anulacion',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'fecha_resultado' => 'date',
            'anulado' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id', 'id');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoUsuario::class, 'documento_id', 'id');
    }

    public function vinculoLaboral(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'vinculo_laboral_id', 'id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por', 'id');
    }
}
