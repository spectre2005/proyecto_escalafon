<?php

namespace App\Models;

use Database\Factories\ProcesoFiscalizacionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcesoFiscalizacion extends Model
{
    /** @use HasFactory<ProcesoFiscalizacionFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'procesos_fiscalizacion';

    protected $fillable = [
        'usuario_id',
        'legajo_id',
        'tipo_muestra',
        'fecha_vinculacion',
        'fecha_inicio',
        'fecha_limite',
        'fecha_fin',
        'estado',
        'responsable_id',
        'informe_documento_id',
        'conclusiones',
        'comunicado_procuraduria_en',
        'comunicado_stpad_en',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'fecha_vinculacion' => 'date',
            'fecha_inicio' => 'date',
            'fecha_limite' => 'date',
            'fecha_fin' => 'date',
            'comunicado_procuraduria_en' => 'date',
            'comunicado_stpad_en' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id', 'id');
    }

    public function legajo(): BelongsTo
    {
        return $this->belongsTo(Legajo::class, 'legajo_id', 'id');
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'responsable_id', 'id');
    }

    public function informeDocumento(): BelongsTo
    {
        return $this->belongsTo(DocumentoUsuario::class, 'informe_documento_id', 'id');
    }

    public function solicitudesFiscalizacion(): HasMany
    {
        return $this->hasMany(SolicitudFiscalizacion::class, 'proceso_fiscalizacion_id', 'id');
    }
}
