<?php

namespace Tests\Feature;

use App\Models\Ubigeo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UbigeoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_seeds_25_regions_196_provinces_and_1893_districts_idempotently(): void
    {
        $this->seed(\Database\Seeders\UbigeoSeeder::class);

        $this->assertSame(1893, Ubigeo::query()->count());
        $this->assertSame(25, Ubigeo::query()->distinct()->count('region'));
        $provinces = Ubigeo::query()->get(['region', 'provincia'])
            ->map(fn (Ubigeo $location): string => mb_strtolower($location->region."\0".$location->provincia))
            ->unique();
        $this->assertCount(196, $provinces);

        $this->seed(\Database\Seeders\UbigeoSeeder::class);
        $this->assertSame(1893, Ubigeo::query()->count());
    }
}
