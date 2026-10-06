<?php

use App\Models\TipoDocumento;
use Database\Seeders\Seccion10CatalogoSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        (new Seccion10CatalogoSeeder)->run();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        TipoDocumento::query()->where('seccion_legajo_id', 10)->where('codigo', 'S10_OTROS')->update(['activo' => true]);
    }
};
