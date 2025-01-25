<?php

namespace App\Http\Controllers;

use App\Http\Services\MercadolibreService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Http\Request;
use Twilio\Rest\Client;
use Mailgun\Mailgun;
use DB;

class MercadolibreController extends Controller{
    public function rawinfo($pseudonimo, $orden){
		$cuenta = DB::select("SELECT 
								app_id, 
								secret
							FROM marketplace_api
							WHERE extra_2 = '" . str_replace("%20", " ", $pseudonimo) . "'");
		
		if(empty($cuenta)){
            return response()->json([
                'code' => 404,
                'message' => "No se encontró ninguna cuenta registrada con el pseudonimo proporcionado."
            ]);
		}

        $token = MercadolibreService::token($cuenta[0]->app_id, $cuenta[0]->secret);
        $seller = MercadolibreService::seller($pseudonimo);

		$orden_ml = json_decode(
            file_get_contents("https://api.mercadolibre.com/orders/search?seller=" . $seller->seller->id . "&q=" . rawurlencode($orden) . "&access_token=" . $token)
        );

        if (!empty($orden_ml->results)) {
            $orden_ml->results[0]->shipping = json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/" . rawurlencode($orden_ml->results[0]->id) . "/shipments?access_token=". $token));
        }

        return (array) $orden_ml;
						
		if (empty($orden_ml)) {
			$json['code'] 		= 404;
            $json['message'] 	= "No se encontró ninguna venta";

    		return $this->make_json($json);
        }
        
        foreach ($orden_ml->results as $orden) {
            $envio = @json_decode(
                file_get_contents("https://api.mercadolibre.com/orders/". $orden->id ."/shipments?access_token=" . $token)
            );
    
            $orden->shipping = $envio;

            if (!empty($orden->mediations)) {
                foreach ($orden->mediations as $index => $mediation) {
                    $info       = json_decode(file_get_contents("https://api.mercadolibre.com/v1/claims/" . $mediation->id . "?access_token=" . $token));
                    $messages   = json_decode(file_get_contents("https://api.mercadolibre.com/v1/claims/" . $mediation->id . "/messages?access_token=" . $token));
                    $evidence   = json_decode(file_get_contents("https://api.mercadolibre.com/v1/claims/" . $mediation->id . "/evidences?access_token=" . $token));
    
                    $info->messages = $messages;
                    $info->evidence = $evidence;
    
                    $mediation->information = $info;
                }
            }
        }

        return json_encode($orden_ml->results);
	}

    public function rawinfo_pseudonimo($pseudonimo){
		$cuenta = DB::select("SELECT 
								app_id, 
								secret
							FROM marketplace_api
							WHERE extra_2 = '" . str_replace(" ", "%20", $pseudonimo) . "'");
		
		if(empty($cuenta)){
            $json['code'] 		= 404;
            $json['message'] 	= "No se encontró la cuenta";

    		return $this->make_json($json);
		}

		$app_id         = $cuenta[0]->app_id;
		$secret_key     = $cuenta[0]->secret;
        $token 			= self::token($app_id, $secret_key);
        
        $seller = json_decode(
            file_get_contents(
            "https://api.mercadolibre.com/"
            . "sites/MLM/search?nickname="
            . str_replace(" ", "%20", $pseudonimo)
        ))->seller->id;

        $ordenes    = json_decode(file_get_contents("https://api.mercadolibre.com/orders/search?seller=" . $seller . "&access_token=" . $token ."&sort=date_desc"));
        
        print_r($ordenes);
    }

    public function rawinfo_importar_publicacion($pseudonimo, $publicacion){
        $fp = fopen('rawinfoimportarpublicacion', 'w+');

        if (!flock($fp, LOCK_SH | LOCK_NB)) {
            die();
        }

        $cuenta = DB::select("SELECT
                                    id_marketplace_area
                                FROM marketplace_api
                                INNER JOIN marketplace_area ON marketplace_api.id_marketplace_area = marketplace_area.id
                                INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                WHERE marketplace_api.extra_2 = '" . str_replace(" ", "%20", $pseudonimo) . "'
                                AND marketplace.marketplace = 'MERCADOLIBRE'");
		
		if(empty($cuenta)){
            return response()->json([
                'code'  => 500,
                'message' => "Pseudonimo no encontrado."
            ]);
        }

        flock($fp, LOCK_UN);
        fclose($fp);
        
        return MercadolibreService::importarVentas($cuenta[0]->id_marketplace_area, $publicacion);
    }

    public function rawinfo_importar_publicaciones_fecha(Request $request){
        $fp = fopen('rawinfoimportarpublicacionfecha', 'w+');

        if (!flock($fp, LOCK_SH | LOCK_NB)) {
            die();
        }

        $data = json_decode($request->input("data"));

        $cuenta = DB::select("SELECT
                                    id_marketplace_area
                                FROM marketplace_api
                                INNER JOIN marketplace_area ON marketplace_api.id_marketplace_area = marketplace_area.id
                                INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                WHERE marketplace_api.extra_2 = '" . str_replace("%20", " ", $data->marketplace) . "'
                                AND marketplace.marketplace = 'MERCADOLIBRE'");
		
		if(empty($cuenta)){
            return response()->json([
                'code'  => 500,
                'message' => "Pseudonimo no encontrado."
            ]);
        }

        flock($fp, LOCK_UN);
        fclose($fp);
        
        return MercadolibreService::importarVentas($cuenta[0]->id_marketplace_area, $data->publicacion, $data->fecha_inicial, $data->fecha_final);
    }

    public function rawinfo_ventas($pseudonimo){
        set_time_limit(0);

        $marketplace = DB::select("SELECT
                                    marketplace_area.id,
                                    marketplace_api.extra_2,
                                    marketplace_api.app_id,
                                    marketplace_api.secret
                                FROM marketplace_area
                                INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                WHERE marketplace_api.extra_2 = '" . str_replace(" ", "%20", $pseudonimo) . "'
                                AND marketplace.marketplace = 'MERCADOLIBRE'");

		if(empty($marketplace)){
            return response()->json([
                'code'  => 500,
                'message' => "Pseudonimo no encontrado."
            ]);
        }

        $marketplace = $marketplace[0];

        $token = MercadolibreService::token($marketplace->app_id, $marketplace->secret);
        $seller = MercadolibreService::seller($marketplace->extra_2);

        $spreadsheet = new Spreadsheet();
        $sheet_name = "VENTA MERCADOLIBRE " . $pseudonimo;
        $sheet = $spreadsheet->getActiveSheet()->setTitle($sheet_name);
        $contador_fila  = 2;

        $sheet->setCellValue('A1', 'VENTA');
        $sheet->setCellValue('B1', 'TOTAL');
        $sheet->setCellValue('C1', 'CLIENTE');
        $sheet->setCellValue('D1', 'ID PUBLICACION');
        $sheet->setCellValue('E1', 'TITULO');
        $sheet->setCellValue('F1', 'CANTIDAD');
        $sheet->setCellValue('G1', 'CATEGORIA');
        $sheet->setCellValue('H1', 'ATRIBUTO (S)');
        $sheet->setCellValue('I1', 'PAQUETERÍA');
        $sheet->setCellValue('J1', 'GUÍA');
        $sheet->setCellValue('K1', 'FECHA');
        $sheet->setCellValue('L1', 'FULFILLMENT');
        $sheet->setCellValue('M1', 'PACK');
        $sheet->setCellValue('N1', 'VENTA CRM');
        $sheet->setCellValue('O1', 'ESTADO CRM');
        $sheet->setCellValue('P1', 'DEVOLUCIÓN / GARANTIA');
        $sheet->setCellValue('Q1', 'FASE');

        $spreadsheet->getSheet(0)->getStyle('A1:Q1')->getFont()->setBold(1); # Cabecera en negritas

        $scroll_publicacion = "";

        while(!is_null($scroll_publicacion)) {
            $publicaciones = json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "users/" . $seller->seller->id . "/items/search?search_type=scan&scroll_id=" . $scroll_publicacion . "&access_token=" . $token));

            foreach ($publicaciones->results as $publicacion) {
                $scroll = "";

                while(!is_null($scroll)) {
                    $ventas = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/search?search_type=scan&scroll_id=" . $scroll . "&seller=" . $seller->seller->id . "&q=" . $publicacion . "&access_token=" . $token));
        
                    if (!empty($ventas)) {
                        foreach ($ventas->results as $venta) {
                            $venta_crm = DB::select("SELECT
                                                        documento.id,
                                                        documento_fase.fase,
                                                        documento.status,
                                                        documento_garantia_re.id_garantia
                                                    FROM documento
                                                    LEFT JOIN documento_garantia_re ON documento.id = documento_garantia_re.id_documento
                                                    INNER JOIN documento_fase ON documento.id_fase = documento_fase.id
                                                    WHERE documento.no_venta = '" . $venta->id . "'");
            
                            $shipping = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "orders/" . $venta->id . "/shipments?attributes=[tracking_number]&access_token=" . $token));
            
                            foreach ($venta->order_items as $item) {
                                $sheet->setCellValue('A' . $contador_fila, $venta->id);
                                $sheet->setCellValue('B' . $contador_fila, $venta->total_amount);
                                $sheet->setCellValue('C' . $contador_fila, $venta->buyer->first_name . " " . $venta->buyer->last_name);
                                $sheet->setCellValue('D' . $contador_fila, $item->item->id);
                                $sheet->setCellValue('E' . $contador_fila, $item->item->title);
                                $sheet->setCellValue('F' . $contador_fila, $item->quantity);
                                $sheet->setCellValue('G' . $contador_fila, $item->item->category_id);
                                $sheet->setCellValue('H' . $contador_fila, "");
                                $sheet->setCellValue('I' . $contador_fila, empty($shipping) ? "NO EXISTE" : $shipping->tracking_method);
                                $sheet->setCellValue('J' . $contador_fila, empty($shipping) ? "NO EXISTE" : $shipping->tracking_number);
                                $sheet->setCellValue('K' . $contador_fila, $venta->date_created);
                                $sheet->setCellValue('L' . $contador_fila, empty($shipping) ? "NO EXISTE" : (property_exists($shipping, "logistic_type") ? ($shipping->logistic_type == "fulfillment" ? 'SI' : 'NO') : "NO"));
                                $sheet->setCellValue('M' . $contador_fila, $venta->pack_id);
                                $sheet->setCellValue('N' . $contador_fila, empty($venta_crm) ? "NO EXISTE" : $venta_crm[0]->id);
                                $sheet->setCellValue('O' . $contador_fila, empty($venta_crm) ? "NO EXISTE" : ($venta_crm[0]->status ? "ACTIVA" : "CANCELADA"));
                                $sheet->setCellValue('P' . $contador_fila, empty($venta_crm) ? "NO EXISTE" : $venta_crm[0]->id_garantia);
                                $sheet->setCellValue('Q' . $contador_fila, empty($venta_crm) ? "NO EXISTE" : $venta_crm[0]->fase);
            
                                $spreadsheet->getActiveSheet()->getStyle("B" . $contador_fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)');
                                $sheet->getCellByColumnAndRow(10, $contador_fila)->setValueExplicit(empty($shipping) ? "NO EXISTE" : $shipping->tracking_number, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                                $sheet->getCellByColumnAndRow(13, $contador_fila)->setValueExplicit($venta->pack_id, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            
                                foreach ($item->item->variation_attributes as $attribute) {
                                    $sheet->setCellValue('H' . $contador_fila, $attribute->value_name);
                                }
            
                                $contador_fila++;
                            }
                        }
        
                        $scroll = $ventas->paging->scroll_id;
                    }
                    else {
                        $scroll = null;
                    }
                }
            }

            $scroll_publicacion = $publicaciones->scroll_id;
        }

        # Poner en automatico el ancho de la columna dependiendo el texto que esté dentro
        foreach(range('A','Q') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }

        $file_name = "VENTAS MERCADOLIBRE " . $pseudonimo . " 2020.xlsx";

        $writer = new Xlsx($spreadsheet);
        $writer->save($file_name);

        header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
        header("Content-Disposition: attachment; filename=\"" . $file_name . "\"");
        header("Cache-Control: max-age=0");
        readfile($file_name);
        unlink($file_name);
    }
    
    public function rawinfo_whatsapp_recibe(){
        header("HTTP/1.1 200 OK");

        $mensaje = file_get_contents("php://input");

        parse_str($mensaje, $array);

        DB::table("whatsapp")->insert([
            "data" => json_encode($array)
        ]);

        $texto = "";

        if (array_key_exists("Body", $array)) {
            $publicacion = explode("@", $array["Body"]);

            if (count($publicacion) > 1) {
                $publicacion_id = $publicacion[0];

                $existe_publicacion = DB::select("SELECT id, url FROM marketplace_publicacion WHERE publicacion_id = '" . $publicacion_id . "'");

                if (!empty($existe_publicacion)) {
                    $variaciones = DB::select("SELECT * FROM marketplace_publicacion_etiqueta WHERE id_publicacion = " . $existe_publicacion[0]->id . "");

                    if (empty($variaciones)) {
                        $inventario = $publicacion[1];

                        if (!is_numeric($inventario)) {
                            $texto = "La cantidad especificada no es correcta, favor de verificar e intentar de nuevo.";
                        }
                        else {
                            $response = MercadolibreService::actualizarPublicacion($existe_publicacion[0]->id, 0, 1);

                            if ($response->error) {
                                $texto = "No fue posible actualizar la publicación, mensaje de error: " . $response->mensaje;
                            }
                            else {
                                $texto = "Publicación actualizada correctamente, puedes verificarlo en la siguiente URL\n\n" . $existe_publicacion[0]->url;
                            }
                        }
                    }
                    else {
                        array_shift($publicacion);
                        
                        if (empty($publicacion)) {
                            $texto = "Esta publicación cuenta con variaciones, favor de especificar el inventario para al menos una variacion.\n\n".
                                    "*Ejemplo:*\n".
                                    "MLM759985133@Verde prisma-10@Azul-5";
                        }
                        else {
                            foreach ($publicacion as $variacion) {
                                $variacion = explode("-", $variacion);

                                if (count($variacion) < 2) {
                                    $texto = "El formato para para especificar la cantidad a una variación es erroneo.\n\n".
                                    "*Ejemplo:*\n".
                                    "MLM759985133@Verde prisma-10@Azul-5";

                                    continue;
                                }

                                if (!is_numeric($variacion[1])) {
                                    $texto = "El formato para para especificar la cantidad a una variación es erroneo.\n\n".
                                    "*Ejemplo:*\n".
                                    "MLM759985133@Verde prisma-10@Azul-5";

                                    continue;
                                }

                                $existe_variacion = 0;

                                foreach ($variaciones as $var) {
                                    if ($var->valor == $variacion[0]) {
                                        $existe_variacion = 1;
                                    }

                                }

                                if (!$existe_variacion) {
                                    $texto .= "No se encontró la variación con el nombre *" . $variacion[0] . "*, favor de verificar e intentar de nuevo.\n";
                                }
                            }

                            if (empty($texto)) {
                                $response = MercadolibreService::actualizarPublicacion($existe_publicacion[0]->id, 0, 1);

                                if ($response->error) {
                                    $texto = "No fue posible actualizar la publicación, mensaje de error: " . $response->mensaje;
                                }
                                else {
                                    $texto = "Publicación actualizada correctamente, puedes verificarlo en la siguiente URL\n\n" . $existe_publicacion[0]->url;
                                }
                            }
                        }
                    }
                }
                else {
                    $texto = "No existe ninguna publicación registrada con el ID *" . $publicacion_id . "*, favor de verificar e intentar de nuevo.";
                }

            }
            else {
                $existe_publicacion = DB::select("SELECT publicacion_id, id_marketplace_area FROM marketplace_publicacion WHERE publicacion_id = '" . $publicacion[0] . "'");

                if (!empty($existe_publicacion)) {
                    $existe_publicacion = $existe_publicacion[0];

                    $publicacion = MercadolibreService::buscarPublicacion($existe_publicacion->publicacion_id, $existe_publicacion->id_marketplace_area);

                    if (!$publicacion->error) {
                        $envio = $publicacion->data->shipping->logistic_type == "drop_off" ? "Drop off" : "Fulfillment";

                        $vendedor = json_decode(file_get_contents("https://api.mercadolibre.com/users/" . $publicacion->data->seller_id));

                        $texto = $publicacion->data->title . "\n\n" .
                                "*Vendedor:* " . $vendedor->nickname . "\n" .
                                "*Precio:* $ " . $publicacion->data->price . "\n" .
                                "*Cantidad disponible:* " . $publicacion->data->available_quantity . "\n" .
                                "*Cantidad vendida:* " . $publicacion->data->sold_quantity . "\n" .
                                "*Tipo de envio:* " . $envio . "\n" .
                                "*URL*: " . $publicacion->data->permalink;
                    }
                }
            }
        }

        $twilio = new Client(config("twilio.sid"), config("twilio.token"));

        $message = $twilio->messages
                        ->create($array["From"], // to
                                [
                                    "from" => "whatsapp:+" . config("twilio.number"),
                                    "body" => $texto
                                ]
                        );
    }

    public function rawinfo_whatsapp_callback($pseudonimo){
        # code...
    }

    public function rawinfo_whatsapp_publicacion($publicacion){
        return (array) MercadolibreService::buscarPublicacion($publicacion);
    }

    public function rawinfo_notificacion($pseudonimo){
        header("HTTP/1.1 200 OK");

        $notificacion = json_decode(file_get_contents("php://input"));
        
        $cuenta = DB::select("SELECT app_id, secret FROM marketplace_api WHERE extra_2 = '" . str_replace(" ", "%20", $pseudonimo) . "'");
		
		if(empty($cuenta)){
            return response()->json([
                'code'  => 500,
                'message' => "Pseudonimo no encontrado."
            ]);
        }

        $cuenta = $cuenta[0];

        if ($notificacion->topic == "catalog_item_competition_status") {
            $token = MercadolibreService::token($cuenta->app_id, $cuenta->secret);

            $informacion = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . substr($notificacion->resource, 1) . "?access_token=" . $token));

            if (!empty($informacion)) {
                $existe = DB::select("SELECT id, competencia_precio_minimo FROM marketplace_publicacion WHERE publicacion_id = '" . $informacion->item_id . "'");

                if (!empty($existe)) {
                    if ((float) $existe[0]->competencia_precio_minimo > 0) {
                        if ($informacion->status == "competing") {
                            $publicacion_info = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "items/" . $item_id . "?access_token=" . $token));

                            if (!empty($publicacion_info)) {
                                $publicacion_info_ganadora = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . "items/" . $informacion->winner->item_id . "?access_token=" . $token));

                                if (!empty($publicacion_info_ganadora)) {
                                    $texto = "Haz perdido la buybox de esta publicacion\n\n" .
                                            $publicacion_info->title . "\n\n" .
                                            "*ID:* " . $informacion->item_id . "\n" .
                                            "*Precio actual:* $ " . $informacion->current_price . "\n" .
                                            "*Precio para ganar:* $ " . $informacion->price_to_win . "\n" .
                                            "*Publicacion ganadora:* " . $publicacion_info_ganadora->permalink;

                                    $twilio = new Client(config("twilio.sid"), config("twilio.token"));

                                    $twilio->messages
                                                ->create("whatsapp:+5212224962363", // to
                                                        [
                                                            "from" => "whatsapp:+" . config("twilio.number"),
                                                            "body" => $texto
                                                        ]
                                                );   
                                }
                            }

                            $precio_minimo = $existe[0]->competencia_precio_minimo;

                            if ((float) $informacion->price_to_win >= (float) $precio_minimo) {
                                /*
                                DB::table('marketplace_publicacion')->where(['id' => $existe[0]->id])->update([
                                    'total' =>  (float) $informacion->price_to_win
                                ]);

                                MercadolibreService::actualizarPublicacion($existe[0]->id);
                                */
                                return "El precio ha sido cambiado a " . $informacion->price_to_win;
                            }

                            return "El precio minimo para la publicación ha sido rebasado.";
                        }

                        return "La publicación está ganando la buybox en Mercadolibre";
                    }

                    return "La publicación no tiene un precio de competencia asignado";
                }

                return "No está registrada en las publicaciones del sistema";
            }
        }
        
        if ($notificacion->topic == "items") {
            $token = MercadolibreService::token($cuenta->app_id, $cuenta->secret);

            $publicacion_info = @json_decode(file_get_contents(config("webservice.mercadolibre_enpoint") . $notificacion->resource . "?access_token=" . $token));

            if (!empty($publicacion_info)) {
                $existe_publicacion = DB::select("SELECT id, cantidad_disponible FROM marketplace_publicacion WHERE publicacion_id = '" . $publicacion_info->id . "'");

                if (!empty($existe_publicacion)) {
                    $whatsapp_message = "";

                    $publicacion_id = $existe_publicacion[0]->id;
                    $cantidad_disponible = $existe_publicacion[0]->cantidad_disponible;

                    DB::table("marketplace_publicacion")
                            ->where("publicacion_id", $publicacion_info->id)
                            ->update(["cantidad_disponible" => $publicacion_info->available_quantity]);

                    if (property_exists($publicacion_info, "sale_terms")) {
                        foreach ($publicacion_info->sale_terms as $term) {
                            if ($term->id == "MANUFACTURING_TIME") {
                                DB::table('marketplace_publicacion')->where(['publicacion_id' => $publicacion_info->id])->update([
                                    'tee' => !is_null($term->value_struct) ? $term->value_struct->number : !empty($term->values) ? $term->values[0]->struct->number : 0
                                ]);
                            }
                        }
                    }
                    
                    if ($publicacion_info->available_quantity > 0) {
                        return "La publicación todavía tiene existencia en Mercadolibre";
                    }

                    if ($cantidad_disponible == 0) {
                        return "La notificación ya ha sido enviada";
                    }

                    $whatsapp_message .= "El inventario de esta publicación se ha agotado.\n".
                                        "*ID:* " . $publicacion_info->id . "\n".
                                        "*Titulo:* " . $publicacion_info->title . "\n\n";

                    if (property_exists($publicacion_info, "variations")) {
                        $whatsapp_message .= "*Variaciones:*\n";

                        foreach ($publicacion_info->variations as $variacion) {
                            $colores = "";

                            foreach ($variacion->attribute_combinations as $combinacion) {
                                $colores .= " " . $combinacion->value_name . " /";
                            }

                            $colores = trim(substr($colores, 0, -1));

                            if ($cantidad_disponible > 0 && $publicacion_info->available_quantity == 0) {
                                $whatsapp_message .= $colores . "\n";
                            }
                        }
                    }

                    if (!empty($whatsapp_message)) {
                        $twilio = new Client(config("twilio.sid"), config("twilio.token"));
            
                        $twilio->messages
                                        ->create("whatsapp:+5212224962363", // to
                                                [
                                                    "from" => "whatsapp:+" . config("twilio.number"),
                                                    "body" => $whatsapp_message
                                                ]
                                        );

                        $twilio->messages
                                        ->create("whatsapp:+5213314460411", // to
                                                [
                                                    "from" => "whatsapp:+" . config("twilio.number"),
                                                    "body" => $whatsapp_message
                                                ]
                                        );
                    }
                }
            }
        }
    }

    public function rawinfo_mercadolibre_factura(){
        $errores = array();

        $documentos = DB::select("SELECT
                                        documento.id
                                    FROM documento
                                    INNER JOIN documento_entidad_re ON documento.id = documento_entidad_re.id_documento
                                    INNER JOIN documento_entidad ON documento_entidad_re.id_entidad = documento_entidad.id
                                    WHERE documento.factura_enviada = 0
                                    AND documento_entidad.rfc != 'XAXX010101000'
                                    AND documento.id_tipo = 2
                                    AND documento.refacturado = 0
                                    AND documento.id_marketplace_area = 2
                                    AND documento.id_fase = 6");

        foreach ($documentos as $documento) {
            $enviar_factura = MercadolibreService::enviarFactura($documento->id);

            if ($enviar_factura->error) {
                array_push($errores, $enviar_factura->mensaje);
            }
        }

        return $errores;
    }

    public function rawinfo_mercadolibre_huawei(){
        set_time_limit(0);

        $mercadolibre = [];

        $huawei = [];

        foreach ($mercadolibre as $index => $mercadolibre) {
            DB::table('documento')->where(['no_venta' => trim($mercadolibre)])->update([
                'referencia' => trim($huawei[$index])
            ]);
        }

        echo "Terminado";
    }
}
