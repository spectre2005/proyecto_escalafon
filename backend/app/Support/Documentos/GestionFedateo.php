<?php

namespace App\Support\Documentos;

use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class GestionFedateo
{
    public static function registrar(Request $request, string $id): DocumentoUsuario
    {
        $campos = ['sustento_documento_id', 'sustento_anterior_id', 'fecha_fedateo', 'estado_anterior', 'motivo'];
        abort_if(array_diff(array_keys($request->all()), $campos) !== [], 422, 'Campo no permitido.');
        $datos = $request->validate(['sustento_documento_id' => 'required|uuid', 'sustento_anterior_id' => 'present|nullable|uuid',
            'fecha_fedateo' => 'required|date_format:Y-m-d|before_or_equal:'.now('America/Lima')->toDateString(),
            'estado_anterior' => 'required|string|max:20', 'motivo' => 'required|string|max:1000']);

        return DB::transaction(function () use ($request, $id, $datos): DocumentoUsuario {
            $doc = DocumentosOperativos::consulta($request, null, todas: true)->findOrFail($id);
            Legajo::query()->whereKey($doc->legajo_id)->lockForUpdate()->firstOrFail();
            $doc = DocumentosOperativos::consulta($request, $doc->legajo_id)->lockForUpdate()->findOrFail($id);
            abort_if($doc->created_by === $request->user()->id, 403, 'El fedateo debe registrarlo otra persona de RR. HH.');
            abort_if($doc->documentosUsuarioPorSubsanaADocumento()->exists(), 409, 'Registra el fedateo del documento vigente.');
            abort_unless($doc->estado === $datos['estado_anterior'] && $doc->fedateo_sustento_documento_id === ($datos['sustento_anterior_id'] ?? null), 409, 'El documento cambió. Actualiza la bandeja.');
            abort_if($datos['sustento_documento_id'] === $doc->id, 422, 'El sustento debe ser otro PDF del mismo legajo.');
            $sustento = DocumentosOperativos::consulta($request, $doc->legajo_id)->with('archivo')->findOrFail($datos['sustento_documento_id']);
            AlmacenDocumento::leer($sustento->archivo);
            AlmacenDocumento::leer($doc->archivo);
            $antes = $doc->getAttributes();
            $doc->forceFill(['fedateado' => true, 'fecha_fedateo' => $datos['fecha_fedateo'], 'fedatario_id' => $request->user()->id,
                'fedateo_sustento_documento_id' => $sustento->id])->save();
            AuditoriaDocumento::registrar($doc, $request, 'documento.fedateado_con_sustento', $antes,
                [...$doc->getAttributes(), 'motivo' => $datos['motivo'], 'sustento_hash_sha256' => $sustento->archivo->hash_sha256]);

            return $doc->refresh();
        });
    }
}
