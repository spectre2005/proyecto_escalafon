<?php

namespace Database\Seeders;

use App\Models\Ubigeo;
use Illuminate\Database\Seeder;
use RuntimeException;

class UbigeoSeeder extends Seeder
{
    private const EXPECTED_REGIONS = 25;

    private const EXPECTED_PROVINCES = 196;

    private const EXPECTED_DISTRICTS = 1893;

    public function run(): void
    {
        // Catálogo nacional e idempotente: también en producción (lo carga el aprovisionamiento de cada institución).
        $catalog = $this->readCatalog();
        $locations = [];
        foreach (Ubigeo::query()->get(['region', 'provincia', 'distrito']) as $existing) {
            $locations[$this->key($existing->region, $existing->provincia, $existing->distrito)] = true;
        }

        foreach ($catalog as [$region, $province, $district]) {
            $key = $this->key($region, $province, $district);
            if (isset($locations[$key])) {
                continue;
            }

            Ubigeo::query()->create([
                'region' => $region,
                'provincia' => $province,
                'distrito' => $district,
            ]);
            $locations[$key] = true;
        }
    }

    /** @return list<array{string, string, string}> */
    private function readCatalog(): array
    {
        $path = database_path('data/ubigeo_peru.csv');
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir el catálogo de ubigeos.');
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if ($header !== ['codigo', 'region', 'provincia', 'distrito']) {
                throw new RuntimeException('El catálogo de ubigeos tiene columnas inesperadas.');
            }

            $rows = [];
            $regions = [];
            $provinces = [];
            $seen = [];
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if (count($row) !== 4) {
                    throw new RuntimeException('El catálogo de ubigeos contiene una fila incompleta.');
                }

                [$code, $region, $province, $district] = array_map('trim', $row);
                if (! preg_match('/^[0-9]{6}$/', $code)
                    || $region === '' || $province === '' || $district === ''
                    || mb_strlen($region) > 100 || mb_strlen($province) > 100 || mb_strlen($district) > 100) {
                    throw new RuntimeException('El catálogo de ubigeos contiene datos inválidos.');
                }

                $key = $this->key($region, $province, $district);
                if (isset($seen[$key])) {
                    throw new RuntimeException('El catálogo de ubigeos contiene ubicaciones duplicadas.');
                }

                $seen[$key] = true;
                $regions[mb_strtolower($region)] = true;
                $provinces[mb_strtolower($region)."\0".mb_strtolower($province)] = true;
                $rows[] = [$region, $province, $district];
            }
        } finally {
            fclose($handle);
        }

        if (count($regions) !== self::EXPECTED_REGIONS
            || count($provinces) !== self::EXPECTED_PROVINCES
            || count($rows) !== self::EXPECTED_DISTRICTS) {
            throw new RuntimeException('El catálogo de ubigeos no coincide con los conteos esperados.');
        }

        return $rows;
    }

    private function key(string $region, string $province, string $district): string
    {
        return mb_strtolower(trim($region))."\0".mb_strtolower(trim($province))."\0".mb_strtolower(trim($district));
    }
}
