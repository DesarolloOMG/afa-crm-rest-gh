<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Almacen extends Model {
    protected $table = 'almacen';
    protected $primaryKey = 'id';

    protected $fillable = [
        "almacen", "status"
    ];
}
