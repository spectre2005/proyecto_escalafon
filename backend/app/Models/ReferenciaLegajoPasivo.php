<?php

namespace App\Models;

use Database\Factories\ReferenciaLegajoPasivoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferenciaLegajoPasivo extends Model
{
    /** @use HasFactory<ReferenciaLegajoPasivoFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'referencias_legajo_pasivo';

    public const UPDATED_AT = null;

    protected $fillable = [
        'legajo_id',
        'vinculo_laboral_id',
        'tipo_documento_id',
        'folio_inicio_pasivo',
        'folio_fin_pasivo',
        'fedateado',
        'fecha_fedateo',
        'fiscalizado',
        'fecha_fiscalizacion',
        'responsable_legajo_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'folio_inicio_pasivo' => 'integer',
            'folio_fin_pasivo' => 'integer',
            'fedateado' => 'boolean',
            'fecha_fedateo' => 'date',
            'fiscalizado' => 'boolean',
            'fecha_fiscalizacion' => 'date',
            'created_at' => 'datetime',
        ];
    }

    public function legajoOrigen(): BelongsTo
    {
        return $this->belongsTo(Legajo::class, 'legajo_origen_id');
    }

    public function documentoOrigen(): BelongsTo
    {
        return $this->belongsTo(DocumentoUsuario::class, 'documento_origen_id');
    }

    public function legajo(): BelongsTo
    {
        return $this->belongsTo(Legajo::class, 'legajo_id', 'id');
    }

    public function vinculoLaboral(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'vinculo_laboral_id', 'id');
    }

    public function tipoDocumento(): BelongsTo
    {
        return $this->belongsTo(TipoDocumento::class, 'tipo_documento_id', 'id');
    }

    public function responsableLegajo(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'responsable_legajo_id', 'id');
    }
}
