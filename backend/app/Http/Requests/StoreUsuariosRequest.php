<?php

namespace App\Http\Requests;

use App\Support\RegimenesLaborales;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'usuarios.*' => ['required', 'array:numero_doc_identidad,nombres,apellido_paterno,apellido_materno,email_institucional,tipo_personal,regimen_normativo_id,regimen_laboral,telefono,ubigeo_id,tiene_derechohabientes,con_discapacidad'],
            'usuarios.*.ubigeo_id' => ['nullable', 'uuid', Rule::exists('ubigeo', 'id')],
            'usuarios.*.numero_doc_identidad' => ['bail', 'required', 'string', 'regex:/^[0-9]{8}$/', 'distinct:strict', Rule::unique('usuarios', 'numero_doc_identidad')],
            'usuarios.*.nombres' => ['required', 'string', 'max:120'],
            'usuarios.*.apellido_paterno' => ['required', 'string', 'max:80'],
            'usuarios.*.apellido_materno' => ['nullable', 'string', 'max:80'],
            'usuarios.*.email_institucional' => ['bail', 'required', 'string', 'email:rfc', 'max:255', 'distinct:ignore_case'],
            'usuarios.*.tipo_personal' => ['required', Rule::in(['docente', 'administrativo'])],
            // Ley (régimen normativo): por id desde el formulario o por código desde la importación (CAS = 1057). El
            // tipo de vínculo no se pide en el alta: se define al registrar el vínculo en 2.2.
            'usuarios.*.regimen_normativo_id' => ['bail', 'nullable', 'uuid', $this->leyDelTipo(...)],
            'usuarios.*.regimen_laboral' => ['bail', 'nullable', 'string', 'max:20', $this->leyDelTipo(...)],
            'usuarios.*.telefono' => ['nullable', 'string', 'regex:/^[0-9]{9}$/'],
            // Banderas de la persona: Sí (true), No (false) o Sin responder (null, por defecto). con_discapacidad exige
            // además discapacidad.editar (CambioDiscapacidad, en el controlador).
            'usuarios.*.tiene_derechohabientes' => ['nullable', 'boolean'],
            'usuarios.*.con_discapacidad' => ['nullable', 'boolean'],
        ];
    }

    /** La ley existe, está activa y corresponde al tipo de personal de la fila (RegimenesLaborales). */
    private function leyDelTipo(string $attribute, mixed $value, Closure $fail): void
    {
        $partes = explode('.', $attribute);
        // Fuera del alta por lotes (UsuarioController::update reutiliza estas reglas) no hay índice de fila: lo valida el controlador.
        if (count($partes) < 3) {
            return;
        }
        $fila = $this->input("usuarios.{$partes[1]}");
        $ley = $partes[2] === 'regimen_normativo_id' ? RegimenesLaborales::ley((string) $value) : RegimenesLaborales::ley(null, (string) $value);
        if ($error = RegimenesLaborales::error($ley, $fila['tipo_personal'] ?? null)) {
            $fail($error);
        }
    }

    /** Toda fila trae la ley, por id o por código. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('usuarios', []) as $indice => $fila) {
                if (is_array($fila) && empty($fila['regimen_normativo_id']) && empty($fila['regimen_laboral'])) {
                    $validator->errors()->add("usuarios.$indice.regimen_normativo_id", RegimenesLaborales::error(null, $fila['tipo_personal'] ?? null));
                }
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'usuarios.*.telefono.regex' => 'El teléfono debe contener 9 dígitos.',
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
