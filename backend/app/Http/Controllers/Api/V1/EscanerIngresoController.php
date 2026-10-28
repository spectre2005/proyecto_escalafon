<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Documentos\ArchivoPdf;
use App\Support\Documentos\EscanerIngreso;
use App\Support\Parametros;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EscanerIngresoController extends Controller
{
    public function estado(EscanerIngreso $escaner): JsonResponse
    {
        return response()->json($escaner->estado())->header('Cache-Control', 'private, no-store');
    }

    public function escanear(Request $request, EscanerIngreso $escaner): JsonResponse
    {
        $request->validate(['pdf' => 'required|file|max:'.Parametros::entero('tamano_maximo_pdf_kb')]);
        if (! ArchivoPdf::esPdf($request->file('pdf'))) {
            throw ValidationException::withMessages(['pdf' => 'Selecciona un PDF válido.']);
        }

        return response()->json($escaner->escanear($request->file('pdf')))->header('Cache-Control', 'private, no-store');
    }
}
