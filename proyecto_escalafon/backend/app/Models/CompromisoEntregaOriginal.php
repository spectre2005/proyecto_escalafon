<?php

namespace App\Models;

use Database\Factories\CompromisoEntregaOriginalFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompromisoEntregaOriginal extends Model
{
    /** @use HasFactory<CompromisoEntregaOriginalFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'compromisos_entrega_originales';

    protected $fillable = [
        'usuario_id',
        'legajo_id',
        'motivo_impedimento',
        'fecha_compromiso',
        'fecha_limite',
        'estado',
        'fecha_cumplimiento',
        'archivo_id',
        'registrado_por',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha_compromiso' => 'date',
            'fecha_limite' => 'date',
            'fecha_cumplimiento' => 'date',
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

    public function archivo(): BelongsTo
    {
        return $this->belongsTo(ArchivoDocumento::class, 'archivo_id', 'id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por', 'id');
    }

    public function compromisoDocumentos(): HasMany
    {
        return $this->hasMany(CompromisoDocumento::class, 'compromiso_id', 'id');
    }
}
