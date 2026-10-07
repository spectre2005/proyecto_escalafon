<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class FotoPersonalController extends Controller
{
    public function show(string $usuario): Response
    {
        $person = Usuario::query()->findOrFail($usuario);
        $disk = Storage::disk('personal_photos');
        $path = $person->foto_path;
        if (! $path || ! $disk->exists($path)) {
            return response()->noContent();
        }

        return $disk->response($path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function store(Request $request, string $usuario): JsonResponse
    {
        $person = Usuario::query()->findOrFail($usuario);
        $request->validate(['foto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=6000,max_height=6000']]);
        $source = @imagecreatefromstring($request->file('foto')->get());
        if ($source === false) {
            throw ValidationException::withMessages(['foto' => 'No se pudo leer la imagen. Selecciona otra foto.']);
        }

        $disk = Storage::disk('personal_photos');
        $path = $person->id.'/'.Str::uuid().'/img_'.Str::slug((string) $person->numero_doc_identidad, '_').'.jpg';
        $portrait = imagecreatetruecolor(600, 800);
        try {
            imagefill($portrait, 0, 0, imagecolorallocate($portrait, 255, 255, 255));
            $width = imagesx($source);
            $height = imagesy($source);
            $cropWidth = min($width, (int) round($height * 3 / 4));
            $cropHeight = min($height, (int) round($width * 4 / 3));
            imagecopyresampled($portrait, $source, 0, 0, (int) floor(($width - $cropWidth) / 2), (int) floor(($height - $cropHeight) / 2), 600, 800, $cropWidth, $cropHeight);

            $stream = fopen('php://temp', 'w+b');
            try {
                abort_unless(imagejpeg($portrait, $stream, 90), 500, 'No se pudo procesar la foto.');
                rewind($stream);
                $stored = $disk->put($path, $stream);
            } finally {
                fclose($stream);
            }
        } finally {
            imagedestroy($source);
            imagedestroy($portrait);
        }
        abort_unless($stored, 500, 'No se pudo guardar la foto.');

        $previousPath = $person->foto_path;
        try {
            $person->update(['foto_path' => $path]);
        } catch (Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }
        if ($previousPath && $previousPath !== $path) {
            $disk->delete($previousPath);
        }

        return response()->json(['message' => 'Foto guardada con exito.', 'foto_path' => $person->foto_path]);
    }
}
