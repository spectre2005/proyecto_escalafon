<?php

namespace App\Models;

use Database\Factories\RegistroSstBienestarFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistroSstBienestar extends Model
{
    /** @use HasFactory<RegistroSstBienestarFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'registros_sst_bienestar';

    protected $fillable = [
        'usuario_id',
        'documento_id',
        'tipo',
        'fecha',
        'descripcion',
        'estado_tramite',
        'registrado_por',
        'anulado',
        'motivo_anulacion',
        'fecha_inicio',
        'fecha_fin',
        'fecha_vencimiento',
        'entidad',
        'numero_documento',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'fecha_vencimiento' => 'date',
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
}
