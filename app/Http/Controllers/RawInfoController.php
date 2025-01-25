<?php

namespace App\Http\Controllers;

use App\Http\Services\ExelDelNorteService;
use App\Http\Services\CTService;
use App\Http\Services\GeneralService;
use App\Http\Services\ArrobaService;
use Illuminate\Http\Request;
use DB;

class RawInfoController extends Controller{
    public function rawinfo_ws_exel_producto(){
        set_time_limit(0);

        return (array) ExelDelNorteService::consultarProductos();
    }

    public function rawinfo_ws_exel_existencia(){
        set_time_limit(0);

        return (array) ExelDelNorteService::consultaPreciosYExistencias();
    }

    public function rawinfo_ws_exel_guia_documento($documento) {
        $archivos = array();

        $archivos_embarque = DB::table("documento_archivo")
                                        ->select("dropbox")
                                        ->where("id_documento", $documento)
                                        ->where("tipo", 2)
                                        ->get()
                                        ->toArray();

        foreach ($archivos_embarque as $archivo) {
            $file_data = new \stdClass();
            $file_data->path = $archivo->dropbox;

            $response = \Httpful\Request::post(config("webservice.dropbox_api") . 'files/get_temporary_link')
                ->addHeader('Authorization', "Bearer " . config("keys.dropbox"))
                ->addHeader('Content-Type', 'application/json')
                ->body(json_encode($file_data))
                ->send();

            $response = @json_decode($response->raw_body);

            array_push($archivos, base64_encode(file_get_contents($response->link)));
        }

        return $archivos;
    }

    public function rawinfo_ws_ct_producto() {
        set_time_limit(0);

        return (array) CTService::consultarProductos();
    }

    public function rawinfo_ws_ct_almacen() {
        return (array) CTService::consultarAlmacenes();
    }

    public function rawinfo_ws_ct_pedido_prueba($documento) {
        return (array) CTService::crearPedido($documento);
    }

    public function rawinfo_ws_ct_adjuntar_guia_pedidos() {
        return (array) CTService::adjuntarGuiaPedidosFacturados();
    }

    public function rawinfo_ws_arroba_producto() {
        return (array) ArrobaService::consultaPreciosYExistencias();
    }

    public function rawinfo_amazon_appid_importar($app_id){
        $fp = fopen('rawinfoimportaramazon', 'w+');

        if (!flock($fp, LOCK_SH | LOCK_NB)) {
            die();
        }

        $cuenta = DB::table("marketplace_area")
                    ->join("marketplace_api", "marketplace_area.id", "=", "marketplace_api.id_marketplace_area")
                    ->join("marketplace", "marketplace_area.id_marketplace", "=", "marketplace.id")
                    ->select("marketplace_area.id")
                    ->where("marketplace_api.extra_1", $app_id)
                    ->where("marketplace.marketplace", "AMAZON")
                    ->first();
		
		if(empty($cuenta)){
            return response()->json([
                'code'  => 404,
                'message' => "Cuenta no encontrada"
            ], 404);
        }
        
        flock($fp, LOCK_UN);
        fclose($fp);

        return AmazonService::importarVentasWeb($cuenta->id);
    }

    public function rawinfo_amazon_appid_venta($venta, $app_id){
        $cuenta = DB::table("marketplace_area")
                    ->join("marketplace_api", "marketplace_area.id", "=", "marketplace_api.id_marketplace_area")
                    ->join("marketplace", "marketplace_area.id_marketplace", "=", "marketplace.id")
                    ->select("marketplace_area.id", "marketplace_api.extra_1", "marketplace_api.app_id", "marketplace_api.secret")
                    ->where("marketplace_api.extra_1", $app_id)
                    ->where("marketplace.marketplace", "AMAZON")
                    ->first();
		
		if(empty($cuenta)){
            return response()->json([
                'code'  => 404,
                'message' => "Cuenta no encontrada"
            ], 404);
        }

        $response = AmazonService::venta($venta, $cuenta);

        return (array) $response->data;
    }

    public function rawinfo_elektra_fase($venta){
        $marketplace_info = DB::select("SELECT
                                            documento.no_venta,
                                            marketplace_area.id,
                                            marketplace_api.extra_1,
                                            marketplace_api.extra_2,
                                            marketplace_api.app_id,
                                            marketplace_api.secret,
                                            marketplace.marketplace
                                        FROM documento
                                        INNER JOIN marketplace_area ON documento.id_marketplace_area = marketplace_area.id
                                        INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                        INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                        WHERE documento.no_venta = '" . $venta . "'");

        if (!empty($marketplace_info)) {
            $marketplace_info = $marketplace_info[0];

            if (strpos($marketplace_info->marketplace, 'ELEKTRA') !== false) {
                $estado = ElektraService::cambiarEstado(trim($marketplace_info->no_venta), $marketplace_info, 2);

                return (array) $estado;
            }
        }
    }

    public function rawinfo_importar_productos() {
        set_time_limit(0);

        $empresas = DB::table("empresa")->where("id", "<>", 0)->get();
        
        foreach ($empresas as $empresa) {
            $productos = \Httpful\Request::get("hhttp://201.7.208.53:11903/api/adminpro/" . $empresa->bd . "/Reporte/Productos/Existencia")->send();

            $productos = @json_decode($productos->raw_body);

            foreach ($productos as $producto) {
                $existe = DB::table("modelo")->where("sku", trim($producto->sku))->first();

                if (!$existe && !$producto->eliminado) {
                    DB::table("modelo")->insert([
                        "id_tipo" => $producto->tipo,
                        "sku" => trim($producto->sku),
                        "descripcion" => trim($producto->producto),
                        "np" => is_null($producto->numeroparte) ? "N/A" : $producto->numeroparte,
                        "costo" => $producto->ultimo_costo,
                        "clave_sat" => is_null($producto->claveprodserv) ? "N/A" : $producto->claveprodserv,
                        "unidad" => is_null($producto->claveunidad) ? "N/A" : $producto->claveunidad,
                        "clave_unidad" => is_null($producto->claveunidad) ? "N/A" : $producto->claveunidad,
                        "cat1" => is_null($producto->cat1) ? "N/A" : $producto->cat1,
                        "cat2" => is_null($producto->cat2) ? "N/A" : $producto->cat2,
                        "cat3" => is_null($producto->cat3) ? "N/A" : $producto->cat3,
                        "cat4" => is_null($producto->cat4) ? "N/A" : $producto->cat4
                    ]);
                }
            }
        }

        return "Terminado";
    }
}
