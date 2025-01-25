<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentoEntidadRelacion extends Model {
    protected $table = 'documento_entidad_re';

    protected $fillable = [
        "id_documento",
        "id_entidad",
    ];
}
