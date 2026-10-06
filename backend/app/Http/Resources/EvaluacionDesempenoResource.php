<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvaluacionDesempenoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'usuario_id' => $this->usuario_id,
            'usuario' => $this->whenLoaded('usuario', fn (): ?array => $this->usuario === null ? null : [
                'id' => $this->usuario->id,
                'nombres' => $this->usuario->nombres,
                'apellido_paterno' => $this->usuario->apellido_paterno,
                'apellido_materno' => $this->usuario->apellido_materno,
            ]),
            'evaluador' => $this->whenLoaded('evaluador', fn (): ?array => $this->evaluador === null ? null : [
                'id' => $this->evaluador->id,
                'nombres' => $this->evaluador->nombres,
                'apellido_paterno' => $this->evaluador->apellido_paterno,
                'apellido_materno' => $this->evaluador->apellido_materno,
            ]),
            'documento' => $this->whenLoaded('documento', fn (): ?array => $this->documento === null ? null : [
                'num_resolucion' => $this->documento->num_resolucion,
                'estado' => $this->documento->estado,
            ]),
            'ciclo_anio' => $this->ciclo_anio,
            'fecha_evaluacion' => $this->fecha_evaluacion,
            // Sin seccion07.ver_calificacion no se envían (pendiente de decisión del cliente).
            'calificacion' => $request->user()?->can('seccion07.ver_calificacion') ? $this->calificacion : null,
            'puntaje' => $request->user()?->can('seccion07.ver_calificacion') ? $this->puntaje : null,
            'plan_mejora' => $this->plan_mejora,
            'anulado' => $this->anulado,
        ];
    }
}
