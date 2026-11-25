<?php

namespace App\Models;

use Database\Factories\FamiliarFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Familiar extends Model
{
    /** @use HasFactory<FamiliarFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'familiares';

    protected $fillable = [
        'usuario_id',
        'documento_id',
        'parentesco',
        'tipo_doc_identidad',
        'numero_doc_identidad',
        'apellidos_nombres',
        'fecha_nacimiento',
        'es_derechohabiente',
        'fecha_fallecimiento',
        'registrado_por',
        'anulado',
        'motivo_anulacion',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
            'fecha_fallecimiento' => 'date',
            'es_derechohabiente' => 'boolean',
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

    /** @return HasMany<CertificadoDiscapacidad, $this> */
    public function certificadosDiscapacidad(): HasMany
    {
        return $this->hasMany(CertificadoDiscapacidad::class, 'familiar_id', 'id');
    }
}
