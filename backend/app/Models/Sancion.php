<?php

namespace App\Models;

use Database\Factories\SancionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sancion extends Model
{
    /** @use HasFactory<SancionFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'sanciones';

    protected $fillable = [
        'usuario_id',
        'documento_id',
        'tipo',
        'numero_resolucion',
        'fecha_resolucion',
        'falta_imputada',
        'fecha_inicio',
        'fecha_fin',
        'dias_suspension',
        'resultado_recurso',
        'fecha_resultado_recurso',
        'registrada_en_rnssc',
        'fecha_registro_rnssc',
        'referencia_rnssc',
        'referencia_procedimiento',
        'registrado_por',
        'anulado',
        'motivo_anulacion',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha_resolucion' => 'date',
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'dias_suspension' => 'integer',
            'fecha_resultado_recurso' => 'date',
            'registrada_en_rnssc' => 'boolean',
            'fecha_registro_rnssc' => 'date',
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

    public function recursosImpugnatorios(): HasMany
    {
        return $this->hasMany(RecursoImpugnatorio::class, 'sancion_id', 'id')->orderBy('fecha')->orderBy('id');
    }
}
