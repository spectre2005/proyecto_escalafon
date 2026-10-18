<?php

namespace App\Models\Plataforma;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Resultado de un respaldo de una institución, de la plataforma o de la base principal (solo metadatos). */
class Respaldo extends Model
{
    use HasUuids;

    public const CORRECTO = 'correcto';

    public const FALLIDO = 'fallido';

    public const ESTADOS = [self::CORRECTO, self::FALLIDO];

    public const COPIAS_EXTERNAS = ['no_configurada', 'correcta', 'fallida'];

    public const VERIFICACIONES = ['correcta', 'fallida'];

    /** Carpeta de una ejecución de respaldar.sh: AAAAMMDD-HHMMSS. */
    public const PATRON_CARPETA = '/^\d{8}-\d{6}$/';

    /** Sin un respaldo correcto en este tiempo, el panel avisa. */
    public const HORAS_AVISO = 26;

    protected $connection = 'plataforma';

    protected $table = 'respaldos';

    protected $fillable = ['institucion_id', 'objetivo', 'carpeta', 'estado', 'base_datos', 'base_bytes', 'base_sha256', 'archivos_bytes',
        'archivos_sha256', 'copia_externa', 'detalle', 'verificacion', 'verificado_en', 'detalle_verificacion'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['base_bytes' => 'integer', 'archivos_bytes' => 'integer', 'verificado_en' => 'datetime'];
    }

    public function institucion(): BelongsTo
    {
        return $this->belongsTo(Institucion::class, 'institucion_id');
    }

    /** @return array<string, mixed> */
    public function datos(): array
    {
        return [
            ...$this->only(['id', 'objetivo', 'carpeta', 'estado', 'base_bytes', 'archivos_bytes', 'copia_externa', 'detalle', 'verificacion', 'detalle_verificacion']),
            'verificado_en' => $this->verificado_en?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
