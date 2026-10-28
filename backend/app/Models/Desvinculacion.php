<?php

namespace App\Models;

use App\Models\Concerns\PerteneceALegajo;
use Database\Factories\DesvinculacionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Desvinculacion extends Model
{
    /** @use HasFactory<DesvinculacionFactory> */
    use HasFactory;

    use HasUuids;
    use PerteneceALegajo;

    protected $table = 'desvinculaciones';

    protected $fillable = [
        'usuario_id',
        'documento_id',
        'vinculo_laboral_id',
        'motivo_cese',
        'fecha_cese',
        'numero_resolucion',
        'fecha_entrega_cargo',
        'informe_gestion_presentado',
        'fecha_liquidacion',
        'observaciones',
        'registrado_por',
        'anulado',
        'motivo_anulacion',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha_cese' => 'date',
            'fecha_entrega_cargo' => 'date',
            'informe_gestion_presentado' => 'boolean',
            'fecha_liquidacion' => 'date',
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

    public function vinculoLaboral(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'vinculo_laboral_id', 'id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por', 'id');
    }
}
