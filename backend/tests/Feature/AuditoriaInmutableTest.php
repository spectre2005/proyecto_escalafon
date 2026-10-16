<?php

namespace Tests\Feature;

use App\Models\AuditoriaEvento;
use App\Models\BitacoraAccesoLegajo;
use App\Models\HistorialEstadoDocumento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AuditoriaInmutableTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_is_read_only_even_for_administrators(): void
    {
        $this->actuarComo();
        foreach ([AuditoriaEvento::class, BitacoraAccesoLegajo::class, HistorialEstadoDocumento::class] as $class) {
            $record = $class::factory()->create();
            $url = '/api/v1/'.str_replace('_', '-', $record->getTable());
            $this->getJson($url.'/'.$record->id)->assertOk();
            $this->postJson($url, $record->getAttributes())->assertStatus(405);
            $this->patchJson($url.'/'.$record->id, [])->assertStatus(405);
            $this->putJson($url.'/'.$record->id, [])->assertStatus(405);
            $this->deleteJson($url.'/'.$record->id)->assertStatus(405);
            $this->assertModelExists($record);
        }
    }

    public function test_eloquent_cannot_modify_or_delete_historical_records(): void
    {
        foreach ([AuditoriaEvento::class, BitacoraAccesoLegajo::class, HistorialEstadoDocumento::class] as $class) {
            $record = $class::factory()->create();
            foreach (['save', 'delete'] as $operation) {
                try {
                    if ($operation === 'save') {
                        $record->setAttribute('ip_origen', '192.0.2.1');
                    }
                    $record->{$operation}();
                    $this->fail('La operación histórica debió rechazarse.');
                } catch (LogicException $exception) {
                    $this->assertStringContainsString('registros históricos', $exception->getMessage());
                }
                $this->assertModelExists($record);
                $record->refresh();
            }
        }
    }
}
