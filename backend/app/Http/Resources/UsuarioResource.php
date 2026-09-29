<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UsuarioResource extends JsonResource
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
            'numero_doc_identidad' => $this->numero_doc_identidad,
            'nombres' => $this->nombres,
            'apellido_paterno' => $this->apellido_paterno,
            'apellido_materno' => $this->apellido_materno ?? '',
            'email_institucional' => $this->email_institucional,
            'tipo_personal' => $this->tipo_personal,
            'regimen_laboral' => $this->regimen_laboral,
            'telefono' => $this->telefono ?? '',
            'activo' => $this->activo,
        ];
    }
}
