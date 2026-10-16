<?php

namespace Tests\Unit;

use App\Support\Documentos\ArchivoPdf;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

class ArchivoPdfTest extends TestCase
{
    public function test_counts_plain_and_compressed_page_trees(): void
    {
        $tree = '/Type /Pages /Count 3';
        $this->assertSame(3, ArchivoPdf::paginas(UploadedFile::fake()->createWithContent('plain.pdf', "%PDF-1.4\n".$tree)));
        $this->assertSame(3, ArchivoPdf::paginas($this->compressed($tree)));
    }

    public function test_oversized_expansion_does_not_exhaust_memory_and_next_file_is_readable(): void
    {
        $this->assertNull(ArchivoPdf::paginas($this->compressed(str_repeat('x', 9 * 1024 * 1024))));
        $this->assertSame(2, ArchivoPdf::paginas($this->compressed('/Type /Pages /Count 2')));
    }

    public function test_limits_input_and_number_of_streams(): void
    {
        $this->assertNull(ArchivoPdf::paginas(UploadedFile::fake()->createWithContent('large.pdf', str_repeat('x', 8 * 1024 * 1024 + 1))));
        $content = "%PDF-1.4\n".str_repeat("stream\ninvalid\nendstream\n", 64)."stream\n".gzcompress('/Type /Pages /Count 2')."\nendstream";
        $this->assertNull(ArchivoPdf::paginas(UploadedFile::fake()->createWithContent('streams.pdf', $content)));
    }

    public function test_malformed_stream_has_no_page_count(): void
    {
        $this->assertNull(ArchivoPdf::paginas(UploadedFile::fake()->createWithContent('bad.pdf', "%PDF-1.4\nstream\nbroken\nendstream")));
    }

    private function compressed(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('compressed.pdf', "%PDF-1.4\nstream\n".gzcompress($content)."\nendstream");
    }
}
