<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\UsuarioEmpresa;
use App\Models\EmpresaAlmacen;

class Empresa extends Model {
    use SoftDeletes;

    protected $table = 'empresa';

    public function almacenes() {
        return $this->hasMany(EmpresaAlmacen::class, "id_empresa", "id");
    }

    public function usuarios() {
        return $this->hasMany(UsuarioEmpresa::class, "id_empresa", "id");
    }
}
