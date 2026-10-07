<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FotoPersonalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actuarComo();
    }

    public function test_photo_is_loaded_from_the_database_path(): void
    {
        Storage::fake('personal_photos');
        $person = Usuario::factory()->create(['foto_path' => 'retratos/foto.jpg']);
        Storage::disk('personal_photos')->put($person->foto_path, 'portrait');

        $this->assertSame('portrait', $this->get('/api/v1/expedientes/'.$person->id.'/foto')->assertOk()->streamedContent());

        $person->update(['foto_path' => 'retratos/missing.jpg']);
        $this->get('/api/v1/expedientes/'.$person->id.'/foto')->assertNoContent();
    }

    public function test_migration_registers_existing_photos(): void
    {
        Storage::fake('personal_photos');
        $person = Usuario::factory()->create();
        $other = Usuario::factory()->create();
        Storage::disk('personal_photos')->put($person->id, 'legacy portrait');
        $migration = require database_path('migrations/2026_10_01_121852_add_foto_path_to_usuarios_table.php');
        $migration->down();
        $migration->up();

        $this->assertSame($person->id, $person->refresh()->foto_path);
        $this->assertNull($other->refresh()->foto_path);
        $this->assertSame('legacy portrait', $this->get('/api/v1/expedientes/'.$person->id.'/foto')->assertOk()->streamedContent());
    }

    public function test_photo_is_persisted_replaced_and_isolated_by_person(): void
    {
        Storage::fake('personal_photos');
        $person = Usuario::factory()->create(['numero_doc_identidad' => '00123456']);
        $other = Usuario::factory()->create();
        $url = '/api/v1/expedientes/'.$person->id.'/foto';
        $this->get($url)->assertNoContent();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        for ($i = 0; $i < 2; $i++) {
            $previousPath = $person->foto_path;
            $file = UploadedFile::fake()->image('foto.png', $i === 0 ? 1200 : 300, $i === 0 ? 400 : 1200);
            $this->post($url, ['foto' => $file], ['Accept' => 'application/json'])->assertOk();
            $person->refresh();
            $this->assertSame('img_00123456.jpg', basename($person->foto_path));
            if ($previousPath) {
                Storage::disk('personal_photos')->assertMissing($previousPath);
            }
            Storage::disk('personal_photos')->assertExists($person->foto_path);
            $this->assertDatabaseHas('usuarios', ['id' => $person->id, 'foto_path' => $person->foto_path]);
            $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
            $dimensions = getimagesizefromstring(Storage::disk('personal_photos')->get($person->refresh()->foto_path));
            $this->assertSame(600, $dimensions[0]);
            $this->assertSame(800, $dimensions[1]);
        }
        $this->assertCount(1, Storage::disk('personal_photos')->allFiles());
        $this->get('/api/v1/expedientes/'.$other->id.'/foto')->assertNoContent();
        $this->postJson($url, ['foto' => UploadedFile::fake()->create('bad.txt', 1, 'text/plain')])->assertUnprocessable();
        $this->postJson($url, ['foto' => UploadedFile::fake()->createWithContent('large.png', $png)->size(2049)])->assertUnprocessable();
    }

    public function test_supported_formats_are_saved_as_standard_portraits(): void
    {
        Storage::fake('personal_photos');
        $person = Usuario::factory()->create();
        $url = '/api/v1/expedientes/'.$person->id.'/foto';

        foreach (['jpg', 'png', 'webp'] as $extension) {
            $this->post($url, ['foto' => UploadedFile::fake()->image('foto.'.$extension, 900, 1200)], ['Accept' => 'application/json'])->assertOk();
            $dimensions = getimagesizefromstring(Storage::disk('personal_photos')->get($person->refresh()->foto_path));
            $this->assertSame([600, 800, IMAGETYPE_JPEG], array_slice($dimensions, 0, 3));
        }
    }

    public function test_landscape_photo_is_cropped_at_the_center_without_stretching(): void
    {
        Storage::fake('personal_photos');
        $person = Usuario::factory()->create();
        $source = imagecreatetruecolor(1200, 800);
        imagefill($source, 0, 0, imagecolorallocate($source, 255, 0, 0));
        imagefilledrectangle($source, 300, 0, 899, 799, imagecolorallocate($source, 0, 255, 0));
        ob_start();
        imagepng($source);
        $png = ob_get_clean();
        imagedestroy($source);

        $this->post('/api/v1/expedientes/'.$person->id.'/foto', ['foto' => UploadedFile::fake()->createWithContent('foto.png', $png)], ['Accept' => 'application/json'])->assertOk();
        $portrait = imagecreatefromstring(Storage::disk('personal_photos')->get($person->refresh()->foto_path));
        foreach ([0, 300, 599] as $x) {
            $color = imagecolorsforindex($portrait, imagecolorat($portrait, $x, 400));
            $this->assertGreaterThan(240, $color['green']);
            $this->assertLessThan(15, $color['red']);
        }
        imagedestroy($portrait);
    }

    public function test_photo_routes_are_blocked_in_production(): void
    {
        $this->app->instance('env', 'production');
        $url = '/api/v1/expedientes/00000000-0000-0000-0000-000000000001/foto';
        $this->getJson($url)->assertForbidden();
        $this->postJson($url)->assertForbidden();
    }
}
