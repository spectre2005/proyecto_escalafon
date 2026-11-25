<?php

namespace App\Models;

use Database\Factories\FormacionAcademicaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormacionAcademica extends Model
{
    /** @use HasFactory<FormacionAcademicaFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'formacion_academica';

    protected $fillable = [
        'usuario_id',
        'documento_id',
        'nivel',
        'denominacion',
        'institucion',
        'pais',
        'es_revalidado',
        'fecha_inicio',
        'fecha_obtencion',
        'numero_registro_sunedu',
        'numero_diploma',
        'registrado_por',
        'anulado',
        'motivo_anulacion',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'es_revalidado' => 'boolean',
            'fecha_inicio' => 'date',
            'fecha_obtencion' => 'date',
            'anulado' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Usuario, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id', 'id');
    }

    /** @return BelongsTo<DocumentoUsuario, $this> */
    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoUsuario::class, 'documento_id', 'id');
    }

    /** @return BelongsTo<Usuario, $this> */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por', 'id');
    }
}
