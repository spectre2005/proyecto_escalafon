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
            'foto_path' => $this->foto_path,
            'numero_doc_identidad' => $this->numero_doc_identidad,
            'nombres' => $this->nombres,
            'apellido_paterno' => $this->apellido_paterno,
            'apellido_materno' => $this->apellido_materno ?? '',
            'email_institucional' => $this->email_institucional,
            'tipo_personal' => $this->tipo_personal,
            // Respaldo de lectura (código de ley); la ley va en regimen_normativo_id.
            'regimen_laboral' => $this->regimen_laboral,
            'regimen_normativo_id' => $this->regimen_normativo_id,
            // Ley para listados y reportes; null = Sin definir (nunca se lee regimen_laboral como si fuera la ley).
            'ley' => $this->whenLoaded('regimenNormativo', fn () => $this->regimenNormativo?->only(['id', 'codigo', 'nombre'])),
            'telefono' => $this->telefono ?? '',
            'activo' => $this->activo,
        ];
    }
}
