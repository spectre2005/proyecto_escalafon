<?php

namespace Database\Seeders;

use App\Models\SeccionLegajo;
use Illuminate\Database\Seeder;

/**
 * Las 13 secciones fijas del legajo (Directiva N° 001-2023-SERVIR-GDSRH). Núcleo común: 01, 02, 03, 04 y 10.
 * Idempotente: crea las que faltan y no modifica las que ya existen.
 */
class SeccionesLegajoCatalogoSeeder extends Seeder
{
    public const SECCIONES = [
        1 => ['Información personal y familiar', true],
        2 => ['Incorporación', true],
        3 => ['Formación académica y capacitación', true],
        4 => ['Experiencia laboral', true],
        5 => ['Movimientos del personal', false],
        6 => ['Compensaciones', false],
        7 => ['Evaluación, progresión y desplazamiento', false],
        8 => ['Reconocimientos y sanciones', false],
        9 => ['Relaciones laborales', false],
        10 => ['SST y bienestar', true],
        11 => ['Desvinculación', false],
        12 => ['Otros', false],
        13 => ['Legajo pasivo', false],
    ];

    public function run(): void
    {
        foreach (self::SECCIONES as $id => [$nombre, $nucleo]) {
            SeccionLegajo::query()->firstOrCreate(['id' => $id], ['nombre' => $nombre, 'es_nucleo_comun' => $nucleo, 'orden' => $id]);
        }
    }
}
