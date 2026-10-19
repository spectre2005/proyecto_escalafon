<?php

namespace App\Support\Documentos;

use App\Models\DocumentoUsuario;
use App\Models\TipoDocumento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class DocumentosOperativos
{
    public static function consulta(Request $request, ?string $legajo, ?string $usuario = null, bool $soloActivos = true, bool $todas = false): Builder
    {
        abort_if($legajo === null && $usuario === null && ! $todas, 500);
        $consulta = DocumentoUsuario::query()->when($legajo, fn (Builder $q): Builder => $q->where('legajo_id', $legajo))
            ->when($usuario, fn (Builder $q): Builder => $q->where('usuario_id', $usuario))
            ->whereHas('legajo', fn (Builder $q): Builder => $q->whereColumn('legajos.usuario_id', 'documentos_usuario.usuario_id')->when($soloActivos, fn (Builder $l): Builder => $l->where('estado_legajo', 'activo')))
            ->whereHas('archivo', fn (Builder $q): Builder => $q->where('estado_antivirus', '!=', 'infectado'))
            ->whereNotIn('estado', ['anulado', 'falso']);
        $consulta->whereNotIn('seccion_legajo_id', self::seccionesOcultas($request))
            ->whereIn('tipo_documento_id', self::tipos($request)->select('id'));

        return $consulta;
    }

    public static function tipos(Request $request): Builder
    {
        return TipoDocumento::query()->whereNotIn('seccion_legajo_id', self::seccionesOcultas($request))
            ->when(! $request->user()->veDatosSensibles(), fn ($q) => $q->where('es_dato_sensible', false)->where('es_dato_salud', false))
            ->when(! $request->user()->can('seccion08.sanciones.ver'), fn ($q) => $q->whereNotIn('codigo', ['SAN_RESOLUCION_SANCION', 'SAN_RESOLUCION_RECURSO']));
    }

    private static function seccionesOcultas(Request $request): array
    {
        return [...($request->user()->veDatosSensibles() ? [] : [1, 9, 10]), ...($request->user()->can('seccion07.ver') ? [] : [7])];
    }
}
