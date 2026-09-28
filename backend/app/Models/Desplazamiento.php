<?php

namespace App\Models;

use Database\Factories\DesplazamientoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Desplazamiento extends Model
{
    /** @use HasFactory<DesplazamientoFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'desplazamientos';

    protected $fillable = [
        'usuario_id',
        'documento_id',
        'tipo',
        'unidad_origen_id',
        'unidad_destino_id',
        'puesto_destino',
        'fecha_inicio',
        'fecha_fin',
        'numero_documento',
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

    public function unidadOrigen(): BelongsTo
    {
        return $this->belongsTo(UnidadOrganica::class, 'unidad_origen_id', 'id');
    }

    public function unidadDestino(): BelongsTo
    {
        return $this->belongsTo(UnidadOrganica::class, 'unidad_destino_id', 'id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por', 'id');
    }
}
