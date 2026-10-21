<?php

namespace App\Models;

use Database\Factories\TipoDocumentoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipoDocumento extends Model
{
    /** @use HasFactory<TipoDocumentoFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'tipos_documento';

    protected $fillable = [
        'seccion_legajo_id',
        'subseccion_legajo_id',
        'codigo',
        'nombre',
        'descripcion',
        'base_normativa',
        'aplica_carrera',
        'aplica_plazo_determinado',
        'aplica_plazo_indeterminado',
        'aplica_designado',
        'aplica_tipo_personal',
        'requiere_docente',
        'requiere_administrativo',
        'requiere_djbr',
        'requiere_colegiatura',
        'requiere_estudios_basicos',
        'requiere_derechohabientes',
        'requiere_periodo_prueba',
        'requiere_discapacidad',
        'tiene_banderas',
        'es_universal',
        'obligatorio',
        'acredita_perfil_puesto',
        'permite_multiples',
        'requiere_num_resolucion',
        'requiere_fedateo',
        'es_dato_sensible',
        'es_dato_salud',
        'es_hook_universidad',
        'formulario_datos',
        'orden',
        'activo',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'seccion_legajo_id' => 'integer',
            'aplica_carrera' => 'boolean',
            'aplica_plazo_determinado' => 'boolean',
            'aplica_plazo_indeterminado' => 'boolean',
            'aplica_designado' => 'boolean',
            'requiere_docente' => 'boolean',
            'requiere_administrativo' => 'boolean',
            'requiere_djbr' => 'boolean',
            'requiere_colegiatura' => 'boolean',
            'requiere_estudios_basicos' => 'boolean',
            'requiere_derechohabientes' => 'boolean',
            'requiere_periodo_prueba' => 'boolean',
            'requiere_discapacidad' => 'boolean',
            'tiene_banderas' => 'boolean',
            'es_universal' => 'boolean',
            'obligatorio' => 'boolean',
            'acredita_perfil_puesto' => 'boolean',
            'permite_multiples' => 'boolean',
            'requiere_num_resolucion' => 'boolean',
            'requiere_fedateo' => 'boolean',
            'es_dato_sensible' => 'boolean',
            'es_dato_salud' => 'boolean',
            'es_hook_universidad' => 'boolean',
            'orden' => 'integer',
            'activo' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function seccionLegajo(): BelongsTo
    {
        return $this->belongsTo(SeccionLegajo::class, 'seccion_legajo_id', 'id');
    }

    public function subseccionLegajo(): BelongsTo
    {
        return $this->belongsTo(SubseccionLegajo::class, 'subseccion_legajo_id', 'id');
    }

    public function documentosUsuario(): HasMany
    {
        return $this->hasMany(DocumentoUsuario::class, 'tipo_documento_id', 'id');
    }

    public function referenciasLegajoPasivo(): HasMany
    {
        return $this->hasMany(ReferenciaLegajoPasivo::class, 'tipo_documento_id', 'id');
    }

    public function compromisoDocumentos(): HasMany
    {
        return $this->hasMany(CompromisoDocumento::class, 'tipo_documento_id', 'id');
    }

    public function requerimientosDocumentos(): HasMany
    {
        return $this->hasMany(RequerimientoDocumento::class, 'tipo_documento_id', 'id');
    }
}
