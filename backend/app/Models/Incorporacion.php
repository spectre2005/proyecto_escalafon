<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Incorporacion extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'incorporaciones';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['documentos' => 'array', 'tareas' => 'array', 'historial' => 'array', 'version' => 'integer', 'incorporado_at' => 'datetime', 'firma_solicitada_at' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'responsable_id');
    }
}
