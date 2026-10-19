<?php

namespace App\Support;

use App\Models\AuditoriaEvento;
use App\Models\RequerimientoDocumento;
use App\Support\Documentos\DocumentosOperativos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class GestionRequerimientos
{
    public const ABIERTOS = ['abierto', 'pendiente', 'vencido', 'prorrogado', 'observado', 'en_revision'];

    public static function crear(Request $request, array $datos): RequerimientoDocumento
    {
        return DB::transaction(function () use ($request, $datos): RequerimientoDocumento {
            $registro = RequerimientoDocumento::query()->create([
                ...$datos, 'estado' => 'abierto', 'solicitado_por' => $request->user()->id,
                'plazo_dias_habiles' => null,
            ]);
            self::auditar($registro, $request, 'crear', null);

            return $registro->refresh();
        });
    }

    public static function ejecutar(Request $request, string $id, array $datos): RequerimientoDocumento
    {
        return DB::transaction(function () use ($request, $id, $datos): RequerimientoDocumento {
            $registro = RequerimientoDocumento::query()->lockForUpdate()->findOrFail($id);
            abort_unless($registro->version === (int) $datos['version'], 409, 'El requerimiento cambió. Actualiza la consulta antes de continuar.');
            abort_unless(in_array($registro->estado, self::ABIERTOS, true) && $registro->cerrado_en === null, 409, 'El requerimiento ya está cerrado.');
            $antes = $registro->getAttributes();
            $accion = $datos['accion'];
            if ($accion === 'editar') {
                abort_unless(in_array($registro->estado, ['abierto', 'pendiente', 'vencido', 'prorrogado'], true) && $registro->documento_id === null, 409, 'Solo se editan solicitudes sin respuesta.');
                $registro->fill(['descripcion' => $datos['descripcion']]);
            } elseif ($accion === 'responder') {
                abort_unless($registro->estado !== 'en_revision', 409, 'La respuesta ya está en revisión.');
                $documento = self::documentos($request, $registro)->lockForUpdate()->findOrFail($datos['documento_id']);
                $registro->fill(['documento_id' => $documento->id, 'estado' => 'en_revision']);
            } elseif ($accion === 'observar' || $accion === 'cumplir') {
                abort_unless($registro->estado === 'en_revision' && $registro->documento_id !== null, 409, 'Primero registra una respuesta para revisión.');
                if ($accion === 'cumplir') {
                    self::documentos($request, $registro)->lockForUpdate()->findOrFail($registro->documento_id);
                }
                $registro->fill(['estado' => $accion === 'cumplir' ? 'cumplido' : 'observado', 'cerrado_en' => $accion === 'cumplir' ? now() : null]);
            } elseif ($accion === 'prorrogar') {
                $limite = ($registro->prorroga_hasta ?? $registro->fecha_limite)->toDateString();
                abort_unless($datos['prorroga_hasta'] > $limite, 422, 'La prórroga debe ser posterior al plazo efectivo.');
                $registro->prorroga_hasta = $datos['prorroga_hasta'];
            } elseif ($accion === 'cancelar') {
                $registro->fill(['estado' => 'cancelado', 'cerrado_en' => now()]);
            }
            $registro->version++;
            $registro->save();
            self::auditar($registro, $request, $accion, $antes, $datos['motivo'] ?? null);

            return $registro->refresh();
        });
    }

    public static function documentos(Request $request, RequerimientoDocumento $registro): Builder
    {
        return DocumentosOperativos::consulta($request, null, $registro->usuario_id)
            ->when($registro->tipo_documento_id, fn (Builder $q): Builder => $q->where('tipo_documento_id', $registro->tipo_documento_id));
    }

    private static function auditar(RequerimientoDocumento $registro, Request $request, string $accion, ?array $antes, ?string $motivo = null): void
    {
        $previo = AuditoriaEvento::query()->where('entidad', 'requerimientos_documentos')->where('entidad_id', $registro->id)->orderByDesc('id')->value('hash_registro');
        $datos = [
            'fecha_evento' => now()->format('Y-m-d H:i:s'), 'actor_usuario_id' => $request->user()->id,
            'usuario_titular_id' => $registro->usuario_id, 'accion' => 'requerimiento.'.$accion,
            'entidad' => 'requerimientos_documentos', 'entidad_id' => $registro->id,
            'datos_antes' => $antes === null ? null : json_encode($antes, JSON_THROW_ON_ERROR),
            'datos_despues' => json_encode([...$registro->getAttributes(), 'motivo' => $motivo], JSON_THROW_ON_ERROR),
            'ip_origen' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 300),
            'request_id' => (string) Str::uuid(), 'hash_previo' => $previo,
        ];
        AuditoriaEvento::query()->create([...$datos, 'hash_registro' => hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR))]);
    }
}
