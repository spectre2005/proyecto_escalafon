<?php

namespace App\Support\Guia;

use App\Models\Usuario;
use App\Support\ModulosSistema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/** Guía de uso: solo responde con sus temas, nunca lee legajos ni documentos. */
class GuiaUso
{
    private const NO_ESTA = 'NO_ESTA';

    private const PALABRAS_VACIAS = ['como', 'cómo', 'donde', 'dónde', 'para', 'puedo', 'quiero', 'hacer', 'hago', 'que', 'qué', 'una', 'uno', 'los', 'las', 'del', 'por', 'con', 'sistema', 'necesito', 'debo', 'cual', 'cuál', 'esta', 'este', 'hay'];

    private const PUNTAJE_MINIMO = 3;

    /** Temas que esta persona puede usar: si no puede hacer algo, la guía tampoco se lo explica. */
    public function temasPara(Usuario $usuario): array
    {
        $modulos = collect(ModulosSistema::estados())->pluck('activa', 'codigo');

        return array_values(array_filter((array) config('guia.temas'), function (array $tema) use ($usuario, $modulos): bool {
            if (isset($tema['modulo']) && $modulos->get($tema['modulo']) !== true) {
                return false;
            }

            return array_filter($tema['permiso'], fn (string $permiso): bool => $usuario->can($permiso)) !== [];
        }));
    }

    /** @return list<array<string, mixed>> los temas más relacionados con la pregunta, de mayor a menor */
    public function buscar(string $pregunta, array $temas, int $limite = 2): array
    {
        $palabras = $this->palabras($pregunta);
        $frecuencias = array_map(fn (string $palabra): int => count(array_filter($temas, fn (array $tema): bool => $this->aparece($palabra, $tema))), array_combine($palabras, $palabras) ?: []);
        $puntuados = [];
        foreach ($temas as $indice => $tema) {
            $puntos = $this->puntaje($palabras, $tema, $frecuencias);
            if ($puntos >= self::PUNTAJE_MINIMO) {
                $puntuados[] = [$puntos, $indice, $tema];
            }
        }
        usort($puntuados, fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        return array_column(array_slice($puntuados, 0, $limite), 2);
    }

    /**
     * Siempre contesta: si el modelo no da una respuesta confiable, responde con los pasos de la guía.
     *
     * @return array{respuesta: string, origen: string, estado: string, temas: list<array<string, mixed>>}
     */
    public function responder(string $pregunta, array $temas, ?string $temaAnterior = null): array
    {
        if (($charla = $this->charla($pregunta)) !== null) {
            return ['respuesta' => $charla, 'origen' => 'sistema', 'estado' => 'charla', 'temas' => []];
        }
        if (preg_match('/^\W*(cu[aá]nt[oa]s|qui[eé]nes)\b/iu', $pregunta)) {
            return ['respuesta' => 'Todavía no consulto datos del personal: solo explico cómo usar el sistema. Si tu perfil lo permite, verás el total en Inicio, en «Personal registrado»; para listados y cifras, usa Reportes.',
                'origen' => 'sistema', 'estado' => 'consulta_datos', 'temas' => array_values(array_filter($temas, fn (array $tema): bool => $tema['id'] === 'reportes'))];
        }
        $encontrados = $this->buscar($pregunta, $temas);
        if ($encontrados === [] || ! $this->preguntaCubierta($pregunta, $encontrados[0])) {
            // «¿Y después?» sigue hablando del tema anterior, siempre que este perfil pueda verlo.
            $anterior = array_values(array_filter($temas, fn (array $tema): bool => $tema['id'] === $temaAnterior));
            if ($anterior === [] || ! $this->esSeguimiento($pregunta)) {
                return ['respuesta' => 'No tengo esa información en la guía. Prueba con otras palabras o revisa la pestaña Temas.', 'origen' => 'sistema', 'estado' => 'sin_tema', 'temas' => []];
            }
            $encontrados = $anterior;
        }
        // Solo el mejor tema: con dos, el modelo pequeño mezcla los pasos de uno con la pantalla del otro.
        $tema = $encontrados[0];
        if (! config('guia.ia_activa')) {
            return $this->desdeLaGuia($tema, $encontrados, 'sin_ia');
        }
        if (! empty($tema['solo_pasos'])) {
            return $this->desdeLaGuia($tema, $encontrados, 'solo_pasos');
        }
        $url = rtrim((string) config('escaner.url'), '/');
        abort_unless(in_array(parse_url($url, PHP_URL_HOST), ['ollama', 'localhost', '127.0.0.1', 'host.docker.internal'], true)
            && parse_url($url, PHP_URL_SCHEME) === 'http' && ! str_contains((string) config('escaner.modelo'), ':cloud'), 503, 'Configura un modelo local de Ollama.');
        $guia = "{$tema['titulo']}\n".implode("\n", array_map(fn (string $paso): string => "- {$paso}", $tema['pasos']));
        try {
            $texto = trim((string) Http::connectTimeout(5)->timeout((int) config('guia.timeout', 90))->post($url.'/api/chat', [
                'model' => config('escaner.modelo'), 'stream' => false, 'think' => false, 'keep_alive' => '5m',
                'options' => ['temperature' => 0, 'num_ctx' => 2048, 'num_predict' => 160, 'num_thread' => (int) config('escaner.hilos', 2)],
                'messages' => [
                    ['role' => 'system', 'content' => 'Eres la guía de uso de un sistema de legajos de personal. Responde en español, en máximo 3 oraciones, usando SOLO la GUÍA. Copia tal cual los nombres de menús y botones. No inventes pantallas, botones ni datos. No repitas la pregunta ni escribas la palabra TEMA: empieza directamente con la indicación. Si la GUÍA no responde la pregunta, responde exactamente '.self::NO_ESTA.'.'],
                    ['role' => 'user', 'content' => "GUÍA:\n{$guia}\n\nPREGUNTA: ".$this->sinNumerosLargos($pregunta)],
                ],
            ])->throw()->json('message.content'));
        } catch (ConnectionException|RequestException) {
            return $this->desdeLaGuia($tema, $encontrados, 'ia_no_disponible');
        }
        $texto = $this->limpiar($texto);
        if ($texto === '' || str_contains($texto, self::NO_ESTA) || ! $this->respuestaFiel($texto, $pregunta, $tema)) {
            return $this->desdeLaGuia($tema, $encontrados, 'respuesta_descartada');
        }

        return ['respuesta' => $texto, 'origen' => 'ia', 'estado' => 'respondida', 'temas' => $encontrados];
    }

    private function desdeLaGuia(array $tema, array $encontrados, string $estado): array
    {
        $pasos = array_map(fn (int $indice, string $paso): string => ($indice + 1).'. '.$paso, array_keys($tema['pasos']), $tema['pasos']);

        return ['respuesta' => "{$tema['titulo']}:\n".implode("\n", $pasos), 'origen' => 'guia', 'estado' => $estado, 'temas' => $encontrados];
    }

    private function charla(string $pregunta): ?string
    {
        $normal = trim((string) preg_replace('/[^\p{L}\s]+/u', '', $this->normalizar($pregunta)));
        if (preg_match('/^(hola|buenas|buenos dias|buenas tardes|buenas noches|hey)( .{0,20})?$/u', $normal)) {
            return 'Hola. Puedo explicarte cómo usar el sistema: registrar personal, subir documentos, emitir constancias, generar reportes y más. ¿Qué necesitas hacer?';
        }
        if (preg_match('/^(gracias|muchas gracias|ok|listo|perfecto)( .{0,15})?$/u', $normal)) {
            return 'Con gusto. Si necesitas algo más del sistema, pregúntame.';
        }
        if (preg_match('/(que|como) (puedes|sabes) (hacer|ayudar|ayudarme)|en que (me )?(puedes|ayudas)|quien eres|que eres|para que sirves/u', $normal)) {
            return 'Soy la guía de uso del sistema. Te explico paso a paso cómo hacer cada tarea, según tu perfil. No veo datos de personas ni hago cambios por ti.';
        }

        return null;
    }

    /** Solo lo que continúa claramente, como «¿y después?»: una pregunta corta de otro asunto no sigue el tema anterior. */
    private function esSeguimiento(string $pregunta): bool
    {
        $normal = $this->normalizar($pregunta);

        return preg_match('/^\W*(y|pero|entonces|luego|despues|tambien)\b|\b(siguiente paso|que sigue|ese boton|esa pantalla|no entiendo|explicame)\b/u', $normal) === 1;
    }

    /** Quita lo que el modelo repite de la indicación: la pregunta y el rótulo del tema. */
    private function limpiar(string $texto): string
    {
        $lineas = array_map(fn (string $linea): string => trim((string) preg_replace('/^¿[^?]*\?\s*/u', '', trim($linea))), explode("\n", $texto));

        return implode("\n", array_filter($lineas, fn (string $linea): bool => $linea !== '' && ! preg_match('/^(TEMA|GU[IÍ]A|PREGUNTA)\s*:/iu', $linea)));
    }

    /** «Boleta de pago» no está en ningún paso de Reportes: aunque «imprimir» coincida, el tema no responde eso. */
    private function preguntaCubierta(string $pregunta, array $tema): bool
    {
        $palabras = $this->palabras($pregunta);
        $fuera = array_filter($palabras, fn (string $palabra): bool => ! $this->aparece($palabra, $tema));

        return count($fuera) * 2 <= count($palabras);
    }

    /** Un modelo pequeño agrega pasos que suenan bien: si usa muchas palabras ajenas al tema, valen más los pasos reales. */
    private function respuestaFiel(string $texto, string $pregunta, array $tema): bool
    {
        $conocidas = [...$this->palabras($pregunta), ...array_map(fn (string $clave): string => $this->normalizar($clave), $tema['palabras']), ...$this->palabras($tema['titulo'].' '.implode(' ', $tema['pasos']))];
        $largas = array_filter($this->palabras($texto), fn (string $palabra): bool => mb_strlen($palabra) >= 5);
        $ajenas = array_filter($largas, function (string $palabra) use ($conocidas): bool {
            $raiz = mb_substr($palabra, 0, 4);

            return array_filter($conocidas, fn (string $otra): bool => str_starts_with($otra, $raiz)) === [];
        });

        return count($ajenas) * 4 <= count($largas);
    }

    /** Lo que el navegador necesita: nunca los permisos con los que se filtró. */
    public function presentar(array $tema): array
    {
        return [
            'id' => $tema['id'], 'titulo' => $tema['titulo'], 'ruta' => $tema['ruta'],
            'palabras' => $tema['palabras'], 'pasos' => $tema['pasos'],
        ];
    }

    /** Un DNI escrito en la pregunta no aporta para orientar y no debe llegar al modelo. */
    private function sinNumerosLargos(string $texto): string
    {
        return preg_replace('/\d{6,}/', '[número]', $texto) ?? $texto;
    }

    private function aparece(string $palabra, array $tema): bool
    {
        $raiz = mb_substr($palabra, 0, 5);
        $todas = [...array_map(fn (string $clave): string => $this->normalizar($clave), $tema['palabras']), ...$this->palabras($tema['titulo'].' '.implode(' ', $tema['pasos']))];

        return array_filter($todas, fn (string $otra): bool => str_starts_with($otra, $raiz)) !== [];
    }

    private function puntaje(array $palabras, array $tema, array $frecuencias): int
    {
        $puntos = 0;
        $titulo = $this->palabras($tema['titulo']);
        $claves = array_map(fn (string $palabra): string => $this->normalizar($palabra), $tema['palabras']);
        $pasos = $this->palabras(implode(' ', $tema['pasos']));
        foreach ($palabras as $palabra) {
            $raiz = mb_substr($palabra, 0, 5);
            $coincide = fn (array $lista): bool => array_filter($lista, fn (string $otra): bool => str_starts_with($otra, $raiz)) !== [];
            $valor = $coincide($claves) ? 3 : ($coincide($titulo) ? 2 : ($coincide($pasos) ? 1 : 0));
            // «Legajo» o «documento» salen en casi todos los temas: no deben ganarle a «certificado».
            $puntos += ($frecuencias[$palabra] ?? 0) >= 4 ? min($valor, 1) : $valor;
        }

        return $puntos;
    }

    /** @return list<string> */
    private function palabras(string $texto): array
    {
        $vacias = array_map(fn (string $palabra): string => $this->normalizar($palabra), self::PALABRAS_VACIAS);
        $palabras = preg_split('/[^\p{L}\p{N}]+/u', $this->normalizar($texto), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($palabras, fn (string $palabra): bool => mb_strlen($palabra) >= 3 && ! in_array($palabra, $vacias, true))));
    }

    private function normalizar(string $texto): string
    {
        return strtr(mb_strtolower($texto), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }
}
