<?php

namespace App\Models;

use App\Models\Concerns\PerteneceALegajo;
use Database\Factories\RatificacionDocenteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RatificacionDocente extends Model
{
    /** @use HasFactory<RatificacionDocenteFactory> */
    use HasFactory;

    use HasUuids;
    use PerteneceALegajo;

    protected $table = 'ratificaciones_docentes';

    protected $fillable = [
        'usuario_id',
        'documento_id',
        'perfil_docente_id',
        'tipo',
        'categoria_anterior',
        'categoria_nueva',
        'numero_resolucion',
        'fecha_resolucion',
        'fecha_inicio_periodo',
        'fecha_vencimiento_periodo',
        'resultado',
        'registrado_por',
        'anulado',
        'motivo_anulacion',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha_resolucion' => 'date',
            'fecha_inicio_periodo' => 'date',
            'fecha_vencimiento_periodo' => 'date',
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

    public function perfilDocente(): BelongsTo
    {
        return $this->belongsTo(PerfilDocente::class, 'perfil_docente_id', 'id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por', 'id');
    }
}
