<?php

namespace App\Models;

use Database\Factories\AuditoriaEventoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditoriaEvento extends Model
{
    /** @use HasFactory<AuditoriaEventoFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'auditoria_eventos';

    public $timestamps = false;

    protected $fillable = [
        'fecha_evento',
        'actor_usuario_id',
        'usuario_titular_id',
        'accion',
        'entidad',
        'entidad_id',
        'datos_antes',
        'datos_despues',
        'ip_origen',
        'user_agent',
        'request_id',
        'hash_previo',
        'hash_registro',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha_evento' => 'datetime',
        ];
    }

    public function actorUsuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'actor_usuario_id', 'id');
    }

    public function usuarioTitular(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_titular_id', 'id');
    }
}
