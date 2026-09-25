<?php

namespace App\Http\Services;

use Illuminate\Support\Facades\DB;

class BusquedaNotaCreditoService
{
    private function documentos()
    {
        // Las NC no requieren los datos de envío ni la fase operativa de una venta.
        return DB::table('documento as nc')
            ->leftJoin('documento_entidad as cliente', 'nc.id_entidad', '=', 'cliente.id')
            ->leftJoin('usuario', 'nc.id_usuario', '=', 'usuario.id')
            ->leftJoin('marketplace_area as ma', 'nc.id_marketplace_area', '=', 'ma.id')
            ->leftJoin('marketplace', 'ma.id_marketplace', '=', 'marketplace.id')
            ->leftJoin('area', 'ma.id_area', '=', 'area.id')
            ->leftJoin('moneda', 'nc.id_moneda', '=', 'moneda.id')
            ->where('nc.id_tipo', 6)
            ->select('nc.id', 'nc.id_tipo', 'nc.status', 'nc.created_at',
                'nc.referencia', 'nc.observacion', 'nc.tipo_cambio',
                'nc.factura_serie', 'nc.factura_folio', 'nc.uuid',
                'cliente.razon_social as cliente', 'cliente.rfc', 'cliente.correo', 'cliente.telefono',
                'usuario.nombre as usuario', 'marketplace.marketplace', 'area.area', 'moneda.moneda');
    }

    public function buscar($criterio)
    {
        // Conserva la coincidencia parcial de la búsqueda por documento.nota.
        return $this->documentos()->where('nc.id', 'LIKE', '%' . $criterio . '%')
            ->orderBy('nc.id')->get();
    }

    public function detalle($id)
    {
        $nota = $this->documentos()->where('nc.id', $id)->first();
        if (!$nota) {
            return null;
        }

        $nota->productos = DB::table('movimiento as m')
            ->leftJoin('modelo', 'm.id_modelo', '=', 'modelo.id')
            ->where('m.id_documento', $nota->id)
            ->orderBy('m.id')
            ->select('m.id', 'm.cantidad', 'm.precio', 'modelo.sku', 'modelo.descripcion')
            ->get();

        $total = 0;
        foreach ($nota->productos as $producto) {
            $producto->importe = round((float) $producto->cantidad * (float) $producto->precio, 2);
            $total += (float) $producto->cantidad * (float) $producto->precio;
        }
        // Los precios incluyen IVA, igual que en el PDF interno de la NC.
        $nota->total = round($total, 2);
        $nota->subtotal = round($total / 1.16, 2);
        $nota->iva = round($nota->total - $nota->subtotal, 2);

        return $nota;
    }
}
