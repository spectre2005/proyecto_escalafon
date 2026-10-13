<?php

namespace App\Console\Commands;

use App\Models\Plataforma\Institucion;
use App\Support\Plataforma\Aprovisionamiento;
use App\Support\Plataforma\AprovisionamientoRechazado;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Aprovisiona una institución registrada (Aprovisionamiento): crea y migra su base, carga los catálogos, crea su primer
 * administrador y su carpeta. Lo mismo que el botón del panel. La contraseña temporal se muestra una sola vez.
 */
class AprovisionarInstitucion extends Command
{
    protected $signature = 'plataforma:aprovisionar {slug : Identificador de la institución}
        {--nombres= : Nombres del primer administrador} {--apellido= : Apellido paterno del primer administrador} {--email= : Correo de acceso del primer administrador}';

    protected $description = 'Crea la base, el esquema, los catálogos, el primer administrador y la carpeta de una institución registrada';

    public function handle(): int
    {
        $institucion = Institucion::query()->where('slug', $this->argument('slug'))->first();
        if (! $institucion) {
            $this->error('No existe una institución con ese identificador.');

            return self::FAILURE;
        }
        $administrador = [
            'nombres' => trim((string) ($this->option('nombres') ?: $this->ask('Nombres del primer administrador'))),
            'apellido_paterno' => trim((string) ($this->option('apellido') ?: $this->ask('Apellido paterno'))),
            'email' => mb_strtolower(trim((string) ($this->option('email') ?: $this->ask('Correo de acceso')))),
        ];
        $validacion = Validator::make($administrador, [
            'nombres' => 'required|string|min:2|max:100', 'apellido_paterno' => 'required|string|min:2|max:100', 'email' => 'required|email|max:255',
        ]);
        if ($validacion->fails()) {
            foreach ($validacion->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->info("Aprovisionando {$institucion->nombre} (base {$institucion->base_datos})…");
        try {
            $clave = Aprovisionamiento::ejecutar($institucion->id, $administrador, null, null);
        } catch (AprovisionamientoRechazado $rechazo) {
            $this->error($rechazo->getMessage());

            return self::FAILURE;
        } catch (Throwable $error) {
            $this->error('No se pudo aprovisionar. Se retiró lo que se había creado. '.$error->getMessage());

            return self::FAILURE;
        }
        $this->info('Institución aprovisionada. Ya se puede activar desde el panel.');
        $this->line("Primer administrador: {$administrador['email']}");
        $this->warn("Contraseña temporal (se muestra solo esta vez): {$clave}");

        return self::SUCCESS;
    }
}
