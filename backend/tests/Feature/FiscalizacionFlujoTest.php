<?php

namespace Tests\Feature;

use App\Models\ArchivoDocumento;
use App\Models\AuditoriaEvento;
use App\Models\ConexionCorreo;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\Rol;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\Documentos\EstadoDocumento;
use App\Support\Documentos\ReconocimientoConsulta;
use App\Support\Documentos\ReconocimientoTitulo;
use App\Support\VerificacionFiscalizacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Mail\Message;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class FiscalizacionFlujoTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $responsable;

    private Legajo $legajo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->responsable = $this->actuarComo();
        $this->legajo = Legajo::factory()->create(['estado_legajo' => 'activo']);
        Storage::fake(config('legajo.disco_documentos'));
        config(['fiscalizacion.firma_peru.url' => null, 'fiscalizacion.firma_peru.credential' => null, 'fiscalizacion.firma_peru.institucion' => null]);
        config(['fiscalizacion.apisted.token' => null, 'fiscalizacion.apisted.institucion' => null]);
        config(['services.gmail.client_id' => null, 'services.gmail.client_secret' => null, 'mail.default' => 'log']);
        Http::preventStrayRequests();
    }

    private function documento(?Legajo $legajo = null, int $seccion = 3): DocumentoUsuario
    {
        $legajo ??= $this->legajo;
        $contenido = "%PDF-1.4\nDocumento de prueba ".fake()->uuid();
        $ruta = 'fiscalizacion/'.fake()->uuid().'.pdf';
        Storage::disk(config('legajo.disco_documentos'))->put($ruta, $contenido);
        $archivo = ArchivoDocumento::factory()->create(['almacenamiento' => config('legajo.disco_documentos'), 'storage_path' => $ruta,
            'hash_sha256' => hash('sha256', $contenido), 'mime_type' => 'application/pdf', 'estado_antivirus' => 'limpio']);
        $tipo = TipoDocumento::factory()->create(['seccion_legajo_id' => $seccion, 'es_dato_sensible' => false, 'es_dato_salud' => false]);

        return DocumentoUsuario::factory()->create(['usuario_id' => $legajo->usuario_id, 'legajo_id' => $legajo->id,
            'seccion_legajo_id' => $seccion, 'tipo_documento_id' => $tipo->id, 'archivo_id' => $archivo->id, 'estado' => 'aceptado', 'fiscalizado' => false]);
    }

    private function abrir(?DocumentoUsuario $doc = null): array
    {
        $doc ??= $this->documento();
        $response = $this->postJson('/api/v1/fiscalizacion/procesos', [
            'legajo_id' => $this->legajo->id, 'tipo_muestra' => 'dirigida', 'fecha_vinculacion' => '2026-01-01',
            'fecha_inicio' => '2026-01-02', 'fecha_limite' => '2026-12-31', 'motivo' => 'Verificar título del puesto',
            'fundamento' => 'Disposición interna para verificar documentos del perfil', 'documentos' => [$doc->id],
        ])->assertCreated();
        $id = $response->json('data.id');
        $detalle = $this->getJson('/api/v1/fiscalizacion/procesos/'.$id)->assertOk()->json();

        return [$id, $detalle['alcance'][0]['id'], $doc];
    }

    public function test_apisted_queries_require_confirmation_and_preserve_manual_evaluation(): void
    {
        config(['fiscalizacion.apisted.token' => 'token-privado', 'fiscalizacion.apisted.institucion' => 'unica']);
        [$id, $alcance, $doc] = $this->abrir();
        $doc->usuario->update(['tipo_doc_identidad' => 'dni', 'numero_doc_identidad' => '10000000']);
        Http::fake(['https://apisted.com/api/minedu/titulos' => Http::response([
            'consultado_por' => 'dni', 'criterio' => '10000000', 'encontrado' => true, 'total' => 1,
            'registros' => [['grado_o_titulo' => 'PROFESIONAL TÉCNICO', 'numero_documento' => '10000000', 'institucion_educativa' => 'INSTITUTO', 'token' => 'no-conservar']],
            'fuentes' => ['tecnologicos' => 'ok', 'pedagogicos' => 'no_disponible'], 'fecha_consulta' => '21/09/2026 12:00:00',
        ])]);
        $url = '/api/v1/fiscalizacion/procesos/'.$id.'/verificar';
        $data = ['fuente' => 'apisted_minedu', 'alcances' => [$alcance]];
        $this->postJson($url, $data)->assertUnprocessable();
        Http::assertNothingSent();
        $this->postJson($url, [...$data, 'confirmar_creditos' => true])->assertOk()
            ->assertJsonPath('data.0.resultado_fuente', 'registros_encontrados')
            ->assertJsonPath('data.0.detalle.registros.0.institucion_educativa', 'INSTITUTO')
            ->assertJsonPath('data.0.detalle.fuentes.pedagogicos', 'no_disponible')
            ->assertDontSee('token-privado')->assertDontSee('no-conservar');
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer token-privado') && $request->data() === ['dni' => '10000000']);
        $this->assertDatabaseHas('documentos_usuario', ['id' => $doc->id, 'fiscalizado' => false]);
        $this->assertDatabaseHas('fiscalizacion_documentos', ['id' => $alcance, 'resultado' => 'pendiente']);
    }

    public function test_sunedu_empty_result_is_not_fraud(): void
    {
        config(['fiscalizacion.apisted.token' => 'token-privado', 'fiscalizacion.apisted.institucion' => 'unica']);
        [$id, $alcance, $doc] = $this->abrir();
        $doc->usuario->update(['tipo_doc_identidad' => 'dni', 'numero_doc_identidad' => '10000000']);
        Http::fake(['https://apisted.com/api/minedu/universitarios' => Http::response([
            'consultado_por' => 'dni', 'numero_documento' => '10000000', 'encontrado' => false, 'total' => 0, 'registros' => [],
        ])]);
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/verificar', ['fuente' => 'apisted_sunedu', 'alcances' => [$alcance], 'confirmar_creditos' => true])
            ->assertOk()->assertJsonPath('data.0.resultado_fuente', 'sin_coincidencias');
        $this->assertDatabaseHas('fiscalizacion_documentos', ['id' => $alcance, 'resultado' => 'pendiente']);
    }

    public function test_apisted_does_not_send_queries_for_another_institution_or_invalid_dni(): void
    {
        config(['fiscalizacion.apisted.token' => 'token-privado', 'fiscalizacion.apisted.institucion' => 'otra']);
        [$id, $alcance, $doc] = $this->abrir();
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/verificar', ['fuente' => 'apisted_minedu', 'alcances' => [$alcance], 'confirmar_creditos' => true])
            ->assertOk()->assertJsonPath('data.0.estado_tecnico', 'no_configurado');
        config(['fiscalizacion.apisted.institucion' => 'unica']);
        $doc->usuario->update(['tipo_doc_identidad' => 'ce', 'numero_doc_identidad' => '10000000']);
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/verificar', ['fuente' => 'apisted_sunedu', 'alcances' => [$alcance], 'confirmar_creditos' => true])
            ->assertOk()->assertJsonPath('data.0.estado_tecnico', 'bloqueado');
        Http::assertNothingSent();
    }

    public function test_apisted_rejects_mismatched_responses_and_hides_provider_errors(): void
    {
        config(['fiscalizacion.apisted.token' => 'token-privado', 'fiscalizacion.apisted.institucion' => 'unica']);
        [$id, $alcance, $doc] = $this->abrir();
        $doc->usuario->update(['tipo_doc_identidad' => 'dni', 'numero_doc_identidad' => '10000000']);
        Http::fake([
            'https://apisted.com/api/minedu/titulos' => Http::response(['consultado_por' => 'dni', 'criterio' => '99999999', 'encontrado' => false, 'total' => 0, 'registros' => []]),
            'https://apisted.com/api/minedu/universitarios' => Http::response(['message' => 'error token-privado'], 402),
        ]);
        $url = '/api/v1/fiscalizacion/procesos/'.$id.'/verificar';
        $this->postJson($url, ['fuente' => 'apisted_minedu', 'alcances' => [$alcance], 'confirmar_creditos' => true])
            ->assertOk()->assertJsonPath('data.0.resultado_fuente', 'respuesta_no_reconocida');
        $this->postJson($url, ['fuente' => 'apisted_sunedu', 'alcances' => [$alcance], 'confirmar_creditos' => true])
            ->assertOk()->assertJsonPath('data.0.detalle.http_estado', 402)->assertDontSee('token-privado');
    }

    public function test_consultation_reader_extracts_recipient_and_actual_send_date_without_using_the_sender(): void
    {
        $lector = app(ReconocimientoConsulta::class);
        $texto = "Oficina de Recursos Humanos\nOFICIO N° 004-2026-ORH\nEntidad destinataria: Universidad de Ejemplo\nFecha de envío: 23/10/2026\nSolicitamos verificar el título presentado.";
        $campos = $lector->analizar($texto)['campos'];
        $this->assertSame('Universidad de Ejemplo', $campos['entidad_consultada']);
        $this->assertSame('004-2026-ORH', $campos['numero_oficio']);
        $this->assertSame('2026-10-23', $campos['fecha_envio']);
        $this->assertSame('oficio', $campos['medio']);
        $sinEnvio = $lector->analizar("Universidad Emisora\nOFICIO N° 005-2026\nLima, 23 de octubre de 2026\nFecha de expedición: 23/10/2026")['campos'];
        $this->assertArrayNotHasKey('entidad_consultada', $sinEnvio);
        $this->assertArrayNotHasKey('fecha_envio', $sinEnvio);
    }

    public function test_email_headers_recognize_source_and_multiple_dates_remain_pending(): void
    {
        $lector = app(ReconocimientoConsulta::class);
        $campos = $lector->analizar("De: rrhh@entidad.gob.pe\nPara: SUNEDU\nEnviado: 23 de octubre de 2026\nAsunto: Consulta de título")['campos'];
        $this->assertSame('sunedu', $campos['fuente']);
        $this->assertSame('correo', $campos['medio']);
        $this->assertSame('2026-10-23', $campos['fecha_envio']);
        $ambiguo = $lector->analizar("Enviado: 23/10/2026\nEnviado: 22/10/2026");
        $this->assertArrayNotHasKey('fecha_envio', $ambiguo['campos']);
    }

    public function test_scanning_a_consultation_requires_access_and_never_creates_or_sends_one(): void
    {
        [$id, $alcance, $doc] = $this->abrir();
        $prueba = $this->documento();
        $this->mock(ReconocimientoTitulo::class)->shouldReceive('extraer')->once()->andReturn([
            'texto' => "OFICIO N° 004-2026-ORH\nEntidad destinataria: Universidad de Ejemplo\nFecha de envío: 23/10/2026",
            'metodo' => 'ocr_local', 'paginas' => 1,
        ]);
        $url = '/api/v1/fiscalizacion/procesos/'.$id.'/consultas/reconocer';
        $this->postJson($url, ['documento_id' => $doc->id, 'evidencia_documento_id' => $doc->id])->assertUnprocessable();
        $ajeno = $this->documento(Legajo::factory()->create());
        $this->postJson($url, ['documento_id' => $doc->id, 'evidencia_documento_id' => $ajeno->id])->assertNotFound();
        $this->postJson($url, ['documento_id' => $doc->id, 'evidencia_documento_id' => $prueba->id])->assertOk()
            ->assertJsonPath('data.campos.numero_oficio', '004-2026-ORH')->assertJsonPath('data.evidencia_documento_id', $prueba->id);
        $this->assertDatabaseCount('solicitudes_fiscalizacion', 0);
        $this->assertDatabaseHas('procesos_fiscalizacion', ['id' => $id, 'estado' => 'abierto']);
        $this->assertDatabaseHas('fiscalizacion_documentos', ['id' => $alcance, 'resultado' => 'pendiente']);
        Http::assertNothingSent();
        $this->actuarComo();
        $this->postJson($url, ['documento_id' => $doc->id, 'evidencia_documento_id' => $prueba->id])->assertForbidden();
    }

    public function test_consultation_specific_fields_are_saved_and_returned_for_each_medium(): void
    {
        [$id, $alcance, $doc] = $this->abrir();
        $evidencia = $this->documento();
        $detalles = [
            'correo' => ['remitente' => 'rrhh@entidad.gob.pe', 'destinatario' => 'grados@universidad.edu.pe', 'asunto' => 'Solicitud de verificación de título con un asunto que supera cuarenta caracteres'],
            'oficio' => ['destinatario' => 'Rectorado', 'cargo_destinatario' => 'Rector', 'canal_envio' => 'mesa_partes_virtual', 'expediente' => 'EXP-2026-001'],
            'registro_oficial' => ['nombre_registro' => 'Registro de títulos', 'url_registro' => 'https://ejemplo.gob.pe/consulta', 'criterio_busqueda' => 'Documento autorizado', 'resultado_consulta' => 'Registro encontrado; pendiente de contraste'],
        ];
        foreach ($detalles as $medio => $detalle) {
            $respuesta = $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/consultas', [
                'documento_id' => $doc->id, 'entidad_consultada' => 'Universidad de Ejemplo', 'medio' => $medio,
                'numero_oficio' => $medio === 'oficio' ? 'OF-001' : null, 'fecha_envio' => '2026-10-23',
                'evidencia_documento_id' => $evidencia->id, 'fuente' => 'emisor', 'datos_medio' => $detalle,
            ])->assertCreated();
            foreach ($detalle as $key => $valor) {
                $respuesta->assertJsonPath('data.datos_medio.'.$key, $valor);
            }
        }
        $this->getJson('/api/v1/fiscalizacion/procesos/'.$id)->assertOk()->assertJsonCount(3, 'consultas');
        $this->assertDatabaseCount('solicitudes_fiscalizacion', 3);
        Http::assertNothingSent();
    }

    public function test_consultation_specific_fields_reject_missing_fields_wrong_medium_and_unsafe_urls(): void
    {
        [$id, $alcance, $doc] = $this->abrir();
        $evidencia = $this->documento();
        $base = ['documento_id' => $doc->id, 'entidad_consultada' => 'Universidad', 'numero_oficio' => 'OF-001', 'fecha_envio' => '2026-10-23', 'evidencia_documento_id' => $evidencia->id, 'fuente' => 'emisor'];
        $url = '/api/v1/fiscalizacion/procesos/'.$id.'/consultas';
        $this->postJson($url, [...$base, 'medio' => 'correo', 'datos_medio' => ['remitente' => 'no-es-correo', 'destinatario' => 'grados@universidad.edu.pe']])
            ->assertUnprocessable()->assertJsonValidationErrors(['datos_medio.remitente', 'datos_medio.asunto']);
        $this->postJson($url, [...$base, 'medio' => 'oficio', 'datos_medio' => ['destinatario' => 'Rectorado', 'remitente' => 'rrhh@entidad.gob.pe']])
            ->assertUnprocessable()->assertJsonValidationErrors(['datos_medio', 'datos_medio.canal_envio']);
        $this->postJson($url, [...$base, 'medio' => 'registro_oficial', 'datos_medio' => ['nombre_registro' => 'Registro', 'url_registro' => 'javascript:alert(1)', 'criterio_busqueda' => 'Documento', 'resultado_consulta' => 'Sin coincidencias']])
            ->assertUnprocessable()->assertJsonValidationErrors('datos_medio.url_registro');
        $this->assertDatabaseCount('solicitudes_fiscalizacion', 0);
    }

    public function test_reader_recognizes_email_headers_and_explicit_registry_details(): void
    {
        $lector = app(ReconocimientoConsulta::class);
        $correo = $lector->analizar("De: Recursos Humanos <rrhh@entidad.gob.pe>\nPara: Grados <grados@universidad.edu.pe>\nAsunto: Solicitud de verificación de título profesional remitida por Recursos Humanos\nEnviado: 23/10/2026")['campos'];
        $this->assertSame('rrhh@entidad.gob.pe', $correo['remitente']);
        $this->assertSame('grados@universidad.edu.pe', $correo['destinatario']);
        $this->assertGreaterThan(40, mb_strlen($correo['asunto']));
        $registro = $lector->analizar("Registro oficial: Registro de títulos\nURL del registro: https://ejemplo.gob.pe/consulta\nCriterio de búsqueda: Documento autorizado\nResultado de consulta: Un registro encontrado\nFecha de consulta: 23/10/2026")['campos'];
        $this->assertSame('registro_oficial', $registro['medio']);
        $this->assertSame('Documento autorizado', $registro['criterio_busqueda']);
        $this->assertSame('Un registro encontrado', $registro['resultado_consulta']);
    }

    private function conectarCorreo(): void
    {
        config(['services.gmail.client_id' => 'cliente-prueba', 'services.gmail.client_secret' => 'secreto-prueba']);
        ConexionCorreo::factory()->create(['clave' => 'gmail_institucional', 'email' => 'rrhh@entidad.gob.pe', 'conectado_por' => $this->responsable->id]);
    }

    private function datosCorreo(DocumentoUsuario $doc): array
    {
        return ['clave_envio' => (string) Str::uuid(), 'documento_id' => $doc->id, 'entidad_consultada' => 'Universidad de Prueba',
            'remitente' => 'rrhh@entidad.gob.pe', 'destinatario' => 'grados@universidad.edu.pe', 'asunto' => 'Verificar titulo profesional',
            'cuerpo' => 'Solicitamos verificar el titulo presentado. Mensaje ficticio para pruebas.', 'fuente' => 'emisor'];
    }

    public function test_connected_account_sends_the_exact_subject_and_body_and_records_only_once(): void
    {
        $this->conectarCorreo();
        [$id, $alcance, $doc] = $this->abrir();
        $datos = $this->datosCorreo($doc);
        Http::fake(['gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response(['id' => 'mensaje-prueba'])]);
        $this->getJson('/api/v1/fiscalizacion/correo-institucional')->assertOk()->assertJsonPath('data.remitente', 'rrhh@entidad.gob.pe')->assertDontSee('secreto-prueba');
        $url = '/api/v1/fiscalizacion/procesos/'.$id.'/correos';
        $this->postJson($url, $datos)->assertCreated()->assertJsonPath('data.estado', 'enviado')->assertJsonPath('data.mensaje_id', 'mensaje-prueba');
        $this->postJson($url, $datos)->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($datos): bool {
            $mensaje = base64_decode(strtr($request['raw'], '-_', '+/'));
            $this->assertStringContainsString('Subject: '.$datos['asunto'], $mensaje);
            $this->assertStringContainsString('To: '.$datos['destinatario'], $mensaje);
            $this->assertStringContainsString('From: '.$datos['remitente'], $mensaje);
            $this->assertStringContainsString($datos['cuerpo'], $mensaje);
            $this->assertStringNotContainsString('Content-Disposition: attachment', $mensaje);

            return true;
        });
        $this->assertDatabaseCount('solicitudes_fiscalizacion', 1);
        $this->assertDatabaseCount('fiscalizacion_correos', 1);
        $this->getJson('/api/v1/fiscalizacion/procesos/'.$id)->assertOk()->assertJsonPath('consultas.0.datos_medio.cuerpo', $datos['cuerpo'])->assertJsonPath('correos.0.estado', 'enviado');
        $this->assertDatabaseHas('fiscalizacion_documentos', ['id' => $alcance, 'resultado' => 'pendiente']);
        $this->postJson($url, [...$datos, 'destinatario' => 'otro@universidad.edu.pe'])->assertConflict();
        Http::assertSentCount(1);
    }

    public function test_sending_requires_responsibility_actual_sender_scope_and_an_available_account(): void
    {
        [$id, $alcance, $doc] = $this->abrir();
        $url = '/api/v1/fiscalizacion/procesos/'.$id.'/correos';
        $datos = $this->datosCorreo($doc);
        $this->postJson($url, $datos)->assertConflict();
        $this->conectarCorreo();
        $this->postJson($url, [...$datos, 'remitente' => 'falso@entidad.gob.pe'])->assertUnprocessable();
        $fuera = $this->documento();
        $this->postJson($url, [...$datos, 'documento_id' => $fuera->id])->assertUnprocessable();
        $this->actuarComo();
        $this->postJson($url, $datos)->assertForbidden();
        Http::assertNothingSent();
        $this->assertDatabaseCount('fiscalizacion_correos', 0);
        $this->assertDatabaseCount('solicitudes_fiscalizacion', 0);
    }

    public function test_concurrent_repeat_is_rejected_without_holding_database_locks_during_the_network_call(): void
    {
        $this->conectarCorreo();
        [$id, $alcance, $doc] = $this->abrir();
        $url = '/api/v1/fiscalizacion/procesos/'.$id.'/correos';
        $datos = $this->datosCorreo($doc);
        $nivel = DB::transactionLevel();
        Http::fake(function () use ($url, $datos, $nivel) {
            $this->assertSame($nivel, DB::transactionLevel());
            $this->postJson($url, $datos)->assertConflict();

            return Http::response(['id' => 'mensaje-unico']);
        });
        $this->postJson($url, $datos)->assertCreated();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('solicitudes_fiscalizacion', 1);
    }

    public function test_uncertain_send_is_not_reported_as_sent_and_never_retries_automatically(): void
    {
        $this->conectarCorreo();
        [$id, $alcance, $doc] = $this->abrir();
        $url = '/api/v1/fiscalizacion/procesos/'.$id.'/correos';
        $datos = $this->datosCorreo($doc);
        $intentos = 0;
        Http::fake(function () use (&$intentos) {
            $intentos++;
            throw new ConnectionException('Error token-secreto-prueba');
        });
        $this->postJson($url, $datos)->assertStatus(502)->assertDontSee('token-secreto-prueba');
        $this->postJson($url, $datos)->assertConflict();
        $this->postJson($url, [...$datos, 'clave_envio' => (string) Str::uuid()])->assertConflict();
        $this->assertSame(1, $intentos);
        $this->assertDatabaseHas('fiscalizacion_correos', ['id' => $datos['clave_envio'], 'estado' => 'no_confirmado']);
        $this->assertDatabaseCount('solicitudes_fiscalizacion', 0);
    }

    public function test_confirmed_provider_message_can_be_reconciled_without_sending_again(): void
    {
        $this->conectarCorreo();
        [$id, $alcance, $doc] = $this->abrir();
        $datos = $this->datosCorreo($doc);
        $contenido = collect($datos)->except('clave_envio')->map(fn ($valor) => trim($valor))->all();
        DB::table('fiscalizacion_correos')->insert([
            'id' => $datos['clave_envio'], 'proceso_id' => $id, 'documento_id' => $doc->id, 'registrado_por' => $this->responsable->id,
            'hash_contenido' => hash('sha256', json_encode($contenido)), 'estado' => 'en_curso', 'remitente' => $datos['remitente'],
            'destinatario' => $datos['destinatario'], 'asunto' => $datos['asunto'], 'cuerpo' => $datos['cuerpo'], 'proveedor' => 'gmail',
            'mensaje_id' => 'confirmado-previamente', 'enviado_en' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/correos', [...$datos, 'solo_conciliar' => true])->assertCreated()->assertJsonPath('data.estado', 'enviado');
        Http::assertNothingSent();
        $this->assertDatabaseCount('solicitudes_fiscalizacion', 1);
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/correos', [...$datos, 'clave_envio' => (string) Str::uuid(), 'solo_conciliar' => true])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_configured_smtp_can_send_without_gmail_or_exposing_credentials(): void
    {
        config(['mail.default' => 'smtp', 'mail.from.address' => 'rrhh@entidad.gob.pe', 'mail.reply_to.address' => 'respuestas@entidad.gob.pe']);
        [$id, $alcance, $doc] = $this->abrir();
        $datos = $this->datosCorreo($doc);
        Mail::shouldReceive('raw')->once()->andReturnUsing(function (string $cuerpo, \Closure $configurar) use ($datos) {
            $this->assertSame($datos['cuerpo'], $cuerpo);
            $mail = new Message(new Email);
            $configurar($mail);
            $this->assertSame($datos['destinatario'], $mail->getSymfonyMessage()->getTo()[0]->getAddress());
            $this->assertSame($datos['asunto'], $mail->getSymfonyMessage()->getSubject());
            $sent = \Mockery::mock(SentMessage::class);
            $sent->shouldReceive('getMessageId')->andReturn('smtp-prueba@entidad.gob.pe');

            return $sent;
        });
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/correos', $datos)->assertCreated()->assertJsonPath('data.proveedor', 'smtp');
        Http::assertNothingSent();
    }

    public function test_closed_processes_invalid_addresses_and_header_injection_never_send(): void
    {
        $this->conectarCorreo();
        [$id, $alcance, $doc] = $this->abrir();
        $datos = $this->datosCorreo($doc);
        $url = '/api/v1/fiscalizacion/procesos/'.$id.'/correos';
        $this->postJson($url, [...$datos, 'destinatario' => 'correo-invalido'])->assertUnprocessable();
        $this->postJson($url, [...$datos, 'asunto' => "Asunto\r\nBcc: otro@ejemplo.test"])->assertUnprocessable();
        DB::table('procesos_fiscalizacion')->where('id', $id)->update(['estado' => 'cerrado']);
        $this->postJson($url, $datos)->assertConflict();
        Http::assertNothingSent();
        $this->assertDatabaseCount('fiscalizacion_correos', 0);
    }

    public function test_explicit_provider_rejection_allows_a_new_manual_attempt_after_correction(): void
    {
        $this->conectarCorreo();
        [$id, $alcance, $doc] = $this->abrir();
        $datos = $this->datosCorreo($doc);
        $url = '/api/v1/fiscalizacion/procesos/'.$id.'/correos';
        Http::fake(['gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::sequence()->push(['error' => 'No mostrar secreto'], 403)->push(['id' => 'segundo-intento-confirmado'])]);
        $this->postJson($url, $datos)->assertConflict()->assertDontSee('No mostrar secreto');
        $this->assertDatabaseHas('fiscalizacion_correos', ['id' => $datos['clave_envio'], 'estado' => 'error']);
        $this->assertDatabaseCount('solicitudes_fiscalizacion', 0);
        $this->postJson($url, [...$datos, 'clave_envio' => (string) Str::uuid()])->assertCreated();
        Http::assertSentCount(2);
        $this->assertDatabaseCount('solicitudes_fiscalizacion', 1);
    }

    public function test_opening_snapshots_document_and_preserves_audited_scope(): void
    {
        [$id, $alcance, $doc] = $this->abrir();
        $this->assertDatabaseHas('fiscalizacion_documentos', ['id' => $alcance, 'documento_id' => $doc->id, 'hash_sha256' => $doc->archivo->hash_sha256]);
        $this->assertDatabaseHas('auditoria_eventos', ['entidad_id' => $id, 'accion' => 'fiscalizacion.apertura', 'actor_usuario_id' => $this->responsable->id]);
        $this->getJson('/api/v1/fiscalizacion/procesos')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('resumen.abierto', 1);
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/documentos', ['documento_id' => $doc->id])->assertUnprocessable();
    }

    public function test_foreign_legajo_documents_cannot_be_included_or_used_as_evidence(): void
    {
        [$id, $alcance] = $this->abrir();
        $extranjero = $this->documento(Legajo::factory()->create());
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/documentos', ['documento_id' => $extranjero->id])->assertNotFound();
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/documentos/'.$alcance.'/evaluar', [
            'resultado' => 'conforme', 'conclusion' => 'Datos confirmados.', 'evidencia_documento_id' => $extranjero->id,
        ])->assertNotFound();
    }

    public function test_integrity_check_does_not_approve_document_and_repeated_requests_are_deduplicated(): void
    {
        [$id, $alcance, $doc] = $this->abrir();
        $url = '/api/v1/fiscalizacion/procesos/'.$id.'/verificar';
        $data = ['fuente' => 'integridad', 'alcances' => [$alcance]];
        $this->postJson($url, $data)->assertOk()->assertJsonPath('data.0.resultado_fuente', 'integridad_coincide');
        $this->assertFalse($doc->fresh()->fiscalizado);
        $this->assertDatabaseHas('fiscalizacion_documentos', ['id' => $alcance, 'resultado' => 'pendiente']);
        $this->postJson($url, $data)->assertConflict();
        $this->assertDatabaseCount('fiscalizacion_verificaciones', 1);
    }

    public function test_tampered_file_is_detected_and_not_sent_to_signature_provider(): void
    {
        [$id, $alcance, $doc] = $this->abrir();
        Storage::disk(config('legajo.disco_documentos'))->put($doc->archivo->storage_path, 'contenido alterado');
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/verificar', ['fuente' => 'firma_peru', 'alcances' => [$alcance]])
            ->assertOk()->assertJsonPath('data.0.resultado_fuente', 'integridad_no_coincide');
        Http::assertNothingSent();
    }

    public function test_signature_service_remains_unconfigured_without_institutional_access(): void
    {
        [$id, $alcance] = $this->abrir();
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/verificar', ['fuente' => 'firma_peru', 'alcances' => [$alcance]])
            ->assertOk()->assertJsonPath('data.0.estado_tecnico', 'no_configurado');
        Http::assertNothingSent();
    }

    public function test_signature_connector_uses_documented_protocol_and_never_exposes_credential(): void
    {
        config(['fiscalizacion.firma_peru.url' => 'http://validador.interno/validador', 'fiscalizacion.firma_peru.credential' => 'secreto-prueba', 'fiscalizacion.firma_peru.institucion' => 'unica']);
        $multipart = '';
        Http::fake(['validador.interno/*' => function ($request) use (&$multipart) {
            $multipart = $request->body();

            return Http::response(['result' => 'RESULTADO VÁLIDO', 'validationDate' => '01/01/2026', 'listSignatures' => [['signer' => 'Emisor', 'status' => 'VÁLIDO']]]);
        }]);
        [$id, $alcance, $doc] = $this->abrir();
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/verificar', ['fuente' => 'firma_peru', 'alcances' => [$alcance]])
            ->assertOk()->assertJsonPath('data.0.resultado_fuente', 'firma_valida')->assertDontSee('secreto-prueba');
        Http::assertSent(fn ($r) => $r->url() === 'http://validador.interno/validador/api/validation');
        $this->assertStringContainsString('secreto-prueba', $multipart);
        $this->assertStringContainsString('{"documentExtension":"pdf"}', $multipart);
        $this->assertStringContainsString('name="signed"', $multipart);
        $this->assertFalse($doc->fresh()->fiscalizado);
        $this->assertStringNotContainsString('secreto-prueba', DB::table('fiscalizacion_verificaciones')->value('detalle'));
        $this->assertStringNotContainsString('secreto-prueba', AuditoriaEvento::query()->where('accion', 'fiscalizacion.consulta_completada')->value('datos_despues'));
    }

    public function test_signature_connection_failure_remains_a_technical_error(): void
    {
        config(['fiscalizacion.firma_peru.url' => 'http://validador.interno/validador', 'fiscalizacion.firma_peru.credential' => 'secreto-prueba', 'fiscalizacion.firma_peru.institucion' => 'unica']);
        Http::fake(['validador.interno/*' => Http::response(['error' => 'secreto-prueba'], 503)]);
        [$id, $alcance] = $this->abrir();
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/verificar', ['fuente' => 'firma_peru', 'alcances' => [$alcance]])
            ->assertOk()->assertJsonPath('data.0.estado_tecnico', 'no_disponible')->assertDontSee('secreto-prueba');
        $this->assertDatabaseHas('fiscalizacion_documentos', ['id' => $alcance, 'resultado' => 'pendiente']);
    }

    public function test_service_cannot_be_used_for_another_institution(): void
    {
        config(['fiscalizacion.firma_peru.url' => 'http://validador.interno/validador', 'fiscalizacion.firma_peru.credential' => 'secreto', 'fiscalizacion.firma_peru.institucion' => 'otra']);
        $this->assertFalse(VerificacionFiscalizacion::firmaConfigurada());
    }

    public function test_delegated_review_requires_all_evaluations_and_separate_authorized_reviewer(): void
    {
        [$id, $alcance, $doc] = $this->abrir();
        $evidencia = $this->documento();
        $revisor = $this->asignarRol(Usuario::factory()->create(), 'revisor_fiscalizacion')->refresh();
        $data = ['revisor_id' => $revisor->id, 'informe_documento_id' => $evidencia->id, 'conclusiones' => 'Informe técnico de fiscalización.'];
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/presentar', $data)->assertUnprocessable();
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/documentos/'.$alcance.'/evaluar', ['resultado' => 'conforme', 'conclusion' => 'El emisor confirma los datos.', 'evidencia_documento_id' => $evidencia->id])->assertOk();
        $this->assertFalse($doc->fresh()->fiscalizado);
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/presentar', [...$data, 'revisor_id' => $this->responsable->id])->assertUnprocessable();
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/presentar', $data)->assertOk();
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/revisar', ['decision' => 'aprobar', 'observaciones' => 'Conforme'])->assertForbidden();
        Sanctum::actingAs($revisor);
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/revisar', ['decision' => 'aprobar', 'observaciones' => 'Revisión independiente conforme.'])->assertOk();
        $this->assertDatabaseHas('procesos_fiscalizacion', ['id' => $id, 'estado' => 'cerrado']);
        $this->assertDatabaseHas('documentos_usuario', ['id' => $doc->id, 'estado' => 'aceptado', 'fiscalizado' => true, 'resultado_fiscalizacion' => 'conforme', 'proceso_fiscalizacion_id' => $id]);
        Sanctum::actingAs($this->responsable);
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/documentos/'.$alcance.'/evaluar', ['resultado' => 'observado', 'conclusion' => 'Cambiar', 'evidencia_documento_id' => $evidencia->id])->assertConflict();
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/derivaciones', ['area' => 'Asesoría jurídica', 'motivo' => 'Seguimiento del informe', 'referencia' => 'OF-001', 'documento_id' => $evidencia->id, 'fecha_envio' => now('America/Lima')->toDateString()])->assertCreated();
        $derivacion = DB::table('fiscalizacion_derivaciones')->first();
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/derivaciones/'.$derivacion->id.'/cargo', ['fecha_cargo' => now('America/Lima')->toDateString(), 'documento_id' => $evidencia->id])->assertOk();
    }

    public function test_reviewer_can_return_report_and_responsible_can_correct_with_history(): void
    {
        [$id, $alcance] = $this->abrir();
        $evidencia = $this->documento();
        $revisor = $this->asignarRol(Usuario::factory()->create(), 'revisor_fiscalizacion')->refresh();
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/documentos/'.$alcance.'/evaluar', ['resultado' => 'no_concluyente', 'conclusion' => 'No hubo respuesta del emisor.', 'evidencia_documento_id' => $evidencia->id])->assertOk();
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/presentar', ['revisor_id' => $revisor->id, 'informe_documento_id' => $evidencia->id, 'conclusiones' => 'Limitaciones sustentadas.'])->assertOk();
        Sanctum::actingAs($revisor);
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/revisar', ['decision' => 'devolver', 'observaciones' => 'Precisar las reiteraciones efectuadas.'])->assertOk();
        $this->assertDatabaseHas('procesos_fiscalizacion', ['id' => $id, 'estado' => 'en_verificacion']);
        Sanctum::actingAs($this->responsable);
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/documentos/'.$alcance.'/evaluar', ['resultado' => 'no_concluyente', 'conclusion' => 'Se documentaron dos reiteraciones.', 'evidencia_documento_id' => $evidencia->id])->assertOk();
        $this->assertSame(2, AuditoriaEvento::query()->where('entidad_id', $id)->where('accion', 'fiscalizacion.evaluacion_documento')->count());
    }

    public function test_sender_response_is_preserved_and_missing_response_requires_non_conclusive_evaluation(): void
    {
        [$id, $alcance, $doc] = $this->abrir();
        $evidencia = $this->documento();
        $base = '/api/v1/fiscalizacion/procesos/'.$id;
        $consulta = $this->postJson($base.'/consultas', ['documento_id' => $doc->id, 'entidad_consultada' => 'Universidad emisora', 'medio' => 'oficio', 'numero_oficio' => 'OF-001', 'fecha_envio' => '2026-01-03', 'evidencia_documento_id' => $evidencia->id, 'fuente' => 'emisor'])->assertCreated()->json('data.id');
        $respuesta = ['fecha_respuesta' => '2026-01-04', 'documento_respuesta_id' => $evidencia->id, 'resultado' => 'conforme', 'descripcion_hallazgo' => 'El registro corresponde al titular y al grado.'];
        $this->postJson($base.'/consultas/'.$consulta.'/respuesta', $respuesta)->assertOk();
        $this->postJson($base.'/consultas/'.$consulta.'/respuesta', $respuesta)->assertConflict();
        $this->assertDatabaseHas('fiscalizacion_documentos', ['id' => $alcance, 'resultado' => 'pendiente']);
    }

    public function test_unauthorized_roles_and_legacy_generic_writes_cannot_bypass_workflow(): void
    {
        $this->actuarComo(codigo: 'asistente_legajo');
        $this->getJson('/api/v1/fiscalizacion/procesos')->assertForbidden();
        $this->postJson('/api/v1/fiscalizacion/procesos', [])->assertForbidden();
        Sanctum::actingAs($this->responsable);
        $this->postJson('/api/v1/procesos-fiscalizacion', [])->assertStatus(405);
        $this->postJson('/api/v1/solicitudes-fiscalizacion', [])->assertStatus(405);
    }

    public function test_sensitive_process_is_hidden_from_reviewer_without_sensitive_access(): void
    {
        [$id] = $this->abrir($this->documento(seccion: 10));
        $revisor = $this->asignarRol(Usuario::factory()->create(), 'revisor_fiscalizacion')->refresh();
        Sanctum::actingAs($revisor);
        $this->getJson('/api/v1/fiscalizacion/procesos')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/fiscalizacion/procesos/'.$id)->assertNotFound();
    }

    public function test_roles_are_assignable_through_existing_team_management(): void
    {
        $usuario = Usuario::factory()->create();
        $this->postJson('/api/v1/equipo-accesos', ['usuario_id' => $usuario->id, 'perfil' => 'revisor_fiscalizacion', 'vigente_desde' => today()->toDateString(), 'motivo' => 'Revisor autorizado para fiscalización.'])->assertCreated();
        $this->assertTrue($usuario->fresh()->can('fiscalizacion.aprobar'));
        $this->assertFalse($usuario->fresh()->can('fiscalizacion.gestionar'));
    }

    public function test_pending_emitter_response_can_only_close_with_justified_non_conclusive_result(): void
    {
        [$id, $alcance, $doc] = $this->abrir();
        $evidencia = $this->documento();
        $base = '/api/v1/fiscalizacion/procesos/'.$id;
        $this->postJson($base.'/consultas', ['documento_id' => $doc->id, 'entidad_consultada' => 'Universidad', 'medio' => 'oficio', 'numero_oficio' => 'OF-02', 'fecha_envio' => '2026-01-03', 'evidencia_documento_id' => $evidencia->id, 'fuente' => 'emisor'])->assertCreated();
        $revisor = $this->asignarRol(Usuario::factory()->create(), 'revisor_fiscalizacion')->refresh();
        $evaluacion = ['resultado' => 'conforme', 'conclusion' => 'Datos contrastados.', 'evidencia_documento_id' => $evidencia->id];
        $informe = ['revisor_id' => $revisor->id, 'informe_documento_id' => $evidencia->id, 'conclusiones' => 'Informe con limitaciones.'];
        $this->postJson($base.'/documentos/'.$alcance.'/evaluar', $evaluacion)->assertOk();
        $this->postJson($base.'/presentar', $informe)->assertUnprocessable();
        $this->postJson($base.'/documentos/'.$alcance.'/evaluar', [...$evaluacion, 'resultado' => 'no_concluyente', 'conclusion' => 'Sin respuesta del emisor; la constancia acredita la consulta y su reiteración.'])->assertOk();
        $this->postJson($base.'/presentar', $informe)->assertOk();
    }

    public function test_no_authentic_conclusion_preserves_acceptance_but_blocks_using_document_as_report_evidence(): void
    {
        [$id, $alcance, $doc] = $this->abrir();
        $evidencia = $this->documento();
        $base = '/api/v1/fiscalizacion/procesos/'.$id;
        $revisor = $this->asignarRol(Usuario::factory()->create(), 'revisor_fiscalizacion')->refresh();
        $this->postJson($base.'/documentos/'.$alcance.'/evaluar', ['resultado' => 'no_autentico', 'conclusion' => 'El emisor declara que no emitió este documento.', 'evidencia_documento_id' => $evidencia->id])->assertOk();
        $this->postJson($base.'/presentar', ['revisor_id' => $revisor->id, 'informe_documento_id' => $evidencia->id, 'conclusiones' => 'Hallazgo sustentado para derivación a la autoridad competente.'])->assertOk();
        Sanctum::actingAs($revisor);
        $this->postJson($base.'/revisar', ['decision' => 'aprobar', 'observaciones' => 'Sustento revisado.'])->assertOk();
        $actual = $doc->fresh();
        $this->assertSame('aceptado', $actual->estado);
        $this->assertSame('no_autentico', $actual->resultado_fiscalizacion);
        $this->assertFalse(EstadoDocumento::aprobado($actual, $actual->tipoDocumento));
    }

    public function test_missing_or_modified_original_file_prevents_approval(): void
    {
        [$id, $alcance, $doc] = $this->abrir();
        $evidencia = $this->documento();
        $base = '/api/v1/fiscalizacion/procesos/'.$id;
        $revisor = $this->asignarRol(Usuario::factory()->create(), 'revisor_fiscalizacion')->refresh();
        $this->postJson($base.'/documentos/'.$alcance.'/evaluar', ['resultado' => 'conforme', 'conclusion' => 'Verificación sustentada.', 'evidencia_documento_id' => $evidencia->id])->assertOk();
        $this->postJson($base.'/presentar', ['revisor_id' => $revisor->id, 'informe_documento_id' => $evidencia->id, 'conclusiones' => 'Informe completo.'])->assertOk();
        Storage::disk(config('legajo.disco_documentos'))->put($doc->archivo->storage_path, 'archivo alterado después de la evaluación');
        Sanctum::actingAs($revisor);
        $this->postJson($base.'/revisar', ['decision' => 'aprobar', 'observaciones' => 'Revisado.'])->assertConflict();
        $this->assertDatabaseHas('procesos_fiscalizacion', ['id' => $id, 'estado' => 'en_revision']);
        $this->assertFalse($doc->fresh()->fiscalizado);
        $this->postJson($base.'/revisar', ['decision' => 'devolver', 'observaciones' => 'Revisar integridad del archivo.'])->assertOk();
    }

    public function test_sensitive_evidence_also_protects_nested_views_and_audit_access(): void
    {
        [$id, $alcance] = $this->abrir();
        $evidencia = $this->documento(seccion: 10);
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/documentos/'.$alcance.'/evaluar', ['resultado' => 'observado', 'conclusion' => 'Hallazgo sensible de prueba.', 'evidencia_documento_id' => $evidencia->id])->assertOk();
        $lector = $this->asignarRol(Usuario::factory()->create(), 'revisor_fiscalizacion')->refresh();
        Sanctum::actingAs($lector);
        $this->getJson('/api/v1/expedientes/'.$this->legajo->usuario_id.'/procesos-fiscalizacion')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/fiscalizacion/procesos/'.$id)->assertNotFound();
        $this->actuarComo(Usuario::factory()->create(), 'ADMIN');
        // A role with system access but without sensitive access must not see protected events.
        Rol::query()->where('codigo', 'ADMIN')->update(['ve_datos_sensibles' => false]);
        $evento = AuditoriaEvento::query()->where('entidad_id', $id)->where('accion', 'fiscalizacion.evaluacion_documento')->firstOrFail();
        $this->getJson('/api/v1/auditoria-eventos/'.$evento->id)->assertNotFound();
    }

    public function test_another_manager_cannot_record_acts_on_the_assigned_process(): void
    {
        [$id, $alcance] = $this->abrir();
        $evidencia = $this->documento();
        $this->actuarComo(Usuario::factory()->create());
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/documentos/'.$alcance.'/evaluar', ['resultado' => 'conforme', 'conclusion' => 'Intento de otro responsable.', 'evidencia_documento_id' => $evidencia->id])->assertForbidden();
    }

    public function test_internal_consistency_detects_data_gaps_without_marking_authenticity(): void
    {
        [$id, $alcance, $doc] = $this->abrir();
        $doc->update(['emisor' => null, 'fecha_emision' => now('America/Lima')->addDay()->toDateString()]);
        $this->postJson('/api/v1/fiscalizacion/procesos/'.$id.'/verificar', ['fuente' => 'consistencia', 'alcances' => [$alcance]])
            ->assertOk()->assertJsonPath('data.0.estado_tecnico', 'completada')->assertJsonPath('data.0.resultado_fuente', 'datos_con_observaciones')
            ->assertJsonCount(2, 'data.0.detalle.hallazgos');
        $this->assertDatabaseHas('fiscalizacion_documentos', ['id' => $alcance, 'resultado' => 'pendiente']);
        $this->assertFalse($doc->fresh()->fiscalizado);
        Http::assertNothingSent();
    }

    public function test_responsible_can_review_and_close_individually_without_reviewer_permission(): void
    {
        $this->responsable = $this->actuarComo(codigo: 'fiscalizador');
        $this->assertFalse($this->responsable->can('fiscalizacion.aprobar'));
        [$id, $alcance, $doc] = $this->abrir();
        $evidencia = $this->documento();
        $base = '/api/v1/fiscalizacion/procesos/'.$id;
        $this->postJson($base.'/documentos/'.$alcance.'/evaluar', ['resultado' => 'conforme', 'conclusion' => 'Datos confirmados por la entidad.', 'evidencia_documento_id' => $evidencia->id])->assertOk();
        $this->postJson($base.'/presentar', ['modalidad' => 'individual', 'confirmacion' => true, 'informe_documento_id' => $evidencia->id, 'conclusiones' => 'Revisé personalmente todas las evidencias.'])->assertOk();
        $this->assertDatabaseHas('procesos_fiscalizacion', ['id' => $id, 'estado' => 'cerrado', 'revisor_id' => null]);
        $this->assertDatabaseHas('documentos_usuario', ['id' => $doc->id, 'fiscalizado' => true, 'fiscalizado_por' => $this->responsable->id]);
        $this->assertDatabaseHas('auditoria_eventos', ['entidad_id' => $id, 'accion' => 'fiscalizacion.cierre_responsable', 'actor_usuario_id' => $this->responsable->id]);
        $this->getJson($base)->assertOk()->assertJsonPath('cierre_responsable', true)->assertJsonPath('revisor', null);
        $this->postJson($base.'/presentar', ['modalidad' => 'individual', 'confirmacion' => true, 'informe_documento_id' => $evidencia->id, 'conclusiones' => 'Cambiar un cierre anterior.'])->assertConflict();
    }

    public function test_individual_closure_requires_confirmation_and_all_documents_evaluated(): void
    {
        [$id, $alcance] = $this->abrir();
        $evidencia = $this->documento();
        $base = '/api/v1/fiscalizacion/procesos/'.$id;
        $informe = ['modalidad' => 'individual', 'informe_documento_id' => $evidencia->id, 'conclusiones' => 'Informe para cierre individual.'];
        $this->postJson($base.'/presentar', $informe)->assertUnprocessable()->assertJsonValidationErrors('confirmacion');
        $this->postJson($base.'/presentar', [...$informe, 'confirmacion' => false])->assertUnprocessable()->assertJsonValidationErrors('confirmacion');
        $this->postJson($base.'/presentar', [...$informe, 'confirmacion' => true])->assertUnprocessable()->assertJsonValidationErrors('alcance');
        $this->postJson($base.'/documentos/'.$alcance.'/evaluar', ['resultado' => 'conforme', 'conclusion' => 'Sustento revisado.', 'evidencia_documento_id' => $evidencia->id])->assertOk();
        $this->postJson($base.'/presentar', [...$informe, 'confirmacion' => true, 'revisor_id' => $this->responsable->id])->assertUnprocessable()->assertJsonValidationErrors('revisor_id');
        $this->postJson($base.'/presentar', [...$informe, 'modalidad' => 'delegada'])->assertUnprocessable()->assertJsonValidationErrors('revisor_id');
        $this->assertDatabaseHas('procesos_fiscalizacion', ['id' => $id, 'estado' => 'abierto']);
    }

    public function test_individual_closure_rechecks_file_integrity_and_rolls_back_on_failure(): void
    {
        [$id, $alcance, $doc] = $this->abrir();
        $evidencia = $this->documento();
        $base = '/api/v1/fiscalizacion/procesos/'.$id;
        $this->postJson($base.'/documentos/'.$alcance.'/evaluar', ['resultado' => 'conforme', 'conclusion' => 'Verificación documentada.', 'evidencia_documento_id' => $evidencia->id])->assertOk();
        Storage::disk(config('legajo.disco_documentos'))->put($doc->archivo->storage_path, 'archivo alterado');
        $this->postJson($base.'/presentar', ['modalidad' => 'individual', 'confirmacion' => true, 'informe_documento_id' => $evidencia->id, 'conclusiones' => 'Intento de cierre.'])->assertConflict();
        $this->assertDatabaseHas('procesos_fiscalizacion', ['id' => $id, 'estado' => 'abierto', 'informe_documento_id' => null]);
        $this->assertFalse($doc->fresh()->fiscalizado);
        $this->assertDatabaseMissing('auditoria_eventos', ['entidad_id' => $id, 'accion' => 'fiscalizacion.cierre_responsable']);
    }

    public function test_other_manager_cannot_close_individually_and_delegate_cannot_be_bypassed(): void
    {
        [$id, $alcance] = $this->abrir();
        $evidencia = $this->documento();
        $base = '/api/v1/fiscalizacion/procesos/'.$id;
        $this->postJson($base.'/documentos/'.$alcance.'/evaluar', ['resultado' => 'conforme', 'conclusion' => 'Datos verificados.', 'evidencia_documento_id' => $evidencia->id])->assertOk();
        $informe = ['informe_documento_id' => $evidencia->id, 'conclusiones' => 'Informe completo.'];
        $this->actuarComo(Usuario::factory()->create());
        $this->postJson($base.'/presentar', [...$informe, 'modalidad' => 'individual', 'confirmacion' => true])->assertForbidden();
        Sanctum::actingAs($this->responsable);
        $revisor = $this->asignarRol(Usuario::factory()->create(), 'revisor_fiscalizacion')->refresh();
        $this->postJson($base.'/presentar', [...$informe, 'modalidad' => 'delegada', 'revisor_id' => $revisor->id])->assertOk();
        $this->postJson($base.'/presentar', [...$informe, 'modalidad' => 'individual', 'confirmacion' => true])->assertConflict();
        Sanctum::actingAs($revisor);
        $this->postJson($base.'/revisar', ['decision' => 'aprobar', 'observaciones' => 'Revisado por la persona designada.'])->assertOk();
        $this->getJson($base)->assertOk()->assertJsonPath('cierre_responsable', false)->assertJsonPath('revisor.id', $revisor->id);
    }
}
