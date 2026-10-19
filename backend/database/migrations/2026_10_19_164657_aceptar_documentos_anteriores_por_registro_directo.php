<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('documentos_usuario as d')
                ->join('archivos_documento as a', 'a.id', '=', 'd.archivo_id')
                ->join('legajos as l', 'l.id', '=', 'd.legajo_id')
                ->join('usuarios as u', 'u.id', '=', 'd.created_by')
                ->whereBetween('d.seccion_legajo_id', [1, 11])
                ->whereIn('d.estado', ['pendiente', 'cargado'])
                ->where('l.estado_legajo', 'activo')
                ->where('a.tamano_bytes', '>', 0)
                ->where('a.mime_type', 'application/pdf')
                ->where(fn ($query) => $query->whereNull('a.estado_antivirus')->orWhere('a.estado_antivirus', '!=', 'infectado'))
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('documentos_usuario as siguiente')->whereColumn('siguiente.subsana_a_documento_id', 'd.id'))
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('observaciones_documento as o')->whereColumn('o.documento_id', 'd.id')->where('o.estado', 'abierta'))
                ->select(['d.id', 'd.estado', 'd.created_by'])
                ->chunkById(200, function ($documentos): void {
                    foreach ($documentos as $documento) {
                        DB::table('documentos_usuario')->where('id', $documento->id)->update(['estado' => 'aceptado', 'updated_at' => now()]);
                        DB::table('historial_estados_documento')->insert([
                            'id' => (string) Str::uuid(), 'documento_usuario_id' => $documento->id,
                            'estado_anterior' => $documento->estado, 'estado_nuevo' => 'aceptado',
                            'cambiado_por' => $documento->created_by, 'fecha_cambio' => now(),
                            'motivo' => 'Regularización automática por cambio a aceptación directa; se conserva como responsable al autor de la carga. No acredita fiscalización externa.',
                        ]);
                    }
                }, 'd.id', 'id');
        });
    }

    public function down(): void
    {
        // La aceptación y su historial se conservan: una reversión de código no debe deshacer decisiones registradas.
    }
};
