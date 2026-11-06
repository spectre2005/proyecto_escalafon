<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\V1\Legajos\Seccion04Controller;
use App\Http\Controllers\Api\V1\Legajos\Seccion06Controller;
use App\Http\Controllers\Api\V1\Legajos\Seccion08Controller;
use App\Http\Controllers\Api\V1\Legajos\Seccion10Controller;
use App\Models;
use App\Models\Plataforma\Institucion;
use App\Support\AplicacionDocumental;
use App\Support\Documentos\AuditoriaDocumento;
use App\Support\Documentos\CargaDocumento;
use App\Support\Documentos\Folios;
use App\Support\Documentos\FormulariosDatos;
use App\Support\MensajeRequerimiento;
use App\Support\Plataforma\ConexionInstitucion;
use App\Support\ResumenSeccion10;
use App\Support\Seccion05;
use App\Support\Seccion07;
use App\Support\Seccion09;
use App\Support\Seccion11;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

/** Ejemplos aislados y repetibles; no conecta proveedores ni envía correos. */
class PoblarSistemaDemo extends Command
{
    protected $signature = 'demo:poblar-sistema {--institucion= : Institución de destino} {--aplicar : Carga los ejemplos; sin esta opción solo muestra el plan}';

    protected $description = 'Crea ocho personas ficticias, legajos, PDF y correos simulados (solo local)';

    private Models\Usuario $admin;

    private string $temporal;

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Solo para demostración en local (APP_ENV=local).');

            return self::FAILURE;
        }
        if (config('plataforma.modo') === 'subdominio' && ! app()->bound(Institucion::class)) {
            $institucion = Institucion::query()->where('slug', (string) $this->option('institucion'))->whereNotNull('aprovisionada_en')->first();
            if (! $institucion) {
                $this->error('Indica una institución aprovisionada con --institucion=<identificador>.');

                return self::FAILURE;
            }

            return ConexionInstitucion::usar($institucion, fn (): int => $this->poblar());
        }

        return $this->poblar();
    }

    private function poblar(): int
    {
        $this->info('Ocho personas ficticias: DNI 99880001–99880008, integral.*@example.test. Documentos y correos SIMULADOS.');
        if (! $this->option('aplicar')) {
            $this->line('Sin cambios. Añade --aplicar para cargar los ejemplos.');

            return self::SUCCESS;
        }
        $this->admin = Models\Usuario::query()->where('activo', true)->get()->first(fn ($u): bool => $u->can('sistema.administrar'))
            ?? throw new \RuntimeException('No hay una cuenta administradora activa en esta institución.');
        $anterior = Auth::user();
        $request = app('request');
        $this->temporal = tempnam(sys_get_temp_dir(), 'demo-integral-');
        Auth::setUser($this->admin);
        try {
            foreach ([12 => 'Otros documentos ficticios', 13 => 'Antecedentes de vínculo anterior ficticio'] as $seccion => $nombre) {
                Models\TipoDocumento::query()->firstOrCreate(['codigo' => 'DEMO_INTEGRAL_S'.$seccion],
                    ['seccion_legajo_id' => $seccion, 'nombre' => $nombre, 'activo' => true, 'permite_multiples' => true, 'es_universal' => true,
                        'aplica_carrera' => true, 'aplica_plazo_determinado' => true, 'aplica_designado' => true, 'aplica_tipo_personal' => 'ambos', 'obligatorio' => false]);
            }
            foreach (range(1, 8) as $n) {
                $this->crearPersona($n);
            }
            if ($this->call('demo:completar-legajos', ['--solo-demo-integral' => true, '--dejar' => 0, '--aplicar' => true]) !== self::SUCCESS) {
                return self::FAILURE;
            }
            foreach (Models\Usuario::query()->whereBetween('numero_doc_identidad', ['99880001', '99880008'])->where('email_institucional', 'like', 'integral.%@example.test')->orderBy('numero_doc_identidad')->get() as $p) {
                $this->line($p->numero_doc_identidad.' · '.$p->nombres);
                $this->completar($p);
                $this->correos($p);
                $this->custodia($p);
                $this->antecedentes($p);
            }
            $this->paquete();
            $this->info('Listo. PDF privados y correos .eml en demo-integral/correos del disco de documentos. Ningún correo se envió.');
        } finally {
            @unlink($this->temporal);
            app()->instance('request', $request);
            $anterior ? Auth::setUser($anterior) : Auth::forgetUser();
        }

        return self::SUCCESS;
    }

    private function crearPersona(int $n): void
    {
        $dni = sprintf('998800%02d', $n);
        $email = sprintf('integral.%02d@example.test', $n);
        $existente = Models\Usuario::query()->where('numero_doc_identidad', $dni)->first();
        if ($existente && $existente->email_institucional !== $email) {
            throw new \RuntimeException('El DNI '.$dni.' ya pertenece a otra persona. No se modifica.');
        }
        DB::transaction(function () use ($n, $dni, $email): void {
            $docente = $n % 2 === 1;
            $tipo = $docente ? 'docente' : 'administrativo';
            $unidad = Models\UnidadOrganica::query()->firstOrCreate(['codigo' => $docente ? 'DEMO-INT-FAC' : 'DEMO-INT-ORH'],
                ['nombre' => $docente ? 'Facultad Ficticia de Demostración' : 'Oficina Ficticia de Personal', 'tipo' => $docente ? 'facultad' : 'oficina', 'activo' => true]);
            $ley = Models\RegimenNormativo::query()->where('codigo', $docente ? '30220' : ($n === 6 ? '1057' : '276'))->firstOrFail();
            $p = Models\Usuario::query()->firstOrCreate(['numero_doc_identidad' => $dni], [
                'tipo_doc_identidad' => 'dni', 'nombres' => ['Ana Lucía', 'Carlos Alberto', 'María Elena', 'José Antonio', 'Rosa Isabel', 'Luis Fernando', 'Patricia Inés', 'Miguel Ángel'][$n - 1].' (DEMO)',
                'apellido_paterno' => 'Ficticio', 'apellido_materno' => 'Integral', 'email_institucional' => $email,
                'password' => Str::random(48), 'activo' => false, 'tipo_personal' => $tipo, 'regimen_laboral' => $ley->codigo,
                'regimen_normativo_id' => $ley->id, 'unidad_organica_id' => $unidad->id,
                'nombre_puesto' => $docente ? 'Docente de demostración' : 'Especialista de demostración',
                'fecha_nacimiento' => today()->subYears(30 + $n), 'nacionalidad' => 'PERUANA', 'estado_civil' => 'casado',
                'domicilio' => 'Avenida Ficticia '.$n.'00, domicilio de demostración', 'telefono' => '90000000'.$n,
                'obligado_djbr' => true, 'exige_colegiatura' => true, 'exige_estudios_basicos' => true,
                'tiene_periodo_prueba' => true, 'tiene_derechohabientes' => true, 'con_discapacidad' => $n === 5,
            ]);
            $legajo = Models\Legajo::query()->firstOrCreate(['usuario_id' => $p->id, 'numero_legajo' => sprintf('DEMO-INT-%03d', $n)], ['fecha_apertura' => today()->subYears(3), 'estado_legajo' => 'activo']);
            foreach (range(1, 13) as $seccion) {
                Models\LegajoSeccion::query()->firstOrCreate(['legajo_id' => $legajo->id, 'seccion_legajo_id' => $seccion], ['ultimo_folio' => 0]);
            }
            $v = Models\VinculoLaboral::query()->firstOrCreate(['usuario_id' => $p->id, 'numero_documento_vinculo' => 'DEMO-INT-VIN-'.$dni], [
                'tipo_personal' => $tipo, 'regimen_laboral' => $n === 6 ? 'plazo_determinado' : 'carrera', 'regimen_normativo_id' => $ley->id,
                'unidad_organica_id' => $unidad->id, 'nombre_puesto' => $p->nombre_puesto, 'fecha_inicio' => today()->subYears(3),
                'fecha_fin' => $n === 6 ? today()->addDays(15) : null, 'fecha_cese' => $n === 8 ? today()->subDays(60) : null,
            ]);
            if ($docente) {
                Models\PerfilDocente::query()->firstOrCreate(['vinculo_laboral_id' => $v->id], ['condicion_docente' => 'ordinario', 'categoria' => 'asociado', 'dedicacion' => 'tiempo_completo']);
            } else {
                Models\PerfilAdministrativo::query()->firstOrCreate(['vinculo_laboral_id' => $v->id], ['grupo_ocupacional' => 'profesional', 'nivel_carrera' => 'SP-ES']);
            }
        });
    }

    private function completar(Models\Usuario $p): void
    {
        $legajo = $p->legajos()->where('estado_legajo', 'activo')->firstOrFail();
        $aplicacion = AplicacionDocumental::para($p);
        foreach (Models\TipoDocumento::query()->where('activo', true)->whereBetween('seccion_legajo_id', [5, 13])->orderBy('seccion_legajo_id')->orderBy('codigo')->get() as $tipo) {
            if ($tipo->codigo === 'REC_LABOR_DOCENTE' && $p->tipo_personal !== 'docente') {
                continue;
            }
            if ($aplicacion->noAplica($tipo) !== null || $legajo->documentosUsuario()->where('tipo_documento_id', $tipo->id)->exists()) {
                continue;
            }
            if ((int) $tipo->seccion_legajo_id === 11 && ($p->numero_doc_identidad !== '99880008' || (Seccion11::principal($tipo->codigo) && $tipo->codigo !== 'S11_X_ACEPTACION_RENUNCIA'))) {
                continue;
            }
            if ($tipo->formulario_datos === 'progresiones_carrera' && $this->vinculo($p)->regimen_laboral !== 'carrera') {
                continue;
            }
            $this->documento($p, $legajo, $tipo);
        }
    }

    private function documento(Models\Usuario $p, Models\Legajo $legajo, Models\TipoDocumento $tipo): void
    {
        $seccion = (int) $tipo->seccion_legajo_id;
        if ($tipo->codigo === 'SAN_RESOLUCION_RECURSO' && ! Models\Sancion::query()->where('usuario_id', $p->id)->exists()) {
            $this->documento($p, $legajo, Models\TipoDocumento::query()->where('codigo', 'SAN_RESOLUCION_SANCION')->firstOrFail());
        }
        $folio = Folios::ultimo($legajo, $seccion) + 1;
        $meta = ['nombre' => mb_substr($tipo->nombre.' (DEMOSTRACIÓN)', 0, 200), 'emisor' => 'OFICINA FICTICIA DE DEMOSTRACIÓN',
            'fecha_emision' => today()->subDays(180)->toDateString(), 'modalidad_presentacion' => 'copia_simple', 'folio_inicio' => $folio, 'folio_fin' => $folio];
        if ($tipo->requiere_num_resolucion || $tipo->codigo === 'COMP_RECONOCIMIENTO_TIEMPO') {
            $meta['num_resolucion'] = 'DEMO-INT-'.substr($p->numero_doc_identidad, -2).'-'.substr(md5($tipo->codigo), 0, 8);
        }
        $pdf = $this->pdf($p, $tipo->nombre);
        if (in_array($seccion, [6, 8, 10], true)) {
            $datos = [...$meta, 'tipo_documento_id' => $tipo->id];
            if ($tipo->codigo === 'COMP_RECONOCIMIENTO_TIEMPO') {
                $datos += ['anios' => 2, 'meses' => 6, 'dias' => 15];
            } elseif ($tipo->codigo === 'COMP_PAGO_COMPENSACION') {
                $datos += ['tipo' => 'economica', 'concepto' => 'Asignación ficticia de demostración', 'fecha' => $meta['fecha_emision']];
            } elseif (in_array($tipo->codigo, ['REC_CARTA_FELICITACION', 'REC_LABOR_DOCENTE', 'REC_TIEMPO_SERVICIOS'], true)) {
                $datos += ['tipo' => match ($tipo->codigo) {
                    'REC_TIEMPO_SERVICIOS' => 'tiempo_servicios', 'REC_LABOR_DOCENTE' => 'labor_docente', default => 'carta'
                }, 'motivo' => 'Desempeño destacado ficticio para demostración'];
                if ($tipo->codigo === 'REC_TIEMPO_SERVICIOS') {
                    $datos += ['anios' => 2, 'meses' => 6, 'dias' => 15];
                }
            } elseif ($tipo->codigo === 'SAN_RESOLUCION_SANCION') {
                $datos += ['tipo' => 'AMONESTACION_ESCRITA', 'registrada_en_rnssc' => false];
            } elseif ($tipo->codigo === 'SAN_RESOLUCION_RECURSO') {
                $datos += ['sancion_id' => Models\Sancion::query()->where('usuario_id', $p->id)->value('id'), 'resultado' => 'INFUNDADO'];
            }
            if ($seccion === 10) {
                unset($datos['folio_inicio'], $datos['folio_fin'], $datos['num_resolucion']);
                $datos['numero_folios'] = 1;
                [, , $periodo, $vigencia, $eps] = ResumenSeccion10::TIPOS[$tipo->codigo];
                if ($periodo) {
                    $datos += ['fecha_inicio' => today()->subDays(90)->toDateString(), 'fecha_fin' => $eps ? today()->addYear()->toDateString() : today()->subDays(86)->toDateString()];
                }
                if ($vigencia) {
                    $datos['fecha_vencimiento'] = today()->addDays($p->numero_doc_identidad === '99880001' ? -5 : 15)->toDateString();
                }
                if ($eps) {
                    $datos['entidad'] = 'EPS FICTICIA DE DEMOSTRACIÓN';
                }
            }
            $controlador = match ($seccion) {
                6 => Seccion06Controller::class, 8 => Seccion08Controller::class, 10 => Seccion10Controller::class
            };
            $respuesta = app($controlador)->store($this->peticion($datos, $pdf), $p->id);
            if ($respuesta->getStatusCode() !== 201) {
                throw new \RuntimeException('No se creó '.$tipo->codigo.': '.$respuesta->getContent());
            }

            return;
        }
        $request = $this->peticion([], $pdf);
        CargaDocumento::registrar($legajo, $tipo, $pdf, [...$meta, 'fecha_incorporacion' => today()->toDateString(),
            'titular_nombre' => $p->nombres.' '.$p->apellido_paterno.' '.$p->apellido_materno, 'titular_documento' => $p->numero_doc_identidad, 'relacion_titular' => 'servidor'],
            $this->admin->id, '127.0.0.1', completar: function (Models\DocumentoUsuario $doc) use ($p, $tipo, $request): void {
                $this->registro($p, $tipo, $doc);
                AuditoriaDocumento::registrar($doc, $request, 'documento.creado');
            });
    }

    private function registro(Models\Usuario $p, Models\TipoDocumento $tipo, Models\DocumentoUsuario $doc): void
    {
        $base = ['usuario_id' => $p->id, 'registrado_por' => $this->admin->id];
        $fecha = today()->subDays(120)->toDateString();
        $v = $this->vinculo($p);
        $registro = match ($tipo->formulario_datos) {
            'licencias_permisos' => Models\LicenciaPermiso::query()->create([...$base, 'tipo' => Seccion05::registros($tipo->codigo)[0],
                'forma_permiso' => Seccion05::registros($tipo->codigo)[0] === 'permiso' ? 'papeleta' : null, 'con_goce' => $tipo->codigo !== 'S05_E_LICENCIA_OTORGADA',
                'fecha_inicio' => $fecha, 'fecha_fin' => today()->subDays(116), 'dias' => 5, 'numero_documento' => 'DEMO-INT-LIC']),
            'goces_vacacionales' => Models\GoceVacacional::query()->create([...$base, 'periodo_vacacional_id' => Models\PeriodoVacacional::query()->firstOrCreate(
                ['usuario_id' => $p->id, 'vinculo_laboral_id' => $v->id, 'periodo_anio' => today()->year - 1], ['dias_derecho' => 30, 'registrado_por' => $this->admin->id])->id,
                'tipo' => Seccion05::registros($tipo->codigo)[0], 'fecha_inicio' => $fecha, 'fecha_fin' => today()->subDays(116), 'dias' => 5]),
            'evaluaciones_desempeno' => Models\EvaluacionDesempeno::query()->create([...$base, 'ciclo_anio' => today()->year - 1, 'fecha_evaluacion' => $fecha,
                'calificacion' => $p->numero_doc_identidad === '99880002' ? 'Sujeto a observación' : 'Buen rendimiento', 'plan_mejora' => $p->numero_doc_identidad === '99880002', 'evaluador_id' => $this->admin->id]),
            'progresiones_carrera' => $p->tipo_personal === 'docente'
                ? Models\RatificacionDocente::query()->create([...$base, 'perfil_docente_id' => Models\PerfilDocente::query()->where('vinculo_laboral_id', $v->id)->value('id'),
                    'tipo' => 'ratificacion', 'categoria_anterior' => 'auxiliar', 'categoria_nueva' => 'asociado', 'numero_resolucion' => $doc->num_resolucion,
                    'fecha_resolucion' => $fecha, 'fecha_inicio_periodo' => $fecha, 'fecha_vencimiento_periodo' => today()->addDays(30), 'resultado' => 'ratificado'])
                : Models\ProgresionCarrera::query()->create([...$base, 'vinculo_laboral_id' => $v->id, 'nivel_anterior' => 'SP-AP', 'nivel_nuevo' => 'SP-ES', 'fecha_efectiva' => $fecha, 'numero_resolucion' => $doc->num_resolucion]),
            'desplazamientos' => Models\Desplazamiento::query()->create([...$base, 'tipo' => Seccion07::desplazamientos($tipo->codigo)[0], 'fecha_inicio' => $fecha,
                'fecha_fin' => today()->subDays(100), 'puesto_destino' => 'Coordinación ficticia', 'numero_documento' => 'DEMO-INT-DES', 'unidad_origen_id' => $p->unidad_organica_id, 'unidad_destino_id' => $p->unidad_organica_id]),
            'controversias' => Models\Controversia::query()->create([...$base, 'tipo' => Seccion09::registros($tipo->codigo)[0], 'instancia' => 'entidad', 'materia' => 'Consulta laboral ficticia de demostración', 'fecha_inicio' => $fecha, 'estado' => 'en_tramite']),
            'afiliaciones' => Models\Afiliacion::query()->create([...$base, 'tipo' => 'sindicato', 'entidad' => 'SINDICATO FICTICIO DE DEMOSTRACIÓN', 'fecha_inicio' => $fecha, 'numero_afiliacion' => 'DEMO-'.$p->numero_doc_identidad]),
            'desvinculaciones' => Models\Desvinculacion::query()->firstOrCreate(['usuario_id' => $p->id, 'vinculo_laboral_id' => $v->id],
                ['registrado_por' => $this->admin->id, 'motivo_cese' => 'renuncia', 'fecha_cese' => $v->fecha_cese, 'numero_resolucion' => 'DEMO-INT-CESE', 'observaciones' => 'Cese ficticio de demostración']),
            default => null,
        };
        if ($registro) {
            FormulariosDatos::enlazarRegistro($doc, $tipo->formulario_datos, $registro->id);
        }
    }

    private function vinculo(Models\Usuario $p): Models\VinculoLaboral
    {
        return Models\VinculoLaboral::query()->where('usuario_id', $p->id)->where('numero_documento_vinculo', 'DEMO-INT-VIN-'.$p->numero_doc_identidad)->firstOrFail();
    }

    private function peticion(array $datos, ?UploadedFile $pdf = null): Request
    {
        $request = Request::create('/demo-integral', 'POST', $datos, [], $pdf ? ['pdf' => $pdf] : [], ['REMOTE_ADDR' => '127.0.0.1']);
        $request->setUserResolver(fn (): Models\Usuario => $this->admin);
        app()->instance('request', $request);

        return $request;
    }

    private function correos(Models\Usuario $p): void
    {
        foreach (['abierto', 'en_revision', 'observado', 'cerrado'] as $i => $estado) {
            $descripcion = 'DEMO INTEGRAL: requerimiento '.$estado;
            if (Models\RequerimientoDocumento::query()->where('usuario_id', $p->id)->where('descripcion', $descripcion)->exists()) {
                continue;
            }
            DB::transaction(function () use ($p, $estado, $descripcion, $i): void {
                $tipo = Models\TipoDocumento::query()->where('codigo', 'FORM_CAPACITACION')->firstOrFail();
                $r = Models\RequerimientoDocumento::query()->create(['usuario_id' => $p->id, 'tipo_documento_id' => $tipo->id, 'descripcion' => $descripcion,
                    'origen' => 'legajo', 'clase' => 'documentos', 'fecha_requerimiento' => today()->subDays(10), 'plazo_dias_habiles' => 5,
                    'fecha_limite' => today()->addDays($i === 0 ? -2 : 7), 'estado' => $estado === 'cerrado' ? 'aceptado' : $estado, 'solicitado_por' => $this->admin->id, 'cerrado_en' => $estado === 'cerrado' ? now() : null]);
                $n = Models\NotificacionUsuario::query()->create(['usuario_id' => $p->id, 'tipo' => 'solicitud_legajo', 'requerimiento_id' => $r->id,
                    'titulo' => MensajeRequerimiento::TITULO_SOLICITUD, 'mensaje' => 'SIMULACIÓN: presenta tu constancia ficticia. No se envió ningún correo.',
                    'correo_destinatario' => $p->email_institucional, 'correo_estado' => $i === 2 ? 'cancelado' : 'enviado',
                    'correo_enviado_en' => $i === 2 ? null : now()->subDays(9), 'correo_id_proveedor' => 'demo-simulado-'.$r->id,
                    'correo_error' => $i === 2 ? 'Ejemplo cancelado; no se envió.' : null]);
                $contenido = MensajeRequerimiento::construir($n, $r);
                $mail = (new Email)->from('orh@example.test')->to($p->email_institucional)->subject('[SIMULADO] '.$contenido['asunto'])
                    ->text("CORREO FICTICIO. NO ENVIADO.\n\n".$contenido['texto'])->html($contenido['html']);
                $disco = Storage::disk(config('legajo.disco_documentos'));
                $disco->put('demo-integral/correos/'.$p->numero_doc_identidad.'-'.$estado.'.eml', $mail->toString());
                if ($estado !== 'abierto') {
                    $pdf = $this->pdf($p, 'Constancia adjunta a respuesta SIMULADA');
                    $ruta = 'demo-integral/respuestas/'.$r->id.'.pdf';
                    $bytes = file_get_contents($pdf->getRealPath());
                    if ($estado === 'cerrado') {
                        $documento = Models\DocumentoUsuario::query()->where('usuario_id', $p->id)->where('tipo_documento_id', $tipo->id)->with('archivo')->firstOrFail();
                        $bytes = Storage::disk($documento->archivo->almacenamiento)->get($documento->archivo->storage_path);
                    }
                    $disco->put($ruta, $bytes);
                    $respuesta = (new Email)->from($p->email_institucional)->to('orh@example.test')->subject('[SIMULADO] Re: '.$contenido['asunto'])
                        ->text('Respuesta ficticia: adjunto mi constancia.')->attach($bytes, 'constancia-demo.pdf', 'application/pdf');
                    $disco->put('demo-integral/correos/'.$p->numero_doc_identidad.'-'.$estado.'-respuesta.eml', $respuesta->toString());
                    $eventos = [['accion' => 'respuesta', 'canal' => 'gmail', 'mensaje' => 'SIMULACIÓN LOCAL: adjunto constancia ficticia; no se recibió correo real.',
                        'autor' => null, 'remitente' => $p->email_institucional, 'referencia_correo' => '[SIMULADO] [REQ-'.$r->id.']', 'gmail_id' => 'demo-simulado-'.$r->id,
                        'fecha' => now()->subDays(5)->toIso8601String(), 'registrado_en' => now()->toIso8601String(),
                        'archivos' => [['archivo' => 'constancia-demo.pdf', 'ruta' => $ruta, 'disco' => config('legajo.disco_documentos'), 'hash' => hash('sha256', $bytes)]], 'avisos_adjuntos' => []]];
                    if ($estado === 'observado') {
                        $eventos[] = ['accion' => 'observar', 'autor' => $this->admin->id, 'fecha' => now()->subDays(4)->toIso8601String(), 'mensaje' => 'SIMULACIÓN: falta la firma del emisor.'];
                    }
                    if ($estado === 'cerrado') {
                        $documento = Models\DocumentoUsuario::query()->where('usuario_id', $p->id)->where('tipo_documento_id', $tipo->id)->firstOrFail();
                        $r->update(['documento_id' => $documento->id]);
                        $eventos[] = ['accion' => 'aceptar', 'autor' => $this->admin->id, 'fecha' => now()->toIso8601String(), 'mensaje' => 'SIMULACIÓN: documento incorporado al legajo.'];
                    }
                    $r->update(['seguimiento' => $eventos]);
                }
            });
        }
    }

    private function custodia(Models\Usuario $p): void
    {
        $legajo = $p->legajos()->where('numero_legajo', 'like', 'DEMO-INT-%')->firstOrFail();
        Models\SolicitudAccesoLegajo::query()->firstOrCreate(['legajo_id' => $legajo->id, 'solicitante_documento' => 'DEMO-INTEGRAL'],
            ['solicitante_nombre' => 'Auditoría Ficticia de Demostración', 'tipo_solicitante' => 'otro', 'entidad_procedencia' => 'Entidad ficticia',
                'detalle_requerido' => 'Consulta ficticia del expediente', 'sustento' => 'Demostración académica', 'forma_entrega' => 'digital',
                'incluye_datos_sensibles' => false, 'compromiso_confidencialidad' => true, 'estado' => 'pendiente']);
        Models\MovimientoCustodiaLegajo::query()->firstOrCreate(['legajo_id' => $legajo->id, 'numero_documento_sustento' => 'DEMO-INT-CUSTODIA'],
            ['tipo_movimiento' => 'prestamo', 'base_autorizacion' => 'autorizacion_orh', 'autorizado_por' => $this->admin->id,
                'entidad_destino' => 'Oficina Ficticia de Archivo', 'formato_legajo' => 'digital', 'numero_inventario' => 'DEMO-'.$p->numero_doc_identidad,
                'fecha_movimiento' => today()->subDays(10), 'fecha_retorno' => today()->subDays(5), 'servidor_comunicado' => true,
                'fecha_comunicacion_servidor' => today()->subDays(10), 'observaciones' => 'Préstamo ficticio devuelto, solo demostración.']);
    }

    private function antecedentes(Models\Usuario $p): void
    {
        $actual = $this->vinculo($p);
        $anterior = Models\VinculoLaboral::query()->firstOrCreate(['usuario_id' => $p->id, 'numero_documento_vinculo' => 'DEMO-INT-ANT-'.$p->numero_doc_identidad],
            ['tipo_personal' => $actual->tipo_personal, 'regimen_laboral' => $actual->regimen_laboral, 'regimen_normativo_id' => $actual->regimen_normativo_id,
                'unidad_organica_id' => $p->unidad_organica_id, 'nombre_puesto' => 'Puesto anterior ficticio',
                'fecha_inicio' => today()->subYears(6), 'fecha_fin' => today()->subYears(4), 'fecha_cese' => today()->subYears(4)]);
        Models\Legajo::query()->firstOrCreate(['usuario_id' => $p->id, 'numero_legajo' => 'DEMO-PAS-'.substr($p->numero_doc_identidad, -2)],
            ['fecha_apertura' => today()->subYears(6), 'fecha_pasivo' => today()->subYears(4), 'estado_legajo' => 'pasivo', 'ubicacion' => 'Archivo ficticio de demostración']);
        $legajo = $p->legajos()->where('numero_legajo', 'like', 'DEMO-INT-%')->firstOrFail();
        $tipo = Models\TipoDocumento::query()->where('codigo', 'DEMO_INTEGRAL_S13')->firstOrFail();
        $doc = $legajo->documentosUsuario()->where('tipo_documento_id', $tipo->id)->firstOrFail();
        Models\ReferenciaLegajoPasivo::query()->firstOrCreate(['legajo_id' => $legajo->id, 'vinculo_laboral_id' => $anterior->id, 'tipo_documento_id' => $tipo->id],
            ['folio_inicio_pasivo' => $doc->folio_inicio, 'folio_fin_pasivo' => $doc->folio_fin, 'fedateado' => false, 'fiscalizado' => false, 'responsable_legajo_id' => $this->admin->id]);
        foreach ([[-12, -8], [-10, -6]] as $i => [$desde, $hasta]) {
            $entidad = 'ENTIDAD FICTICIA SUPERPUESTA '.($i + 1);
            if (Models\ExperienciaLaboral::query()->where('usuario_id', $p->id)->where('entidad_empresa', $entidad)->exists()) {
                continue;
            }
            $folio = Folios::ultimo($legajo, 4) + 1;
            app(Seccion04Controller::class)->store($this->peticion([
                'tipo_documento_id' => Models\TipoDocumento::query()->where('codigo', 'EXP_CERTIFICADO_TRABAJO')->value('id'),
                'nombre' => 'CERTIFICADO FICTICIO DE TRABAJO SUPERPUESTO', 'emisor' => $entidad, 'sector' => 'publico', 'cargo' => 'Especialista ficticio',
                'fecha_inicio' => today()->subYears(abs($desde))->toDateString(), 'fecha_fin' => today()->subYears(abs($hasta))->toDateString(),
                'fecha_emision' => today()->subYears(abs($hasta))->toDateString(), 'folio_inicio' => $folio, 'folio_fin' => $folio,
                'modalidad_presentacion' => 'copia_simple', 'tipo_vinculo' => 'planilla', 'es_ejercicio_profesional' => true,
                'es_docencia_universitaria' => $p->tipo_personal === 'docente',
            ], $this->pdf($p, $entidad)), $p->id);
        }
    }

    private function paquete(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'demo-paquete-');
        $zip = new \ZipArchive;
        if ($zip->open($ruta, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('No se pudo preparar el paquete de prueba.');
        }
        try {
            $personas = Models\Usuario::query()->whereBetween('numero_doc_identidad', ['99880001', '99880008'])->where('email_institucional', 'like', 'integral.%@example.test')->get();
            $zip->addFromString('personas.json', $personas->map(fn ($p): array => $p->only(['numero_doc_identidad', 'nombres', 'apellido_paterno', 'apellido_materno', 'email_institucional', 'tipo_personal']))->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            foreach (Models\DocumentoUsuario::query()->whereIn('usuario_id', $personas->modelKeys())->with(['archivo', 'tipoDocumento', 'usuario'])->get() as $doc) {
                $nombre = $doc->usuario->numero_doc_identidad.'/seccion-'.sprintf('%02d', $doc->seccion_legajo_id).'/'.$doc->tipoDocumento->codigo.'-'.$doc->folio_inicio.'.pdf';
                $zip->addFromString($nombre, Storage::disk($doc->archivo->almacenamiento)->get($doc->archivo->storage_path));
            }
            $disco = Storage::disk(config('legajo.disco_documentos'));
            foreach ($disco->files('demo-integral/correos') as $correo) {
                $zip->addFromString('correos/'.basename($correo), $disco->get($correo));
            }
            if (! $zip->close()) {
                throw new \RuntimeException('No se pudo cerrar el paquete de prueba.');
            }
            $disco->put('demo-integral/paquete-pruebas.zip', file_get_contents($ruta));
        } finally {
            @unlink($ruta);
        }
    }

    /** PDF con marca visible y tabla xref válida. */
    private function pdf(Models\Usuario $p, string $tipo): UploadedFile
    {
        $lineas = ['DOCUMENTO FICTICIO DE DEMOSTRACION', 'SIN VALIDEZ OFICIAL. SOLO PRUEBAS.', '', $tipo, 'Titular: '.$p->nombres.' '.$p->apellido_paterno,
            'Documento ficticio: '.$p->numero_doc_identidad, 'Correo de prueba: '.$p->email_institucional, 'Fecha: '.today()->format('d/m/Y')];
        $texto = 'BT /F1 12 Tf 45 770 Td ';
        foreach ($lineas as $linea) {
            $texto .= '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], Str::ascii(mb_substr($linea, 0, 85))).') Tj 0 -24 Td ';
        }
        $texto .= 'ET';
        $objetos = ['<</Type /Catalog /Pages 2 0 R>>', '<</Type /Pages /Kids [3 0 R] /Count 1>>',
            '<</Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources <</Font <</F1 4 0 R>>>> /Contents 5 0 R>>',
            '<</Type /Font /Subtype /Type1 /BaseFont /Helvetica>>', '<</Length '.strlen($texto).">>\nstream\n".$texto."\nendstream"];
        $pdf = "%PDF-1.4\n";
        $posiciones = [];
        foreach ($objetos as $i => $objeto) {
            $posiciones[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$objeto."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach ($posiciones as $posicion) {
            $pdf .= sprintf("%010d 00000 n \n", $posicion);
        }
        $pdf .= "trailer <</Size 6 /Root 1 0 R>>\nstartxref\n{$xref}\n%%EOF";
        file_put_contents($this->temporal, $pdf);

        return new UploadedFile($this->temporal, Str::slug(mb_substr($tipo, 0, 70)).'-demo.pdf', 'application/pdf', null, true);
    }
}
