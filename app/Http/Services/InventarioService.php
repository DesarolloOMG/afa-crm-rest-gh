<?php

namespace App\Http\Services;

use DB;

/**
 * Class InventarioService
 *
 * Servicio para el manejo de inventario, costo y kardex.
 * Contiene métodos para aplicar movimientos, buscar o crear registros
 * de existencia y costo, insertar en kardex, y para calcular existencia
 * y stock disponible usando Stored Procedures.
 *
 * @package App\Http\Services
 */
class InventarioService
{
    /**
     * Aplica un movimiento a un documento, actualizando inventario, costo y kardex.
     *
     * Este metodo busca el documento por su ID, obtiene su tipo, sus movimientos
     * y procesa cada movimiento según corresponda (suma o resta de inventario, afecta costo, etc.).
     * Se usa una transacción para garantizar la atomicidad de las operaciones.
     *
     * @param int $idDocumento ID del documento a procesar.
     * @return \stdClass Objeto de respuesta con propiedades:
     *                   - code: Código de estado (200, 404, 500).
     *                   - error: 0 si no hay error, 1 si hay error.
     *                   - message: Mensaje descriptivo.
     */
    public static function aplicarMovimiento(int $idDocumento): \stdClass
    {
        set_time_limit(0);
        // Creamos el objeto de respuesta
        $response = new \stdClass();
        $response->code = 0;
        $response->error = 0;
        $response->message = '';

        // Iniciamos una transacción para asegurar la atomicidad de las operaciones.
        DB::beginTransaction();
        try {
            // 1. Buscamos el documento por su ID.
            $documento = DB::table('documento')->where('id', $idDocumento)->first();
            if (!$documento) {
                $response->code = 404;
                $response->error = 1;
                $response->message = 'Documento no encontrado.';
                $response->mensaje = 'Documento no encontrado.';
                return $response;
            }

            // 2. Obtenemos el tipo de documento para determinar la operación.
            $docTipo = DB::table('documento_tipo')->where('id', $documento->id_tipo)->first();
            if (!$docTipo) {
                $response->code = 404;
                $response->error = 1;
                $response->message = 'Tipo de documento no encontrado.';
                $response->mensaje = 'Tipo de documento no encontrado.';
                return $response;
            }

            // 3. Obtenemos los movimientos asociados al documento.
            $movimientos = DB::table('movimiento')->where('id_documento', $idDocumento)->get();
            if ($movimientos->isEmpty()) {
                DB::commit();
                $response->code = 200;
                $response->error = 0;
                $response->message = 'No hay movimientos para este documento.';
                $response->mensaje = 'No hay movimientos para este documento.';
                return $response;
            }

            // 4. Procesamos cada movimiento del documento.
            foreach ($movimientos as $mov) {
                $se_agrego_costo = false;
                $se_agrego_inventario = false;
                // Si es un traspaso, se procesa de forma especial.
                if ($docTipo->tipo == 'TRASPASO' || $docTipo->id == 5) {
                    self::procesarTraspaso($mov, $documento);
                    continue; // Continuamos con el siguiente movimiento.
                }

                // Se utiliza el almacén principal del documento para las operaciones.
                $almacen = $documento->id_almacen_principal_empresa;

                // Obtenemos (o creamos) la existencia y el costo para este producto en el almacén.
                $hay_existencia = DB::table('modelo_existencias')->where('id_modelo', $mov->id_modelo)->where('id_almacen', $almacen)->first();

                if(empty($hay_existencia)) {
                    if($docTipo->tipo == 'ENTRADA' || $docTipo->id == 3 || $docTipo->id == 0) {
                        DB::table('modelo_existencias')->insert([
                            'id_modelo' => $mov->id_modelo,
                            'id_almacen' => $almacen,
                            'stock_inicial' => $mov->cantidad,
                            'stock' => $mov->cantidad,
                            'stock_anterior' => 0
                        ]);

                        $existencia = DB::table('modelo_existencias')->where('id_modelo', $mov->id_modelo)->where('id_almacen', $almacen)->first();

                        $se_agrego_inventario = true;
                    } else {
                        $modelo_info = DB::table('modelo')->where('id', $mov->id_modelo)->first();
                        $response->code = 404;
                        $response->error = 1;
                        $response->message = "No existe registro de existencias en la base de datos del producto: " . $modelo_info->descripcion;
                        $response->mensaje = "No existe registro de existencias en la base de datos del producto: " . $modelo_info->descripcion;
                        return $response;
                    }

                } else {
                    $existencia = $hay_existencia;
                }

                if($docTipo->afectaCosto == 1) {
                    $hay_costo = DB::table('modelo_costo')->where('id_modelo', $mov->id_modelo)->first();

                    if (empty($hay_costo)) {
                        if ($docTipo->tipo == 'ENTRADA' || $docTipo->id == 3 || $docTipo->tipo == 'COMPRA' || $docTipo->id == 1 || $docTipo->id == 0) {
                            DB::table('modelo_costo')->insert([
                                'id_modelo' => $mov->id_modelo,
                                'costo_inicial' => $mov->precio ?? 0,
                                'costo_promedio' => $mov->precio ?? 0,
                                'ultimo_costo' => $mov->precio ?? 0
                            ]);

                            $costo = DB::table('modelo_costo')->where('id_modelo', $mov->id_modelo)->first();

                            $se_agrego_costo = true;
                        } else {
                            $modelo_info = DB::table('modelo')->where('id', $mov->id_modelo)->first();
                            $response->code = 404;
                            $response->error = 1;
                            $response->message = "No existe registro de costo en la base de datos del producto: " . $modelo_info->descripcion;
                            $response->mensaje = "No existe registro de costo en la base de datos del producto: " . $modelo_info->descripcion;
                            return $response;
                        }
                    } else {
                        $costo = $hay_costo;
                    }
                }

                // Se guardan los valores actuales para poder registrar en el kardex posteriormente.
                $stockAnterior = $se_agrego_inventario ? 0 : $existencia->stock;
                $costoPromAnterior = $se_agrego_costo ? 0 : $docTipo->afectaCosto ? $costo->costo_promedio : 0;

                // Se calcula la cantidad, el precio unitario (considerando el tipo de cambio) y el total del movimiento.
                $cantidad = $mov->cantidad;
                $precioUnitario = $mov->precio;
                $totalMovimiento = round($cantidad * $precioUnitario, 2);

                // Inicializamos el nuevo stock con el valor actual.
                $nuevoStock = $stockAnterior;

                // Si el documento suma inventario, se incrementa el stock.
                if ($docTipo->sumaInventario == 1) {
                    $nuevoStock = $stockAnterior + $cantidad;
                }

                // Si el documento resta inventario, se disminuye el stock sin dejarlo negativo.
                if ($docTipo->restaInventario == 1) {
                    $nuevoStock = $stockAnterior - $cantidad;
                }

                // Se actualiza el registro de existencia si hubo cambio.
                if (!$se_agrego_inventario) {
                    DB::table('modelo_existencias')
                        ->where('id', $existencia->id)
                        ->update([
                            'stock_anterior' => $stockAnterior,
                            'stock'          => $nuevoStock
                        ]);
                }

                // Si el documento afecta costo, se recalcula el costo promedio.
                if ($docTipo->afectaCosto == 1) {
                    $totalStockAnterior = DB::table('modelo_existencias')
                        ->where('id_modelo', $mov->id_modelo)
                        ->sum('stock');

                    if ($totalStockAnterior <= 0) {
                        $nuevoCostoPromedio = $precioUnitario;
                    } else {
                        $montoAnterior = $totalStockAnterior * $costoPromAnterior;
                        $montoActual = $cantidad * $precioUnitario;
                        $nuevoCostoPromedio = ($montoAnterior + $montoActual) / ($totalStockAnterior + $cantidad);
                    }
                    // Se actualiza el registro de costo si hay cambio.
                    if (!$se_agrego_costo) {
                        DB::table('modelo_costo')
                            ->where('id', $costo->id)
                            ->update([
                                'costo_promedio' => $nuevoCostoPromedio,
                                'ultimo_costo'   => $costoPromAnterior
                            ]);
                    }
                }

                // Se registra el movimiento en el kardex.
                self::insertarKardex(
                    $mov,
                    $documento,
                    $cantidad,
                    $stockAnterior,
                    ($docTipo->afectaCosto == 1 ? 1 : 0),
                    ($docTipo->afectaCosto == 1 ? $nuevoCostoPromedio : $costoPromAnterior),
                    $precioUnitario,
                    $totalMovimiento
                );
            }

            // Confirmamos la transacción.
            DB::commit();
            $response->code = 200;
            $response->error = 0;
            $response->message = 'Documento procesado exitosamente.';
            $response->mensaje = 'Documento procesado exitosamente.';
            return $response;
        } catch (\Exception $e) {
            // Si ocurre un error, revertimos la transacción.
            DB::rollBack();
            $response->code = 500;
            $response->error = 1;
            $response->message = $e->getMessage();
            $response->mensaje = $e->getMessage();
            return $response;
        }
    }

    /**
     * Busca o crea el registro de existencia (modelo_existencias) para un producto en un almacén.
     * Si no existe, lo crea con stock inicial en 0.
     *
     * @param int $idModelo         ID del producto.
     * @param int $idEmpresaAlmacen ID del almacén.
     * @return object               Registro de existencia.
     */
    public static function obtenerOcrearExistencia(int $idModelo, int $idEmpresaAlmacen)
    {
        $existencia = DB::table('modelo_existencias')
            ->where('id_modelo', $idModelo)
            ->where('id_almacen', $idEmpresaAlmacen)
            ->first();

        if (!$existencia) {
            $id = DB::table('modelo_existencias')->insertGetId([
                'id_modelo'          => $idModelo,
                'id_almacen' => $idEmpresaAlmacen,
                'stock_inicial'      => 0,
                'stock'              => 0,
                'stock_anterior'     => 0
            ]);
            $existencia = DB::table('modelo_existencias')->where('id', $id)->first();
        }
        return $existencia;
    }

    /**
     * Busca o crea el registro de costo (modelo_costo) para un producto en un almacén.
     * Si no existe, lo crea con costo inicial en 0.
     *
     * @param int $idModelo         ID del producto.
     * @param int $idEmpresaAlmacen ID del almacén.
     * @return object               Registro de costo.
     */
    public static function obtenerOcrearCosto(int $idModelo)
    {
        $costo = DB::table('modelo_costo')
            ->where('id_modelo', $idModelo)
            ->first();

        if (!$costo) {
            $id = DB::table('modelo_costo')->insertGetId([
                'id_modelo'          => $idModelo,
                'costo_inicial'      => 0,
                'costo_promedio'     => 0,
                'ultimo_costo'       => 0
            ]);
            $costo = DB::table('modelo_costo')->where('id', $id)->first();
        }
        return $costo;
    }

    /**
     * Inserta un registro en el kardex (modelo_kardex) con la información del movimiento.
     *
     * @param object $mov             Movimiento actual.
     * @param object $documento       Documento al que pertenece el movimiento.
     * @param int    $cantidad        Cantidad del movimiento.
     * @param int    $stockAnterior   Stock previo del producto.
     * @param int    $afectaCosto     Indicador si afecta costo (1 = sí, 0 = no).
     * @param float  $costoPromedio   Nuevo costo promedio o el anterior si no afecta.
     * @param float  $precioUnitario  Precio unitario del movimiento.
     * @param float  $totalMovimiento Total calculado del movimiento.
     * @return void
     */
    public static function insertarKardex(
        $mov,
        $documento,
        int $cantidad,
        int $stockAnterior,
        int $afectaCosto,
        float $costoPromedio,
        float $precioUnitario,
        float $totalMovimiento
    ) {
        DB::table('modelo_kardex')->insert([
            'id_modelo'                 => $mov->id_modelo,
            'id_documento'              => $mov->id_documento,
            'id_tipo_documento'         => $documento->id_tipo,
            'id_fase'                   => $documento->id_fase,
            'id_empresa_almacen'        => $documento->id_almacen_principal_empresa,
            'id_empresa_almacen_salida' => $documento->id_almacen_secundario_empresa,
            'afecta_costo'              => $afectaCosto,
            'cantidad'                  => $cantidad,
            'costo'                     => $precioUnitario,
            'total'                     => $totalMovimiento,
            'stock_anterior'            => $stockAnterior,
            'costo_promedio'            => $costoPromedio,
            'created_at'                => $mov->created_at,
        ]);
    }

    /**
     * Procesa un traspaso entre almacenes.
     *
     * Un traspaso se procesa de forma especial:
     *   - Se resta inventario en el almacén principal (salida).
     *   - Se suma inventario en el almacén secundario (entrada).
     * Se registran ambos movimientos en el kardex.
     *
     * @param object $mov        Movimiento actual.
     * @param object $documento  Documento asociado al traspaso.
     * @return void
     */
    public static function procesarTraspaso($mov, $documento)
    {
        // Procesar salida (almacén principal)
        $almacenSalida = $documento->id_almacen_secundario_empresa;
        $existenciaSalida = self::obtenerOcrearExistencia($mov->id_modelo, $almacenSalida);
        $costoSalida = self::obtenerOcrearCosto($mov->id_modelo);

        $stockAnteriorSalida = $existenciaSalida->stock;
        $cantidad = $mov->cantidad;
        $precioUnitario = $mov->precio * ($documento->tipo_cambio ?? 1);
        $totalMovimiento = round($cantidad * $precioUnitario, 2);

        $nuevoStockSalida = $stockAnteriorSalida - $cantidad;

        if ($nuevoStockSalida != $stockAnteriorSalida) {
            DB::table('modelo_existencias')
                ->where('id', $existenciaSalida->id)
                ->update([
                    'stock_anterior' => $stockAnteriorSalida,
                    'stock'          => $nuevoStockSalida,
                ]);
        }

        // Registrar salida en el kardex.
        DB::table('modelo_kardex')->insert([
            'id_modelo'                 => $mov->id_modelo,
            'id_documento'              => $mov->id_documento,
            'id_tipo_documento'         => $documento->id_tipo,
            'id_fase'                   => $documento->id_fase,
            'id_empresa_almacen'        => $documento->id_almacen_secundario_empresa,
            'id_empresa_almacen_salida' => $documento->id_almacen_principal_empresa,
            'afecta_costo'              => 0,
            'cantidad'                  => $cantidad,
            'costo'                     => $precioUnitario,
            'total'                     => $totalMovimiento,
            'stock_anterior'            => $stockAnteriorSalida,
            'costo_promedio'            => $costoSalida->costo_promedio,
            'created_at'                => $mov->created_at,
        ]);

        // Procesar entrada (almacén principañ)
        $almacenEntrada = $documento->id_almacen_principal_empresa;
        if (!$almacenEntrada) {
            return;
        }
        $existenciaEntrada = self::obtenerOcrearExistencia($mov->id_modelo, $almacenEntrada);
        $costoEntrada = self::obtenerOcrearCosto($mov->id_modelo);
        $stockAnteriorEntrada = $existenciaEntrada->stock;
        $nuevoStockEntrada = $stockAnteriorEntrada + $cantidad;

        if ($nuevoStockEntrada != $stockAnteriorEntrada) {
            DB::table('modelo_existencias')
                ->where('id', $existenciaEntrada->id)
                ->update([
                    'stock_anterior' => $stockAnteriorEntrada,
                    'stock'          => $nuevoStockEntrada,
                ]);
        }

        // Registrar entrada en el kardex.
        DB::table('modelo_kardex')->insert([
            'id_modelo'                 => $mov->id_modelo,
            'id_documento'              => $mov->id_documento,
            'id_tipo_documento'         => $documento->id_tipo,
            'id_fase'                   => $documento->id_fase,
            'id_empresa_almacen'        => $almacenEntrada,
            'id_empresa_almacen_salida' => $almacenSalida,
            'afecta_costo'              => 0,
            'cantidad'                  => $cantidad,
            'costo'                     => $precioUnitario,
            'total'                     => round($cantidad * $precioUnitario, 2),
            'stock_anterior'            => $stockAnteriorEntrada,
            'costo_promedio'            => $costoEntrada->costo_promedio,
            'created_at'                => $mov->created_at,
        ]);
    }

    public static function procesarRecepcion($idMov,$cantidad)
    {
        set_time_limit(0);
        $response = new \stdClass();
        $response->error = 0;
        $response->mensaje = '';

        $movimiento = DB::table('movimiento')->where('id', $idMov)->first();
        $documento = DB::table('documento')->where('id',$movimiento->id_documento)->first();

        $almacen = $documento->id_almacen_principal_empresa;

        $hay_existencia = DB::table('modelo_existencias')->where('id_modelo', $movimiento->id_modelo)
            ->where('id_almacen', $documento->id_almacen_principal_empresa)->first();

        if(empty($hay_existencia)) {
            $existencia = DB::table('modelo_existencias')->insert([
                'id_modelo' => $movimiento->id_modelo,
                'id_almacen' => $almacen,
                'stock_inicial' => $cantidad,
                'stock' => $cantidad,
                'stock_anterior' => 0
            ]);

            if($existencia) {
                $response->error = 0;
                $response->mensaje = 'Se agrego la existencia';
            } else {
                $response->error = 1;
                $response->mensaje = 'No se agrego la existencia';
            }
        } else {
            $existencia = DB::table('modelo_existencias')->where('id_modelo', $movimiento->id_modelo)
                ->where('id_almacen', $documento->id_almacen_principal_empresa)->update([
                    'stock' => $hay_existencia->stock + $cantidad,
                ]);

            if($existencia) {
                $response->error = 0;
                $response->mensaje = 'Se agrego la existencia';
            } else {
                $response->error = 1;
                $response->mensaje = 'No se agrego la existencia';
            }
        }
        return $response;
    }

    /**
     * Obtiene la existencia simplificada (stock, pendientes y disponible) de un producto
     * en un almacén específico utilizando el SP sp_calcularExistenciaCompleta.
     *
     * @param string $sku         SKU del producto a consultar.
     * @param int $idAlmacen      ID del almacén específico.
     * @return \stdClass          Objeto con stock, pendientesVenta, transito, pretransferencia y disponible.
     */
    public static function existenciaProducto(string $sku, int $idAlmacen)
    {
        $response = new \stdClass();
        $response->error = 0;
        $response->mensaje = '';
        $response->stock = 0;
        $response->pendientesVenta = 0;
        $response->transito = 0;
        $response->pretransferencia = 0;
        $response->disponible = 0;

        try {
            $results = DB::select("CALL sp_calcularExistenciaCompleta(?, ?)", [$sku, $idAlmacen]);

            if (count($results) > 0) {
                $row = $results[0];

                if ($row->error == 1) {
                    $response->error = 1;
                    $response->mensaje = $row->mensaje;
                } else {
                    $response->stock = (int)$row->stock;
                    $response->pendientesVenta = (int)$row->pendientesVenta;
                    $response->transito = (int)$row->transito;
                    $response->pretransferencia = (int)$row->pretransferencia;
                    $response->disponible = (int)$row->disponible;
                    $response->mensaje = $row->mensaje;
                    $response->tipo = $row->tipo;
                }
            } else {
                $response->error = 1;
                $response->mensaje = "No se obtuvo respuesta del Stored Procedure sp_calcularExistenciaCompleta.";
            }
        } catch (\Exception $e) {
            $response->error = 1;
            $response->mensaje = "Error al llamar SP: " . $e->getMessage();
        }

        return $response;
    }

    /**
     * Obtiene el stock disponible y detalles relacionados de un producto específico en un almacén
     * llamando al SP sp_calcularExistenciaCompleta.
     *
     * @param string $sku        SKU del producto.
     * @param int $idAlmacen     ID del almacén.
     * @return \stdClass         Objeto con stock, pendientesVenta, transito, pretransferencia y disponible.
     */
    public static function stockDisponible(string $sku, int $idAlmacen)
    {
        $response = new \stdClass();
        $response->error = 0;
        $response->mensaje = '';
        $response->stock = 0;
        $response->pendientesVenta = 0;
        $response->transito = 0;
        $response->pretransferencia = 0;
        $response->disponible = 0;

        try {
            $results = DB::select("CALL sp_calcularExistenciaCompleta(?, ?)", [$sku, $idAlmacen]);

            if (count($results) > 0) {
                $row = $results[0];

                if ($row->error == 1) {
                    $response->error = 1;
                    $response->mensaje = $row->mensaje;
                } else {
                    $response->stock = (int)$row->stock;
                    $response->pendientesVenta = (int)$row->pendientesVenta;
                    $response->transito = (int)$row->transito;
                    $response->pretransferencia = (int)$row->pretransferencia;
                    $response->disponible = (int)$row->disponible;
                    $response->mensaje = $row->mensaje;
                }
            } else {
                $response->error = 1;
                $response->mensaje = "No se obtuvo respuesta del Stored Procedure sp_calcularExistenciaCompleta.";
            }
        } catch (\Exception $e) {
            $response->error = 1;
            $response->mensaje = "Error al llamar al SP: " . $e->getMessage();
        }

        return $response;
    }

    /**
     * Obtiene toda la información detallada de existencia de un producto en un almacén,
     * incluyendo stock físico, pendientes y disponibilidad mediante el SP sp_calcularExistenciaCompleta.
     *
     * @param string $sku         SKU del producto.
     * @param int $idAlmacen      ID del almacén.
     * @return \stdClass          Objeto con stock, pendientesVenta, transito, pretransferencia y disponible.
     */
    public static function obtenerExistencia(string $sku, int $idAlmacen)
    {
        $response = new \stdClass();
        $response->error = 0;
        $response->mensaje = '';
        $response->stock = 0;
        $response->pendientesVenta = 0;
        $response->transito = 0;
        $response->pretransferencia = 0;
        $response->disponible = 0;

        try {
            $results = DB::select("CALL sp_calcularExistenciaCompleta(?, ?)", [$sku, $idAlmacen]);

            if (count($results) > 0) {
                $row = $results[0];

                if ($row->error == 1) {
                    $response->error = 1;
                    $response->mensaje = $row->mensaje;
                } else {
                    $response->stock = (int)$row->stock;
                    $response->pendientesVenta = (int)$row->pendientesVenta;
                    $response->transito = (int)$row->transito;
                    $response->pretransferencia = (int)$row->pretransferencia;
                    $response->disponible = (int)$row->disponible;
                    $response->mensaje = $row->mensaje;
                }
            } else {
                $response->error = 1;
                $response->mensaje = "No se obtuvo respuesta del Stored Procedure sp_calcularExistenciaCompleta.";
            }
        } catch (\Exception $e) {
            $response->error = 1;
            $response->mensaje = "Error al llamar al SP: " . $e->getMessage();
        }

        return $response;
    }

    /**
     * Crea un documento de traspaso para los productos de una devolución.
     * Mueve el inventario desde el almacén de la venta original al almacén de garantías.
     *
     * @param int $id_documento_original
     * @param int $id_garantia
     * @return \stdClass
     */
    public static function crear_traspaso_devolucion(int $id_documento_original, int $id_garantia): \stdClass
    {
        $response = new \stdClass();

        DB::beginTransaction();
        try {
            // 1. Obtenemos la información necesaria del documento de venta original
            $info_documento_original = DB::table('documento')
                ->select('id_almacen_principal_empresa', 'id_periodo', 'id_cfdi', 'id_marketplace_area', 'id_moneda')
                ->where('id', $id_documento_original)
                ->first();

            if (!$info_documento_original) {
                throw new \Exception("No se encontró el documento de venta original para el traspaso.");
            }

            // 2. Obtenemos los productos y series de la garantía (los que se van a traspasar)
            $productos_a_traspasar = DB::table('documento_garantia_producto as dgp')
                ->join('modelo', 'dgp.producto', '=', 'modelo.sku')
                ->join('movimiento', 'modelo.id', '=', 'movimiento.id_modelo')
                ->select(
                    'modelo.id as id_modelo',
                    'dgp.cantidad',
                    'movimiento.precio as precio_unitario', // Tomamos el precio del movimiento original
                    'dgp.id as id_documento_garantia_producto'
                )
                ->where('dgp.id_garantia', $id_garantia)
                ->where('movimiento.id_documento', $id_documento_original)
                ->get();

            // 3. Creamos el encabezado del documento de traspaso
            $documento_traspaso_id = DB::table('documento')->insertGetId([
                'id_almacen_principal_empresa' => 2, // Destino: Almacén de Garantías (ID 2)
                'id_almacen_secundario_empresa' => $info_documento_original->id_almacen_principal_empresa, // Origen: Almacén de la venta
                'id_tipo' => 5, // Tipo: Traspaso
                'id_fase' => 100, // Fase: Terminado
                'observacion' => 'Traspaso por devolucion de venta ' . $id_documento_original,
                'id_usuario' => 1,
                'id_periodo' => $info_documento_original->id_periodo,
                'id_cfdi' => $info_documento_original->id_cfdi,
                'id_marketplace_area' => $info_documento_original->id_marketplace_area,
                'id_moneda' => $info_documento_original->id_moneda,
                'id_paqueteria' => 6, // Default o según se necesite
                'tipo_cambio' => 1,
            ]);

            // 4. Creamos los movimientos para cada producto y serie
            foreach ($productos_a_traspasar as $producto) {
                $movimiento_traspaso_id = DB::table('movimiento')->insertGetId([
                    'id_documento' => $documento_traspaso_id,
                    'id_modelo' => $producto->id_modelo,
                    'cantidad' => $producto->cantidad,
                    'precio' => $producto->precio_unitario,
                    'garantia' => 0,
                    'regalo' => 0,
                ]);

                $series = DB::table('documento_garantia_producto_series')
                    ->where('id_documento_garantia_producto', $producto->id_documento_garantia_producto)
                    ->pluck('serie');

                foreach ($series as $serie) {
                    // Actualizamos el almacén de la serie física al de garantías
                    // Se asume que el almacén de garantías para series es el 2
                    DB::table('producto')->where('serie', $serie)->update(['id_almacen' => 2]);

                    // Obtenemos el ID del producto (la instancia con serie)
                    $producto_id = DB::table('producto')->where('serie', $serie)->value('id');

                    // Relacionamos la serie con el nuevo movimiento de traspaso
                    if ($producto_id) {
                        DB::table('movimiento_producto')->insert([
                            'id_movimiento' => $movimiento_traspaso_id,
                            'id_producto' => $producto_id
                        ]);
                    }
                }
            }

            // 5. Afectamos el inventario
            $afectar = InventarioService::aplicarMovimiento($documento_traspaso_id);
            if ($afectar->error) {
                throw new \Exception("El traspaso con ID " . $documento_traspaso_id . " no se pudo afectar correctamente.");
            }

            // 6. Si todo sale bien, confirmamos la transacción
            DB::commit();
            $response->error = 0;
            $response->message = "Traspaso creado y afectado correctamente con el ID " . $documento_traspaso_id . ".";
            $response->id_traspaso = $documento_traspaso_id;

        } catch (\Exception $e) {
            // Si algo falla, revertimos todos los cambios
            DB::rollBack();
            $response->error = 1;
            $response->message = "Error al crear el traspaso: " . $e->getMessage();
        }

        return $response;
    }
}
