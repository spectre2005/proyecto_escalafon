<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiProduccionTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_api_is_disabled_until_explicitly_configured(): void
    {
        $this->app->instance('env', 'production');
        config(['deployment.api_enabled' => false]);
        $this->postJson('/api/v1/auth/login', [])->assertForbidden();
    }

    public function test_enabled_functional_api_still_requires_authentication_and_keeps_generic_crud_closed(): void
    {
        $this->app->instance('env', 'production');
        config(['deployment.api_enabled' => true, 'app.debug' => false, 'session.secure' => true,
            'permisos.fuente' => 'database', 'antivirus.enabled' => true, 'antivirus.enforce' => true]);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/usuarios')->assertUnauthorized();
        $this->getJson('/api/v1/roles')->assertUnauthorized();
        $this->actuarComo();
        $this->getJson('/api/v1/roles')->assertForbidden();
    }

    public function test_unsafe_configuration_blocks_production_even_if_flag_is_enabled(): void
    {
        $this->app->instance('env', 'production');
        config(['deployment.api_enabled' => true, 'app.debug' => true]);
        $this->postJson('/api/v1/auth/login', [])->assertForbidden();
    }
}
