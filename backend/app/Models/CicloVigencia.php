<?php

namespace App\Models;

use Database\Factories\CicloVigenciaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CicloVigencia extends Model
{
    /** @use HasFactory<CicloVigenciaFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'ciclos_vigencia_legajo';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha_base' => 'date', 'fecha_vencimiento' => 'date', 'fecha_aviso' => 'date', 'actual' => 'boolean', 'cerrado_en' => 'datetime', 'anios' => 'integer'];
    }

    public function legajo(): BelongsTo
    {
        return $this->belongsTo(Legajo::class);
    }

    public function perfilDocente(): BelongsTo
    {
        return $this->belongsTo(PerfilDocente::class);
    }

    public function documentoBase(): BelongsTo
    {
        return $this->belongsTo(DocumentoUsuario::class, 'documento_base_id');
    }

    public function requerimientos(): HasMany
    {
        return $this->hasMany(RequerimientoDocumento::class, 'ciclo_id');
    }

    public function renovacion(): HasOne
    {
        return $this->hasOne(RenovacionLegajo::class, 'ciclo_id');
    }

    public function avisos(): HasMany
    {
        return $this->hasMany(AvisoVencimiento::class, 'ciclo_id');
    }
}
