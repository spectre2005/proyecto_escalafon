<?php

namespace App\Http\Requests;

use App\Support\RegimenesLaborales;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUsuariosRequest extends FormRequest
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
            'usuarios' => ['required', 'array', 'list', 'min:1', 'max:1000'],
            'usuarios.*' => ['required', 'array:numero_doc_identidad,nombres,apellido_paterno,apellido_materno,email_institucional,tipo_personal,regimen_laboral,telefono,ubigeo_id'],
            'usuarios.*.ubigeo_id' => ['nullable', 'uuid', Rule::exists('ubigeo', 'id')],
            'usuarios.*.numero_doc_identidad' => ['bail', 'required', 'string', 'regex:/^[0-9]{8}$/', 'distinct:strict', Rule::unique('usuarios', 'numero_doc_identidad')],
            'usuarios.*.nombres' => ['required', 'string', 'max:120'],
            'usuarios.*.apellido_paterno' => ['required', 'string', 'max:80'],
            'usuarios.*.apellido_materno' => ['nullable', 'string', 'max:80'],
            'usuarios.*.email_institucional' => ['bail', 'required', 'string', 'email:rfc', 'max:255', 'distinct:ignore_case'],
            'usuarios.*.tipo_personal' => ['required', Rule::in(['docente', 'administrativo'])],
            'usuarios.*.regimen_laboral' => ['bail', 'required', 'string', 'max:20', $this->regimenDelTipo(...)],
            'usuarios.*.telefono' => ['nullable', 'string', 'regex:/^[+0-9()\s-]{6,20}$/'],
        ];
    }

    private function regimenDelTipo(string $attribute, mixed $value, Closure $fail): void
    {
        $index = explode('.', $attribute)[1];
        $codigos = RegimenesLaborales::codigos($this->input("usuarios.$index.tipo_personal"));
        if ($codigos !== [] && ! in_array($value, $codigos, true)) {
            $fail('El régimen laboral debe ser uno de: '.implode(', ', $codigos).'.');
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'string' => 'El campo :attribute debe ser texto.',
            'max' => 'El campo :attribute supera el máximo permitido (:max).',
            'min' => 'Debe enviar al menos :min registro.',
            'array' => 'La estructura de :attribute no es válida.',
            'list' => 'Los usuarios deben enviarse como una lista.',
            'regex' => 'El formato de :attribute no es válido.',
            'distinct' => 'El campo :attribute está duplicado en el archivo.',
            'unique' => 'El campo :attribute ya está registrado.',
            'email' => 'El correo de :attribute no es válido.',
            'in' => 'El tipo de personal debe ser docente o administrativo.',
        ];
    }
}
