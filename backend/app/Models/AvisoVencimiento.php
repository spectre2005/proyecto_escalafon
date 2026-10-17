<?php

namespace App\Models;

use Database\Factories\AvisoVencimientoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AvisoVencimiento extends Model
{
    /** @use HasFactory<AvisoVencimientoFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'avisos_vencimiento';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha_programada' => 'date'];
    }

    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(CicloVigencia::class, 'ciclo_id');
    }

    public function envios(): HasMany
    {
        return $this->hasMany(EnvioVencimiento::class, 'aviso_id');
    }
}
