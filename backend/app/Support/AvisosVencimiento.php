<?php

namespace App\Support;

use App\Models\AvisoVencimiento;
use App\Models\CicloVigencia;
use App\Models\EnvioVencimiento;
use App\Models\NotificacionUsuario;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

final class AvisosVencimiento
{
    public static function crear(CicloVigencia $ciclo, ?string $actor = null): AvisoVencimiento
    {
        return DB::transaction(function () use ($ciclo, $actor): AvisoVencimiento {
            $ciclo = VigenciaLegajo::bloquearActual($ciclo);
            $clave = $actor ? 'manual:'.$ciclo->id.':'.VigenciaLegajo::hoy()->toDateString() : 'automatico:'.$ciclo->id;
            $existente = AvisoVencimiento::query()->where('clave', $clave)->first();
            if ($existente) {
                return $existente;
            }
            VigenciaLegajo::asegurarRequisitos($ciclo);
            $aviso = AvisoVencimiento::query()->create([
                'ciclo_id' => $ciclo->id, 'clave' => $clave, 'fecha_programada' => $actor ? VigenciaLegajo::hoy()->toDateString() : $ciclo->fecha_aviso->toDateString(),
                'origen' => $actor ? 'manual' : ($ciclo->fecha_aviso->toDateString() < VigenciaLegajo::hoy()->toDateString() ? 'recuperacion_tardia' : 'automatico'),
                'solicitado_por' => $actor,
            ]);
            $usuario = $ciclo->legajo->usuario;
            $mensaje = trim($usuario->nombres.' '.$usuario->apellido_paterno).' · '.$ciclo->categoria.' · vence '.$ciclo->fecha_vencimiento->format('d/m/Y').'. Consulta los requisitos de renovación.';
            Usuario::query()->where('activo', true)->chunkById(100, function ($usuarios) use ($aviso, $mensaje, $usuario): void {
                foreach ($usuarios as $destinatario) {
                    if ($destinatario->id === $usuario->id || $destinatario->tienePermiso('vencimientos.gestionar')) {
                        NotificacionUsuario::query()->firstOrCreate(['aviso_vencimiento_id' => $aviso->id, 'usuario_id' => $destinatario->id], [
                            'tipo' => 'vencimiento_legajo', 'titulo' => 'Vencimiento del legajo docente', 'mensaje' => $mensaje,
                        ]);
                    }
                }
            });
            foreach (array_unique(array_filter([$usuario->id, $ciclo->jefe_inmediato_id])) as $destinatarioId) {
                $destinatario = Usuario::query()->find($destinatarioId);
                EnvioVencimiento::query()->create([
                    'aviso_id' => $aviso->id, 'destinatario_id' => $destinatarioId, 'destino' => $destinatario?->email_institucional,
                    'estado' => $destinatario?->activo ? 'pendiente' : 'fallido',
                    'error' => $destinatario?->activo ? null : 'La cuenta destinataria está inactiva.',
                ]);
            }
            VigenciaLegajo::auditar($ciclo, 'vigencia.notificar', $actor, ['aviso_id' => $aviso->id, 'origen' => $aviso->origen]);

            return $aviso;
        });
    }

    public static function reintentar(CicloVigencia $ciclo, Usuario $actor): AvisoVencimiento
    {
        return DB::transaction(function () use ($ciclo, $actor): AvisoVencimiento {
            $ciclo = VigenciaLegajo::bloquearActual($ciclo);
            $aviso = $ciclo->avisos()->latest()->first();
            if (! $aviso) {
                return self::crear($ciclo, $actor->id);
            }
            $envios = $aviso->envios()->lockForUpdate()->get();
            abort_if($envios->contains('estado', 'procesando') || $envios->contains('estado', 'pendiente'), 409, 'Hay un aviso pendiente o en proceso. Espera su resultado.');
            abort_if($envios->contains('estado', 'incierto'), 409, 'Un envío tiene resultado incierto. Concílialo con el proveedor antes de reenviar.');
            $fallidos = $envios->whereIn('estado', ['fallido', 'simulado']);
            abort_if($fallidos->isEmpty(), 409, 'El aviso ya fue enviado.');
            foreach ($fallidos as $envio) {
                $envio->update(['estado' => 'pendiente', 'intentos' => 0, 'proximo_intento_en' => null, 'error' => null]);
            }
            VigenciaLegajo::auditar($ciclo, 'vigencia.reintentar_aviso', $actor->id, ['aviso_id' => $aviso->id]);

            return $aviso;
        });
    }

    public static function procesar(): array
    {
        $resultado = ['enviados' => 0, 'simulados' => 0, 'fallidos' => 0, 'cancelados' => 0];
        EnvioVencimiento::query()->where('estado', 'procesando')->where('updated_at', '<', now()->subMinutes(10))
            ->update(['estado' => 'incierto', 'error' => 'El proceso se interrumpió. Conciliar la entrega antes de reenviar.']);
        EnvioVencimiento::query()->where('estado', 'pendiente')->where(fn ($q) => $q->whereNull('proximo_intento_en')->orWhere('proximo_intento_en', '<=', now()))
            ->chunkById(100, function ($envios) use (&$resultado): void {
                foreach ($envios as $envio) {
                    $reservado = EnvioVencimiento::query()->whereKey($envio->id)->where('estado', 'pendiente')->update(['estado' => 'procesando', 'intentos' => DB::raw('intentos + 1'), 'updated_at' => now()]);
                    if (! $reservado) {
                        continue;
                    }
                    $envio->refresh()->load(['aviso.ciclo.legajo.usuario', 'destinatario']);
                    $ciclo = $envio->aviso->ciclo;
                    if (! $ciclo->actual || $ciclo->legajo->estado_legajo !== 'activo') {
                        $envio->update(['estado' => 'cancelado']);
                        $resultado['cancelados']++;

                        continue;
                    }
                    $destino = $envio->destinatario?->email_institucional;
                    if (! $envio->destinatario?->activo || Validator::make(['email' => $destino], ['email' => 'required|email'])->fails()) {
                        $envio->update(['estado' => 'fallido', 'error' => 'La cuenta está inactiva o no tiene correo institucional válido.']);
                        $resultado['fallidos']++;

                        continue;
                    }
                    $envio->update(['destino' => $destino]);
                    $usuario = $ciclo->legajo->usuario;
                    $texto = 'Estimado/a '.trim($usuario->nombres.' '.$usuario->apellido_paterno).', su legajo como docente '.$ciclo->categoria.' vence el '.$ciclo->fecha_vencimiento->format('d/m/Y').'.';
                    if ($envio->destinatario_id === $usuario->id) {
                        $tipos = $ciclo->requerimientos()->with('tipoDocumento')->get()->map(fn ($r) => $r->tipoDocumento?->nombre)->filter()->implode(', ');
                        $texto .= $tipos ? ' Por favor, presente los documentos pendientes: '.$tipos.'.' : ' Consulte a ORH para completar su revisión documental.';
                    } else {
                        $texto = 'El legajo de '.trim($usuario->nombres.' '.$usuario->apellido_paterno).' ('.$ciclo->categoria.') vence el '.$ciclo->fecha_vencimiento->format('d/m/Y').'. Consulte el estado del trámite con ORH.';
                    }
                    $texto .= "\nAcceda al sistema: ".rtrim((string) config('vencimientos.frontend_url'), '/').'/vencimientos';
                    try {
                        Mail::raw($texto, function ($mensaje) use ($destino, $envio): void {
                            $mensaje->to($destino)->subject('Vencimiento del legajo docente');
                            $mensaje->getSymfonyMessage()->getHeaders()->addTextHeader('X-Vencimiento-Envio', $envio->id);
                        });
                        $simulado = in_array(config('mail.default'), ['log', 'array'], true);
                        $envio->update(['estado' => $simulado ? 'simulado' : 'enviado', 'enviado_en' => $simulado ? null : now(), 'error' => $simulado ? 'Correo de prueba: configura SMTP para entrega real.' : null]);
                        $resultado[$simulado ? 'simulados' : 'enviados']++;
                    } catch (\Throwable $exception) {
                        report($exception);
                        $incierto = str_contains(strtolower($exception->getMessage()), 'timeout') || str_contains(strtolower($exception->getMessage()), 'timed out');
                        $estado = $incierto ? 'incierto' : ($envio->intentos >= 5 ? 'fallido' : 'pendiente');
                        $envio->update(['estado' => $estado, 'proximo_intento_en' => now()->addMinutes(5 * $envio->intentos), 'error' => $incierto ? 'Tiempo de espera agotado; verificar entrega con el proveedor.' : 'No se pudo entregar el correo. Revisa la configuración del servicio.']);
                        $resultado['fallidos']++;
                    }
                }
            });

        return $resultado;
    }
}
