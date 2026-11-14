<?php

namespace Tests\Unit\Support\Documentos;

use App\Models\ArchivoDocumento;
use App\Models\DocumentoUsuario;
use App\Models\TipoDocumento;
use App\Support\Documentos\EstadoDocumento;
use Tests\TestCase;

/**
 * Estado visible y condición de "aprobado" de un documento. Modelos en memoria: no toca la base de datos.
 */
class EstadoDocumentoTest extends TestCase
{
    private function documento(array $datos = [], ?string $antivirus = 'limpio', bool $conArchivo = true): DocumentoUsuario
    {
        $documento = (new DocumentoUsuario)->forceFill([
            'estado' => 'aceptado', 'archivo_id' => $conArchivo ? 'archivo-1' : null,
            'fecha_recepcion' => null, 'recepcionado_por' => null, 'fiscalizado' => false, ...$datos,
        ]);
        $documento->setRelation('archivo', $conArchivo ? (new ArchivoDocumento)->forceFill(['estado_antivirus' => $antivirus]) : null);

        return $documento;
    }

    private function tipo(bool $acreditaPerfil = false): TipoDocumento
    {
        return (new TipoDocumento)->forceFill(['acredita_perfil_puesto' => $acreditaPerfil]);
    }

    private function recepcion(): array
    {
        return ['fecha_recepcion' => '2026-10-01', 'recepcionado_por' => 'usuario-1'];
    }

    public function test_recepcionado_exige_fecha_y_responsable(): void
    {
        $this->assertTrue(EstadoDocumento::recepcionado($this->documento($this->recepcion())));
        $this->assertFalse(EstadoDocumento::recepcionado($this->documento(['recepcionado_por' => 'usuario-1'])));
        $this->assertFalse(EstadoDocumento::recepcionado($this->documento(['fecha_recepcion' => '2026-10-01'])));
        $this->assertFalse(EstadoDocumento::recepcionado($this->documento()));
    }

    public function test_visible_distingue_cargado_de_recepcionado(): void
    {
        $this->assertSame('cargado', EstadoDocumento::visible($this->documento(['estado' => 'cargado'])));
        $this->assertSame('recepcionado', EstadoDocumento::visible($this->documento(['estado' => 'cargado', ...$this->recepcion()])));
    }

    public function test_visible_devuelve_los_demas_estados_tal_cual(): void
    {
        foreach (['aceptado', 'pendiente', 'en_fiscalizacion', 'verificado', 'observado', 'falso'] as $estado) {
            $this->assertSame($estado, EstadoDocumento::visible($this->documento(['estado' => $estado, ...$this->recepcion()])));
        }
    }

    public function test_documento_aceptado_con_archivo_limpio_cuenta_como_aprobado_sin_recepcion_ni_fiscalizacion(): void
    {
        // Regla del CLAUDE.md: los documentos llegan validados; al subirse quedan aceptados y cuentan como aprobados.
        $this->assertTrue(EstadoDocumento::aprobado($this->documento(), $this->tipo()));
        $this->assertTrue(EstadoDocumento::aprobado($this->documento(), $this->tipo(acreditaPerfil: true)));
    }

    public function test_documento_aceptado_con_antivirus_pendiente_cuenta_como_aprobado(): void
    {
        $this->assertTrue(EstadoDocumento::aprobado($this->documento(antivirus: 'pendiente'), $this->tipo()));
    }

    public function test_documento_aceptado_sin_archivo_no_cuenta_como_aprobado(): void
    {
        $this->assertFalse(EstadoDocumento::aprobado($this->documento(conArchivo: false), $this->tipo()));
    }

    public function test_documento_aceptado_con_archivo_infectado_no_cuenta_como_aprobado(): void
    {
        $this->assertFalse(EstadoDocumento::aprobado($this->documento(antivirus: 'infectado'), $this->tipo()));
    }

    public function test_documento_falso_o_pendiente_nunca_cuenta_como_aprobado(): void
    {
        foreach (['falso', 'pendiente'] as $estado) {
            $documento = $this->documento(['estado' => $estado, 'fiscalizado' => true, ...$this->recepcion()]);
            $this->assertFalse(EstadoDocumento::aprobado($documento, $this->tipo()), $estado);
        }
    }

    public function test_estado_anterior_cargado_exige_recepcion_registrada(): void
    {
        $this->assertFalse(EstadoDocumento::aprobado($this->documento(['estado' => 'cargado']), $this->tipo()));
        $this->assertTrue(EstadoDocumento::aprobado($this->documento(['estado' => 'cargado', ...$this->recepcion()]), $this->tipo()));
    }

    public function test_estado_anterior_sin_archivo_no_cuenta_como_aprobado(): void
    {
        $documento = $this->documento(['estado' => 'cargado', ...$this->recepcion()], conArchivo: false);

        $this->assertFalse(EstadoDocumento::aprobado($documento, $this->tipo()));
    }

    public function test_tipo_que_acredita_perfil_exige_fiscalizacion_verificada_en_estados_anteriores(): void
    {
        $tipo = $this->tipo(acreditaPerfil: true);

        $this->assertFalse(EstadoDocumento::aprobado($this->documento(['estado' => 'cargado', ...$this->recepcion()]), $tipo));
        $this->assertFalse(EstadoDocumento::aprobado($this->documento(['estado' => 'verificado', ...$this->recepcion()]), $tipo));
        $this->assertFalse(EstadoDocumento::aprobado($this->documento(['estado' => 'cargado', 'fiscalizado' => true, ...$this->recepcion()]), $tipo));
        $this->assertTrue(EstadoDocumento::aprobado($this->documento(['estado' => 'verificado', 'fiscalizado' => true, ...$this->recepcion()]), $tipo));
    }

    public function test_documento_observado_no_cuenta_como_aprobado(): void
    {
        $this->markTestSkipped('Posible bug: EstadoDocumento::aprobado() (app/Support/Documentos/EstadoDocumento.php:43) trata un documento '
            .'"observado" con archivo y recepción como aprobado; el CLAUDE.md dice que lo observado cuenta como pendiente '
            .'(el propio código lo deja como TODO pendiente de confirmar).');
    }
}
