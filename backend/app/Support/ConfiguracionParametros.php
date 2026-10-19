<?php

namespace App\Support;

use App\Models\ParametroSistema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConfiguracionParametros
{
    public const VERSION = 'configuracion_parametros_version';

    public const CLAVES = ['dias_aviso_vencimiento_vinculo', 'tamano_maximo_pdf_kb', 'numero_legajo_prefijo', 'numero_legajo_digitos', self::VERSION];

    public static function datos(): array
    {
        $formato = Parametros::formatoNumero();
        $valores = ['dias_aviso_vencimiento_vinculo' => Parametros::entero('dias_aviso_vencimiento_vinculo'),
            'tamano_maximo_pdf_kb' => Parametros::entero('tamano_maximo_pdf_kb'), 'numero_legajo_prefijo' => $formato['prefijo'], 'numero_legajo_digitos' => $formato['digitos']];

        return ['version' => (int) (ParametroSistema::query()->where('clave', self::VERSION)->value('valor') ?? 0), 'valores' => $valores,
            'maximo_pdf_kb' => 2048, 'ejemplo_numero' => str_replace('{anio}', now('America/Lima')->format('Y'), $formato['prefijo']).str_pad('1', max(1, min(10, $formato['digitos'])), '0', STR_PAD_LEFT)];
    }

    public static function guardar(Request $request): array
    {
        $campos = ['version', 'motivo', 'valores'];
        abort_if(array_diff(array_keys($request->all()), $campos) !== [], 422, 'Campo no permitido.');
        $datos = $request->validate(['version' => 'required|integer|min:0', 'motivo' => 'required|string|min:5|max:1000',
            'valores' => 'required|array:dias_aviso_vencimiento_vinculo,tamano_maximo_pdf_kb,numero_legajo_prefijo,numero_legajo_digitos',
            'valores.dias_aviso_vencimiento_vinculo' => 'required|integer|between:0,365', 'valores.tamano_maximo_pdf_kb' => 'required|integer|between:1,2048',
            'valores.numero_legajo_digitos' => 'required|integer|between:1,10', 'valores.numero_legajo_prefijo' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]*(?:\{anio\})?[A-Za-z0-9._-]*$/']]);
        $prefijo = str_replace('{anio}', now('America/Lima')->format('Y'), $datos['valores']['numero_legajo_prefijo']);
        if (strlen($prefijo) + (int) $datos['valores']['numero_legajo_digitos'] > 20) {
            throw ValidationException::withMessages(['valores.numero_legajo_prefijo' => 'El prefijo expandido y el correlativo no pueden superar 20 caracteres.']);
        }

        return DB::transaction(function () use ($request, $datos): array {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(?)', [crc32('numero_legajo')]);
            }
            $control = ParametroSistema::query()->firstOrCreate(['clave' => self::VERSION], ['valor' => '0', 'tipo_dato' => 'int']);
            $control = ParametroSistema::query()->lockForUpdate()->findOrFail($control->id);
            abort_unless((int) $control->valor === (int) $datos['version'], 409, 'La configuración cambió. Actualiza antes de guardar.');
            $antes = self::datos();
            foreach ($datos['valores'] as $clave => $valor) {
                ParametroSistema::query()->updateOrCreate(['clave' => $clave], ['valor' => (string) $valor,
                    'tipo_dato' => $clave === 'numero_legajo_prefijo' ? 'string' : 'int', 'actualizado_por' => $request->user()->id]);
            }
            $control->update(['valor' => (string) ((int) $control->valor + 1), 'actualizado_por' => $request->user()->id]);
            $despues = self::datos();
            AuditoriaInstitucional::registrar('parametros_sistema', $control->id, 'parametros.actualizados', $request, null, $antes, [...$despues, 'motivo' => $datos['motivo']]);

            return [...$despues, 'historial_id' => $control->id];
        });
    }
}
