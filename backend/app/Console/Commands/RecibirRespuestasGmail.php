<?php

namespace App\Console\Commands;

use App\Models\Plataforma\Institucion;
use App\Support\Plataforma\ConexionInstitucion;
use App\Support\RecepcionGmail;
use Illuminate\Console\Command;
use Throwable;

class RecibirRespuestasGmail extends Command
{
    protected $signature = 'legajo:recibir-respuestas';

    protected $description = 'Recibe respuestas y PDF de Gmail sin enviar correos ni aprobar documentos';

    public function handle(): int
    {
        if (config('plataforma.modo') !== 'subdominio' || app()->bound(Institucion::class)) {
            $this->info('Respuestas recibidas: '.RecepcionGmail::sincronizar());

            return self::SUCCESS;
        }
        $resultado = self::SUCCESS;
        foreach (Institucion::query()->get() as $institucion) {
            if (! $institucion->operativa() || ! $institucion->aprovisionada()) {
                continue;
            }
            try {
                $total = ConexionInstitucion::usar($institucion, fn () => RecepcionGmail::sincronizar());
                $this->info($institucion->slug.': '.$total.' respuesta(s) recibida(s).');
            } catch (Throwable) {
                $this->error('No se pudo revisar Gmail de '.$institucion->slug.'. Consulta la conexión en Configuración.');
                $resultado = self::FAILURE;
            }
        }

        return $resultado;
    }
}
