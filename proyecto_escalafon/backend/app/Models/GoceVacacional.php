<?php

namespace App\Models;

use Database\Factories\GoceVacacionalFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoceVacacional extends Model
{
    /** @use HasFactory<GoceVacacionalFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'goces_vacacionales';

    protected $fillable = [
        'usuario_id',
        'documento_id',
        'periodo_vacacional_id',
        'tipo',
        'fecha_inicio',
        'fecha_fin',
        'dias',
        'numero_documento',
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
            'dias' => 'integer',
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

    public function periodoVacacional(): BelongsTo
    {
        return $this->belongsTo(PeriodoVacacional::class, 'periodo_vacacional_id', 'id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por', 'id');
    }
}
