<?php

namespace App\Models\Plataforma;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Operación administrativa de la plataforma (solo se crea: no se edita ni se borra). */
class AuditoriaPlataforma extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $connection = 'plataforma';

    protected $table = 'auditoria_plataforma';

    protected $fillable = ['administrador_id', 'accion', 'institucion_id', 'datos_antes', 'datos_despues', 'motivo', 'ip_origen', 'user_agent'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['datos_antes' => 'array', 'datos_despues' => 'array', 'created_at' => 'datetime'];
    }

    public function administrador(): BelongsTo
    {
        return $this->belongsTo(AdministradorPlataforma::class, 'administrador_id');
    }

    public function institucion(): BelongsTo
    {
        return $this->belongsTo(Institucion::class, 'institucion_id');
    }
}
