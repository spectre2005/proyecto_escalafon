<?php

namespace Tests\Feature;

use App\Models\DocumentoUsuario;
use App\Models\Ubigeo;
use App\Models\UnidadOrganica;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

class ModelPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public static function models(): array
    {
        $models = [];
        foreach (glob(__DIR__.'/../../app/Models/*.php') as $path) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            if (! preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $name)) {
                continue;
            }
            $models[$name] = ['App\\Models\\'.$name];
        }

        return $models;
    }

    #[DataProvider('models')]
    public function test_models_can_be_saved_reloaded_and_query_their_relations(string $class): void
    {
        $model = $class::factory()->create();
        $model->refresh();
        $this->assertDatabaseHas($model->getTable(), ['id' => $model->getKey()]);
        if ($model->getKeyType() === 'string') {
            $this->assertTrue(Str::isUuid($model->getKey()));
        }
        $this->assertTrue($model->save());

        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            $type = (string) $method->getReturnType();
            if (! in_array($type, [BelongsTo::class, HasMany::class], true)) {
                continue;
            }
            $relation = $model->{$method->getName()}();
            if ($relation instanceof BelongsTo) {
                $foreignId = $model->getAttribute($relation->getForeignKeyName());
                $related = $relation->getResults();
                $this->assertEquals($foreignId, $related?->getKey());
            } else {
                $this->assertInstanceOf(Collection::class, $relation->getResults());
            }
        }
    }

    public function test_usuario_hashes_password_hides_it_and_uses_institutional_email(): void
    {
        $usuario = Usuario::factory()->create(['password' => 'example-password']);
        $usuario->refresh();
        $this->assertTrue(Hash::check('example-password', $usuario->password));
        $this->assertArrayNotHasKey('password', $usuario->toArray());
        $this->assertSame($usuario->email_institucional, $usuario->getEmailForPasswordReset());
        $this->assertSame($usuario->email_institucional, $usuario->routeNotificationFor('mail'));
        $this->assertSame('', $usuario->getRememberTokenName());
        $this->assertTrue($usuario->activo);
        $this->assertSame(Usuario::class, config('auth.providers.users.model'));
    }

    public function test_ubigeo_and_self_references_can_be_traversed_in_both_directions(): void
    {
        $ubigeo = Ubigeo::factory()->create();
        $usuario = Usuario::factory()->create(['ubigeo_id' => $ubigeo->id]);
        $this->assertTrue($usuario->ubigeo->is($ubigeo));
        $this->assertTrue($ubigeo->usuarios->contains($usuario));

        $parent = UnidadOrganica::factory()->create();
        $child = UnidadOrganica::factory()->create(['unidad_padre_id' => $parent->id]);
        $this->assertTrue($child->unidadPadre->is($parent));
        $this->assertTrue($parent->unidadesOrganicasPorUnidadPadre->contains($child));

        $original = DocumentoUsuario::factory()->create();
        $correction = DocumentoUsuario::factory()->create([
            'usuario_id' => $original->usuario_id,
            'legajo_id' => $original->legajo_id,
            'tipo_documento_id' => $original->tipo_documento_id,
            'seccion_legajo_id' => $original->seccion_legajo_id,
            'subsana_a_documento_id' => $original->id,
        ]);
        $this->assertTrue($correction->subsanaADocumento->is($original));
        $this->assertTrue($original->documentosUsuarioPorSubsanaADocumento->contains($correction));
    }
}
