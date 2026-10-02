<?php

namespace Database\Seeders;

use App\Models\EvaluacionDesempeno;
use App\Models\Usuario;
use Illuminate\Database\Seeder;

class EvaluacionDesempenoSeeder extends Seeder
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

        $registrar = $demoUsers->get('demo10@example.test');
        if ($registrar === null || $demoUsers->count() !== 10) {
            return;
        }

        foreach (range(1, 10) as $number) {
            $person = $demoUsers->get(sprintf('demo%02d@example.test', $number));
            if ($person === null) {
                continue;
            }

            $cycle = 2021 + (($number - 1) % 5);
            EvaluacionDesempeno::query()->updateOrCreate(
                ['usuario_id' => $person->id, 'ciclo_anio' => $cycle],
                [
                    'fecha_evaluacion' => sprintf('%d-08-15', $cycle),
                    'calificacion' => sprintf('Ejemplo ficticio %02d', $number),
                    'puntaje' => 70 + $number,
                    'plan_mejora' => $number % 2 === 0,
                    'evaluador_id' => null,
                    'registrado_por' => $registrar->id,
                    'anulado' => false,
                    'motivo_anulacion' => null,
                ],
            );
        }
    }
}
