<?php

namespace App\Models;

use App\Models\Concerns\PerteneceALegajo;
use Database\Factories\AfiliacionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Afiliacion extends Model
{
    /** @use HasFactory<AfiliacionFactory> */
    use HasFactory;

    use HasUuids;
    use PerteneceALegajo;

    protected $table = 'afiliaciones';

    protected $fillable = [
        'usuario_id',
        'documento_id',
        'tipo',
        'entidad',
        'numero_afiliacion',
        'fecha_inicio',
        'fecha_fin',
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

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por', 'id');
    }
}
