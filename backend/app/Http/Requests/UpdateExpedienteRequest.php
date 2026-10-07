<?php

namespace App\Http\Requests;

use App\Models\Ubigeo;
use App\Models\Usuario;
use App\Support\Catalogos;
use App\Support\RegimenesLaborales;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
            'tipo_doc_identidad' => ['sometimes', 'required', Rule::in(Catalogos::TIPOS_DOC_IDENTIDAD)],
            'numero_doc_identidad' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('usuarios', 'numero_doc_identidad')->ignore($this->route('usuario'))],
            'nombres' => 'sometimes|required|string|max:120',
            'apellido_paterno' => 'sometimes|required|string|max:80',
            'apellido_materno' => 'sometimes|nullable|string|max:80',
            'fecha_nacimiento' => 'sometimes|nullable|date_format:Y-m-d|before_or_equal:today',
            'estado_civil' => ['sometimes', 'nullable', Rule::in(Catalogos::ESTADOS_CIVILES)],
            'domicilio' => 'sometimes|nullable|string|max:250',
            'telefono' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{9}$/'],
            'email_institucional' => 'sometimes|required|string|email:rfc|max:255',
            'tipo_personal' => 'sometimes|required|in:docente,administrativo',
            'regimen_laboral' => 'sometimes|required|string|max:20',
            'nombre_puesto' => 'sometimes|nullable|string|max:150',
            'unidad_organica_id' => 'sometimes|nullable|uuid|exists:unidades_organicas,id',
            'regimen_normativo_id' => 'sometimes|nullable|uuid|exists:regimenes_normativos,id',
            'activo' => 'sometimes|required|boolean',
            'con_discapacidad' => 'sometimes|required|boolean',
            'obligado_djbr' => 'sometimes|required|boolean',
            'exige_colegiatura' => 'sometimes|required|boolean',
            'exige_estudios_basicos' => 'sometimes|required|boolean',
            'tiene_periodo_prueba' => 'sometimes|required|boolean',
            'tiene_derechohabientes' => 'sometimes|required|boolean',
            'ubicacion' => 'sometimes|nullable|array:region,provincia,distrito|min:3',
            'ubicacion.region' => 'required_with:ubicacion|string|max:100',
            'ubicacion.provincia' => 'required_with:ubicacion|string|max:100',
            'ubicacion.distrito' => 'required_with:ubicacion|string|max:100',
        ];
    }

    public function messages(): array
    {
        return ['telefono.regex' => 'El teléfono debe contener 9 dígitos.'];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'Campo no editable desde la ficha general.');
            }
            if ($this->hasAny(['tipo_personal', 'regimen_laboral'])) {
                $person = Usuario::query()->findOrFail($this->route('usuario'));
                $type = $this->input('tipo_personal', $person->tipo_personal);
                $regime = $this->input('regimen_laboral', $person->regimen_laboral);
                $codes = RegimenesLaborales::codigos($type);
                if ($codes !== [] && ! in_array($regime, $codes, true)) {
                    $validator->errors()->add('regimen_laboral', 'Selecciona un régimen laboral válido para el tipo de personal.');
                }
            }
            if ($this->hasAny(['tipo_doc_identidad', 'numero_doc_identidad'])) {
                $person = Usuario::query()->findOrFail($this->route('usuario'));
                $type = $this->input('tipo_doc_identidad', $person->tipo_doc_identidad);
                $number = $this->input('numero_doc_identidad', $person->numero_doc_identidad);
                if ($type === 'dni' && (! is_string($number) || ! preg_match('/^[0-9]{8}$/', $number))) {
                    $validator->errors()->add('numero_doc_identidad', 'El DNI debe contener 8 dígitos.');
                }
            }
            $location = $this->input('ubicacion');
            if (is_array($location) && isset($location['region'], $location['provincia'], $location['distrito'])) {
                $exists = Ubigeo::query();
                foreach (['region', 'provincia', 'distrito'] as $field) {
                    $exists->whereRaw('LOWER('.$field.') = ?', [mb_strtolower(trim($location[$field]))]);
                }
                if (! $exists->exists()) {
                    $validator->errors()->add('ubicacion.distrito', 'Selecciona una región, provincia y distrito del catálogo.');
                }
            }
        });
    }
}
