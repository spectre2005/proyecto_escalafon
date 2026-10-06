<?php

namespace App\Models;

use Database\Factories\ExperienciaLaboralFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExperienciaLaboral extends Model
{
    /** @use HasFactory<ExperienciaLaboralFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'experiencia_laboral';

    protected $fillable = [
        'usuario_id',
        'documento_id',
        'entidad_empresa',
        'sector',
        'cargo',
        'tipo_vinculo',
        'es_ejercicio_profesional',
        'es_docencia_universitaria',
        'funciones',
        'fecha_inicio',
        'fecha_fin',
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
            'es_ejercicio_profesional' => 'boolean',
            'es_docencia_universitaria' => 'boolean',
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
