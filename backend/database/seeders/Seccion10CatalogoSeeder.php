<?php

namespace Database\Seeders;

use App\Models\SeccionLegajo;
use App\Models\SubseccionLegajo;
use App\Models\TipoDocumento;
use App\Support\ResumenSeccion10;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Seccion10CatalogoSeeder extends Seeder
{
    public static function id(int $orden): string
    {
        return sprintf('00000010-0000-4000-8000-%012d', $orden);
    }

    public function run(): void
    {
        DB::transaction(function (): void {
            SeccionLegajo::query()->firstOrCreate(['id' => 10], [
                'nombre' => 'SST y bienestar', 'es_nucleo_comun' => true, 'orden' => 10,
            ]);
            $subsecciones = [];
            foreach (['10.1' => 'Seguridad y Salud en el Trabajo (SST)', '10.2' => 'Bienestar Social'] as $codigo => $nombre) {
                $subsecciones[$codigo] = SubseccionLegajo::query()->firstOrCreate(['seccion_legajo_id' => 10, 'codigo' => $codigo],
                    ['id' => $this->idDisponible(SubseccionLegajo::class, sprintf('00000010-0000-4000-9000-%012d', count($subsecciones) + 1)), 'nombre' => $nombre, 'orden' => count($subsecciones) + 1]);
            }
            $orden = 0;
            foreach (ResumenSeccion10::TIPOS as $codigo => [$subseccion, $nombre]) {
                $orden++;
                $tipo = TipoDocumento::query()->firstOrCreate(['seccion_legajo_id' => 10, 'codigo' => $codigo], [
                    'id' => $this->idDisponible(TipoDocumento::class, self::id($orden)), 'subseccion_legajo_id' => $subsecciones[$subseccion]->id,
                    'nombre' => $nombre, 'orden' => $orden, 'permite_multiples' => true, 'obligatorio' => false,
                    'es_dato_sensible' => true, 'es_dato_salud' => in_array($codigo, ['S10_ACCIDENTES_INCIDENTES', 'S10_EXAMEN_OCUPACIONAL', 'S10_DESCANSO_MEDICO'], true),
                    'es_hook_universidad' => false, 'activo' => true,
                    'descripcion' => $codigo === 'S10_EXAMEN_OCUPACIONAL' ? 'Solo evidencia administrativa de realización, sin diagnósticos ni resultados clínicos.' : null,
                ]);
                if ($tipo->subseccion_legajo_id !== $subsecciones[$subseccion]->id) {
                    $tipo->update(['subseccion_legajo_id' => $subsecciones[$subseccion]->id]);
                }
            }
            TipoDocumento::query()->where('seccion_legajo_id', 10)->where('codigo', 'S10_OTROS')->update(['activo' => false]);
        });
    }

    /** @param class-string<Model> $modelo */
    private function idDisponible(string $modelo, string $preferido): string
    {
        return $modelo::query()->whereKey($preferido)->exists() ? (string) Str::uuid() : $preferido;
    }
}
