<?php

namespace App\Models;

use Database\Factories\PresentacionRenovacionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PresentacionRenovacion extends Model
{
    /** @use HasFactory<PresentacionRenovacionFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'presentaciones_renovacion';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha_vencimiento' => 'date', 'actual' => 'boolean'];
    }
}
