<?php

namespace App\Models;

use Database\Factories\ConexionCorreoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConexionCorreo extends Model
{
    /** @use HasFactory<ConexionCorreoFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'conexiones_correo';

    protected $fillable = ['clave', 'email', 'access_token', 'refresh_token', 'expira_en', 'habilitada', 'conectado_por', 'alcances', 'recepcion_revisada_en', 'recepcion_error'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'expira_en' => 'datetime', 'habilitada' => 'boolean', 'alcances' => 'array', 'recepcion_revisada_en' => 'datetime'];
    }
}
