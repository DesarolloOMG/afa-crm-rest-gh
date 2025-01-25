<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\Almacen;
use App\Models\Empresa;

class EmpresaAlmacen extends Model{
    protected $table = 'empresa_almacen';

    public function almacen() {
        return $this->hasOne(Almacen::class, "id", "id_almacen");
    }

    public function empresa() {
        return $this->hasOne(Empresa::class, "id", "id_empresa");
    }
}
