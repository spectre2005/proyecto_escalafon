<?php

namespace App\Support;

use App\Models\CertificadoDiscapacidad;
use App\Models\DocumentoUsuario;
use App\Models\Familiar;
use App\Models\PeriodoPrueba;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Illuminate\Support\Collection;

/**
 * Qué tipos de documento se ofrecen para cargar a una persona (aplicabilidad) y, para las banderas del perfil, si
 * se exigen. Es la única fuente de las banderas: PerfilDocumental (lo exigido) y las secciones la consultan.
 *
 * - Tipo de personal: tipos_documento.aplica_tipo_personal (ambos, docente o administrativo).
 * - Régimen: aplica_carrera / aplica_plazo_determinado / aplica_designado.
 * - Fuente: todos los vínculos no anulados, también los cesados (el legajo guarda la historia y al digitalizarlo se
 *   cargan documentos de vínculos anteriores); sin vínculos, el tipo de personal de la persona. El tipo de vínculo sale
 *   solo de los vínculos: sin vínculos, o con uno sin tipo, está Sin definir y no se oculta nada por régimen.
 * - Si no se puede saber (sin tipo de personal, o con un régimen que no es carrera, plazo determinado ni designado),
 *   el tipo se ofrece: nunca se impide cargar algo legítimo por falta de datos.
 * - El cargo (puesto) no se usa: el catálogo no relaciona documentos con puestos (pendiente U4).
 *
 * Banderas (requiere_* del tipo), con tres estados: true = Sí, false = No, null = Sin responder.
 * - Las del puesto se registran en el vínculo laboral. Para exigir cuentan los vínculos vigentes (sin cese, no
 *   anulados): Sí si alguno dice Sí, No solo si todos dicen No, si no Sin responder. Sin vínculo vigente se usa la
 *   copia de la persona (usuarios), solo de lectura, con aviso.
 * - Para ofrecer basta que algún vínculo no anulado, también cesado, diga Sí.
 * - Las de la persona (tiene_derechohabientes, con_discapacidad) están solo en usuarios.
 * - No: el tipo no se ofrece y su carga se rechaza (lo ya cargado se sigue viendo y se puede subsanar).
 *   Sin responder: se ofrece con aviso y queda por determinar (no cuenta en la completitud). Sí: se exige.
 */
final class AplicacionDocumental
{
    /** Banderas del puesto: se registran en el vínculo laboral. */
    public const PUESTO = ['obligado_djbr', 'exige_colegiatura', 'exige_estudios_basicos', 'tiene_periodo_prueba'];

    /** Banderas de la persona: solo en usuarios. */
    public const PERSONA = ['tiene_derechohabientes', 'con_discapacidad'];

    /** Columna requiere_* => [bandera, cuándo corresponde, pregunta tal como se ve en los formularios]. */
    public const REQUIERE = [
        'requiere_estudios_basicos' => ['exige_estudios_basicos', 'solo si el puesto exige estudios básicos', 'El puesto exige estudios básicos'],
        'requiere_djbr' => ['obligado_djbr', 'solo para obligados a la declaración jurada de bienes y rentas', 'Obligado a presentar declaración jurada de bienes y rentas'],
        'requiere_colegiatura' => ['exige_colegiatura', 'solo si el puesto exige colegiatura', 'El puesto exige colegiatura'],
        'requiere_derechohabientes' => ['tiene_derechohabientes', 'solo si tiene derechohabientes', 'Tiene derechohabientes'],
        'requiere_periodo_prueba' => ['tiene_periodo_prueba', 'solo si tiene período de prueba', 'Tiene período de prueba'],
        'requiere_discapacidad' => ['con_discapacidad', 'solo con discapacidad del servidor o de alguno de sus hijos', 'Con discapacidad (el servidor o alguno de sus hijos)'],
    ];

    /**
     * g, i y j de la sección 01 dependen de tiene_derechohabientes, salvo que ya haya familiares registrados.
     * TODO PENDIENTE de confirmar con el cliente (ESTADO_PROYECTO.md, pregunta 18): la directiva no ata estos
     * documentos a la bandera; antes dependían solo de los familiares registrados.
     */
    public const DE_DERECHOHABIENTES = [Seccion01::PARTIDA_MATRIMONIO, Seccion01::DNI_CONYUGE, Seccion01::DNI_HIJOS];

    /**
     * Módulos de la sección 01 (claves del menú, como «menu» en config/funcionalidades.php) que sigue una bandera de la
     * persona: con la bandera en No y sin datos registrados (registros no anulados ni documentos de sus tipos), el
     * módulo entero se oculta. Con No y datos, Sí o Sin responder, se mantiene. El backend no rechaza sus registros.
     */
    public const MODULOS = [
        's01-familia' => ['tiene_derechohabientes', self::DE_DERECHOHABIENTES],
        's01-discapacidad' => ['con_discapacidad', ['S01_K_CERTIFICADO_DISCAPACIDAD']],
    ];

    private ?bool $hayFamiliares = null;

    /**
     * @param  list<string>  $tiposPersonal
     * @param  list<string>  $regimenes
     * @param  array<string, ?bool>  $banderas  para exigir
     * @param  array<string, ?bool>  $ofrecidas  para ofrecer
     */
    private function __construct(
        private readonly string $personaId,
        private readonly array $tiposPersonal,
        private readonly array $regimenes,
        public readonly array $banderas,
        private readonly array $ofrecidas,
        public readonly bool $sinVinculoVigente,
    ) {}

    /** @param Collection<int, VinculoLaboral> $vinculos */
    public static function de(Usuario $persona, Collection $vinculos): self
    {
        $noAnulados = $vinculos->reject(fn (VinculoLaboral $vinculo): bool => (bool) $vinculo->anulado);
        $vigentes = $noAnulados->whereNull('fecha_cese');
        $origen = $noAnulados->isEmpty() ? collect([$persona]) : $noAnulados;
        $banderas = [];
        $ofrecidas = [];
        foreach (self::PUESTO as $bandera) {
            $banderas[$bandera] = $vigentes->isEmpty() ? $persona->{$bandera} : self::combinar($vigentes->pluck($bandera));
            $ofrecidas[$bandera] = $noAnulados->contains(fn (VinculoLaboral $vinculo): bool => $vinculo->{$bandera} === true) ? true : $banderas[$bandera];
        }
        foreach (self::PERSONA as $bandera) {
            $banderas[$bandera] = $ofrecidas[$bandera] = $persona->{$bandera};
        }

        return new self(
            (string) $persona->getKey(),
            $origen->pluck('tipo_personal')->filter()->map(fn (string $tipo): string => mb_strtolower($tipo))->unique()->values()->all(),
            // Tipo de vínculo: solo de los vínculos (no de la persona, que guarda la ley). null = Sin definir: con uno así
            // no todos son conocidos y no se oculta nada por régimen.
            $noAnulados->pluck('regimen_laboral')->unique()->values()->all(),
            $banderas,
            $ofrecidas,
            $vigentes->isEmpty(),
        );
    }

    public static function para(Usuario $persona): self
    {
        return self::de($persona, $persona->vinculosLaborales()->get());
    }

    /**
     * Varios vínculos vigentes: Sí si alguno dice Sí, No solo si todos dicen No, si no Sin responder.
     *
     * @param  iterable<?bool>  $valores
     */
    public static function combinar(iterable $valores): ?bool
    {
        $valores = collect($valores);
        if ($valores->contains(fn (mixed $valor): bool => $valor === true)) {
            return true;
        }

        return $valores->isNotEmpty() && $valores->every(fn (mixed $valor): bool => $valor === false) ? false : null;
    }

    /** Estado de la bandera para ofrecer (algún vínculo no anulado dice Sí) o, por defecto, para exigir. */
    public function bandera(string $bandera, bool $paraOfrecer = false): ?bool
    {
        return $paraOfrecer ? $this->ofrecidas[$bandera] : $this->banderas[$bandera];
    }

    /** Motivo por el que el tipo no corresponde a la persona (y qué hacer), o null si corresponde o no se puede saber. */
    public function noAplica(TipoDocumento $tipo): ?string
    {
        $solo = $tipo->aplica_tipo_personal ?: 'ambos';
        if ($solo !== 'ambos' && $this->tiposPersonal !== [] && ! in_array($solo, $this->tiposPersonal, true)) {
            return "Solo para personal {$solo}.";
        }
        $aplica = array_keys(array_filter(PerfilDocumental::APLICA, fn (string $columna): bool => (bool) $tipo->{$columna}));
        $conocidos = array_keys(PerfilDocumental::APLICA);
        $todosConocidos = $this->regimenes !== [] && array_diff($this->regimenes, $conocidos) === [];
        if (count($aplica) < count($conocidos) && $todosConocidos && array_intersect($this->regimenes, $aplica) === []) {
            return 'No corresponde a su régimen laboral.';
        }
        foreach (self::REQUIERE as $columna => [$bandera, $texto, $pregunta]) {
            if ($tipo->{$columna} && $this->ofrecidas[$bandera] === false) {
                return "Corresponde {$texto} y «{$pregunta}» está en No. Si corresponde, cámbialo a Sí en {$this->donde($bandera)} y vuelve a intentarlo.";
            }
        }
        if ($this->deDerechohabientes($tipo) && $this->ofrecidas['tiene_derechohabientes'] === false && ! $this->hayFamiliares()) {
            return '«Tiene derechohabientes» está en No y no hay familiares registrados. Si tiene cónyuge, concubino/a o hijos, '
                .'cambia «Tiene derechohabientes» a Sí en la ficha de datos personales y registra sus familiares.';
        }

        return null;
    }

    /** Aviso «Falta definir si corresponde» cuando el tipo se ofrece pero su bandera está sin responder, o null. */
    public function porDefinir(TipoDocumento $tipo): ?string
    {
        if ($this->noAplica($tipo) !== null) {
            return null;
        }
        foreach (self::REQUIERE as $columna => [$bandera, , $pregunta]) {
            if ($tipo->{$columna} && $this->banderas[$bandera] === null) {
                return "Falta definir si corresponde: «{$pregunta}» está sin responder en {$this->donde($bandera)}.";
            }
        }
        if ($this->deDerechohabientes($tipo) && $this->banderas['tiene_derechohabientes'] === null && ! $this->hayFamiliares()) {
            return 'Falta definir si corresponde: «Tiene derechohabientes» está sin responder en la ficha de datos personales.';
        }

        return null;
    }

    /**
     * Exigencia que deciden las banderas del tipo (requiere_*), o null si ninguna la impide (todas en Sí o sin bandera).
     *
     * @return array{exigido: ?bool, motivo: string}|null
     */
    public function exigencia(TipoDocumento $tipo): ?array
    {
        foreach (self::REQUIERE as $columna => [$bandera, $texto, $pregunta]) {
            if (! $tipo->{$columna}) {
                continue;
            }
            if ($this->banderas[$bandera] === false) {
                return ['exigido' => false, 'motivo' => "No se exige: {$texto}."];
            }
            if ($this->banderas[$bandera] === null) {
                return ['exigido' => null, 'motivo' => "Falta definir si corresponde: «{$pregunta}» está sin responder en {$this->donde($bandera)}."];
            }
        }

        return null;
    }

    /** @return list<string> Avisos del perfil (hoy: condiciones del puesto tomadas de la persona por falta de vínculo vigente). */
    public function avisos(): array
    {
        return $this->sinVinculoVigente ? ['No tiene un vínculo laboral vigente: las condiciones del puesto (declaración jurada de bienes y '
            .'rentas, colegiatura, estudios básicos y período de prueba) se toman de la ficha de la persona. Regístralas en su vínculo laboral (sección 02).'] : [];
    }

    /**
     * Tipos activos que no corresponden a la persona (con id y código: los paneles identifican sus tipos por uno u otro).
     *
     * @return list<array{id: string, codigo: string, motivo: string}>
     */
    public function noAplicables(): array
    {
        return $this->listar(fn (TipoDocumento $tipo): ?string => $this->noAplica($tipo));
    }

    /**
     * Tipos activos que se ofrecen con el aviso «Falta definir si corresponde».
     *
     * @return list<array{id: string, codigo: string, motivo: string}>
     */
    public function porDefinirTipos(): array
    {
        return $this->listar(fn (TipoDocumento $tipo): ?string => $this->porDefinir($tipo));
    }

    /** @return list<array{id: string, codigo: string, motivo: string}> */
    private function listar(\Closure $motivo): array
    {
        return TipoDocumento::query()->where('activo', true)->orderBy('seccion_legajo_id')->orderBy('orden')->get()
            ->map(fn (TipoDocumento $tipo): array => ['id' => $tipo->id, 'codigo' => $tipo->codigo, 'motivo' => $motivo($tipo)])
            ->filter(fn (array $tipo): bool => $tipo['motivo'] !== null)->values()->all();
    }

    private function deDerechohabientes(TipoDocumento $tipo): bool
    {
        return (int) $tipo->seccion_legajo_id === 1 && in_array($tipo->codigo, self::DE_DERECHOHABIENTES, true);
    }

    /** @return list<string> Módulos de la sección 01 que se ocultan por su bandera en No y sin datos (MODULOS). */
    public function modulosOcultos(): array
    {
        return array_keys(array_filter(self::MODULOS, fn (array $modulo, string $clave): bool => $this->banderas[$modulo[0]] === false
            && ! $this->hayDatosDelModulo($clave), ARRAY_FILTER_USE_BOTH));
    }

    /** Registros no anulados del módulo (familiares o certificados) o documentos de sus tipos, en cualquier estado. */
    private function hayDatosDelModulo(string $clave): bool
    {
        $registros = $clave === 's01-familia' ? $this->hayFamiliares()
            : CertificadoDiscapacidad::query()->where('usuario_id', $this->personaId)->where('anulado', false)->exists();

        return $registros || DocumentoUsuario::query()->where('usuario_id', $this->personaId)
            ->whereHas('tipoDocumento', fn ($tipo) => $tipo->where('seccion_legajo_id', 1)->whereIn('codigo', self::MODULOS[$clave][1]))->exists();
    }

    /**
     * 2.4: ¿hay períodos de prueba no anulados o documentos m (en cualquier estado)? Con «Tiene período de prueba» en No,
     * la subsección se sigue viendo si los hay (lo registrado no se esconde), aunque no se exige ni se cuenta.
     */
    public function hayDatosPeriodoPrueba(): bool
    {
        return PeriodoPrueba::query()->where('usuario_id', $this->personaId)->where('anulado', false)->exists()
            || DocumentoUsuario::query()->where('usuario_id', $this->personaId)
                ->whereHas('tipoDocumento', fn ($tipo) => $tipo->where('seccion_legajo_id', 2)->where('requiere_periodo_prueba', true))->exists();
    }

    /** Familiares vigentes (sin anulados): con alguno registrado, g, i y j se resuelven por familiar. */
    public function hayFamiliares(): bool
    {
        return $this->hayFamiliares ??= Familiar::query()->where('usuario_id', $this->personaId)->where('anulado', false)->exists();
    }

    private function donde(string $bandera): string
    {
        return in_array($bandera, self::PUESTO, true) ? 'el vínculo laboral (sección 02)' : 'la ficha de datos personales';
    }
}
