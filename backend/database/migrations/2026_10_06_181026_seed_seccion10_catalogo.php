<?php

use App\Models\SubseccionLegajo;
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
        $tipos = TipoDocumento::query()->where('seccion_legajo_id', 10)->whereIn('id', array_map(Seccion10CatalogoSeeder::id(...), range(1, 10)));
        if ((clone $tipos)->whereHas('documentosUsuario')->exists()) {
            throw new RuntimeException('La Sección 10 tiene documentos o trámites. No se puede retirar su catálogo.');
        }
        $tipos->delete();
        SubseccionLegajo::query()->where('seccion_legajo_id', 10)->whereIn('codigo', ['10.1', '10.2'])->whereDoesntHave('tiposDocumento')->delete();
    }
};
