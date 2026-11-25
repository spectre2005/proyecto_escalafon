<?php

namespace App\Models;

use Database\Factories\SolicitudFiscalizacionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolicitudFiscalizacion extends Model
{
    /** @use HasFactory<SolicitudFiscalizacionFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'solicitudes_fiscalizacion';

    protected $fillable = [
        'proceso_fiscalizacion_id',
        'documento_id',
        'entidad_consultada',
        'medio',
        'numero_oficio',
        'fecha_envio',
        'oficio_archivo_id',
        'fecha_respuesta',
        'documento_respuesta_id',
        'resultado',
        'descripcion_hallazgo',
        'registrado_por',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha_envio' => 'date',
            'fecha_respuesta' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProcesoFiscalizacion, $this> */
    public function procesoFiscalizacion(): BelongsTo
    {
        return $this->belongsTo(ProcesoFiscalizacion::class, 'proceso_fiscalizacion_id', 'id');
    }

    /** @return BelongsTo<DocumentoUsuario, $this> */
    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoUsuario::class, 'documento_id', 'id');
    }

    /** @return BelongsTo<ArchivoDocumento, $this> */
    public function oficioArchivo(): BelongsTo
    {
        return $this->belongsTo(ArchivoDocumento::class, 'oficio_archivo_id', 'id');
    }

    /** @return BelongsTo<DocumentoUsuario, $this> */
    public function documentoRespuesta(): BelongsTo
    {
        return $this->belongsTo(DocumentoUsuario::class, 'documento_respuesta_id', 'id');
    }

    /** @return BelongsTo<Usuario, $this> */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por', 'id');
    }
}
