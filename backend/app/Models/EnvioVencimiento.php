<?php

namespace App\Models;

use Database\Factories\EnvioVencimientoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnvioVencimiento extends Model
{
    /** @use HasFactory<EnvioVencimientoFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'envios_vencimiento';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['proximo_intento_en' => 'datetime', 'enviado_en' => 'datetime', 'intentos' => 'integer'];
    }

    public function aviso(): BelongsTo
    {
        return $this->belongsTo(AvisoVencimiento::class, 'aviso_id');
    }

    public function destinatario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'destinatario_id');
    }
}
