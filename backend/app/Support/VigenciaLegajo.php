<?php

namespace App\Support;

use App\Models\AuditoriaEvento;
use App\Models\CicloVigencia;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\PerfilDocente;
use App\Models\RatificacionDocente;
use App\Models\RegistroSstBienestar;
use App\Models\RenovacionLegajo;
use App\Models\RequerimientoDocumento;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\Documentos\EstadoDocumento;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class VigenciaLegajo
{
    public const ANIOS = ['auxiliar' => 3, 'asociado' => 5, 'principal' => 7];

    public static function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now('America/Lima')->startOfDay();
    }

    public static function calcular(string $categoria, string $fechaBase, ?int $anios = null): array
    {
        if (! isset(self::ANIOS[$categoria])) {
            throw ValidationException::withMessages(['categoria' => 'Categoría docente sin política de vigencia.']);
        }
        $base = CarbonImmutable::parse($fechaBase, 'America/Lima')->startOfDay();
        if ($base->greaterThan(self::hoy())) {
            throw ValidationException::withMessages(['fecha_base' => 'La fecha base no puede ser futura.']);
        }
        $vencimiento = $base->addYearsNoOverflow($anios ?? self::ANIOS[$categoria]);
        $dias = (int) self::hoy()->diffInDays($vencimiento, false);

        return [
            'fecha_vencimiento' => $vencimiento->toDateString(),
            'fecha_aviso' => $vencimiento->subDays(7)->toDateString(),
            'dias_restantes' => $dias,
            'estado_vigencia' => $dias <= 0 ? 'vencido' : ($dias <= 30 ? 'por_vencer' : 'vigente'),
            'color' => $dias <= 7 ? 'rojo' : ($dias <= 30 ? 'amarillo' : 'verde'),
        ];
    }

    public static function perfiles(Legajo $legajo): Collection
    {
        return PerfilDocente::query()->where('condicion_docente', 'ordinario')->whereIn('categoria', array_keys(self::ANIOS))
            ->whereHas('vinculoLaboral', fn ($q) => $q->where('usuario_id', $legajo->usuario_id)->where('tipo_personal', 'docente')
                ->where('anulado', false)->whereDate('fecha_inicio', '<=', self::hoy()->toDateString())->whereNull('fecha_cese'))
            ->with('vinculoLaboral')->get();
    }

    public static function evidenciaAprobada(?DocumentoUsuario $documento): bool
    {
        if (! $documento || ! $documento->tipoDocumento || in_array($documento->estado, ['anulado', 'observado', 'falso', 'pendiente'], true)) {
            return false;
        }
        $archivo = $documento->archivo;

        return EstadoDocumento::aprobado($documento, $documento->tipoDocumento)
            && $archivo && $archivo->estado_antivirus !== 'infectado'
            && (! config('antivirus.enforce') || $archivo->estado_antivirus === 'limpio')
            && $archivo->almacenamiento === config('legajo.disco_documentos')
            && Storage::disk($archivo->almacenamiento)->exists($archivo->storage_path);
    }

    public static function resolucionAplicable(DocumentoUsuario $documento, PerfilDocente $perfil): bool
    {
        if (! $documento->num_resolucion || ! $documento->fecha_emision || ! self::evidenciaAprobada($documento)) {
            return false;
        }
        if ($documento->seccion_legajo_id === 2) {
            return $documento->vinculo_laboral_id === $perfil->vinculo_laboral_id
                && $documento->tipoDocumento?->formulario_datos === 'vinculos_laborales'
                && $documento->tipoDocumento->requiere_num_resolucion;
        }

        return $documento->seccion_legajo_id === 7 && RatificacionDocente::query()->where('documento_id', $documento->id)
            ->where('perfil_docente_id', $perfil->id)->where('tipo', 'nombramiento')->where('anulado', false)
            ->where('categoria_nueva', $perfil->categoria)->where('resultado', '!=', 'no_ratificado')
            ->whereNotNull('fecha_resolucion')->whereDate('fecha_inicio_periodo', '<=', self::hoy()->toDateString())
            ->whereDate('fecha_resolucion', '<=', self::hoy()->toDateString())->exists();
    }

    public static function crear(Legajo $legajo, PerfilDocente $perfil, string $fechaBase, ?DocumentoUsuario $documento, string $origen, ?string $actor, ?string $jefe = null): CicloVigencia
    {
        $regla = DB::table('reglas_vigencia_categoria')->where('categoria', $perfil->categoria)->first();
        abort_unless($regla, 422, 'La categoría no tiene regla de vigencia.');
        $calculo = self::calcular($perfil->categoria, $fechaBase, $regla->anios);
        $ciclo = CicloVigencia::query()->create([
            'legajo_id' => $legajo->id, 'perfil_docente_id' => $perfil->id, 'regla_id' => $regla->id,
            'categoria' => $perfil->categoria, 'anios' => $regla->anios, 'version_regla' => $regla->version,
            'fecha_base' => $fechaBase, 'fecha_vencimiento' => $calculo['fecha_vencimiento'], 'fecha_aviso' => $calculo['fecha_aviso'],
            'estado_vigencia' => $calculo['estado_vigencia'], 'origen_base' => $origen,
            'documento_base_id' => $documento?->id, 'creado_por' => $actor, 'jefe_inmediato_id' => $jefe,
        ]);
        self::auditar($ciclo, 'vigencia.iniciar', $actor, ['fecha_base' => $fechaBase, 'categoria' => $perfil->categoria, 'origen' => $origen]);

        return $ciclo;
    }

    public static function inicializar(Legajo $legajo, PerfilDocente $perfil, DocumentoUsuario $documento, ?string $actor, ?string $jefe = null): CicloVigencia
    {
        return DB::transaction(function () use ($legajo, $perfil, $documento, $actor, $jefe): CicloVigencia {
            $legajo = Legajo::query()->lockForUpdate()->findOrFail($legajo->id);
            abort_unless($legajo->estado_legajo === 'activo', 409, 'El legajo ya no está activo.');
            abort_if(CicloVigencia::query()->where('legajo_id', $legajo->id)->where('actual', true)->exists(), 409, 'El legajo ya tiene un ciclo. Usa su renovación.');
            abort_unless(self::perfiles($legajo)->contains('id', $perfil->id), 422, 'El perfil no pertenece a un vínculo docente ordinario activo de esta persona.');
            $documento = DocumentoUsuario::query()->lockForUpdate()->findOrFail($documento->id);
            abort_unless($documento->legajo_id === $legajo->id && $documento->usuario_id === $legajo->usuario_id && self::evidenciaAprobada($documento), 422, 'La resolución debe estar aprobada y pertenecer al legajo.');
            abort_unless($documento->num_resolucion && $documento->fecha_emision && in_array($documento->seccion_legajo_id, [2, 7], true), 422, 'Selecciona una resolución de incorporación o nombramiento con fecha de emisión en las secciones 02 o 07.');
            abort_unless(self::resolucionAplicable($documento, $perfil), 422, 'La resolución no acredita el vínculo seleccionado o un nombramiento aplicable.');
            $fecha = $documento->fecha_emision->toDateString();
            $ratificacion = RatificacionDocente::query()->where('documento_id', $documento->id)->where('perfil_docente_id', $perfil->id)
                ->where('tipo', 'nombramiento')->where('anulado', false)->first();
            if ($documento->seccion_legajo_id === 7 && $ratificacion) {
                abort_if($ratificacion->resultado === 'no_ratificado', 422, 'La resolución tiene resultado no ratificado.');
                abort_unless($ratificacion->categoria_nueva === $perfil->categoria && $ratificacion->fecha_resolucion, 422, 'La categoría o fecha de la resolución contradice el perfil.');
                abort_if($ratificacion->fecha_inicio_periodo->toDateString() > self::hoy()->toDateString(), 422, 'El nombramiento aún no es efectivo.');
                $fecha = $ratificacion->fecha_resolucion->toDateString();
            }
            $ciclo = self::crear($legajo, $perfil, $fecha, $documento, 'resolucion', $actor, $jefe);
            self::asegurarRequisitos($ciclo);

            return $ciclo;
        });
    }

    public static function sincronizar(): int
    {
        $creados = 0;
        Legajo::query()->where('estado_legajo', 'activo')->whereHas('usuario', fn ($q) => $q->where('tipo_personal', 'docente'))
            ->whereNotIn('id', CicloVigencia::query()->where('actual', true)->select('legajo_id'))->chunkById(100, function ($legajos) use (&$creados): void {
                foreach ($legajos as $legajo) {
                    $perfiles = self::perfiles($legajo);
                    if ($perfiles->count() !== 1) {
                        continue;
                    }
                    $perfil = $perfiles->first();
                    $resoluciones = RatificacionDocente::query()->where('perfil_docente_id', $perfil->id)->where('usuario_id', $legajo->usuario_id)
                        ->where('anulado', false)->where('tipo', 'nombramiento')->whereIn('resultado', ['ratificado', 'promovido'])
                        ->whereDate('fecha_inicio_periodo', '<=', self::hoy()->toDateString())->whereDate('fecha_resolucion', '<=', self::hoy()->toDateString())
                        ->where('categoria_nueva', $perfil->categoria)->with('documento')->orderByDesc('fecha_resolucion')->get();
                    $candidatos = $resoluciones->filter(fn ($r) => $r->documento?->legajo_id === $legajo->id && self::resolucionAplicable($r->documento, $perfil))
                        ->map(fn ($r) => ['documento' => $r->documento, 'fecha' => $r->fecha_resolucion->toDateString()]);
                    foreach ($legajo->documentosUsuario()->where('seccion_legajo_id', 2)->where('vinculo_laboral_id', $perfil->vinculo_laboral_id)
                        ->whereDate('fecha_emision', '<=', self::hoy()->toDateString())->with(['tipoDocumento', 'archivo'])->get() as $documento) {
                        if (self::resolucionAplicable($documento, $perfil)) {
                            $candidatos->push(['documento' => $documento, 'fecha' => $documento->fecha_emision->toDateString()]);
                        }
                    }
                    $ultimo = $candidatos->sortByDesc('fecha')->first();
                    if ($ultimo && $candidatos->where('fecha', $ultimo['fecha'])->pluck('documento.id')->unique()->count() > 1) {
                        continue;
                    }
                    if ($ultimo) {
                        try {
                            self::inicializar($legajo, $perfil, $ultimo['documento'], null);
                            $creados++;
                        } catch (\Throwable $exception) {
                            report($exception);
                        }
                    }
                }
            });

        return $creados;
    }

    public static function asegurarRequisitos(CicloVigencia $ciclo): void
    {
        $politicas = DB::table('politicas_renovacion')->where('categoria', $ciclo->categoria)->where('actual', true)->get();
        foreach ($politicas as $politica) {
            $tipo = TipoDocumento::withoutGlobalScopes()->find($politica->tipo_documento_id);
            if (! $tipo) {
                continue;
            }
            $anterior = DocumentoUsuario::withoutGlobalScopes()->where('legajo_id', $ciclo->legajo_id)->where('tipo_documento_id', $tipo->id)
                ->where('estado', '!=', 'anulado')->with(['tipoDocumento', 'archivo'])->orderByDesc('fecha_emision')->orderByDesc('created_at')->first();
            $falta = ! self::evidenciaAprobada($anterior);
            $hasta = $anterior ? self::vencimientoDocumento($anterior) : null;
            $evaluacion = $ciclo->fecha_vencimiento->toDateString() > self::hoy()->toDateString()
                && $ciclo->fecha_vencimiento->toDateString() <= self::hoy()->addDays(30)->toDateString()
                ? CarbonImmutable::parse($ciclo->fecha_vencimiento->toDateString()) : self::hoy();
            $antiguo = $politica->antiguedad_maxima_dias && $anterior?->fecha_emision
                && $anterior->fecha_emision->format('Y-m-d') < $evaluacion->subDays($politica->antiguedad_maxima_dias)->toDateString();
            $exigir = $politica->modo === 'por_ciclo' || $falta || ($politica->modo === 'por_vencimiento' && ($antiguo || ($hasta && $hasta <= $evaluacion->toDateString())));
            if ($exigir) {
                RequerimientoDocumento::query()->firstOrCreate(['ciclo_id' => $ciclo->id, 'tipo_documento_id' => $tipo->id], [
                    'usuario_id' => $ciclo->legajo->usuario_id, 'origen' => 'vencimiento', 'descripcion' => mb_substr('Renovación de legajo: '.$tipo->nombre, 0, 300),
                    'fecha_requerimiento' => self::hoy()->toDateString(), 'plazo_dias_habiles' => null, 'unidad_plazo' => 'calendario',
                    'fecha_limite' => $ciclo->fecha_vencimiento->toDateString(), 'solicitado_por' => null,
                    'politica_id' => $politica->id, 'documento_objetivo_id' => $anterior?->id,
                    'motivo_renovacion' => $falta ? 'Documento faltante o no aprobado' : ($politica->modo === 'por_ciclo' ? 'Renovación por ciclo' : 'Vencimiento individual o antigüedad'),
                    'antiguedad_maxima_dias' => $politica->antiguedad_maxima_dias,
                ]);
            }
        }
    }

    public static function vencimientoDocumento(DocumentoUsuario $documento): ?string
    {
        $registro = RegistroSstBienestar::withoutGlobalScopes()->where('documento_id', $documento->id)->where('anulado', false)->whereNotNull('fecha_vencimiento')->orderBy('fecha_vencimiento')->first();

        return $registro?->fecha_vencimiento->toDateString();
    }

    public static function bloquearActual(CicloVigencia $ciclo): CicloVigencia
    {
        $legajo = Legajo::query()->lockForUpdate()->findOrFail($ciclo->legajo_id);
        $actual = CicloVigencia::query()->lockForUpdate()->findOrFail($ciclo->id);
        abort_unless($actual->actual && $legajo->estado_legajo === 'activo', 409, 'El ciclo ya cambió o el legajo pasó a pasivo. Actualiza el panel.');

        return $actual;
    }

    public static function validarCumplimiento(CicloVigencia $ciclo, RequerimientoDocumento $requisito, DocumentoUsuario $documento, Usuario $revisor): void
    {
        abort_unless($documento->usuario_id === $ciclo->legajo->usuario_id && $documento->legajo_id === $ciclo->legajo_id
            && $documento->tipo_documento_id === $requisito->tipo_documento_id && self::evidenciaAprobada($documento), 422, 'Selecciona evidencia aprobada del mismo titular, legajo y tipo documental.');
        abort_if($documento->created_by === $revisor->id || $documento->archivo?->subido_por === $revisor->id || $requisito->presentacion?->subido_por === $revisor->id, 403, 'Quien carga la evidencia no puede aprobar su cumplimiento.');
        if ($requisito->documento_objetivo_id === $documento->id || ($requisito->motivo_renovacion === 'Renovación por ciclo' && $documento->fecha_incorporacion?->format('Y-m-d') < $requisito->fecha_requerimiento->toDateString())) {
            abort(422, 'La renovación requiere nueva evidencia, no el documento anterior.');
        }
        if ($requisito->presentacion) {
            abort_unless(hash_equals($requisito->presentacion->hash_sha256, (string) $documento->archivo?->hash_sha256), 422, 'El PDF incorporado no coincide con la presentación vigente.');
        }
        $hasta = self::vencimientoDocumento($documento) ?? $requisito->fecha_vencimiento_evidencia?->toDateString() ?? $requisito->presentacion?->fecha_vencimiento?->toDateString();
        abort_if($hasta && $hasta <= self::hoy()->toDateString(), 422, 'La evidencia ya venció. Solicita su subsanación.');
        if ($requisito->antiguedad_maxima_dias) {
            abort_unless($documento->fecha_emision && $documento->fecha_emision->toDateString() >= self::hoy()->subDays($requisito->antiguedad_maxima_dias)->toDateString(), 422, 'La evidencia supera la antigüedad máxima de la política.');
        }
    }

    public static function aprobar(CicloVigencia $ciclo, Usuario $revisor): CicloVigencia
    {
        return DB::transaction(function () use ($ciclo, $revisor): CicloVigencia {
            $ciclo = self::bloquearActual($ciclo);
            $renovacion = RenovacionLegajo::query()->where('ciclo_id', $ciclo->id)->lockForUpdate()->first();
            abort_unless($renovacion?->estado === 'en_revision', 409, 'Primero presenta la renovación completa para revisión.');
            self::asegurarRequisitos($ciclo);
            $requisitos = $ciclo->requerimientos()->with(['tipoDocumento', 'documento.archivo', 'documento.tipoDocumento', 'presentacion'])->get();
            abort_if($requisitos->isEmpty(), 422, 'Configura al menos un requisito documental antes de aprobar.');
            $evidencias = DocumentoUsuario::query()->whereIn('id', $requisitos->pluck('documento_id')->filter())->orderBy('id')->lockForUpdate()->with(['tipoDocumento', 'archivo'])->get()->keyBy('id');
            foreach ($requisitos as $requisito) {
                abort_unless($requisito->tipoDocumento?->activo && $requisito->tipoDocumento->seccionLegajo?->activa, 422, 'Hay un requisito en una sección o tipo deshabilitado. Resuelve la configuración.');
                abort_if($requisito->estado !== 'cumplido' || ! $requisito->documento, 422, 'Todos los requisitos deben tener evidencia aprobada vinculada.');
                $documento = $evidencias->get($requisito->documento_id);
                abort_unless($documento, 422, 'La evidencia ya no está disponible.');
                self::validarCumplimiento($ciclo, $requisito, $documento, $revisor);
            }
            $perfil = self::perfiles($ciclo->legajo)->firstWhere('id', $ciclo->perfil_docente_id);
            abort_unless($perfil, 422, 'El vínculo o categoría docente ya no es aplicable. Revisa los datos del perfil.');
            $ciclo->update(['actual' => false, 'cerrado_en' => now()]);
            $nuevo = self::crear($ciclo->legajo, $perfil, self::hoy()->toDateString(), null, 'renovacion_aprobada', $revisor->id, $ciclo->jefe_inmediato_id);
            $renovacion->update(['estado' => 'aprobada', 'aprobada_en' => now(), 'aprobada_por' => $revisor->id, 'ciclo_nuevo_id' => $nuevo->id]);
            $ciclo->requerimientos()->update(['cerrado_en' => now()]);
            self::auditar($ciclo, 'renovacion.aprobar', $revisor->id, ['ciclo_nuevo_id' => $nuevo->id]);
            self::asegurarRequisitos($nuevo);

            return $nuevo;
        });
    }

    public static function auditar(CicloVigencia $ciclo, string $accion, ?string $actor, array $datos): void
    {
        $previo = AuditoriaEvento::query()->where('entidad', 'ciclos_vigencia_legajo')->where('entidad_id', $ciclo->id)->orderByDesc('fecha_evento')->orderByDesc('id')->value('hash_registro');
        $evento = ['fecha_evento' => now(), 'actor_usuario_id' => $actor, 'usuario_titular_id' => $ciclo->legajo->usuario_id,
            'accion' => $accion, 'entidad' => 'ciclos_vigencia_legajo', 'entidad_id' => $ciclo->id,
            'datos_despues' => json_encode($datos, JSON_THROW_ON_ERROR), 'hash_previo' => $previo, 'request_id' => (string) Str::uuid()];
        AuditoriaEvento::query()->create([...$evento, 'hash_registro' => hash('sha256', json_encode($evento, JSON_THROW_ON_ERROR))]);
    }
}
