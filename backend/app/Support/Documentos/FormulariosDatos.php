<?php

namespace App\Support\Documentos;

use App\Models\DocumentoUsuario;
use App\Models\Induccion;
use App\Models\PeriodoPrueba;
use App\Models\VinculoAdenda;
use App\Models\VinculoLaboral;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Enlace entre un documento y el registro de datos que acredita (tipos_documento.formulario_datos).
 *
 * vinculos_laborales no tiene documento_id: el enlace va en documentos_usuario.vinculo_laboral_id. Las demás
 * tablas guardan documento_id. El enlace se escribe una sola vez (de vacío a un valor): no se cambia después.
 * Otras secciones agregan aquí sus formularios.
 */
final class FormulariosDatos
{
    private const MODELOS = [
        'vinculos_laborales' => VinculoLaboral::class,
        'vinculo_adendas' => VinculoAdenda::class,
        'inducciones' => Induccion::class,
        'periodos_prueba' => PeriodoPrueba::class,
    ];

    public static function soportado(?string $formulario): bool
    {
        return $formulario !== null && isset(self::MODELOS[$formulario]);
    }

    /** Enlaza el documento recién subido con un registro ya existente de su formulario (de la misma persona). */
    public static function enlazarRegistro(DocumentoUsuario $documento, string $formulario, string $registroId): void
    {
        $registro = self::registro($formulario, $documento->usuario_id, $registroId);
        if (! $registro) {
            throw ValidationException::withMessages(['enlace_id' => 'Selecciona un registro de esta persona que no esté anulado y (salvo los vínculos) que aún no tenga documento.']);
        }
        if ($formulario === 'vinculos_laborales') {
            $documento->update(['vinculo_laboral_id' => $registro->getKey()]);
        } else {
            $registro->update(['documento_id' => $documento->id]);
        }
    }

    /** Documento de la persona, del tipo que abre este formulario, al que se enlaza un registro nuevo. */
    public static function documentoPara(string $usuario, string $documentoId, string $formulario): DocumentoUsuario
    {
        $documento = DocumentoUsuario::query()->where('usuario_id', $usuario)->whereKey($documentoId)
            ->whereHas('tipoDocumento', fn ($query) => $query->where('formulario_datos', $formulario))->first();
        if (! $documento || ($formulario === 'vinculos_laborales' && $documento->vinculo_laboral_id !== null)) {
            throw ValidationException::withMessages(['documento_id' => 'El documento no es de esta persona, no corresponde a este formulario o ya está enlazado.']);
        }

        return $documento;
    }

    /** Registro sin documento enlazado (los vínculos admiten varios documentos: contrato, renovaciones). */
    private static function registro(string $formulario, string $usuario, string $id): ?Model
    {
        $query = (self::MODELOS[$formulario])::query()->whereKey($id);

        return match ($formulario) {
            'vinculos_laborales' => $query->where('usuario_id', $usuario)->where('anulado', false)->first(),
            'vinculo_adendas' => $query->where('anulado', false)->whereNull('documento_id')->whereHas('vinculoLaboral', fn ($q) => $q->where('usuario_id', $usuario))->first(),
            default => $query->where('usuario_id', $usuario)->where('anulado', false)->whereNull('documento_id')->first(),
        };
    }
}
