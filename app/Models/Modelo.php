<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\ModeloTipo;
use App\Models\ModeloImagen;
use App\Models\ModeloProveedor;
use App\Models\ModeloProveedorProducto;

class Modelo extends Model {
    use SoftDeletes;

    protected $table = 'modelo';

    protected $fillable = [
        "id_tipo",
        "sku","np",
        "descripcion",
        "costo", "costo_extra",
        "alto",
        "ancho",
        "largo",
        "peso",
        "serie",
        "refurbished", "clave_sat", "unidad", "clave_unidad", "consecutivo",
        "cat1", "cat2", "cat3", "cat4", "status", "created_at", "updated_at", "deleted_at"
    ];

    public function tipo() {
        return $this->hasOne(ModeloTipo::class, "id", "id_tipo");
    }

    public function sinonimos() {
        return $this->hasMany(ModeloSinonimo::class, "id_modelo", "id");
    }

    public function imagenes() {
        return $this->hasMany(ModeloImagen::class, "id_modelo", "id");
    }

    public function proveedores() {
        return $this->hasManyThrough(ModeloProveedor::class, ModeloProveedorProducto::class, "id_modelo_proveedor", "id", "id_modelo_proveedor");
    }

    public function empresas() {
        return $this->hasMany(ModeloEmpresa::class, "id_modelo", "id");
    }
}
