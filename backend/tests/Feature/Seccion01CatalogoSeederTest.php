<?php

namespace Tests\Feature;

use App\Models\DocumentoUsuario;
use App\Models\Familiar;
use App\Models\Legajo;
use App\Models\SubseccionLegajo;
use App\Models\TipoDocumento;
use Database\Seeders\Seccion01CatalogoSeeder;
use Database\Seeders\Seccion01DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Seccion01CatalogoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_types_of_annex_01_with_their_data_forms_repeatably(): void
    {
        $this->seed(Seccion01CatalogoSeeder::class);
        $this->seed(Seccion01CatalogoSeeder::class);

        // La directiva no define subsecciones para la sección 01.
        $this->assertSame(0, SubseccionLegajo::query()->where('seccion_legajo_id', 1)->count());
        $tipos = TipoDocumento::query()->where('seccion_legajo_id', 1)->orderBy('orden')->get();
        $this->assertCount(15, $tipos);
        $this->assertTrue($tipos->every(fn (TipoDocumento $tipo): bool => $tipo->activo && $tipo->subseccion_legajo_id === null
            && $tipo->aplica_carrera && $tipo->aplica_plazo_determinado && $tipo->aplica_designado && ! $tipo->requiere_derechohabientes));
        $this->assertSame([
            'S01_A_FICHA_DATOS' => null,
            'S01_B_DOCUMENTO_IDENTIDAD' => null,
            'S01_C_ANTECEDENTES_PENALES' => 'declaraciones_verificaciones',
            'S01_D_ANTECEDENTES_POLICIALES' => 'declaraciones_verificaciones',
            'S01_E_DJ_BIENES_RENTAS' => 'declaraciones_verificaciones',
            'S01_F_DJ_NO_IMPEDIMENTOS_31419' => 'declaraciones_verificaciones',
            'S01_G_PARTIDA_MATRIMONIO_CONCUBINATO' => null,
            'S01_H_IMPEDIMENTOS_INHABILITACIONES' => 'declaraciones_verificaciones',
            'S01_I_DNI_CONYUGE_CONCUBINO' => 'familiares',
            'S01_J_DNI_HIJOS_MENORES' => 'familiares',
            'S01_K_CERTIFICADO_DISCAPACIDAD' => 'certificados_discapacidad',
            'S01_L_OTRAS_DJ' => 'declaraciones_verificaciones',
            'S01_OTROS_UNIVERSIDAD' => null,
            'S01_X_ANTECEDENTES_JUDICIALES' => 'declaraciones_verificaciones',
            'S01_X_DJ_INTERESES' => 'declaraciones_verificaciones',
        ], $tipos->pluck('formulario_datos', 'codigo')->all());

        $this->assertSame(['S01_E_DJ_BIENES_RENTAS'], $tipos->where('requiere_djbr', true)->pluck('codigo')->values()->all());
        $this->assertSame(['S01_E_DJ_BIENES_RENTAS'], $tipos->where('es_dato_sensible', true)->pluck('codigo')->values()->all());
        $this->assertSame(['S01_K_CERTIFICADO_DISCAPACIDAD'], $tipos->where('requiere_discapacidad', true)->pluck('codigo')->values()->all());
        $this->assertSame(['S01_K_CERTIFICADO_DISCAPACIDAD'], $tipos->where('es_dato_salud', true)->pluck('codigo')->values()->all());
        $this->assertSame(['S01_OTROS_UNIVERSIDAD'], $tipos->where('es_hook_universidad', true)->pluck('codigo')->values()->all());
        // f no se ata a ninguna bandera; los dos del Anexo 05 son opcionales.
        $f = $tipos->firstWhere('codigo', 'S01_F_DJ_NO_IMPEDIMENTOS_31419');
        $this->assertFalse($f->tiene_banderas);
        $this->assertFalse($tipos->firstWhere('codigo', 'S01_X_ANTECEDENTES_JUDICIALES')->obligatorio);
        $this->assertFalse($tipos->firstWhere('codigo', 'S01_X_DJ_INTERESES')->obligatorio);
        $this->assertSame('Ficha de datos de el/la servidor/a civil', $tipos->first()->nombre);
        // g, i y j son obligatorios: su exigencia se calcula por familiar registrado.
        $this->assertSame(['S01_F_DJ_NO_IMPEDIMENTOS_31419', 'S01_L_OTRAS_DJ', 'S01_OTROS_UNIVERSIDAD', 'S01_X_ANTECEDENTES_JUDICIALES', 'S01_X_DJ_INTERESES'],
            $tipos->where('obligatorio', false)->pluck('codigo')->values()->all());
    }

    public function test_it_does_not_alter_types_with_documents(): void
    {
        $this->seed(Seccion01CatalogoSeeder::class);
        $legajo = Legajo::factory()->create();
        $documentar = fn (TipoDocumento $tipo) => DocumentoUsuario::factory()->create(['tipo_documento_id' => $tipo->id, 'legajo_id' => $legajo->id, 'usuario_id' => $legajo->usuario_id, 'seccion_legajo_id' => 1]);

        // Con documentos y otros valores: no se modifica.
        $ficha = TipoDocumento::query()->where('codigo', 'S01_A_FICHA_DATOS')->firstOrFail();
        $ficha->update(['obligatorio' => false, 'descripcion' => 'Valor de la base']);
        $documentar($ficha);
        // Con documentos y otro nombre: se desactiva sin tocarlo y se crea uno bien.
        $penales = TipoDocumento::query()->where('codigo', 'S01_C_ANTECEDENTES_PENALES')->firstOrFail();
        $penales->update(['nombre' => 'Nombre anterior']);
        $documentar($penales);
        // Sin documentos: se corrige.
        $policiales = TipoDocumento::query()->where('codigo', 'S01_D_ANTECEDENTES_POLICIALES')->firstOrFail();
        $policiales->update(['obligatorio' => false]);
        // formulario_datos distinto ya registrado: no se pisa.
        $otras = TipoDocumento::query()->where('codigo', 'S01_L_OTRAS_DJ')->firstOrFail();
        $otras->update(['formulario_datos' => 'otra_tabla']);
        $extra = TipoDocumento::factory()->create(['seccion_legajo_id' => 1, 'codigo' => 'NO_DIRECTIVA']);

        $this->seed(Seccion01CatalogoSeeder::class);
        $this->seed(Seccion01CatalogoSeeder::class);

        $ficha->refresh();
        $this->assertTrue($ficha->activo);
        $this->assertFalse($ficha->obligatorio);
        $this->assertSame('Valor de la base', $ficha->descripcion);
        $this->assertFalse($penales->refresh()->activo);
        $this->assertSame('Nombre anterior', $penales->nombre);
        $this->assertSame('Declaración jurada o Certificado de antecedentes penales',
            TipoDocumento::query()->where('codigo', 'S01_C_ANTECEDENTES_PENALES')->where('activo', true)->sole()->nombre);
        $this->assertTrue($policiales->refresh()->obligatorio);
        $this->assertSame('otra_tabla', $otras->refresh()->formulario_datos);
        $this->assertFalse($extra->refresh()->activo);
        $this->assertModelExists($extra);
        $this->assertSame(15, TipoDocumento::query()->where('seccion_legajo_id', 1)->where('activo', true)->count());
    }

    public function test_demo_seeder_is_repeatable(): void
    {
        $this->seed(Seccion01DemoSeeder::class);
        $this->seed(Seccion01DemoSeeder::class);

        $this->assertSame(1, Legajo::query()->where('numero_legajo', 'DEMO-S01-001')->count());
        $this->assertSame(4, Familiar::query()->count());
        $this->assertDatabaseCount('declaraciones_verificaciones', 1);
        $this->assertSame(0, DocumentoUsuario::query()->count());
    }
}
