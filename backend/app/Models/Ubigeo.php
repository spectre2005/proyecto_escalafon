<?php

namespace App\Models;

use Database\Factories\UbigeoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ubigeo extends Model
{
    /** @use HasFactory<UbigeoFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'ubigeo';

    public $timestamps = false;

    protected $fillable = [
        'region',
        'provincia',
        'distrito',
        'descripcion',
    ];

    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class, 'ubigeo_id', 'id');
    }
}
