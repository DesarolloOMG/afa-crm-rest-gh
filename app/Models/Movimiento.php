<?php

namespace App\Models;

use App\Models\Modelo;
use Illuminate\Database\Eloquent\Model;

class Movimiento extends Model{
    protected $table = 'movimiento';

    protected $fillable = [
        "id_documento",
        "id_modelo",
        "cantidad", "cantidad_aceptada", "cantidad_recepcionada",
        "precio", "descuento",
        "garantia",
        "modificacion", "regalo",
        "comentario", "addenda", "completa", "retencion", "created_at", "updated_at"
    ];

    public function modelo() {
        return $this->hasOne(Modelo::class, "id_modelo");
    }

    public function documento() {
        return $this->hasOne(Documento::class, "id_documento");
    }
}
