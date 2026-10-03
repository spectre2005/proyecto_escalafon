<?php

namespace App\Models;

use Database\Factories\ColegiaturaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Colegiatura extends Model
{
    /** @use HasFactory<ColegiaturaFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'colegiaturas';

    protected $fillable = [
        'usuario_id',
        'documento_id',
        'colegio_profesional',
        'numero_colegiatura',
        'fecha_colegiatura',
        'habilitado',
        'fecha_verificacion',
        'vigente_hasta',
        'registrado_por',
        'anulado',
        'motivo_anulacion',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha_colegiatura' => 'date',
            'habilitado' => 'boolean',
            'fecha_verificacion' => 'date',
            'vigente_hasta' => 'date',
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

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por', 'id');
    }
}
