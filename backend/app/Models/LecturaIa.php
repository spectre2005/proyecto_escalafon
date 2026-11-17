<?php

namespace App\Models;

use Database\Factories\LecturaIaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Una lectura de PDF con IA y su resultado por campo (sin valores del documento). */
class LecturaIa extends Model
{
    /** @use HasFactory<LecturaIaFactory> */
    use HasFactory;

    use HasUuids;

    public const RESULTADOS = ['aceptado', 'corregido', 'borrado', 'no_detectado', 'sugerencia_usada'];

    protected $table = 'lecturas_ia';

    protected $fillable = ['usuario_id', 'tipo_documento_id', 'registrado_por', 'modelo', 'segundos', 'titular', 'campos_pedidos',
        'campos_propuestos', 'campos_sugeridos', 'resultado', 'evaluado_en'];

    protected function casts(): array
    {
        return [
            'segundos' => 'float',
            'campos_pedidos' => 'array',
            'campos_propuestos' => 'array',
            'campos_sugeridos' => 'array',
            'resultado' => 'array',
            'evaluado_en' => 'datetime',
        ];
    }
}
