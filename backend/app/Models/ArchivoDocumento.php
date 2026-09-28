<?php

namespace App\Models;

use Database\Factories\ArchivoDocumentoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArchivoDocumento extends Model
{
    /** @use HasFactory<ArchivoDocumentoFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'archivos_documento';

    public $timestamps = false;

    protected $fillable = [
        'almacenamiento',
        'bucket',
        'storage_path',
        'nombre_original',
        'mime_type',
        'tamano_bytes',
        'hash_sha256',
        'num_paginas',
        'firmado_digitalmente',
        'cifrado',
        'estado_antivirus',
        'subido_por',
        'subido_en',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tamano_bytes' => 'integer',
            'num_paginas' => 'integer',
            'firmado_digitalmente' => 'boolean',
            'cifrado' => 'boolean',
            'subido_en' => 'datetime',
        ];
    }

    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'subido_por', 'id');
    }

    public function documentosUsuario(): HasMany
    {
        return $this->hasMany(DocumentoUsuario::class, 'archivo_id', 'id');
    }

    public function compromisosEntregaOriginales(): HasMany
    {
        return $this->hasMany(CompromisoEntregaOriginal::class, 'archivo_id', 'id');
    }

    public function movimientosCustodiaLegajo(): HasMany
    {
        return $this->hasMany(MovimientoCustodiaLegajo::class, 'sustento_archivo_id', 'id');
    }

    public function solicitudesFiscalizacion(): HasMany
    {
        return $this->hasMany(SolicitudFiscalizacion::class, 'oficio_archivo_id', 'id');
    }
}
