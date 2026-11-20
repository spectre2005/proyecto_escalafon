<?php

namespace App\Models;

use App\Models\Concerns\PerteneceALegajo;
use Database\Factories\LicenciaPermisoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenciaPermiso extends Model
{
    /** @use HasFactory<LicenciaPermisoFactory> */
    use HasFactory;

    use HasUuids;
    use PerteneceALegajo;

    protected $table = 'licencias_permisos';

    protected $fillable = [
        'usuario_id',
        'documento_id',
        'tipo',
        'forma_permiso',
        'con_goce',
        'fecha_inicio',
        'fecha_fin',
        'dias',
        'numero_documento',
        'registrado_por',
        'anulado',
        'motivo_anulacion',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'con_goce' => 'boolean',
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'dias' => 'integer',
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
