<?php

namespace App\Support;

use App\Models\Usuario;
use App\Models\VinculoLaboral;

/**
 * Condiciones del perfil que faltan responder, para el bloque «Completa el perfil» de la ficha. Mismo criterio que el
 * contador del Inicio (BanderasSinResponderController) y que AplicacionDocumental, sin cambiar su semántica:
 * - De la persona (usuarios): pendiente si es null. Son datos sensibles: solo se informan con datos_sensibles.ver.
 * - Del puesto: pendiente si la combinación de los vínculos vigentes (no anulados, sin cese) está Sin responder; se
 *   pregunta en cada vínculo vigente que la tiene en null, porque ahí se guarda. Sin vínculo vigente no se pregunta:
 *   la copia de la persona es de solo lectura y la condición se registra al registrar el vínculo (sección 02).
 * Sin responder sigue siendo válido: esto solo lista lo pendiente, no obliga a responder ni convierte null en No.
 */
final class PerfilPendiente
{
    /**
     * @return list<array{bandera: string, ambito: string, vinculo_id?: string, puesto?: string}>
     */
    public static function de(Usuario $persona, AplicacionDocumental $aplicacion, bool $veSensibles): array
    {
        $pendientes = [];
        if ($veSensibles) {
            foreach (AplicacionDocumental::PERSONA as $bandera) {
                if ($persona->{$bandera} === null) {
                    $pendientes[] = ['bandera' => $bandera, 'ambito' => 'persona'];
                }
            }
        }
        if ($aplicacion->sinVinculoVigente) {
            return $pendientes;
        }
        $vigentes = $persona->vinculosLaborales()->where('anulado', false)->whereNull('fecha_cese')
            ->orderByDesc('fecha_inicio')->orderBy('id')->get(['id', 'nombre_puesto', ...AplicacionDocumental::PUESTO]);
        foreach (AplicacionDocumental::PUESTO as $bandera) {
            if ($aplicacion->bandera($bandera) !== null) {
                continue;
            }
            foreach ($vigentes->filter(fn (VinculoLaboral $vinculo): bool => $vinculo->{$bandera} === null) as $vinculo) {
                $pendientes[] = ['bandera' => $bandera, 'ambito' => 'vinculo', 'vinculo_id' => $vinculo->id, 'puesto' => $vinculo->nombre_puesto];
            }
        }

        return $pendientes;
    }
}
