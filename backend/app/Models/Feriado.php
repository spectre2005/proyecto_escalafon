<?php

namespace App\Models;

use Database\Factories\FeriadoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Feriado extends Model
{
    /** @use HasFactory<FeriadoFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'feriados';

    public $timestamps = false;

    protected $fillable = [
        'fecha',
        'descripcion',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha' => 'date',
        ];
    }
}
