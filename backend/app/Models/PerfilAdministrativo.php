<?php

namespace App\Models;

use Database\Factories\PerfilAdministrativoFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerfilAdministrativo extends Model
{
    /** @use HasFactory<PerfilAdministrativoFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'perfil_administrativo';

    protected $fillable = [
        'vinculo_laboral_id',
        'tipo_personal',
        'grupo_ocupacional',
        'nivel_carrera',
        'cargo_estructural',
        'es_cargo_confianza',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'es_cargo_confianza' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** El nivel se guarda como en la escala (en mayúsculas): así se compara y se cuenta su uso sin variantes. */
    protected function nivelCarrera(): Attribute
    {
        return Attribute::set(fn (?string $valor): ?string => $valor === null || trim($valor) === '' ? null : mb_strtoupper(trim($valor)));
    }

    public function vinculoLaboral(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'vinculo_laboral_id', 'id');
    }
}
