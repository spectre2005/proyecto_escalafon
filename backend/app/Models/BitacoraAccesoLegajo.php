<?php

namespace App\Models;

use App\Support\RegistroInmutable;
use Database\Factories\BitacoraAccesoLegajoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BitacoraAccesoLegajo extends Model
{
    /** @use HasFactory<BitacoraAccesoLegajoFactory> */
    use HasFactory;

    use HasUuids;
    use RegistroInmutable;

    protected $table = 'bitacora_accesos_legajo';

    public $timestamps = false;

    protected $fillable = [
        'legajo_id',
        'documento_id',
        'usuario_id',
        'solicitud_acceso_id',
        'accion',
        'ip_origen',
        'user_agent',
        'fecha_acceso',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha_acceso' => 'datetime',
        ];
    }

    public function legajo(): BelongsTo
    {
        return $this->belongsTo(Legajo::class, 'legajo_id', 'id');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoUsuario::class, 'documento_id', 'id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id', 'id');
    }

    public function solicitudAcceso(): BelongsTo
    {
        return $this->belongsTo(SolicitudAccesoLegajo::class, 'solicitud_acceso_id', 'id');
    }
}
