<?php

namespace App\Models;

use Database\Factories\LecturaIaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Una lectura de PDF con IA y su resultado por campo (sin valores del documento). */
class LecturaIa extends Model
{
    /** @use HasFactory<LecturaIaFactory> */
    use HasFactory;

    use HasUuids;

    public const RESULTADOS = ['aceptado', 'corregido', 'borrado', 'no_detectado', 'sugerencia_usada'];

    protected $table = 'lecturas_ia';

    /** Estados de una lectura en curso: la pantalla sigue consultando mientras esté en uno de estos. */
    public const EN_CURSO = ['en_espera', 'leyendo', 'interpretando'];

    /** Sin avances en este tiempo, una lectura en curso se da por interrumpida (p. ej. se reinició el servidor). */
    public const MINUTOS_SIN_AVANCE = 20;

    protected $fillable = ['usuario_id', 'tipo_documento_id', 'registrado_por', 'modelo', 'segundos', 'titular', 'campos_pedidos',
        'campos_propuestos', 'campos_sugeridos', 'resultado', 'evaluado_en', 'estado', 'pagina_actual', 'paginas_leer',
        'paginas_documento', 'error'];

    /** Clave de caché del resultado (con valores del documento): cifrado y temporal, nunca en la base. */
    public function claveResultado(): string
    {
        return 'lectura-ia:'.$this->id;
    }

    public function interrumpida(): bool
    {
        return in_array($this->estado, self::EN_CURSO, true) && $this->updated_at?->lt(now()->subMinutes(self::MINUTOS_SIN_AVANCE));
    }

    protected function casts(): array
    {
        return [
            'segundos' => 'float',
            'pagina_actual' => 'integer',
            'paginas_leer' => 'integer',
            'paginas_documento' => 'integer',
            'campos_pedidos' => 'array',
            'campos_propuestos' => 'array',
            'campos_sugeridos' => 'array',
            'resultado' => 'array',
            'evaluado_en' => 'datetime',
        ];
    }
}
