<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class FotoPersonalController extends Controller
{
    public function show(string $usuario): Response
    {
        Usuario::query()->findOrFail($usuario);
        $disk = Storage::disk('personal_photos');
        $path = $usuario;
        if (! $disk->exists($path)) {
            return response()->noContent();
        }

        return $disk->response($path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function store(Request $request, string $usuario): JsonResponse
    {
        Usuario::query()->findOrFail($usuario);
        $request->validate(['foto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=6000,max_height=6000']]);
        $stored = Storage::disk('personal_photos')->putFileAs('', $request->file('foto'), $usuario);
        abort_unless($stored, 500, 'No se pudo guardar la foto.');

        return response()->json(['message' => 'Foto guardada.']);
    }
}
