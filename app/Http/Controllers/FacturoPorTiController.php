<?php

namespace App\Http\Controllers;

use App\Http\Services\DocumentoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Firebase\JWT\JWT;
use DOMDocument;
use Validator;
use Exception;
use DB;
use Carbon\Carbon;

use App\Http\Services\FacturoPorTiService;
use PHPUnit\Util\Json;

class FacturoPorTiController extends Controller
{
    protected $facturoporti;
    protected $production;

    public function __construct( Request $request, FacturoPorTiService $facturoporti)
    {
        if( $request->get('production') == "" ){
            $this->production = true;
        }else{
            $this->production =  (bool) $request->get('production');
        }
        
        $this->facturoporti = $facturoporti;
    }

    public function getToken(){
    
        return $this->facturoporti->getToken( $this->production );
    }

    public function endpointurl(){
        //return "asd";
        return $this->facturoporti->urlendpoint();
    }

    public function GenerarFacturaCliente(Request $request){
        $response_json = ["success" => 0, "mensaje" => "", "timbrado" => []];

        $token = $this->facturoporti->getToken( $this->production );

        $rfc = env("FACTUROPORTI_RFC_SANDBOX", "EKU9003173C9");
        $csd_password = env("FACTUROPORTI_CSD_PASSWORD_SANDBOX", "12345678a");

        $cer = Storage::get("Certificados/$rfc/$rfc.cer");
        $key = Storage::get("Certificados/$rfc/$rfc.key");

        $csd = base64_encode($cer);
        $llaveprivada = base64_encode($key);

        $emisor = [
            "rfc" => "EKU9003173C9",
            "razon_social" => "ESCUELA KEMPER URGATE",
            "regimen" => "601",

            "cp" => "42501"
        ];

        $receptor = [
            "rfc" => "MACM771226EA9",
            "razon_social" => "MARINO EDUARDO MARTINEZ CAMARGO",
            "regimen" => "605",
            "uso_cfdi" => "S01",
            "cp" => "05410"
        ];

        $conceptos = [
            [
                "serie" => "8806095272559",
                "producto" => "TABLET SAMSUNG GALAXY TAB A9+ OCTA-CORE 8GB 128GB 11PULG FHD WIFI CN GRAPHITE GREY SM-X210",
                "cantidad" => 2,
                "codigo_unidad" => "H87",
                "unidad" => "PIEZA",
                "codigo_producto" => "43191500",
                "precio_unitario" => 3293.98,
            ],
            [
                "serie" => "7502239529121",
                "producto" => "AUDIFONOS TIPO DIADEMA BLUETOOTH INALAMBRICOS RECARGABLES GREYSTONE BUNDLE SOUND COLORS",
                "cantidad" => 2,
                "codigo_unidad" => "H87",
                "unidad" => "PIEZA",
                "codigo_producto" => "52161514",
                "precio_unitario" => 33.61,
            ],
            [
                "serie" => "742488071768",
                "producto" => "LAPIZ PARA TABLET 2 EN 1 STYLUS V1 CON BOLIGRAFO METALICO 17CM ANDROID IOS BLACK",
                "cantidad" => 2,
                "codigo_unidad" => "H87",
                "unidad" => "PIEZA",
                "codigo_producto" => "43211700",
                "precio_unitario" => 33.61,
            ],
        ];

        //EL SAT la regla de redondeo es de .5 hacia arriba y es menor, hacia abajo. Hasta dos digitos.
        //Como calcula es que le saca el iva a cada concepto y lo redondea, y al final lo suma. 
        //No suma todo y lo multiplica por 0.16
        $subtotal = 0.00;
        $impuesto = 0.00;
        foreach( $conceptos as $conc ){
            $precio = $conc["cantidad"] * $conc["precio_unitario"];

            $impuesto += round( $precio * 0.16, 2 );

            $subtotal += $precio;
        }
        //return $impuesto + $subtotal;
        $data = [
            "serie" => "GM",
            "folio" => "1627737",

            "subtotal" => $subtotal,
            "impuesto" => $impuesto
        ];
       
        $request_data = [

            "DatosGenerales" => self::Req_DatosGenerales([
                "csd" => $csd,
                "llave_privada" => $llaveprivada,
                "csd_password" => $csd_password,
            ]),
            "Encabezado"     => self::Req_Encabezado( $emisor, $receptor, $data ),
            "Conceptos" => self::Req_Conceptos($conceptos)
        ];

        //return json_encode( $request_data );

        $curl = curl_init();

        curl_setopt_array($curl, [
          CURLOPT_URL => "https://testapi.facturoporti.com.mx/servicios/timbrar/json",
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => "",
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 30,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => "POST",
          CURLOPT_POSTFIELDS => json_encode( $request_data ),
          CURLOPT_HTTPHEADER => [
            "accept: application/json",
            "authorization: Bearer $token",
            "content-type: application/*+json"
          ],
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            //echo "cURL Error #:" . $err;
            $response_json["mensaje"] = "cURL Error #:" . $err;
            return json_encode( $response_json );
        } else {
            $response = json_decode($response, true);
            //return json_encode( $request_data );
            if( isset($response["status"]) ){
                if( $response["status"] == 400 ){
                    //return $response["errors"];
                    $response_json["mensaje"] = $response["errors"];
                    return json_encode( $response_json );
                }
            }
            

            #Si hay error
            if( is_null($response["cfdiTimbrado"]) ){
                //return $response["estatus"]["descripcion"];
                $response_json["mensaje"] = $response["estatus"]["descripcion"];
                return json_encode( $response_json );
            }

            if( isset( $response["estatus"] ) ){
                if( $response["estatus"]["codigo"] != "000" ){
                    //error
                    $descripcion = $response["estatus"]["descripcion"];
                    $informacionTecnica = $response["estatus"]["informacionTecnica"];
                    $response_json["mensaje"] = $informacionTecnica;
                    return json_encode( $response_json );
                }else{
                    //success
                    $response_json["success"] = 1;
                    $response_json["timbrado"] = $response["cfdiTimbrado"]["respuesta"];
                    return json_encode( $response_json );
                }
            }
          // return $response;
        }
    }


    public function Req_DatosGenerales($data){

        $data = [
            
            "Version"      => "4.0",
            "CSD"          => $data["csd"],//"",
            "LlavePrivada" => $data["llave_privada"],//"",
            "CSDPassword"  => $data["csd_password"],//"",

            "GeneraPDF" => true,
            "Logotipo"  => "",
            "CFDI" => "Factura",
            "OpcionDecimales" => 2,
            "NumeroDecimales" => 2,
            "TipoCFDI" => "Ingreso",
            "EnviaEmail" => false,
            "ReceptorEmail" => "micorreo@midominio.com",
            "ReceptorEmailCC" => "",
            "ReceptorEmailCCO" => "",
            "EmailMensaje" => "Prueba de envío y generación de factura mediante rest api desde el servicio de timbrado de FacturoPorTi"

        ];

        return $data;

    }

    public function Req_Encabezado( $emisor, $receptor, $data ){

        $ahoraMexico = Carbon::now('America/Mexico_City')->format('Y-m-d\TH:i:s');

        return [

            "CFDIsRelacionados" => null,
            "TipoRelacion"      => null,
            "Emisor"   => self::Req_Datos_ER($emisor),
            "Receptor" => self::Req_Datos_ER($receptor, false) + ["UsoCFDI" => $receptor["uso_cfdi"]],

            "Fecha" => $ahoraMexico,//"",
            "Serie" => $data["serie"],
            "Folio" => $data["folio"],
            "MetodoPago" => "PUE",
            "FormaPago" => "01",
            "Moneda" => "MXN",
            "LugarExpedicion" => $emisor["cp"], //"",
            "SubTotal" => $data["subtotal"],
            "Total" => $data["subtotal"] + $data["impuesto"],
            "Observaciones" => "Campo observaciones"
        ];
    }

    public function Req_Datos_ER($data, $emisor = true){

        if( $emisor ){
            $direccion = [
                [
                    /*
                    "Calle" => "",
                    "NumeroExterior" => "",
                    "NumeroInterior" => "",
                    "Colonia" => "",
                    "Localidad" => "",
                    "Municipio" => "",
                    "Estado" => "",*/
                    "Pais" => "México",
                    "CodigoPostal" => $data["cp"], //"",
                ]
            ];
        }else{
            $direccion = [/*
                "Calle" => "",
                "NumeroExterior" => "",
                "NumeroInterior" => "",
                "Colonia" => "",
                "Localidad" => "",
                "Municipio" => "",
                "Estado" => "",*/
                "Pais" => "México",
                "CodigoPostal" => $data["cp"], //"",
            ];
        }

        return [
            "RFC" => $data["rfc"],//"",
            "NombreRazonSocial" => $data["razon_social"],//"",
            "RegimenFiscal"     => $data["regimen"],//"601",
            "Direccion" => $direccion
        ];
    }

    public function Req_Conceptos($data){

        $conceptos = [];

        /*
            "serie" => "8806095272559",
                "producto" => "TABLET SAMSUNG GALAXY TAB A9+ OCTA-CORE 8GB 128GB 11PULG FHD WIFI CN GRAPHITE GREY SM-X210",
                "cantidad" => 2,
                "codigo_unidad" => "H87",
                "unidad" => "PIEZA",
                "codigo_producto" => "43191500",
                "precio_unitario" => 3293.98,
        */

        foreach( $data as $concepto ){

            $base = $concepto["precio_unitario"] * $concepto["cantidad"];

            array_push( $conceptos, [

                "Cantidad"     => $concepto["cantidad"],//23,
                "CodigoUnidad" => $concepto["codigo_unidad"],//"H87",
                "Unidad"       => $concepto["unidad"],//"PIEZA",
                "CodigoProducto" => $concepto["codigo_producto"],//"41116118",
                "Serie"          => $concepto["serie"],//"JC0457",
                "Producto"       => $concepto["producto"],//"KIT DE REACTIVOS PARA LA DETECCION DE ANTIBIOTICOS EN LECHE, CON LA CAPACIDAD PARA DETECTAR LOS SIGUIENTES GRUPOS DE ANTIBIOTICOS - TETRACICLINAS - SULFONAMIDAS - FLUOROQUINOLONAS - BETALACTAMICOS",
                "PrecioUnitario" => $concepto["precio_unitario"],//1930.5,
                "Importe"        => $base,//44401.5,
                "ObjetoDeImpuesto" => "02",
                "Impuestos" => [
                    [
                        "TipoImpuesto" => 1,
                        "Impuesto" => 2,
                        "Factor" => 1,
                        "Base" => $base,
                        "Tasa" => "0.160000",
                        "ImpuestoImporte" => round($base * 0.16, 2)
                    ]
                ]
            ]);
        }

        return $conceptos;

    }
}