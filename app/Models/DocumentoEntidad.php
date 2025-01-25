<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Movimiento;

class DocumentoEntidad extends Model {
    use SoftDeletes;

    protected $table = 'documento_entidad';

    protected $fillable = [
        "tipo",
        "razon_social",
        "rfc"
    ];
}
