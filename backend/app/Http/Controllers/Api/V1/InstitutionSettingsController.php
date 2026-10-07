<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InstitutionSettingsController extends Controller
{
    private const DEFAULTS = [
        'name' => 'Mi institución', 'initials' => 'MI', 'area' => 'Gestión de personas',
        'logo' => '', 'ruc' => '', 'address' => '', 'email' => '', 'phone' => '', 'website' => '',
        'reportTitle' => 'Ficha de datos personales', 'reportFooter' => 'Documento de uso institucional.',
        'reportColor' => '#0f766e', 'showLogo' => true, 'showEmissionDate' => true, 'showPageNumbers' => true,
    ];

    public function show(): JsonResponse
    {
        return response()->json($this->snapshot())->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->hasHeader('If-Match'), 428, 'Actualiza la configuración antes de guardar.');
        $unknown = array_diff(array_keys($request->all()), array_keys(self::DEFAULTS));
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'Opción de configuración no permitida.'));
        }
        $data = $request->validate([
            'name' => 'required|string|max:150', 'initials' => 'required|string|max:12', 'area' => 'required|string|max:150',
            'logo' => ['nullable', 'string', 'max:750000', $this->validLogo(...)],
            'ruc' => 'nullable|string|regex:/^[0-9]{11}$/', 'address' => 'nullable|string|max:250',
            'email' => 'nullable|email:rfc|max:255', 'phone' => 'nullable|string|regex:/^[+0-9()\s-]{6,20}$/',
            'website' => 'nullable|url:http,https|max:255', 'reportTitle' => 'required|string|max:100',
            'reportFooter' => 'nullable|string|max:180', 'reportColor' => 'required|string|regex:/^#[0-9a-fA-F]{6}$/',
            'showLogo' => 'sometimes|boolean', 'showEmissionDate' => 'sometimes|boolean', 'showPageNumbers' => 'sometimes|boolean',
        ]);
        DB::transaction(function () use ($request, $data): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(?)', [crc32('institution_settings')]);
            }
            DB::table('institution_settings')->where('id', 1)->lockForUpdate()->first();
            $current = $this->snapshot();
            abort_unless(hash_equals($current['version'], (string) $request->header('If-Match')), 409,
                'Otra persona cambió la configuración. Recarga los datos antes de guardar.');
            $settings = array_replace(self::DEFAULTS, $data);
            foreach (self::DEFAULTS as $key => $default) {
                $settings[$key] = is_bool($default) ? (bool) $settings[$key] : trim($settings[$key] ?? '');
            }
            $row = ['settings' => json_encode($settings, JSON_THROW_ON_ERROR), 'updated_by' => $request->user()->getAuthIdentifier(), 'updated_at' => now()];
            if ($current['configured']) {
                DB::table('institution_settings')->where('id', 1)->update($row);
            } else {
                DB::table('institution_settings')->insert(['id' => 1, 'created_at' => now(), ...$row]);
            }
        });

        return response()->json($this->snapshot())->header('Cache-Control', 'private, no-store');
    }

    /** @return array{data: array<string, mixed>, version: string, configured: bool, updated_at: ?string} */
    private function snapshot(): array
    {
        $row = DB::table('institution_settings')->where('id', 1)->first();
        $settings = $row ? array_replace(self::DEFAULTS, json_decode($row->settings, true, 512, JSON_THROW_ON_ERROR)) : self::DEFAULTS;

        return ['data' => $settings, 'version' => hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR)),
            'configured' => $row !== null, 'updated_at' => $row?->updated_at ? Carbon::parse($row->updated_at)->toIso8601String() : null];
    }

    private function validLogo(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/=]+)$#', $value, $matches)) {
            $fail('Selecciona un logo PNG o JPG válido.');

            return;
        }
        $bytes = base64_decode($matches[2], true);
        $size = $bytes === false ? false : @getimagesizefromstring($bytes);
        if ($size === false || $size[0] > 512 || $size[1] > 512 || ! in_array($size['mime'], ['image/png', 'image/jpeg'], true)) {
            $fail('El logo debe ser una imagen válida de hasta 512 × 512 píxeles.');

            return;
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            $fail('No se pudo leer la imagen del logo.');
        } else {
            imagedestroy($image);
        }
    }
}
