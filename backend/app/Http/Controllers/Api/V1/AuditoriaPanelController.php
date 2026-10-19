<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditoriaEvento;
use App\Models\BitacoraAccesoLegajo;
use App\Models\Usuario;
use App\Support\ConsultaAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AuditoriaPanelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->consulta($request)->paginate(20)->through(fn ($r) => $this->evento($r)));
    }

    public function accesos(Request $request): JsonResponse
    {
        return response()->json($this->consultaAccesos($request)->paginate(20)->through(fn ($r) => $this->acceso($r)));
    }

    public function exportar(Request $request): BinaryFileResponse
    {
        return ConsultaAuditoria::csv($this->consulta($request), fn ($r): array => [$r->fecha_evento?->toIso8601String(), $this->nombre($r->actorUsuario), $r->accion, $r->entidad, $r->entidad_id, $r->usuario_titular_id, $r->ip_origen],
            ['Fecha UTC', 'Actor', 'Acción', 'Entidad', 'ID del registro', 'Persona titular', 'IP'], 'auditoria-institucional.csv');
    }

    public function exportarAccesos(Request $request): BinaryFileResponse
    {
        return ConsultaAuditoria::csv($this->consultaAccesos($request), fn ($r): array => [$r->fecha_acceso?->toIso8601String(), $this->nombre($r->usuario), $r->accion, $r->legajo?->numero_legajo, $r->documento_id, $r->solicitud_acceso_id, $r->ip_origen],
            ['Fecha UTC', 'Actor', 'Acción', 'Legajo', 'Documento', 'Autorización', 'IP'], 'accesos-legajo.csv');
    }

    private function consulta(Request $request): Builder
    {
        $f = ConsultaAuditoria::filtros($request);
        $q = ConsultaAuditoria::fechas(AuditoriaEvento::query(), 'fecha_evento', $f)->with('actorUsuario:id,nombres,apellido_paterno,apellido_materno');
        foreach (['accion', 'entidad'] as $campo) {
            $q->when($f[$campo] ?? null, fn ($q, $valor) => $q->whereRaw('LOWER('.$campo.') LIKE ?', ['%'.mb_strtolower($valor).'%']));
        }
        $q->when($f['usuario_id'] ?? null, fn ($q, $id) => $q->where('usuario_titular_id', $id));
        $this->actor($q, $f, 'actorUsuario');

        return $q->orderByDesc('fecha_evento')->orderByDesc('id');
    }

    private function consultaAccesos(Request $request): Builder
    {
        $f = ConsultaAuditoria::filtros($request);
        $q = ConsultaAuditoria::fechas(BitacoraAccesoLegajo::query(), 'fecha_acceso', $f)->with(['usuario:id,nombres,apellido_paterno,apellido_materno', 'legajo:id,numero_legajo']);
        $q->when($f['accion'] ?? null, fn ($q, $valor) => $q->whereRaw('LOWER(accion) LIKE ?', ['%'.mb_strtolower($valor).'%']))
            ->when($f['legajo_id'] ?? null, fn ($q, $id) => $q->where('legajo_id', $id));
        $this->actor($q, $f, 'usuario');

        return $q->orderByDesc('fecha_acceso')->orderByDesc('id');
    }

    private function actor(Builder $q, array $f, string $relacion): void
    {
        $q->when($f['actor'] ?? null, fn ($q, $valor) => $q->whereHas($relacion, fn ($u) => $u->whereRaw("LOWER(nombres || ' ' || apellido_paterno || ' ' || COALESCE(apellido_materno, '') || ' ' || COALESCE(email_institucional, '')) LIKE ?", ['%'.mb_strtolower($valor).'%'])));
    }

    private function evento(AuditoriaEvento $r): array
    {
        return ['id' => $r->id, 'fecha' => $r->fecha_evento, 'actor' => $this->nombre($r->actorUsuario), 'accion' => $r->accion,
            'entidad' => $r->entidad, 'registro_id' => $r->entidad_id, 'usuario_titular_id' => $r->usuario_titular_id, 'ip' => $r->ip_origen,
            'antes' => ConsultaAuditoria::redactar($r->datos_antes), 'despues' => ConsultaAuditoria::redactar($r->datos_despues)];
    }

    private function acceso(BitacoraAccesoLegajo $r): array
    {
        return ['id' => $r->id, 'fecha' => $r->fecha_acceso, 'actor' => $this->nombre($r->usuario), 'accion' => $r->accion,
            'entidad' => 'accesos_legajo', 'registro_id' => $r->documento_id, 'legajo' => $r->legajo?->numero_legajo, 'autorizacion_id' => $r->solicitud_acceso_id, 'ip' => $r->ip_origen];
    }

    private function nombre(?Usuario $u): string
    {
        return $u ? trim($u->nombres.' '.$u->apellido_paterno.' '.$u->apellido_materno) : 'Sistema';
    }
}
