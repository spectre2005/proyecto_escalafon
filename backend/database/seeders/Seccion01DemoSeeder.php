<?php

namespace Database\Seeders;

use App\Models\DeclaracionVerificacion;
use App\Models\Familiar;
use App\Models\Legajo;
use App\Models\Usuario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Una persona ficticia para recorrer la sección 01 en local (99000101), con legajo abierto y sin documentos:
 * - Figura con derechohabientes y sin discapacidad ni obligación de DJBR (e y k salen atenuados).
 * - Familiares: concubina (exige g e i), un hijo menor (exige j), un hijo sin fecha de nacimiento (j por
 *   determinar) y una hija mayor de edad (j no se exige).
 * - Una declaración de antecedentes penales sin documento, para enlazarla al subir c).
 * Las edades son relativas a hoy. No lo llama DatabaseSeeder: php artisan db:seed --class=Seccion01DemoSeeder
 */
class Seccion01DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }
        $this->call(Seccion01CatalogoSeeder::class);
        if (Legajo::query()->where('numero_legajo', 'DEMO-S01-001')->exists()) {
            $this->command?->info('Seccion01DemoSeeder: los datos de demostración ya existen.');

            return;
        }

        DB::transaction(function (): void {
            $hoy = today();
            $persona = Usuario::factory()->create([
                'numero_doc_identidad' => '99000101', 'nombres' => 'Persona Demo Familia', 'apellido_paterno' => 'Ficticia', 'apellido_materno' => 'Sección Uno',
                'email_institucional' => 'demo99000101@example.test', 'tipo_personal' => 'administrativo', 'regimen_laboral' => 'plazo_determinado',
                'nombre_puesto' => 'Especialista administrativo', 'estado_civil' => 'conviviente',
                'obligado_djbr' => false, 'con_discapacidad' => false, 'tiene_derechohabientes' => true,
            ]);
            $registrador = Usuario::query()->whereHas('usuarioRolesPorUsuario.rol', fn ($query) => $query->where('codigo', 'ADMIN'))->value('id') ?? $persona->id;
            Legajo::query()->create(['usuario_id' => $persona->id, 'numero_legajo' => 'DEMO-S01-001', 'fecha_apertura' => $hoy->copy()->subDays(30)]);

            $familiar = fn (string $parentesco, string $nombre, ?int $anios, string $dni) => Familiar::query()->create([
                'usuario_id' => $persona->id, 'parentesco' => $parentesco, 'apellidos_nombres' => $nombre, 'tipo_doc_identidad' => 'dni', 'numero_doc_identidad' => $dni,
                'fecha_nacimiento' => $anios === null ? null : $hoy->copy()->subYears($anios)->subDays(10), 'es_derechohabiente' => $anios === null || $anios < 18, 'registrado_por' => $registrador,
            ]);
            $familiar('concubino', 'Concubina Demo Ficticia', 35, '99000111');
            $familiar('hijo', 'Hijo Demo Menor', 9, '99000112');
            $familiar('hijo', 'Hijo Demo Sin Fecha', null, '99000113');
            $familiar('hijo', 'Hija Demo Mayor', 20, '99000114');

            DeclaracionVerificacion::query()->create([
                'usuario_id' => $persona->id, 'tipo' => 'antecedente_penal', 'fecha' => $hoy->copy()->subDays(20), 'resultado' => 'sin_registros',
                'observacion' => 'Certificado en línea (demostración).', 'registrado_por' => $registrador,
            ]);
        });
    }
}
