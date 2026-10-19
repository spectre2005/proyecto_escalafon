<?php

namespace Tests\Feature;

use App\Support\Documentos\ArchivoPdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ArchivoPdfTest extends TestCase
{
    public function test_counts_real_pages_with_compression_and_rotation(): void
    {
        $pdf = UploadedFile::fake()->createWithContent('comprimido-rotado.pdf', self::pdfValido(3, comprimido: true, rotacion: 90));
        $this->assertSame(3, ArchivoPdf::paginas($pdf));
    }

    public function test_ignores_fake_page_counts_inside_content_streams(): void
    {
        $pdf = UploadedFile::fake()->createWithContent('conteo-real.pdf', self::pdfValido(2, texto: "q Q\n% /Type /Pages /Count 9999"));
        $this->assertSame(2, ArchivoPdf::paginas($pdf));
    }

    public function test_invalid_pdf_is_rejected_instead_of_inventing_a_page_count(): void
    {
        $this->expectException(ValidationException::class);
        ArchivoPdf::paginas(UploadedFile::fake()->createWithContent('incompleto.pdf', "%PDF-1.4\n%%EOF"));
    }

    public function test_missing_poppler_is_an_operational_error_and_has_no_regex_fallback(): void
    {
        config(['documentos.pdfinfo_binario' => '/no-existe/pdfinfo']);
        try {
            ArchivoPdf::paginas(UploadedFile::fake()->createWithContent('valido.pdf', self::pdfValido()));
            $this->fail('Debió informar que falta Poppler.');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
        }
    }
}
