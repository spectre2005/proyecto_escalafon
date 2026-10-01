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

    public function test_photo_is_persisted_replaced_and_isolated_by_person(): void
    {
        Storage::fake('personal_photos');
        $person = Usuario::factory()->create();
        $other = Usuario::factory()->create();
        $url = '/api/v1/expedientes/'.$person->id.'/foto';
        $this->get($url)->assertNoContent();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        for ($i = 0; $i < 2; $i++) {
            $file = UploadedFile::fake()->createWithContent('foto.png', $png);
            $this->post($url, ['foto' => $file], ['Accept' => 'application/json'])->assertOk();
            Storage::disk('personal_photos')->assertExists($person->id);
            $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        }
        $this->assertCount(1, Storage::disk('personal_photos')->allFiles());
        $this->get('/api/v1/expedientes/'.$other->id.'/foto')->assertNoContent();
        $this->postJson($url, ['foto' => UploadedFile::fake()->create('bad.txt', 1, 'text/plain')])->assertUnprocessable();
        $this->postJson($url, ['foto' => UploadedFile::fake()->createWithContent('large.png', $png)->size(2049)])->assertUnprocessable();
    }

    public function test_photo_routes_are_blocked_in_production(): void
    {
        $this->app->instance('env', 'production');
        $url = '/api/v1/expedientes/00000000-0000-0000-0000-000000000001/foto';
        $this->getJson($url)->assertForbidden();
        $this->postJson($url)->assertForbidden();
    }
}
