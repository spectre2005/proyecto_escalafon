<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Controller;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\Documentos\DocumentosOperativos;
use App\Support\Documentos\EstadoDocumento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class Seccion12Controller extends Controller
{
    public function show(Request $request, string $usuario): JsonResponse
    {
        $persona = Usuario::query()->findOrFail($usuario);
        $filtros = $request->validate(['legajo_id' => 'nullable|uuid']);
        $legajo = ! empty($filtros['legajo_id']) ? Legajo::query()->where('usuario_id', $usuario)->findOrFail($filtros['legajo_id']) : Legajo::paraConsulta($usuario);
        $tipos = DocumentosOperativos::tipos($request)->where('seccion_legajo_id', 12)->orderBy('orden')->get();
        $documentos = $legajo ? DocumentoUsuario::query()->where('usuario_id', $usuario)->where('legajo_id', $legajo->id)->where('seccion_legajo_id', 12)
            ->whereIn('tipo_documento_id', $tipos->pluck('id'))->with(['archivo', 'createdBy', 'recepcionadoPor', 'documentosUsuarioPorSubsanaADocumento'])->orderByDesc('folio_fin')->get() : collect();
        $tipos = $tipos->map(function (TipoDocumento $tipo) use ($documentos): array {
            $lista = $documentos->where('tipo_documento_id', $tipo->id)->map(function (DocumentoUsuario $doc) use ($tipo): array {
                $valores = $doc->only(['estado', 'folio_inicio', 'folio_fin', 'nombre', 'emisor', 'titular_nombre', 'titular_documento', 'num_resolucion', 'modalidad_presentacion', 'relacion_titular', 'motivo_incorporacion', 'subsana_a_documento_id']);
                foreach (['fecha_emision', 'fecha_incorporacion', 'fecha_caducidad', 'fecha_recepcion'] as $campo) {
                    $valores[$campo] = $doc->{$campo}?->format('Y-m-d');
                }
                $valores['created_at'] = $doc->created_at?->toIso8601String();

                return ['id' => $doc->id, 'values' => $valores, 'estado_visible' => EstadoDocumento::visible($doc), 'aprobado' => EstadoDocumento::aprobado($doc, $tipo),
                    'subsanado' => $doc->documentosUsuarioPorSubsanaADocumento->isNotEmpty(), 'archivo' => $doc->archivo?->only(['id', 'num_paginas', 'tamano_bytes']),
                    'subido_por' => $doc->createdBy ? trim($doc->createdBy->nombres.' '.$doc->createdBy->apellido_paterno) : null,
                    'recepcionado_por' => $doc->recepcionadoPor ? trim($doc->recepcionadoPor->nombres.' '.$doc->recepcionadoPor->apellido_paterno) : null];
            })->values();

            return [...$tipo->only(['id', 'codigo', 'nombre', 'descripcion', 'activo', 'permite_multiples', 'requiere_num_resolucion', 'requiere_fedateo', 'formulario_datos']),
                'exigido' => false, 'lista' => $lista, 'documentos' => $lista->count()];
        });

        return response()->json(['data' => ['legajo' => $legajo?->only(['id', 'numero_legajo', 'estado_legajo']), 'tipos' => $tipos,
            'titular' => ['nombre' => trim($persona->nombres.' '.$persona->apellido_paterno.' '.$persona->apellido_materno), 'documento' => $persona->numero_doc_identidad]]]);
    }
}
