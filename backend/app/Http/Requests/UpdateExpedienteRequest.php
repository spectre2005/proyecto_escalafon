<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateExpedienteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nombres' => 'sometimes|required|string|max:120',
            'apellido_paterno' => 'sometimes|required|string|max:80',
            'apellido_materno' => 'sometimes|nullable|string|max:80',
            'fecha_nacimiento' => 'sometimes|nullable|date_format:Y-m-d|before_or_equal:today',
            'nacionalidad' => 'sometimes|nullable|string|max:60',
            'estado_civil' => 'sometimes|nullable|string|max:15',
            'domicilio' => 'sometimes|nullable|string|max:250',
            'telefono' => ['sometimes', 'nullable', 'string', 'regex:/^[+0-9()\s-]{6,20}$/'],
            'email_institucional' => 'sometimes|required|string|email:rfc|max:255',
            'ubicacion' => 'sometimes|nullable|array:region,provincia,distrito|min:3',
            'ubicacion.region' => 'required_with:ubicacion|string|max:100',
            'ubicacion.provincia' => 'required_with:ubicacion|string|max:100',
            'ubicacion.distrito' => 'required_with:ubicacion|string|max:100',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'Campo no editable desde la ficha general.');
            }
        });
    }
}
