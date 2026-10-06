<?php

use App\Models\ParametroSistema;
use App\Models\TipoDocumento;
use Database\Seeders\Seccion04CatalogoSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const PARAMETROS = [
        'exp_incluir_pendientes' => ['0', 'boolean', 'Incluir periodos con documentos en revisión.'],
        'exp_dias_por_mes' => ['30', 'integer', 'Días por mes para desglosar tiempo de experiencia.'],
        'exp_dias_por_anio' => ['360', 'integer', 'Días por año para desglosar experiencia y calcular años decimales.'],
        'exp_hueco_largo_dias' => ['90', 'integer', 'Avisar cuando un hueco entre periodos supera este número de días.'],
    ];

    public function up(): void
    {
        (new Seccion04CatalogoSeeder)->run();
        foreach (self::PARAMETROS as $indice => $datos) {
            if (ParametroSistema::query()->where('clave', $indice)->exists()) {
                continue;
            }
            $p = new ParametroSistema(['clave' => $indice, 'valor' => $datos[0], 'tipo_dato' => $datos[1], 'descripcion' => $datos[2]]);
            $p->id = '00000004-0000-4000-9000-'.substr(hash('sha256', $indice), 0, 12);
            $p->save();
        }
    }

    public function down(): void
    {
        $tipos = TipoDocumento::query()->whereIn('id', array_map(fn (int $i): string => Seccion04CatalogoSeeder::id($i), range(1, 3)));
        if ((clone $tipos)->whereHas('documentosUsuario')->exists()) {
            throw new RuntimeException('La sección 04 tiene documentos; el catálogo no puede retirarse.');
        }
        $tipos->delete();
        foreach (self::PARAMETROS as $clave => $datos) {
            ParametroSistema::query()->whereKey('00000004-0000-4000-9000-'.substr(hash('sha256', $clave), 0, 12))->where('valor', $datos[0])->delete();
        }
    }
};
