<?php

namespace App\Console\Commands;

use App\Models\Plataforma\Institucion;
use App\Support\Auditoria;
use App\Support\Plataforma\ConexionInstitucion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pasa a mayúsculas los datos ya guardados de los campos cortos que hoy se escriben en mayúsculas (observación del
 * docente, 21/10/2026; frontend: shared/forms/mayusculas.js). PROPUESTO: preparado, no ejecutado en ninguna base.
 *
 * Alcance: datos de la persona, sus familiares y sus vínculos. Fuera, a propósito:
 * - documentos_usuario y los registros de las secciones que salen de un documento: un documento no se altera (arts. 2.3
 *   y 5.3 de la directiva); queda pendiente de decidir.
 * - filas anuladas (se conservan tal como se anularon), correos, códigos de catálogo y textos largos (motivos).
 *
 * Sin --aplicar solo cuenta lo que cambiaría, sin mostrar ningún dato personal. Con --aplicar convierte en PHP
 * (mb_strtoupper: la ñ y las tildes no dependen del idioma de la base), una transacción por tabla y un evento de
 * auditoría por fila (datos.mayusculas, sin actor).
 */
class ConvertirMayusculas extends Command
{
    /** Tabla => [columnas, cómo llegar al titular]. */
    public const CAMPOS = [
        'usuarios' => [['nombres', 'apellido_paterno', 'apellido_materno', 'domicilio', 'nombre_puesto'], 'id'],
        'familiares' => [['apellidos_nombres'], 'usuario_id'],
        'vinculos_laborales' => [['nombre_puesto', 'proceso_seleccion', 'numero_documento_vinculo'], 'usuario_id'],
        'vinculo_adendas' => [['numero'], 'vinculo_laboral_id'],
        'perfil_docente' => [['codigo_renacyt'], 'vinculo_laboral_id'],
        'perfil_administrativo' => [['nivel_carrera', 'cargo_estructural'], 'vinculo_laboral_id'],
    ];

    private const CON_ANULACION = ['familiares', 'vinculos_laborales', 'vinculo_adendas'];

    protected $signature = 'datos:mayusculas {--aplicar : Escribe los cambios; sin esta opción solo cuenta} {--institucion= : Solo la institución con este identificador}';

    protected $description = 'Pasa a mayúsculas los nombres, puestos y números ya guardados (sin --aplicar solo cuenta)';

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $this->info($aplicar ? 'Convirtiendo a mayúsculas.' : 'Solo conteo: no se escribe nada (usa --aplicar para convertir).');
        if (config('plataforma.modo') !== 'subdominio' || app()->bound(Institucion::class)) {
            return $this->convertir($aplicar) ? self::SUCCESS : self::FAILURE;
        }

        $instituciones = Institucion::query()->whereNotNull('aprovisionada_en')->orderBy('slug')
            ->when($this->option('institucion'), fn ($query, string $slug) => $query->where('slug', $slug))->get();
        if ($instituciones->isEmpty()) {
            $this->warn('No hay instituciones aprovisionadas'.($this->option('institucion') ? ' con ese identificador.' : '.'));

            return $this->option('institucion') ? self::FAILURE : self::SUCCESS;
        }
        $correcto = true;
        foreach ($instituciones as $institucion) {
            $this->line($institucion->slug.':');
            try {
                $correcto = ConexionInstitucion::usar($institucion, fn (): bool => $this->convertir($aplicar)) && $correcto;
            } catch (Throwable $error) {
                report($error);
                $this->error('  falló: '.$error->getMessage());
                $correcto = false;
            }
        }

        return $correcto ? self::SUCCESS : self::FAILURE;
    }

    private function convertir(bool $aplicar): bool
    {
        foreach (self::CAMPOS as $tabla => [$columnas, $titular]) {
            $cambiadas = 0;
            $porTabla = function () use ($tabla, $columnas, $titular, $aplicar, &$cambiadas): void {
                DB::table($tabla)->select(['id', $titular, ...$columnas])
                    ->when(in_array($tabla, self::CON_ANULACION, true), fn ($query) => $query->where('anulado', false))
                    ->orderBy('id')->chunkById(200, function ($filas) use ($tabla, $columnas, $titular, $aplicar, &$cambiadas): void {
                        foreach ($filas as $fila) {
                            $antes = array_filter(array_intersect_key((array) $fila, array_flip($columnas)), fn ($valor) => is_string($valor) && $valor !== mb_strtoupper($valor));
                            if ($antes === []) {
                                continue;
                            }
                            $cambiadas++;
                            if (! $aplicar) {
                                continue;
                            }
                            $despues = array_map(fn (string $valor): string => mb_strtoupper($valor), $antes);
                            DB::table($tabla)->where('id', $fila->id)->update($despues);
                            Auditoria::registrar(null, 'datos.mayusculas', $tabla, $fila->id, $this->titular($titular, $fila->{$titular}), $antes, $despues);
                        }
                    });
            };
            $aplicar ? DB::transaction($porTabla) : $porTabla();
            $this->line(sprintf('  %s: %d fila(s) %s', $tabla, $cambiadas, $aplicar ? 'convertida(s)' : 'por convertir'));
        }

        return true;
    }

    private function titular(string $columna, ?string $valor): ?string
    {
        return $columna === 'vinculo_laboral_id' && $valor !== null
            ? DB::table('vinculos_laborales')->where('id', $valor)->value('usuario_id')
            : $valor;
    }
}
