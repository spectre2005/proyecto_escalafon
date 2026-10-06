<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecursoImpugnatorio extends Model
{
    use HasUuids;

    protected $table = 'recursos_impugnatorios';

    protected $fillable = ['sancion_id', 'documento_id', 'fecha', 'resultado', 'registrado_por'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['fecha' => 'date', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function sancion(): BelongsTo
    {
        return $this->belongsTo(Sancion::class, 'sancion_id', 'id');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoUsuario::class, 'documento_id', 'id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por', 'id');
    }
}
