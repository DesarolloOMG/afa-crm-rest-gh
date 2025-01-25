<?php
/** @noinspection PhpDynamicFieldDeclarationInspection */
/** @noinspection PhpComposerExtensionStubsInspection */
/** @noinspection PhpMissingReturnTypeInspection */
/** @noinspection PhpUndefinedClassInspection */
/** @noinspection PhpUnhandledExceptionInspection */

namespace App\Http\Services;

use GuzzleHttp\Client;
use Httpful\Request;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use DB;
use stdClass;

class AutoAzurService
{
    public static function getToken() {
        // Obtener el GUID único desde la base de datos
        $uniqueGUID = DB::table('marketplace_api')->where('extra_1', 'AUTOAZUR')->value('app_id');

        // Obtener token y fecha de expiración desde la BD
        $storedTokenData = DB::table('marketplace_api')
            ->where('extra_1', 'AUTOAZUR')
            ->select('token', 'token_expired_at')
            ->first();

        // Verificar si hay un token almacenado y aún no ha expirado
        if ($storedTokenData && $storedTokenData->token && $storedTokenData->token_expired_at) {
            $currentTime = date('Y-m-d H:i:s'); // Obtener la fecha y hora actual

            // Comparar si el token aún es válido (fecha de expiración futura)
            if ($currentTime < $storedTokenData->token_expired_at) {
                return $storedTokenData->token; // Retorna el token almacenado
            }
        }

        // Si no hay token almacenado o ha expirado, genera uno nuevo
        $apiUrl = config('webservice.autoazur') . 'token/redeem';
        $client = new Client();

        try {
            // Realizar la solicitud HTTP POST al endpoint
            $response = $client->post($apiUrl, [
                'json' => ['UniqueGUID' => $uniqueGUID],
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            // Decodificar la respuesta JSON
            $responseData = json_decode($response->getBody()->getContents(), true);

            // Extraer el token y la fecha de expiración
            $token = $responseData['Message']['Token'] ?? null;
            $expirationDate = $responseData['Message']['ExpirationDate'] ?? null;

            // Verificar si el token fue encontrado
            if (!$token) {
                throw new \Exception('El token no se encontró en la respuesta del servidor.');
            } else {
                // Actualizar el token y la fecha de expiración en la base de datos
                DB::table('marketplace_api')->where('extra_1', 'AUTOAZUR')->update([
                    'token' => $token,
                    'token_expired_at' => $expirationDate,
                ]);
            }

            // Retornar el token recién generado
            return $token;
        } catch (\Exception $e) {
            // Manejo de errores
            return response()->json([
                'error' => 'No se pudo obtener el token',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public static function getSales()
    {
        // Configurar valores por defecto para pruebas
        $params = [
//        'FromDate' => '2024-12-27', // Fecha inicial
//        'ToDate' => '2024-12-28',   // Fecha final
//        'Channels' => 'MERCADO LIBRE', // Ejemplo de canales
//        'Status' => 'Entregado,Pendiente', // Ejemplo de estados
//        'Reference' => '6130974032130',
            'PerPage' => 500,                   // Número máximo de elementos por página
            'Page' => 1                         // Primera página
        ];

        // URL del API externo
        $apiUrl = config('webservice.autoazur') . 'sales/general';

        // Configurar el token de autorización
        $token = self::getToken(); // Token usado para pruebas

        // Crear el cliente HTTP
        $client = new Client();

        $allOrders = []; // Aquí se acumularán todas las órdenes
        try {
            do {
                // Realizar la solicitud GET al API
                $response = $client->get($apiUrl, [
                    'headers' => [
                        'Authorization' => $token,
                        'Content-Type' => 'application/json',
                    ],
                    'query' => $params, // Pasar parámetros predeterminados como consulta
                ]);

                // Decodificar la respuesta JSON
                $responseData = json_decode($response->getBody()->getContents());

                // Extraer las órdenes actuales
                $orders = $responseData['Orders'] ?? [];
                $allOrders = array_merge($allOrders, $orders); // Acumular las órdenes

                // Obtener información paginada
                $currentPage = $responseData['Page'] ?? 1;
                $perPage = $responseData['PerPage'] ?? 500;
                $totalOrders = $responseData['TotalOrders'] ?? count($orders);

                // Calcular si hay más páginas
                $totalPages = ceil($totalOrders / $perPage); // Total de páginas

                // Incrementar el número de página para la siguiente iteración
                $params['Page'] = $currentPage + 1;

            } while ($currentPage < $totalPages); // Continuar mientras haya más páginas disponibles

            // Retornar todas las órdenes acumuladas
            return response()->json($allOrders, 200);
        } catch (\Exception $e) {
            // Manejo de errores
            return response()->json([
                'error' => 'No se pudo obtener la información de ventas',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public static function getSalesLabel(string $folio = 'M2448910', string $format = 'pdf')
    {
        // URL del endpoint para obtener las etiquetas
        $apiUrl = config('webservice.autoazur').'sales/labels';

        // Configurar el token de autorización
        $token = self::getToken();

        // Crear el cliente HTTP
        $client = new Client();

        try {
            // Realizar la solicitud POST al API con el folio
            $response = $client->post($apiUrl, [
                'headers' => [
                    'Authorization' => $token,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'Folios' => $folio// Formato (por defecto, 'pdf')
                ],
            ]);

            // Retornar el contenido de la respuesta
            // Retornar la etiqueta en formato PDF o TXT según el caso
            return $response->getBody()->getContents();
        } catch (\Exception $e) {
            // Manejo de errores
            return [
                'error' => true,
                'message' => 'No se pudo obtener la etiqueta de venta',
                'details' => $e->getMessage()
            ];
        }
    }

    public static function importarVentasAutoAzur($data)
    {
        set_time_limit(0); // Asegurar que no haya límite de tiempo
        $response = new stdClass();
        $response->ventasImportadas = 0; // Contador de ventas importadas
        $response->ventasConError = []; // Ventas que no pudieron ser importadas
        $response->mensaje = [];

        try {
            // Obtener las ventas desde la API de AutoAzur
            $ventas = AutoAzurService::getSales()->getData();

            foreach ($ventas as $venta) {
                // Verificar si la venta ya existe en la base de datos
                $existe = DB::select("SELECT * FROM documento WHERE no_venta = ?", [$venta->Reference]);

                if ($existe) {
                    // Si ya existe, registrar un mensaje y continuar
                    $response->mensaje[] = "La venta con referencia {$venta->Reference} ya existe en CRM.";
                    continue;
                }

                // Intentar importar la venta individualmente
                $importarVenta = self::importarVentaIndividualAutoAzur($venta);
                if ($importarVenta->error) {
                    // Si hubo error, guardar en el registro de errores
                    $response->ventasConError[] = [
                        'venta' => $venta->Reference,
                        'mensaje' => $importarVenta->mensaje
                    ];
                } else {
                    // Si fue exitoso, aumentar el contador de ventas
                    $response->ventasImportadas++;
                    $response->mensaje[] = "La venta con referencia {$venta->Reference} fue importada correctamente.";
                }
            }

        } catch (Exception $e) {
            return self::errorResponse($e->getMessage());
        }

        return $response;
    }

    public static function importarVentaIndividualAutoAzur($venta)
    {
        set_time_limit(0);
        $response = new stdClass();
        $response->error = 0; // 0: Éxito, 1: Error
        $response->mensaje = "Venta importada exitosamente";
        $documentosStr = "";

        try {
            // Inserta la entidad (cliente u otra información relacionada)
            $entidadId = self::insertarEntidadAutoAzur($venta);
            if (!$entidadId) {
                throw new Exception("No se pudo crear la entidad para la venta con referencia {$venta->Reference}");
            }

            // Obtener o calcular datos específicos como el ID de la paquetería
            $paqueteriaId = self::obtenerPaqueteriaIdAutoAzur($venta);
            if (!$paqueteriaId) {
                throw new Exception("No se encontró información de paquetería para la venta con referencia {$venta->Reference}");
            }

            // Insertar el documento relacionado a la venta
            $documentoId = self::insertarDocumentoAutoAzur($venta, $paqueteriaId);
            if (!$documentoId) {
                throw new Exception("No se pudo crear el documento para la venta con referencia {$venta->Reference}");
            }

            // Insertar la dirección del envío
            $direccion = self::insertarDireccionAutoAzur($venta, $documentoId);
            if (!$direccion) {
                throw new Exception("No se pudo la direccion para la venta con referencia {$venta->Reference}");
            }

            self::insertarSeguimiento($documentoId, "PEDIDO IMPORTADO AUTOMATICAMENTE");
            self::relacionarEntidadDocumento($entidadId, $documentoId);

            // Insertar el movimiento relacionado al artículo
            self::insertarMovimientoAutoAzur($venta, $documentoId);
            $pagoId = self::insertarPago($venta, $documentoId);
            if (!$pagoId) throw new Exception("No se creó el pago");

            // Acumular la información de los documentos creados
            $documentosStr .= ($documentosStr === "" ? "" : ",") . $documentoId;

            $response->documentos = $documentosStr; // Documentos relacionados a la venta

            $factura = DocumentoService::crearFacturaAutoAzur($documentoId, 0);
            if ($factura->error) {
                self::insertarSeguimiento($documentoId, $factura->mensaje ?? "Error desconocido");
                self::actualizarDocumentoFase($documentoId);
            }

        } catch (Exception $e) {
            $response->error = 1;
            $response->mensaje = $e->getMessage();
        }

        return $response;
    }

    private static function actualizarDocumentoFase($documentoId)
    {
        DB::table('documento')->where(['id' => $documentoId])->update([
            'id_fase' => 5
        ]);
    }

    private static function insertarEntidadAutoAzur($venta)
    {
        return DB::table('documento_entidad')->insertGetId([
            'razon_social' => $venta->Customer ?? "CLIENTE GENÉRICO",
            'rfc' => $venta->CustomerDocument->DocNumber ?? "XAXX010101000",
            'telefono' => $venta->CustomerPhone ?? null,
            'correo' => $venta->CustomerEmail ?? "N/A"
        ]);
    }

    private static function relacionarEntidadDocumento($entidadId, $documentoId)
    {
        DB::table('documento_entidad_re')->insert([
            'id_entidad' => $entidadId,
            'id_documento' => $documentoId
        ]);
    }

    private static function obtenerPaqueteriaIdAutoAzur($venta)
    {
        // Obtener el nombre de la paquetería desde la propiedad `TrackingMethod`
        $carrier = $venta->Shipment->TrackingMethod ?? null;

        if ($carrier) {
            // Buscar la paquetería en la base de datos
            $paqueteria = DB::table('paqueteria')->where('paqueteria', $carrier)->first();
            return $paqueteria->id ?? 1; // Retornar el ID de la paquetería encontrada, o 1 como valor predeterminado
        }

        // En caso de no encontrar el valor, retornar el ID predeterminado (1)
        return 1;
    }

    private static function insertarSeguimiento($documentoId, $mensaje)
    {
        DB::table('seguimiento')->insert([
            'id_documento' => $documentoId,
            'id_usuario' => 1,
            'seguimiento' => "<h2>{$mensaje}</h2>"
        ]);
    }

    private static function insertarDocumentoAutoAzur($venta, $paqueteriaId)
    {
        return DB::table('documento')->insertGetId([
            'id_cfdi' => 3,
            'id_tipo' => 2,
            'id_almacen_principal_empresa' => $venta->DeliveryMethod == "ENVIO" ? 133 : 100,
            'id_marketplace_area' => $venta->Channel == "MERCADO LIBRE" ? 32 : 35,
            'id_usuario' => 1,
            'id_paqueteria' => $paqueteriaId,
            'id_fase' => $venta->DeliveryMethod == "ENVIO" ? 1 : 6,
            'id_modelo_proveedor' => 0,
            'no_venta' => $venta->Reference,
            'referencia' => $venta->Folio,
            'documento_extra' => $venta->Folio,
            'observacion' => "Pedido Importado AUTOAZUR",
            'fulfillment' => $venta->DeliveryMethod == "ENVIO" ? 0 : 1,
            'comentario' => "",
            'mkt_publicacion' => "N/A",
            'mkt_total' => $venta->Total ?? 0,
            'mkt_fee' => 0,
            'mkt_coupon' => 0,
            'mkt_shipping_total' => 0,
            'mkt_created_at' => $venta->SaleDate ?? 0,
            'mkt_user_total' => 0,
            'started_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private static function insertarDireccionAutoAzur($venta, $documentoId)
    {

        return DB::table('documento_direccion')->insertGetId([
            'id_documento' => $documentoId,
            'id_direccion_pro' => 0,
            'contacto' => $venta->Shipment->ReceiverName ?? "SIN NOMBRE",
            'calle' => $venta->Shipment->Street ?? "SIN CALLE",
            'numero' => $venta->Shipment->Number ?? 0,
            'numero_int' => "N/A",
            'colonia' => $venta->Shipment->Neighborhood ?? 0,
            'ciudad' => $venta->Shipment->City ?? "SIN CIUDAD",
            'estado' => $venta->Shipment->State ?? "0",
            'codigo_postal' => $venta->Shipment->ZipCode ?? "00000",
            'referencia' => 0
        ]);
    }

    private static function insertarMovimientoAutoAzur($venta, $documentoId)
    {
        // Recorrer todos los ítems automáticamente
        foreach ($venta->Items as $item) {
            // Primero, verificar si el ItemID pertenece a una publicación del Marketplace
            $publicaciones = DB::table('marketplace_publicacion as mp')
                ->join('marketplace_publicacion_producto as mpp', 'mpp.id_publicacion', '=', 'mp.id')
                ->where('mp.publicacion_id', $item->ItemID) // Ahora se usa ItemID en lugar de Sku
                ->select('mpp.*')
                ->get();

            if (!empty($publicaciones) && $publicaciones->isNotEmpty()) {
                // Si es una publicación, procesar sus productos relacionados
                foreach ($publicaciones as $publicacion) {
                    DB::table('movimiento')->insertGetId([
                        'id_documento' => $documentoId,
                        'id_modelo' => $publicacion->id_modelo,
                        'cantidad' => ($item->Quantity * $publicacion->cantidad) ?? (1 * $publicacion->cantidad),
                        'precio' => ($item->Total / 1.16) * ($publicacion->porcentaje / 100),
                        'garantia' => $publicacion->garantia,
                        'modificacion' => '',
                        'regalo' => $publicacion->regalo,
                    ]);
                }
            } else {
                // Si no es una publicación, verificar si corresponde a un modelo
                $modelo = DB::table('modelo')->where('sku', $item->Sku)->first();

                if ($modelo) {
                    // Procesar como modelo si se encuentra uno
                    $modeloId = $modelo->id;
                    self::insertarMensajeSeguimiento($documentoId, "Producto insertado correctamente como modelo: " . $item->Sku);
                    DB::table('movimiento')->insertGetId([
                        'id_documento' => $documentoId,
                        'id_modelo' => $modeloId,
                        'cantidad' => $item->Quantity ?? 1,
                        'precio' => $item->Total / 1.16,
                        'garantia' => 90,
                        'modificacion' => '',
                        'regalo' => ''
                    ]);
                } else {
                    // Si no corresponde a un modelo, verificar si es un sinónimo
                    $modeloId = DB::table('modelo_sinonimo')
                        ->where('codigo', $item->Sku)
                        ->value('id_modelo');

                    if (!empty($modeloId)) {
                        // Insertar usando el modelo relacionado al sinónimo
                        self::insertarMensajeSeguimiento($documentoId, "Producto insertado correctamente con sinónimo: " . $item->Sku);
                        DB::table('movimiento')->insertGetId([
                            'id_documento' => $documentoId,
                            'id_modelo' => $modeloId,
                            'cantidad' => $item->Quantity ?? 1,
                            'precio' => $item->Total / 1.16,
                            'garantia' => 90,
                            'modificacion' => '',
                            'regalo' => ''
                        ]);
                    } else {
                        // Si no pertenece a ninguna categoría, registrar un mensaje
                        self::insertarMensajeSeguimiento($documentoId, "No se encontró la relación del producto: " . $item->Sku);
                    }
                }
            }
        }
    }

    private static function insertarPago($venta, $documentoId)
    {
        $pagoId = DB::table('documento_pago')->insertGetId([
            'id_usuario' => 1,
            'id_metodopago' => 31,
            'id_vertical' => 0,
            'id_categoria' => 0,
            'id_clasificacion' => 0,
            'tipo' => 1,
            'origen_importe' => 0,
            'destino_importe' => $venta->Total,
            'folio' => "",
            'entidad_origen' => 1,
            'origen_entidad' => 'XAXX010101000',
            'entidad_destino' => "",
            'destino_entidad' => '',
            'referencia' => '',
            'clave_rastreo' => '',
            'autorizacion' => '',
            'destino_fecha_operacion' => date('Y-m-d'),
            'destino_fecha_afectacion' => '',
            'cuenta_cliente' => ''
        ]);

        if ($pagoId) {
            DB::table('documento_pago_re')->insert([
                'id_documento' => $documentoId,
                'id_pago' => $pagoId
            ]);
        }

        return $pagoId;
    }

    private static function insertarMensajeSeguimiento($documentoId, $mensaje)
    {
        DB::table('seguimiento')->insert([
            'id_documento' => $documentoId,
            'id_usuario' => 1,
            'seguimiento' => $mensaje
        ]);
    }

    public static function logVariableLocation()
    {
        // $log = self::logVariableLocation();
        $sis = 'BE'; //Front o Back
        $ini = 'AC'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'ZON'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        return ('<br>' . $sis . $ini . $trace['line'] . $fin);
    }
    private static function errorResponse($mensaje)
    {
        LoggerService::writeLog('amazon', $mensaje);

        $response = new stdClass();
        $response->error = 1;
        $response->mensaje = 'Error '.$mensaje .' Terminacion del proceso por error.';

        return $response;
    }

}
