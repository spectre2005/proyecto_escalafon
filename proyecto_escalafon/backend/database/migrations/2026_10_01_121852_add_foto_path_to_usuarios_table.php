<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->string('foto_path')->nullable();
        });

        $disk = Storage::disk('personal_photos');
        foreach (DB::table('usuarios')->select('id')->orderBy('id')->lazy() as $usuario) {
            if ($disk->exists($usuario->id)) {
                DB::table('usuarios')->where('id', $usuario->id)->update(['foto_path' => $usuario->id]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('foto_path');
        });
    }
};
