<?php

namespace App\Models;

use Database\Factories\CompromisoDocumentoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompromisoDocumento extends Model
{
    /** @use HasFactory<CompromisoDocumentoFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'compromiso_documentos';

    public $timestamps = false;

    protected $fillable = [
        'compromiso_id',
        'tipo_documento_id',
        'descripcion',
        'documento_id',
        'entregado_en',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'entregado_en' => 'date',
        ];
    }

    public function compromiso(): BelongsTo
    {
        return $this->belongsTo(CompromisoEntregaOriginal::class, 'compromiso_id', 'id');
    }

    public function tipoDocumento(): BelongsTo
    {
        return $this->belongsTo(TipoDocumento::class, 'tipo_documento_id', 'id');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoUsuario::class, 'documento_id', 'id');
    }
}
