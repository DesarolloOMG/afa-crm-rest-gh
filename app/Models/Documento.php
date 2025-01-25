<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Movimiento;

class Documento extends Model {
    use SoftDeletes;

    protected $table = 'documento';

    protected $fillable = [
        "id_almacen_principal_empresa",
        "id_almacen_secundario_empresa",
        "id_tipo",
        "id_periodo",
        "id_cfdi",
        "id_marketplace_area",
        "id_usuario",
        "id_moneda",
        "id_paqueteria",
        "id_fase",
        "id_modelo_proveedor",
        "documento_extra",
        "factura_serie",
        "factura_folio",
        "nota",
        "no_venta",
        "no_venta_btob",
        "tipo_cambio",
        "referencia",
        "observacion",
        "info_extra",
        "picking",
        "picking_by",
        "packing_by",
        "sandbox",
        "status",
        "canceled_by",
        "canceled_authorized_by",
        "autorizado",
        "autorizado_by",
        "anticipada",
        "refacturado",
        "problema",
        "pagado",
        "credito",
        "fulfillment",
        "series_factura",
        "importado",
        "modificacion",
        "factura_enviada",
        "solicitar_refacturacion",
        "status_proveedor_btob",
        "comentario",
        "uuid",
        "pedimento",
    ];

    public function productos() {
        return $this->hasMany(Movimiento::class, "id_documento", "id");
    }
}
