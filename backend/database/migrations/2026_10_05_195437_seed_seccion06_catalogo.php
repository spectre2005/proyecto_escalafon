<?php

use App\Models\TipoDocumento;
use Database\Seeders\Seccion06CatalogoSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new Seccion06CatalogoSeeder)->run();
    }

    public function down(): void
    {
        $tipos = TipoDocumento::query()->where('seccion_legajo_id', 6)
            ->whereIn('id', array_map(fn (int $i): string => Seccion06CatalogoSeeder::id($i), range(1, 5)));
        if ((clone $tipos)->whereHas('documentosUsuario')->exists()) {
            throw new RuntimeException('La sección 06 tiene documentos; el catálogo no puede retirarse.');
        }
        $tipos->delete();
    }
};
