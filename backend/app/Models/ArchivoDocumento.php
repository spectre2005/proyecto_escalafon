<?php

namespace App\Models;

use App\Support\Documentos\DocumentoProtegido;
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

    /** Lo único que cambia de un archivo guardado es el resultado del antivirus (antivirus:escanear). */
    public const CAMPOS_EDITABLES = ['estado_antivirus'];

    /** El PDF es la evidencia del documento: su registro no se elimina ni se reescribe (Directiva, arts. 2.3 y 5.3). */
    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new DocumentoProtegido('Los archivos del legajo no se eliminan (Directiva, arts. 2.3 y 5.3).');
        });
        static::updating(function (self $archivo): void {
            $cambiados = array_diff(array_keys($archivo->getDirty()), self::CAMPOS_EDITABLES);
            if ($cambiados !== []) {
                throw new DocumentoProtegido('No se puede modificar '.implode(', ', $cambiados).' de un archivo del legajo.');
            }
        });
    }

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

    /** @return BelongsTo<Usuario, $this> */
    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'subido_por', 'id');
    }

    /** @return HasMany<DocumentoUsuario, $this> */
    public function documentosUsuario(): HasMany
    {
        return $this->hasMany(DocumentoUsuario::class, 'archivo_id', 'id');
    }

    /** @return HasMany<CompromisoEntregaOriginal, $this> */
    public function compromisosEntregaOriginales(): HasMany
    {
        return $this->hasMany(CompromisoEntregaOriginal::class, 'archivo_id', 'id');
    }

    /** @return HasMany<MovimientoCustodiaLegajo, $this> */
    public function movimientosCustodiaLegajo(): HasMany
    {
        return $this->hasMany(MovimientoCustodiaLegajo::class, 'sustento_archivo_id', 'id');
    }

    /** @return HasMany<SolicitudFiscalizacion, $this> */
    public function solicitudesFiscalizacion(): HasMany
    {
        return $this->hasMany(SolicitudFiscalizacion::class, 'oficio_archivo_id', 'id');
    }
}
