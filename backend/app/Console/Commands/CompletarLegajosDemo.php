<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\V1\Legajos\DocumentoLegajoController;
use App\Http\Controllers\Api\V1\Legajos\FamiliarPersonalController;
use App\Http\Controllers\Api\V1\Legajos\Seccion01Controller;
use App\Http\Controllers\Api\V1\Legajos\Seccion02Controller;
use App\Http\Controllers\Api\V1\Legajos\Seccion03Controller;
use App\Http\Controllers\Api\V1\Legajos\Seccion04Controller;
use App\Models\DocumentoUsuario;
use App\Models\Familiar;
use App\Models\Legajo;
use App\Models\Plataforma\Institucion;
use App\Models\RegimenNormativo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\AplicacionDocumental;
use App\Support\Documentos\Folios;
use App\Support\PerfilDocumental;
use App\Support\Plataforma\ConexionInstitucion;
use App\Support\ResumenSeccion03;
use App\Support\Seccion01;
use Faker\Factory as Faker;
use Faker\Generator;
use Illuminate\Console\Command;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * SOLO DEMOSTRACIÓN (APP_ENV=local): completa con datos ficticios (Faker, es_PE) las secciones 01 a 04 del legajo de las
 * personas con legajo activo, para presentar el sistema con fichas llenas. Cada documento lleva un PDF de una página que
 * dice «DOCUMENTO FICTICIO DE DEMOSTRACIÓN» y se registra por los mismos controladores que usa el administrador (mismas
 * validaciones, folios correlativos, estado aceptado y auditoría). Solo carga los tipos que el sistema exige a la persona
 * (PerfilDocumental) y que aún no tiene. A quien no tiene vínculo le registra uno ficticio. Deja --dejar personas sin
 * tocar para la carga en vivo. Nada se puede borrar después (arts. 2.3 y 5.3): sacar una copia de la base antes.
 */
class CompletarLegajosDemo extends Command
{
    protected $signature = 'demo:completar-legajos {--institucion= : Identificador de la institución (modo subdominio)}
        {--dejar=3 : Personas con legajo activo que se dejan sin completar} {--aplicar : Escribe; sin esta opción solo muestra el plan}';

    protected $description = 'Completa con datos ficticios las secciones 01-04 de los legajos (solo local, para demostración)';

    private const EMISORES = ['UNIVERSIDAD NACIONAL DE EJEMPLO', 'UNIVERSIDAD PRIVADA DEMOSTRATIVA', 'INSTITUTO SUPERIOR FICTICIO',
        'ESCUELA DE POSGRADO DE PRUEBA', 'CENTRO DE CAPACITACIÓN MODELO'];

    private const EMPRESAS = ['EMPRESA FICTICIA S.A.C.', 'MUNICIPALIDAD DE EJEMPLO', 'CONSULTORA DEMO E.I.R.L.', 'GOBIERNO REGIONAL DE PRUEBA'];

    private const COLEGIOS = ['COLEGIO DE INGENIEROS DE EJEMPLO', 'COLEGIO DE ABOGADOS DE PRUEBA', 'COLEGIO DE CONTADORES FICTICIO'];

    private const PUESTOS = ['ANALISTA ADMINISTRATIVO', 'ASISTENTE DE ESCALAFÓN', 'DOCENTE AUXILIAR', 'ESPECIALISTA EN PLANIFICACIÓN', 'TÉCNICO EN ARCHIVO'];

    private Generator $faker;

    private Usuario $admin;

    private string $carpeta;

    private array $contador = ['documentos' => 0, 'registros' => 0, 'vinculos' => 0, 'errores' => 0];

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Solo para demostración en local (APP_ENV=local).');

            return self::FAILURE;
        }
        $this->faker = Faker::create('es_PE');
        $this->faker->seed(2026);
        if (config('plataforma.modo') !== 'subdominio' || app()->bound(Institucion::class)) {
            return $this->completar();
        }
        $institucion = Institucion::query()->whereNotNull('aprovisionada_en')->where('slug', (string) $this->option('institucion'))->first();
        if (! $institucion) {
            $this->error('Indica una institución aprovisionada con --institucion=<identificador>.');

            return self::FAILURE;
        }

        return ConexionInstitucion::usar($institucion, fn (): int => $this->completar());
    }

    private function completar(): int
    {
        $admin = Usuario::query()->where('activo', true)->orderBy('created_at')->get()->first(fn (Usuario $usuario): bool => $usuario->can('sistema.administrar'));
        if (! $admin) {
            $this->error('No hay una cuenta activa con sistema.administrar en esta base.');

            return self::FAILURE;
        }
        $this->admin = $admin;
        Auth::setUser($admin);
        $personas = Usuario::query()->whereHas('legajos', fn ($query) => $query->where('estado_legajo', 'activo'))
            ->whereKeyNot($admin->id)->orderBy('apellido_paterno')->orderBy('nombres')->get();
        // Siempre las mismas (orden fijo por un hash del id), para que el plan y la ejecución coincidan.
        $dejar = $personas->sortBy(fn (Usuario $u): string => md5('demo'.$u->id))->take(max(0, (int) $this->option('dejar')));
        $this->line('Se dejan sin completar (carga en vivo): '.($dejar->map(fn (Usuario $u): string => $this->nombre($u))->join(', ') ?: 'ninguna'));
        $personas = $personas->diff($dejar);
        if (! $this->option('aplicar')) {
            $this->info("Se completarían {$personas->count()} persona(s). Ejecuta con --aplicar para escribir (saca antes una copia de la base).");

            return self::SUCCESS;
        }
        $this->carpeta = sys_get_temp_dir().'/demo-legajos-'.Str::random(8);
        mkdir($this->carpeta, 0700, true);
        try {
            foreach ($personas as $persona) {
                $this->line('· '.$this->nombre($persona));
                $this->persona($persona);
            }
        } finally {
            array_map('unlink', glob($this->carpeta.'/*') ?: []);
            rmdir($this->carpeta);
        }
        $this->info(sprintf('Listo: %d documento(s), %d registro(s) de datos y %d vínculo(s); %d aviso(s).',
            $this->contador['documentos'], $this->contador['registros'], $this->contador['vinculos'], $this->contador['errores']));

        return self::SUCCESS;
    }

    private function persona(Usuario $persona): void
    {
        if (! VinculoLaboral::query()->where('usuario_id', $persona->id)->where('anulado', false)->exists()) {
            $this->vinculo($persona);
        }
        // Con «Tiene derechohabientes» en Sí y sin familiares, se registran un cónyuge y un hijo menor ficticios (01 g, i, j).
        if (AplicacionDocumental::para($persona)->bandera('tiene_derechohabientes') === true
            && ! Familiar::query()->where('usuario_id', $persona->id)->where('anulado', false)->exists()) {
            $this->familiares($persona);
        }
        $legajo = Legajo::query()->where('usuario_id', $persona->id)->where('estado_legajo', 'activo')->firstOrFail();
        $tipos = TipoDocumento::query()->where('activo', true)->whereIn('seccion_legajo_id', [1, 2, 3, 4])
            ->orderBy('seccion_legajo_id')->orderBy('codigo')->get();
        $perfil = PerfilDocumental::de($persona, $persona->vinculosLaborales()->get());
        $aplicacion = AplicacionDocumental::para($persona);
        foreach ($tipos as $tipo) {
            $extra = $tipo->codigo === 'FORM_CAPACITACION';
            if ((! $extra && $perfil->exigencia($tipo)['exigido'] !== true) || $aplicacion->noAplica($tipo) !== null || $this->tiene($legajo, $tipo)) {
                continue;
            }
            $this->intentar($tipo->codigo, fn () => $this->documento($persona, $legajo, $tipo));
        }
    }

    private function tiene(Legajo $legajo, TipoDocumento $tipo): bool
    {
        return DocumentoUsuario::query()->where('legajo_id', $legajo->id)->where('tipo_documento_id', $tipo->id)->where('estado', '!=', 'anulado')->exists();
    }

    private function vinculo(Usuario $persona): void
    {
        $tipoPersonal = $persona->tipo_personal === 'docente' ? 'docente' : 'administrativo';
        $ley = $persona->regimen_normativo_id ?? RegimenNormativo::query()->where('aplica_a', $tipoPersonal)->value('id');
        $regimen = $this->faker->randomElement(['carrera', 'plazo_determinado']);
        $this->intentar('vínculo', function () use ($persona, $tipoPersonal, $ley, $regimen): void {
            $this->llamar(Seccion02Controller::class, 'storeVinculo', $persona, [
                'tipo_personal' => $tipoPersonal, 'regimen_laboral' => $regimen, 'regimen_normativo_id' => $ley,
                'nombre_puesto' => $tipoPersonal === 'docente' ? 'DOCENTE AUXILIAR' : $this->faker->randomElement(self::PUESTOS),
                'obligado_djbr' => false, 'exige_colegiatura' => $this->faker->boolean(40), 'exige_estudios_basicos' => true,
                'tiene_periodo_prueba' => $regimen === 'plazo_determinado',
                'proceso_seleccion' => 'CONCURSO PÚBLICO N.° '.$this->faker->numerify('0##').'-2026',
                'numero_documento_vinculo' => ($regimen === 'carrera' ? 'RESOLUCIÓN N.° ' : 'CONTRATO N.° ').$this->faker->numerify('###').'-2026-DEMO',
                'fecha_inicio' => $this->faker->dateTimeBetween('-3 years', '-2 months')->format('Y-m-d'),
            ]);
            $this->contador['vinculos']++;
        });
    }

    private function familiares(Usuario $persona): void
    {
        $familiares = [
            ['parentesco' => 'conyuge', 'fecha_nacimiento' => $this->fecha('-50 years', '-28 years')],
            ['parentesco' => 'hijo', 'fecha_nacimiento' => $this->fecha('-15 years', '-2 years')],
        ];
        foreach ($familiares as $familiar) {
            $this->intentar('familiar', function () use ($persona, $familiar): void {
                $this->llamar(FamiliarPersonalController::class, 'storeForPerson', $persona, [...$familiar,
                    'apellidos_nombres' => mb_strtoupper($this->faker->lastName().' '.$this->faker->firstName()),
                    'tipo_doc_identidad' => 'dni', 'numero_doc_identidad' => $this->faker->numerify('99######'), 'es_derechohabiente' => true]);
                $this->contador['registros']++;
            });
        }
    }

    private function documento(Usuario $persona, Legajo $legajo, TipoDocumento $tipo): void
    {
        $seccion = (int) $tipo->seccion_legajo_id;
        $emision = $this->fecha('-6 years', '-1 month');
        $folio = Folios::ultimo($legajo, $seccion) + 1;
        $comunes = ['tipo_documento_id' => $tipo->id, 'folio_inicio' => $folio, 'folio_fin' => $folio, 'fecha_emision' => $emision,
            'modalidad_presentacion' => $this->faker->randomElement(['copia_simple', 'copia_fedateada', 'digital_firmado'])];

        if ($seccion === 3) {
            [$letra] = ResumenSeccion03::ITEMS[$tipo->codigo] ?? [null];
            if ($letra === null) {
                return;
            }
            $datos = [...$comunes, 'nombre' => $this->nombre03($letra), 'emisor' => $letra === 'c' ? $this->faker->randomElement(self::COLEGIOS) : $this->faker->randomElement(self::EMISORES)];
            $datos += match ($letra) {
                'b' => ['nivel' => $this->faker->randomElement(['bachiller', 'titulo_profesional']), 'numero_registro' => 'SUNEDU-'.$this->faker->numerify('######')],
                'e' => ['nivel' => $this->faker->randomElement(['maestria', 'doctorado']), 'numero_registro' => 'SUNEDU-'.$this->faker->numerify('######')],
                // Algunas colegiaturas vencen pronto, para que el panel de vencimientos tenga qué mostrar.
                'c' => ['numero_colegiatura' => $this->faker->numerify('#####'), 'vigente_hasta' => $this->faker->boolean(35)
                    ? now()->addDays($this->faker->numberBetween(5, 40))->toDateString() : now()->addYear()->toDateString()],
                'f' => ['tipo_capacitacion' => 'curso', 'horas' => $this->faker->randomElement([40, 60, 120, 160])],
                'g' => ['tipo_capacitacion' => 'seminario', 'horas' => 16],
                default => [],
            };
            $this->subir(Seccion03Controller::class, 'store', $persona, $tipo, $datos);

            return;
        }
        if ($seccion === 4) {
            $inicio = $this->fecha('-12 years', '-4 years');
            $datos = [...$comunes, 'nombre' => 'CERTIFICADO DE TRABAJO', 'emisor' => $this->faker->randomElement(self::EMPRESAS)];
            if ($tipo->codigo === 'EXP_CERTIFICADO_TRABAJO') {
                $datos += ['sector' => $this->faker->randomElement(['publico', 'privado']), 'cargo' => $this->faker->randomElement(self::PUESTOS),
                    'fecha_inicio' => $inicio, 'fecha_fin' => $this->fecha($inicio.' +1 year', '-3 years'), 'tipo_vinculo' => 'planilla',
                    'es_ejercicio_profesional' => true, 'es_docencia_universitaria' => $persona->tipo_personal === 'docente'];
            } elseif ($tipo->codigo === 'EXP_RESOLUCION_FUNCIONES') {
                $datos['funcion_asignada'] = 'FUNCIONES DE APOYO ADMINISTRATIVO (DEMOSTRACIÓN)';
            }
            if ($tipo->requiere_num_resolucion) {
                $datos['num_resolucion'] = $this->resolucion();
            }
            $this->subir(Seccion04Controller::class, 'store', $persona, $tipo, $datos);

            return;
        }

        // Secciones 01 y 02: carga general y, si el tipo tiene formulario de datos, su registro enlazado.
        $titular = ['titular_nombre' => $this->nombre($persona), 'titular_documento' => $persona->numero_doc_identidad, 'relacion_titular' => 'servidor'];
        $datos = [...collect($comunes)->except('tipo_documento_id')->all(), 'seccion_legajo_id' => $seccion, 'tipo_documento_id' => $tipo->id,
            'fecha_incorporacion' => now()->toDateString(), 'emisor' => $this->faker->randomElement(['UNIVERSIDAD NACIONAL DE EJEMPLO', 'OFICINA DE RECURSOS HUMANOS']), ...$titular];
        if ($tipo->codigo === 'S01_B_DOCUMENTO_IDENTIDAD') {
            $datos = ['seccion_legajo_id' => 1, 'tipo_documento_id' => $tipo->id, 'fecha_incorporacion' => now()->toDateString(),
                'fecha_emision' => $emision, 'fecha_caducidad' => now()->addYears(4)->toDateString()];
        }
        if ($tipo->requiere_num_resolucion) {
            $datos['num_resolucion'] = $this->resolucion();
        }
        $vinculo = VinculoLaboral::query()->where('usuario_id', $persona->id)->where('anulado', false)->whereNull('fecha_cese')->orderByDesc('fecha_inicio')->first();
        if ($tipo->formulario_datos === 'vinculos_laborales' && $vinculo) {
            $datos['enlace_id'] = $vinculo->id;
        }
        if ($tipo->formulario_datos === 'familiares') {
            $familiar = Familiar::query()->where('usuario_id', $persona->id)->where('anulado', false)->whereNull('documento_id')
                ->whereIn('parentesco', Seccion01::PARENTESCOS[$tipo->codigo] ?? [])->first();
            if (! $familiar) {
                return;
            }
            $datos = [...$datos, 'enlace_id' => $familiar->id, 'titular_nombre' => $familiar->apellidos_nombres,
                'titular_documento' => $familiar->numero_doc_identidad ?: $this->faker->numerify('99######'),
                'relacion_titular' => $familiar->parentesco === 'hijo' ? 'hijo' : 'conyuge_concubino'];
        }
        $id = $this->subir(DocumentoLegajoController::class, 'store', $persona, $tipo, $datos);
        if ($id === null) {
            return;
        }
        $registro = match (true) {
            isset(Seccion01::DECLARACIONES[$tipo->codigo]) => [Seccion01Controller::class, 'storeDeclaracion', ['tipo' => Seccion01::DECLARACIONES[$tipo->codigo][0],
                'anio' => (int) substr($emision, 0, 4), 'fecha' => $emision, 'resultado' => $tipo->codigo === 'S01_E_DJ_BIENES_RENTAS' ? 'presentada' : 'sin_registros']],
            $tipo->formulario_datos === 'certificados_discapacidad' => [Seccion01Controller::class, 'storeCertificado', ['persona' => 'servidor',
                'numero_conadis' => $this->faker->numerify('######'), 'fecha_emision' => $emision]],
            $tipo->formulario_datos === 'inducciones' && $vinculo => [Seccion02Controller::class, 'storeInduccion', ['vinculo_laboral_id' => $vinculo->id,
                'tipo' => 'general', 'fecha' => $this->fecha($vinculo->fecha_inicio->format('Y-m-d'), 'now'), 'responsable' => 'OFICINA DE RECURSOS HUMANOS']],
            $tipo->formulario_datos === 'periodos_prueba' && $vinculo => [Seccion02Controller::class, 'storePeriodo', ['vinculo_laboral_id' => $vinculo->id,
                'fecha_inicio' => $vinculo->fecha_inicio->format('Y-m-d'), 'fecha_fin' => $vinculo->fecha_inicio->copy()->addMonths(3)->format('Y-m-d'),
                ...($vinculo->fecha_inicio->copy()->addMonths(3)->isPast() ? ['resultado' => 'aprobado', 'fecha_resultado' => $vinculo->fecha_inicio->copy()->addMonths(3)->format('Y-m-d')] : [])]],
            default => null,
        };
        if ($registro) {
            [$controlador, $metodo, $campos] = $registro;
            $this->intentar($tipo->codigo.' (datos)', function () use ($controlador, $metodo, $persona, $campos, $id): void {
                $this->llamar($controlador, $metodo, $persona, [...$campos, 'documento_id' => $id]);
                $this->contador['registros']++;
            });
        }
    }

    /** Sube el documento por el controlador de su sección con un PDF ficticio; devuelve su id. */
    private function subir(string $controlador, string $metodo, Usuario $persona, TipoDocumento $tipo, array $datos): ?string
    {
        $respuesta = $this->llamar($controlador, $metodo, $persona, $datos, $this->pdf($persona, $tipo));
        $this->contador['documentos']++;

        return $respuesta->getData(true)['data']['id'] ?? null;
    }

    /** Llama al controlador como el administrador, con una petición armada como la del formulario. */
    private function llamar(string $controlador, string $metodo, Usuario $persona, array $datos, ?UploadedFile $pdf = null): JsonResponse
    {
        $peticion = Request::create('/api/v1/expedientes/'.$persona->id, 'POST', $datos, [], $pdf ? ['pdf' => $pdf] : [], ['REMOTE_ADDR' => '127.0.0.1']);
        $peticion->setUserResolver(fn (): Usuario => $this->admin);
        app()->instance('request', $peticion);
        $respuesta = app($controlador)->{$metodo}($peticion, $persona->id);

        return $respuesta instanceof JsonResponse ? $respuesta : $respuesta->response();
    }

    private function intentar(string $que, callable $paso): void
    {
        try {
            $paso();
        } catch (ValidationException $error) {
            $this->aviso($que, collect($error->errors())->flatten()->first());
        } catch (HttpExceptionInterface $error) {
            $this->aviso($que, $error->getMessage());
        } catch (Throwable $error) {
            report($error);
            $this->aviso($que, $error->getMessage());
        }
    }

    private function aviso(string $que, ?string $mensaje): void
    {
        $this->contador['errores']++;
        $this->warn("    {$que}: {$mensaje}");
    }

    /** PDF mínimo de una página que dice que es ficticio (Helvetica, texto en ASCII). */
    private function pdf(Usuario $persona, TipoDocumento $tipo): UploadedFile
    {
        $lineas = ['DOCUMENTO FICTICIO DE DEMOSTRACION', 'Sin validez oficial. Generado para presentar el sistema.', '',
            'Tipo: '.$tipo->nombre, 'Titular: '.$this->nombre($persona), 'DNI: '.$persona->numero_doc_identidad, 'Fecha: '.now()->format('d/m/Y')];
        $texto = 'BT /F1 13 Tf 60 760 Td ';
        foreach ($lineas as $linea) {
            $texto .= '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], Str::limit(Str::ascii($linea), 90)).') Tj 0 -22 Td ';
        }
        $texto .= 'ET';
        $objetos = ['<</Type /Catalog /Pages 2 0 R>>', '<</Type /Pages /Kids [3 0 R] /Count 1>>',
            '<</Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources <</Font <</F1 4 0 R>>>> /Contents 5 0 R>>',
            '<</Type /Font /Subtype /Type1 /BaseFont /Helvetica>>', '<</Length '.strlen($texto).">>\nstream\n".$texto."\nendstream"];
        $contenido = "%PDF-1.4\n";
        $posiciones = [];
        foreach ($objetos as $indice => $objeto) {
            $posiciones[] = strlen($contenido);
            $contenido .= ($indice + 1)." 0 obj\n".$objeto."\nendobj\n";
        }
        $xref = strlen($contenido);
        $contenido .= "xref\n0 6\n0000000000 65535 f \n".implode('', array_map(fn (int $p): string => sprintf("%010d 00000 n \n", $p), $posiciones));
        $contenido .= "trailer <</Size 6 /Root 1 0 R>>\nstartxref\n{$xref}\n%%EOF";
        $ruta = $this->carpeta.'/'.Str::uuid().'.pdf';
        file_put_contents($ruta, $contenido);

        return new UploadedFile($ruta, Str::slug($tipo->nombre).'-demo.pdf', 'application/pdf', null, true);
    }

    private function nombre03(string $letra): string
    {
        return match ($letra) {
            'a' => 'CERTIFICADO DE ESTUDIOS SECUNDARIOS',
            'b' => $this->faker->randomElement(['TÍTULO PROFESIONAL DE LICENCIADO EN ADMINISTRACIÓN', 'BACHILLER EN INGENIERÍA DE SISTEMAS', 'TÍTULO DE CONTADOR PÚBLICO']),
            'c' => 'CERTIFICADO DE HABILIDAD PROFESIONAL',
            'e' => $this->faker->randomElement(['MAESTRÍA EN GESTIÓN PÚBLICA', 'MAESTRÍA EN EDUCACIÓN', 'DOCTORADO EN ADMINISTRACIÓN']),
            'f' => $this->faker->randomElement(['CURSO DE GESTIÓN DOCUMENTAL', 'CURSO DE OFIMÁTICA AVANZADA', 'PROGRAMA DE DOCENCIA UNIVERSITARIA']),
            default => 'DOCUMENTO DE FORMACIÓN (DEMOSTRACIÓN)',
        };
    }

    private function resolucion(): string
    {
        return 'RES. N.° '.$this->faker->numerify('###').'-'.$this->faker->numberBetween(2019, 2026).'-DEMO';
    }

    private function fecha(string $desde, string $hasta): string
    {
        return $this->faker->dateTimeBetween($desde, $hasta)->format('Y-m-d');
    }

    private function nombre(Usuario $persona): string
    {
        return trim($persona->nombres.' '.$persona->apellido_paterno.' '.$persona->apellido_materno);
    }
}
