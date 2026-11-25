<?php

namespace App\Models;

use App\Models\Concerns\PerteneceALegajo;
use Database\Factories\PeriodoVacacionalFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PeriodoVacacional extends Model
{
    /** @use HasFactory<PeriodoVacacionalFactory> */
    use HasFactory;

    use HasUuids;
    use PerteneceALegajo;

    protected $table = 'periodos_vacacionales';

    protected $fillable = [
        'usuario_id',
        'documento_id',
        'vinculo_laboral_id',
        'periodo_anio',
        'dias_derecho',
        'registrado_por',
        'anulado',
        'motivo_anulacion',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'periodo_anio' => 'integer',
            'dias_derecho' => 'integer',
            'anulado' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Usuario, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id', 'id');
    }

    /** @return BelongsTo<DocumentoUsuario, $this> */
    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoUsuario::class, 'documento_id', 'id');
    }

    /** @return BelongsTo<VinculoLaboral, $this> */
    public function vinculoLaboral(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'vinculo_laboral_id', 'id');
    }

    /** @return BelongsTo<Usuario, $this> */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por', 'id');
    }

    /** @return HasMany<GoceVacacional, $this> */
    public function gocesVacacionales(): HasMany
    {
        return $this->hasMany(GoceVacacional::class, 'periodo_vacacional_id', 'id');
    }
}
