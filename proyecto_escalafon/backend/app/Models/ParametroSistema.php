<?php

namespace App\Models;

use Database\Factories\ParametroSistemaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParametroSistema extends Model
{
    /** @use HasFactory<ParametroSistemaFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'parametros_sistema';

    public const CREATED_AT = null;

    protected $fillable = [
        'clave',
        'valor',
        'tipo_dato',
        'descripcion',
        'actualizado_por',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'updated_at' => 'datetime',
        ];
    }

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'actualizado_por', 'id');
    }
}
