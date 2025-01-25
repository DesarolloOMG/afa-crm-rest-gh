<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MovimientoProducto extends Model{
    protected $table = 'movimiento_producto';

    protected $fillable = [
        "id_movimiento",
        "id_producto"
    ];
}
