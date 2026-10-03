<?php

namespace App\Models;

use Database\Factories\VinculoAdendaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VinculoAdenda extends Model
{
    /** @use HasFactory<VinculoAdendaFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'vinculo_adendas';

    public const UPDATED_AT = null;

    protected $fillable = [
        'vinculo_laboral_id',
        'numero',
        'motivo',
        'fecha_inicio',
        'fecha_fin',
        'documento_id',
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
            'anulado' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function vinculoLaboral(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'vinculo_laboral_id', 'id');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoUsuario::class, 'documento_id', 'id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por', 'id');
    }
}
