<?php

namespace App\Support;

/**
 * Reglas del vínculo laboral (vinculos_laborales) y de sus datos del puesto (perfil_docente / perfil_administrativo).
 * Una sola fuente para la sección 02 (Seccion02Controller) y para el alta de personal con su primer vínculo
 * (UsuarioController::storeConVinculo), para que los dos formularios no tengan reglas distintas.
 */
final class ReglasVinculo
{
    public const VINCULO = [
        'tipo_personal' => 'required|in:docente,administrativo',
        'regimen_laboral' => 'required|in:carrera,plazo_determinado,designado',
        'regimen_normativo_id' => 'required|uuid|exists:regimenes_normativos,id',
        'unidad_organica_id' => 'nullable|uuid|exists:unidades_organicas,id',
        'nombre_puesto' => 'required|string|max:150',
        'obligado_djbr' => 'sometimes|nullable|boolean',
        'exige_colegiatura' => 'sometimes|nullable|boolean',
        'exige_estudios_basicos' => 'sometimes|nullable|boolean',
        'tiene_periodo_prueba' => 'sometimes|nullable|boolean',
        'proceso_seleccion' => 'nullable|string|max:100',
        'fecha_resultado_seleccion' => 'nullable|date_format:Y-m-d|before_or_equal:today',
        'numero_documento_vinculo' => 'nullable|string|max:60',
        'fecha_inicio' => 'required|date_format:Y-m-d',
        'fecha_fin_prevista' => 'nullable|date_format:Y-m-d|after_or_equal:fecha_inicio',
        'fecha_cese' => 'nullable|date_format:Y-m-d|after_or_equal:fecha_inicio|before_or_equal:today',
    ];

    // Datos del puesto según el tipo de personal del vínculo: solo los campos del esquema (perfil_docente y
    // perfil_administrativo). La categoría es solo de ordinarios (comentario del esquema) y el subtipo, de extraordinarios.
    public const PERFIL = [
        'docente' => [
            'condicion_docente' => 'required|in:ordinario,extraordinario,contratado',
            'categoria' => 'nullable|in:principal,asociado,auxiliar|prohibited_unless:condicion_docente,ordinario',
            'dedicacion' => 'nullable|in:exclusiva,tiempo_completo,tiempo_parcial',
            'subtipo_extraordinario' => 'nullable|in:emerito,honorario,investigador,visitante,experto|prohibited_unless:condicion_docente,extraordinario',
            'funcion_previa' => 'nullable|in:jefe_practica,ayudante_catedra,ayudante_laboratorio',
            'codigo_renacyt' => 'nullable|string|max:30',
        ],
        'administrativo' => [
            'grupo_ocupacional' => 'nullable|in:profesional,tecnico,auxiliar',
            'nivel_carrera' => 'nullable|string|max:20',
            'cargo_estructural' => 'nullable|string|max:150',
            'es_cargo_confianza' => 'sometimes|boolean',
        ],
    ];

    /** En el alta, el tipo de personal y la ley del vínculo son los de la persona, y el primer vínculo es el vigente. */
    public const NO_EN_ALTA = ['tipo_personal', 'regimen_normativo_id', 'fecha_cese'];
}
