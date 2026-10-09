<?php

namespace App\Models;

use Database\Factories\RenovacionLegajoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RenovacionLegajo extends Model
{
    /** @use HasFactory<RenovacionLegajoFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'renovaciones_legajo';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['presentada_en' => 'datetime', 'aprobada_en' => 'datetime'];
    }
}
