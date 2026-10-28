<?php

namespace App\Http\Requests;

use App\Models\Usuario;
use App\Support\Documentos\ArchivoPdf;
use App\Support\Parametros;
use App\Support\ReglasVinculo;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator as Validacion;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * Alta manual de una persona con su primer vínculo laboral (obligatorio en este flujo). La persona se valida con las
 * mismas reglas del alta por lotes (StoreUsuariosRequest, una sola fila); el vínculo y sus datos del puesto, con las de la
 * sección 02 (ReglasVinculo), sin repetirlas. El tipo de personal y la ley del vínculo son los de la persona, y el primer
 * vínculo es el vigente (sin fecha de cese). Los errores llegan como usuarios.0.*, vinculo.* y datos_puesto.*.
 */
class StoreUsuarioConVinculoRequest extends StoreUsuariosRequest
{
    /** @var array<string, mixed> */
    private array $vinculo = [];

    /** @var array<string, mixed> */
    private array $datosPuesto = [];

    protected function prepareForValidation(): void
    {
        if ($this->exists('registro')) {
            $datos = is_string($this->input('registro')) ? json_decode($this->input('registro'), true) : null;
            if (! is_array($datos) || array_is_list($datos)) {
                throw ValidationException::withMessages(['documento_ingreso' => 'No se pudo leer el formulario adjunto. Vuelve a intentarlo.']);
            }
            $this->request->remove('registro');
            $this->merge($datos);
        }
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'usuarios' => ['required', 'array', 'list', 'size:1'],
            'vinculo' => ['required', 'array'],
            // Puede venir vacío (los datos del puesto administrativo son todos opcionales); el docente exige su condición.
            'datos_puesto' => ['present', 'array'],
            'documento_ingreso' => ['required_with:documento_ingreso_pdf', 'array:tipo_documento_id,emisor,fecha_emision,modalidad_presentacion'],
            'documento_ingreso_pdf' => ['required_with:documento_ingreso', 'file', 'max:'.Parametros::entero('tamano_maximo_pdf_kb')],
            'documento_ingreso.tipo_documento_id' => ['required_with:documento_ingreso', 'uuid', Rule::exists('tipos_documento', 'id')->where(fn ($q) => $q->where('seccion_legajo_id', 2)->where('activo', true)->whereIn('codigo', ['S02_C_RESOLUCION_DESIGNACION', 'S02_E_RESOLUCION_CARRERA', 'S02_F_CONTRATO']))],
            'documento_ingreso.emisor' => 'required_with:documento_ingreso|string|max:200',
            'documento_ingreso.fecha_emision' => 'required_with:documento_ingreso|date_format:Y-m-d|before_or_equal:today',
            'documento_ingreso.modalidad_presentacion' => ['required_with:documento_ingreso', Rule::in(['original', 'copia_simple', 'copia_fedateada', 'digital_firmado'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['usuarios', 'vinculo', 'datos_puesto', 'documento_ingreso', 'documento_ingreso_pdf']) as $campo) {
                $validator->errors()->add($campo, 'Campo no permitido.');
            }
            if ($this->hasFile('documento_ingreso_pdf') && ! ArchivoPdf::esPdf($this->file('documento_ingreso_pdf'))) {
                $validator->errors()->add('documento_ingreso_pdf', 'El archivo debe ser un PDF válido; se comprueba su contenido.');
            }
            $this->vinculo = $this->parte($validator, 'vinculo', array_diff_key(ReglasVinculo::VINCULO, array_flip(ReglasVinculo::NO_EN_ALTA)));
            $tipo = $this->input('usuarios.0.tipo_personal');
            $this->datosPuesto = is_string($tipo) && isset(ReglasVinculo::PERFIL[$tipo]) ? $this->parte($validator, 'datos_puesto', ReglasVinculo::PERFIL[$tipo]) : [];
        });
    }

    /** Valida una parte con sus reglas de la sección 02; los campos que no son de esas reglas se rechazan. */
    private function parte(Validator $validator, string $clave, array $reglas): array
    {
        $datos = $this->input($clave);
        if (! is_array($datos)) {
            return [];
        }
        foreach (array_diff(array_keys($datos), array_keys($reglas)) as $campo) {
            $validator->errors()->add("$clave.$campo", in_array($campo, ReglasVinculo::NO_EN_ALTA, true)
                ? 'En el alta se toma de los datos de la persona (el primer vínculo es el vigente).' : 'Campo no permitido.');
        }
        $parcial = Validacion::make($datos, $reglas, [], ReglasVinculo::ATRIBUTOS);
        foreach ($parcial->errors()->messages() as $campo => $mensajes) {
            foreach ($mensajes as $mensaje) {
                $validator->errors()->add("$clave.$campo", $mensaje);
            }
        }

        return $parcial->errors()->isEmpty() ? $parcial->validated() : [];
    }

    /** @return array<string, mixed> */
    public function vinculo(): array
    {
        return $this->vinculo;
    }

    /** @return array<string, mixed> */
    public function datosPuesto(): array
    {
        return $this->datosPuesto;
    }

    public function messages(): array
    {
        return [...parent::messages(), 'usuarios.size' => 'El registro manual da de alta una sola persona.',
            'usuarios.0.numero_doc_identidad.unique' => 'Esta persona ya está registrada con ese DNI.'];
    }

    /**
     * DNI ya registrado: además de los errores, identifica a la persona existente para ir a su ficha en vez de buscarla.
     * Solo con legajo.ver (quien no puede ver fichas no recibe nada más) y solo su id y nombre: sin otros datos personales.
     * No se crea nada ni se ofrece registrar otro vínculo (reingreso y renovación pendientes de confirmar con el cliente).
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        $dni = $this->input('usuarios.0.numero_doc_identidad');
        $existente = $validator->errors()->has('usuarios.0.numero_doc_identidad') && is_string($dni) && $this->user()?->can('legajo.ver')
            ? Usuario::query()->where('numero_doc_identidad', $dni)->first(['id', 'nombres', 'apellido_paterno', 'apellido_materno'])
            : null;
        if (! $existente) {
            parent::failedValidation($validator);
        }

        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors()->messages(),
            'persona_existente' => ['id' => $existente->id, 'nombre' => trim($existente->nombres.' '.$existente->apellido_paterno.' '.$existente->apellido_materno)],
        ], 422));
    }
}
