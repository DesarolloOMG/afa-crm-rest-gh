<?php

namespace App\Http\Services;

use DB;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class ComodinService
{
    public static function logistica_envio_pendiente_documento($documento, $marketplace, $zpl = 0)
    {
        $marketplace_data = DB::select("SELECT
                                            marketplace_area.id,
                                            marketplace_api.app_id,
                                            marketplace_api.secret,
                                            marketplace_api.extra_2,
                                            marketplace_api.extra_1,
                                            marketplace.marketplace
                                        FROM marketplace_area
                                        INNER JOIN marketplace_api ON marketplace_area.id = marketplace_api.id_marketplace_area
                                        INNER JOIN marketplace ON marketplace_area.id_marketplace = marketplace.id
                                        WHERE marketplace_area.id = " . $marketplace . "")[0];

        $informacion = DB::select("SELECT
                                documento.no_venta,
                                paqueteria.paqueteria
                            FROM documento
                            INNER JOIN paqueteria ON documento.id_paqueteria = paqueteria.id
                            WHERE documento.id = " . $documento . "")[0];

        switch (strtolower($marketplace_data->marketplace)) {
            case 'mercadolibre externo':
            case 'mercadolibre':
                $response = $zpl ? MercadolibreService::documentoZPL($informacion->no_venta, $marketplace_data) : MercadolibreService::documento($informacion->no_venta, $marketplace_data);

                break;

            case 'linio 2':
            case 'linio':
                try {
                    $marketplace_data->secret = Crypt::decrypt($marketplace_data->secret);
                } catch (DecryptException $e) {
                    $marketplace_data->secret = "";
                }

                $response = LinioService::documento($informacion->no_venta, $marketplace_data);
                break;

            //!! RELEASE T1 reempalzar

            case 'claroshop':
            case 'sears':
            case 'sanborns':
                $response = ClaroshopServiceV2::documento($informacion->no_venta, $marketplace_data);
                break;

            // case 'claroshop':
            // case 'sears':
            //     try {
            //         $marketplace_data->secret = Crypt::decrypt($marketplace_data->secret);
            //     } catch (DecryptException $e) {
            //         $marketplace_data->secret = "";
            //     }

            //     $response = ClaroshopService::documento($informacion->no_venta, $marketplace_data, strtolower($informacion->paqueteria));
            //     break;

            case 'walmart':
                $response = WalmartService::documento($documento, $marketplace_data->id);
                break;

            case 'coppel':
                $response = CoppelService::documento($documento, $marketplace_data->id);
                break;

            default:
                $response = new \stdClass();
                $response->error = 1;
                $response->mensaje = "El marketplace no ha sido configurado, favor de contactar al administrador.<br/> Error: LC249";

                break;
        }

        if ($response->error) {
            return response()->json([
                'code'  => 500,
                'message'   => $response->mensaje,
                'raw'   => property_exists($response, 'raw') ? $response->raw : ''
            ]);
        }

        return response()->json([
            'code' => 200,
            'file' => $response->file,
            'pdf' => property_exists($response, 'pdf') ? $response->pdf : 1
        ]);
    }

    public static function importar_sinonimos($data) {
        foreach ($data as $skus) {
            $modelo = DB::table("modelo")->where("sku", $skus->sku)->first();

            if (!empty($modelo)) {
                DB::table("modelo_sinonimo")->insert([
                    'id_usuario' => 1,
                    'id_modelo' => $modelo->id,
                    'codigo' => $skus->codigo,
                ]);
            }
        }
    }

    public static function insertar_seguimiento($documento, $mensaje) {
        DB::table("seguimiento")->insert([
            'id_documento' => $documento,
            'id_usuario' => 1,
            'seguimiento' => $mensaje,
        ]);
    }

    /**
     * Valida una lista de series para un producto determinado.
     *
     * Este metodo se encarga de comprobar, para cada serie en la lista, que:
     *  - La serie no sea un SKU registrado en la tabla "modelo".
     *  - La serie no sea un sinónimo registrado en la tabla "modelo_sinonimo".
     *  - La serie exista en la tabla "producto" y pertenezca al modelo correspondiente al SKU ingresado.
     *
     * Se devuelve un objeto de respuesta que incluye:
     *  - La lista de series con su estado de validación.
     *  - Un mensaje y un código de error en caso de que alguna serie no cumpla con las condiciones.
     *
     * @param array $series Lista de series a validar.
     * @param string $sku    SKU del producto para el que se validan las series.
     * @return object Objeto de respuesta con la validación, que incluye:
     *                - error: 0 si todas las series son válidas, 1 si se encontraron errores.
     *                - mensaje: Texto descriptivo del resultado.
     *                - errores: (opcional) JSON con los errores encontrados.
     *                - series: Array de objetos con la serie y su estado de validación.
     */
    public static function validar_series(array $series, string $sku, int $almacen = 0)
    {
        // Inicializa un array para almacenar mensajes de error durante la validación.
        $errores = array();

        // Crea un objeto para la respuesta final.
        $response = new \stdClass();

        // Array que contendrá el resultado de cada validación de serie.
        $array = array();

        // Obtiene el ID del modelo asociado al SKU del producto consultando la tabla "modelo".
        $id_modelo = DB::table("modelo")->where("sku", $sku)->first()->id;

        $id_producto = 0;

        // Itera sobre cada serie en la lista.
        foreach ($series as $serie) {
            $object = new \stdClass();
            // Almacena la serie original en el objeto temporal.
            $object->serie = $serie;

            // Limpia la cadena de la serie removiendo comillas simples y barras invertidas para evitar inyección o errores en la consulta.
            $serie = str_replace(["'", '\\'], '', $serie);

            // Verifica si la serie corresponde a un SKU registrado en la tabla "modelo".
            $es_sku = DB::table('modelo')->where('sku', $serie)->first();

            // Si no se encontró la serie como SKU, se realizan las siguientes comprobaciones:
            if(empty($es_sku)) {
                // Comprueba si la serie es un sinónimo registrado en la tabla "modelo_sinonimo".
                $es_sinonimo = DB::table('modelo_sinonimo')->where('codigo', $serie)->first();

                // Si la serie no es un sinónimo...
                if(empty($es_sinonimo)) {
                    // Busca la serie en la tabla "producto" (se utiliza TRIM para eliminar espacios en blanco).
                    $existe_serie = DB::table('producto')->where('serie', TRIM($serie))->first();

                    // Si la serie existe en la base de datos...
                    if(!empty($existe_serie)) {
                        // Comprueba si el ID del modelo asociado a la serie coincide con el ID obtenido del SKU.
                        if($existe_serie->id_modelo != $id_modelo) {
                            // Si no coinciden, marca la serie como inválida y registra el error.
                            $object->status = 0;
                            $object->mensaje = "La serie " . $serie . " no pertenece a" . $sku;
                            array_push($errores, "La serie " . $serie . " no pertenece a" . $sku);
                        } else if($existe_serie->status != 1) {
                            $object->status = 0;
                            $object->mensaje = "La serie " . $serie . " no está disponible para venta.";
                            array_push($errores, $object->mensaje);
                        } else {
                            if($almacen != 0) {
                                if($existe_serie->id_almacen != $almacen) {
                                    $object->status = 0;
                                    $object->mensaje = "La serie " . $serie . " no pertenece al almacen " . $almacen;
                                    array_push($errores, "La serie " . $serie . " no pertenece al almacen " . $almacen);
                                }
                            } else {
                                $object->status = 1;
                                $id_producto = $existe_serie->id;
                            }
                        }
                    } else {
                        // Si la serie no se encontró en la tabla "producto", se marca como error.
                        $object->status = 0;
                        $object->mensaje = "La serie " . $serie . " no existe en la Base de Datos";
                        array_push($errores, "La serie " . $serie . " no existe en la Base de Datos");
                    }
                } else {
                    // Si la serie es un sinónimo (existe en "modelo_sinonimo"), se marca como error.
                    $object->status = 0;
                    $object->mensaje = "La serie " . $serie . " es un sinonimo.";
                    array_push($errores, "La serie " . $serie . " es un sinonimo.");
                }
            } else {
                // Si la serie se encontró en la tabla "modelo" (es un SKU), se marca como error.
                $object->status = 0;
                $object->mensaje = "La serie " . $serie . " es un sku.";
                array_push($errores, "La serie " . $serie . " es un sku.");
            }
            // Agrega el objeto de validación de la serie al array de resultados.
            array_push($array, $object);
        }

        // Prepara el objeto de respuesta final basándose en si se encontraron errores o no.
        if(!empty($errores)) {
            // En caso de errores, se codifican en JSON y se asigna un mensaje de error.
            $response->errores = $errores;
            $response->error = 1;
            $response->mensaje = "Una o mas series no se pueden agregar.";
            $response->series = $array;
            $response->producto = $id_producto;
        } else {
            // Si no hay errores, se indica que la validación fue exitosa.
            $response->error = 0;
            $response->mensaje = "Series Validadas.";
            $response->series = $array;
            $response->producto = $id_producto;
        }

        // Retorna el objeto de respuesta con toda la información de la validación.
        return $response;
    }

    public static function validar_series_entrada(array $series, string $sku)
    {
        // Inicializa un array para almacenar mensajes de error durante la validación.
        $errores = [];

        // Crea un objeto para la respuesta final.
        $response = new \stdClass();

        // Array que contendrá el resultado de cada validación de serie.
        $array = array();

        // Obtiene el ID del modelo asociado al SKU del producto consultando la tabla "modelo".
        $id_modelo = DB::table("modelo")->where("sku", $sku)->first()->id;

        $id_producto = 0;

        // Itera sobre cada serie en la lista.
        foreach ($series as $serie) {
            $object = new \stdClass();
            // Almacena la serie original en el objeto temporal.
            $object->serie = $serie;

            // Limpia la cadena de la serie removiendo comillas simples y barras invertidas para evitar inyección o errores en la consulta.
            $serie = str_replace(["'", '\\'], '', $serie);

            // Verifica si la serie corresponde a un SKU registrado en la tabla "modelo".
            $es_sku = DB::table('modelo')->where('sku', $serie)->first();

            // Si no se encontró la serie como SKU, se realizan las siguientes comprobaciones:
            if(empty($es_sku)) {
                // Comprueba si la serie es un sinónimo registrado en la tabla "modelo_sinonimo".
                $es_sinonimo = DB::table('modelo_sinonimo')->where('codigo', $serie)->first();

                // Si la serie no es un sinónimo...
                if(empty($es_sinonimo)) {
                    // Busca la serie en la tabla "producto" (se utiliza TRIM para eliminar espacios en blanco).
                    $existe_serie = DB::table('producto')->where('serie', TRIM($serie))->first();

                    // Si la serie existe en la base de datos...
                    if(!empty($existe_serie)) {
                        // Si coincide, marca la serie como válida.
                        $object->status = 0;
                        $errores[] = "La serie {$serie} ya existe en la Base de Datos, en el SKU: {$sku}";
                        $id_producto = $existe_serie->id;
                    } else {
                        // Si la serie no se encontró en la tabla "producto", se marca como error.
                        $object->status = 1;
                    }
                } else {
                    // Si la serie es un sinónimo (existe en "modelo_sinonimo"), se marca como error.
                    $object->status = 0;
                    $errores[] = "La serie {$serie} es un sinónimo, en el SKU: {$sku}";

                }
            } else {
                // Si la serie se encontró en la tabla "modelo" (es un SKU), se marca como error.
                $object->status = 0;
                $errores[] = "La serie {$serie} es un SKU: {$sku}";
            }
            // Agrega el objeto de validación de la serie al array de resultados.
            array_push($array, $object);
        }

        // Prepara el objeto de respuesta final basándose en si se encontraron errores o no.
        if(count($errores)) {
            // En caso de errores, se codifican en JSON y se asigna un mensaje de error.
            $response->errores = $errores;
            $response->error = 1;
            $response->mensaje = "Una o mas series no se pueden agregar.";
            $response->series = $array;
            $response->producto = $id_producto;
        } else {
            // Si no hay errores, se indica que la validación fue exitosa.
            $response->error = 0;
            $response->mensaje = "Series Validadas.";
            $response->series = $array;
            $response->producto = $id_producto;
        }

        // Retorna el objeto de respuesta con toda la información de la validación.
        return $response;
    }

}
