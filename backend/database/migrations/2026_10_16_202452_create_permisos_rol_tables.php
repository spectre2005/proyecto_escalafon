<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permisos', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('descripcion');
        });
        Schema::create('permisos_rol', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('rol_id')->constrained('roles');
            $table->foreignId('permiso_id')->constrained('permisos');
            $table->boolean('permitido')->default(false);
            $table->unique(['rol_id', 'permiso_id']);
        });
        foreach (config('permisos.catalogo') as $slug => $descripcion) {
            DB::table('permisos')->insert(['slug' => $slug, 'descripcion' => $descripcion]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('permisos_rol');
        Schema::dropIfExists('permisos');
    }
};
