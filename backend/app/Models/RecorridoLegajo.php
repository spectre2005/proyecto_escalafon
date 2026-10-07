<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RecorridoLegajo extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'recorridos_legajo';

    protected $fillable = ['legajo_id', 'actor_usuario_id', 'seccion_actual_id', 'secciones_omitidas'];

    protected function casts(): array
    {
        return ['seccion_actual_id' => 'integer', 'secciones_omitidas' => 'array'];
    }
}
