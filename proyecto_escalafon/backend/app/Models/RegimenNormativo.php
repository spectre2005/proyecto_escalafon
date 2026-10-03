<?php

namespace App\Models;

use Database\Factories\RegimenNormativoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RegimenNormativo extends Model
{
    /** @use HasFactory<RegimenNormativoFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'regimenes_normativos';

    public $timestamps = false;

    protected $fillable = [
        'codigo',
        'nombre',
        'aplica_a',
        'activo',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class, 'regimen_normativo_id', 'id');
    }

    public function vinculosLaborales(): HasMany
    {
        return $this->hasMany(VinculoLaboral::class, 'regimen_normativo_id', 'id');
    }
}
