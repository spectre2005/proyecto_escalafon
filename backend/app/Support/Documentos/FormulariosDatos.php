<?php

namespace App\Support\Documentos;

use App\Models\CertificadoDiscapacidad;
use App\Models\DeclaracionVerificacion;
use App\Models\DocumentoUsuario;
use App\Models\Familiar;
use App\Models\GoceVacacional;
use App\Models\Induccion;
use App\Models\LicenciaPermiso;
use App\Models\PeriodoPrueba;
use App\Models\VinculoAdenda;
use App\Models\VinculoLaboral;
use App\Support\Seccion01;
use App\Support\Seccion05;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Enlace entre un documento y el registro de datos que acredita (tipos_documento.formulario_datos).
 *
 * vinculos_laborales no tiene documento_id: el enlace va en documentos_usuario.vinculo_laboral_id. Las demás
 * tablas guardan documento_id. El enlace se escribe una sola vez (de vacío a un valor): no se cambia después.
 * Otras secciones agregan aquí sus formularios.
 *
 * Sección 01: el registro debe ser compatible con el tipo del documento (App\Support\Seccion01) y un documento
 * acredita un solo registro vigente de familiares, declaraciones_verificaciones o certificados_discapacidad.
 */
final class FormulariosDatos
{
    private const MODELOS = [
        'vinculos_laborales' => VinculoLaboral::class,
        'vinculo_adendas' => VinculoAdenda::class,
        'inducciones' => Induccion::class,
        'periodos_prueba' => PeriodoPrueba::class,
        'familiares' => Familiar::class,
        'declaraciones_verificaciones' => DeclaracionVerificacion::class,
        'certificados_discapacidad' => CertificadoDiscapacidad::class,
        'licencias_permisos' => LicenciaPermiso::class,
        'goces_vacacionales' => GoceVacacional::class,
    ];

    /** Formularios en los que un documento acredita un solo registro vigente. */
    private const UN_REGISTRO_POR_DOCUMENTO = ['familiares', 'declaraciones_verificaciones', 'certificados_discapacidad'];

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
        // Sección 05: cada tipo de documento acredita solo ciertos tipos de movimiento (p. ej. b) solo goces).
        if (! Seccion05::admite($documento->tipoDocumento?->codigo, $registro->getAttribute('tipo'))) {
            throw ValidationException::withMessages(['enlace_id' => 'Este tipo de documento no acredita ese tipo de movimiento.']);
        }
        if (! Seccion01::compatible($documento->tipoDocumento, $registro)) {
            throw ValidationException::withMessages(['enlace_id' => 'El registro no corresponde a este tipo de documento.']);
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
        $enlazado = in_array($formulario, self::UN_REGISTRO_POR_DOCUMENTO, true)
            && $documento && (self::MODELOS[$formulario])::query()->where('documento_id', $documento->id)->where('anulado', false)->exists();
        if (! $documento || ($formulario === 'vinculos_laborales' && $documento->vinculo_laboral_id !== null) || $enlazado) {
            throw ValidationException::withMessages(['documento_id' => 'El documento no es de esta persona, no corresponde a este formulario o ya está enlazado.']);
        }

        return $documento;
    }

    /**
     * Crea un registro de datos (sección 01) enlazado, si se indica, al documento recién subido que lo acredita.
     * En una transacción con el documento bloqueado: dos registros simultáneos no toman el mismo documento.
     */
    public static function crearEnlazado(string $formulario, string $usuario, ?string $documentoId, Model $registro): Model
    {
        return DB::transaction(function () use ($formulario, $usuario, $documentoId, $registro): Model {
            if ($documentoId !== null) {
                DocumentoUsuario::query()->whereKey($documentoId)->lockForUpdate()->first();
                $documento = self::documentoPara($usuario, $documentoId, $formulario);
                if (! Seccion01::compatible($documento->tipoDocumento, $registro)) {
                    throw ValidationException::withMessages(['documento_id' => 'El registro no corresponde al tipo del documento que acredita.']);
                }
                $registro->documento_id = $documento->id;
            }
            $registro->save();

            return $registro->refresh();
        });
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
