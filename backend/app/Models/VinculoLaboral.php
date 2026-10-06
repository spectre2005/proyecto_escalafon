<?php

namespace App\Models;

use Database\Factories\VinculoLaboralFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VinculoLaboral extends Model
{
    /** @use HasFactory<VinculoLaboralFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'vinculos_laborales';

    protected $fillable = [
        'usuario_id',
        'tipo_personal',
        'regimen_laboral',
        'regimen_normativo_id',
        'unidad_organica_id',
        'nombre_puesto',
        'obligado_djbr',
        'exige_colegiatura',
        'exige_estudios_basicos',
        'tiene_periodo_prueba',
        'proceso_seleccion',
        'fecha_resultado_seleccion',
        'numero_documento_vinculo',
        'fecha_inicio',
        'fecha_fin_prevista',
        'fecha_cese',
        'anulado',
        'motivo_anulacion',
        'registrado_por',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'obligado_djbr' => 'boolean',
            'exige_colegiatura' => 'boolean',
            'exige_estudios_basicos' => 'boolean',
            'tiene_periodo_prueba' => 'boolean',
            'fecha_resultado_seleccion' => 'date',
            'fecha_inicio' => 'date',
            'fecha_fin_prevista' => 'date',
            'fecha_cese' => 'date',
            'anulado' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id', 'id');
    }

    public function regimenNormativo(): BelongsTo
    {
        return $this->belongsTo(RegimenNormativo::class, 'regimen_normativo_id', 'id');
    }

    public function unidadOrganica(): BelongsTo
    {
        return $this->belongsTo(UnidadOrganica::class, 'unidad_organica_id', 'id');
    }

    public function perfilDocente(): HasMany
    {
        return $this->hasMany(PerfilDocente::class, 'vinculo_laboral_id', 'id');
    }

    public function perfilAdministrativo(): HasMany
    {
        return $this->hasMany(PerfilAdministrativo::class, 'vinculo_laboral_id', 'id');
    }

    public function documentosUsuario(): HasMany
    {
        return $this->hasMany(DocumentoUsuario::class, 'vinculo_laboral_id', 'id');
    }

    public function referenciasLegajoPasivo(): HasMany
    {
        return $this->hasMany(ReferenciaLegajoPasivo::class, 'vinculo_laboral_id', 'id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por', 'id');
    }

    public function vinculoAdendas(): HasMany
    {
        return $this->hasMany(VinculoAdenda::class, 'vinculo_laboral_id', 'id');
    }

    public function inducciones(): HasMany
    {
        return $this->hasMany(Induccion::class, 'vinculo_laboral_id', 'id');
    }

    public function periodosPrueba(): HasMany
    {
        return $this->hasMany(PeriodoPrueba::class, 'vinculo_laboral_id', 'id');
    }

    public function periodosVacacionales(): HasMany
    {
        return $this->hasMany(PeriodoVacacional::class, 'vinculo_laboral_id', 'id');
    }

    public function progresionesCarrera(): HasMany
    {
        return $this->hasMany(ProgresionCarrera::class, 'vinculo_laboral_id', 'id');
    }

    public function desvinculaciones(): HasMany
    {
        return $this->hasMany(Desvinculacion::class, 'vinculo_laboral_id', 'id');
    }
}
