<?php

use App\Models\DocumentoUsuario;
use App\Models\TipoDocumento;
use Database\Seeders\Seccion08CatalogoSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new Seccion08CatalogoSeeder)->run();
    }

    public function down(): void
    {
        $ids = array_map(fn (int $i): string => Seccion08CatalogoSeeder::id($i), range(1, count(Seccion08CatalogoSeeder::TIPOS)));
        if (DocumentoUsuario::query()->whereIn('tipo_documento_id', $ids)->exists()) {
            throw new RuntimeException('La sección 08 ya tiene documentos; el catálogo no puede retirarse.');
        }

        TipoDocumento::query()->where('seccion_legajo_id', 8)->whereIn('id', $ids)->delete();
    }
};
