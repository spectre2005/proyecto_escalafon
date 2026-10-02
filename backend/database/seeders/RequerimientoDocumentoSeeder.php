<?php

namespace Database\Seeders;

use App\Models\RequerimientoDocumento;
use App\Models\Usuario;
use Illuminate\Database\Seeder;

class RequerimientoDocumentoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $this->call(UsuarioSeeder::class);

        $demoUsers = Usuario::query()
            ->whereIn('email_institucional', array_map(
                fn (int $number): string => sprintf('demo%02d@example.test', $number),
                range(1, 10),
            ))
            ->get()
            ->keyBy('email_institucional');

        $requester = $demoUsers->get('demo10@example.test');
        if ($requester === null || $demoUsers->count() !== 10) {
            return;
        }

        foreach (range(1, 10) as $number) {
            $person = $demoUsers->get(sprintf('demo%02d@example.test', $number));
            if ($person === null) {
                continue;
            }

            RequerimientoDocumento::query()->updateOrCreate(
                [
                    'usuario_id' => $person->id,
                    'descripcion' => sprintf('Ejemplo ficticio de requerimiento %02d', $number),
                ],
                [
                    'tipo_documento_id' => null,
                    'origen' => 'legajo',
                    'fecha_requerimiento' => sprintf('2026-09-%02d', $number),
                    'plazo_dias_habiles' => 5,
                    'fecha_limite' => sprintf('2026-09-%02d', 20 + $number),
                    'prorroga_hasta' => null,
                    'estado' => 'abierto',
                    'documento_id' => null,
                    'solicitado_por' => $requester->id,
                    'cerrado_en' => null,
                ],
            );
        }
    }
}
