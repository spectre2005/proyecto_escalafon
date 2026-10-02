<?php

namespace App\Models;

use Database\Factories\CertificadoDiscapacidadFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CertificadoDiscapacidad extends Model
{
    /** @use HasFactory<CertificadoDiscapacidadFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'certificados_discapacidad';

    protected $hidden = ['pdf_path'];

    protected $fillable = [
        'usuario_id',
        'documento_id',
        'persona',
        'familiar_id',
        'numero_conadis',
        'fecha_emision',
        'vigente_hasta',
        'registrado_por',
        'anulado',
        'motivo_anulacion',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha_emision' => 'date',
            'vigente_hasta' => 'date',
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

    public function familiar(): BelongsTo
    {
        return $this->belongsTo(Familiar::class, 'familiar_id', 'id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por', 'id');
    }
}
