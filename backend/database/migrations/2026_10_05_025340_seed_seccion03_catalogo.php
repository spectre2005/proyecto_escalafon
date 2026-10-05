<?php

use App\Models\TipoDocumento;
use Database\Seeders\Seccion03CatalogoSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new Seccion03CatalogoSeeder)->run();
    }

    public function down(): void
    {
        $ids = array_map(fn (int $orden): string => Seccion03CatalogoSeeder::id($orden), range(1, 8));
        $tipos = TipoDocumento::query()->whereIn('id', $ids);
        if ((clone $tipos)->whereHas('documentosUsuario')->exists()) {
            throw new RuntimeException('El catálogo de la sección 03 tiene documentos. No se puede revertir sin perder su referencia.');
        }
        $tipos->delete();
    }
};
