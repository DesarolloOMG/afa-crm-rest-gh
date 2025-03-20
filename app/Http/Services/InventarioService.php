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
    public static function aplicarMovimiento(int $idDocumento)
    {
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
                // Si es un traspaso, se procesa de forma especial.
                if ($docTipo->tipo == 'TRASPASO' || $docTipo->id == 5) {
                    self::procesarTraspaso($mov, $documento);
                    continue; // Continuamos con el siguiente movimiento.
                }

                // Se utiliza el almacén principal del documento para las operaciones.
                $almacen = $documento->id_almacen_principal_empresa;

                // Obtenemos (o creamos) la existencia y el costo para este producto en el almacén.
                $existencia = self::obtenerOcrearExistencia($mov->id_modelo, $almacen);
                $costo = self::obtenerOcrearCosto($mov->id_modelo, $almacen);

                // Se guardan los valores actuales para poder registrar en el kardex posteriormente.
                $stockAnterior = $existencia->stock;
                $costoPromAnterior = $costo->costo_promedio;

                // Se calcula la cantidad, el precio unitario (considerando el tipo de cambio) y el total del movimiento.
                $cantidad = $mov->cantidad;
                $precioUnitario = $mov->precio * ($documento->tipo_cambio ?? 1);
                $totalMovimiento = round($cantidad * $precioUnitario, 2);

                // Inicializamos el nuevo stock con el valor actual.
                $nuevoStock = $stockAnterior;

                // Si el documento suma inventario, se incrementa el stock.
                if ($docTipo->sumainventario == 1) {
                    $nuevoStock = $stockAnterior + $cantidad;
                }

                // Si el documento resta inventario, se disminuye el stock sin dejarlo negativo.
                if ($docTipo->restainventario == 1) {
                    $nuevoStock = $stockAnterior - $cantidad;
                    if ($nuevoStock < 0) {
                        $nuevoStock = 0;
                    }
                }

                // Se actualiza el registro de existencia si hubo cambio.
                if ($nuevoStock != $stockAnterior) {
                    DB::table('modelo_existencias')
                        ->where('id', $existencia->id)
                        ->update([
                            'stock_anterior' => $stockAnterior,
                            'stock'          => $nuevoStock,
                            'updated_at'     => now()
                        ]);
                }

                // Si el documento afecta costo, se recalcula el costo promedio.
                if ($docTipo->afectaCosto == 1) {
                    if ($stockAnterior <= 0) {
                        $nuevoCostoPromedio = $precioUnitario;
                    } else {
                        $montoAnterior = $stockAnterior * $costoPromAnterior;
                        $montoActual = $cantidad * $precioUnitario;
                        $nuevoCostoPromedio = ($montoAnterior + $montoActual) / ($stockAnterior + $cantidad);
                    }
                    // Se actualiza el registro de costo si hay cambio.
                    if ($nuevoCostoPromedio != $costoPromAnterior) {
                        DB::table('modelo_costo')
                            ->where('id', $costo->id)
                            ->update([
                                'stock_anterior' => $stockAnterior,
                                'costo_promedio' => $nuevoCostoPromedio,
                                'ultimo_costo'   => $costoPromAnterior,
                                'updated_at'     => now()
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
            ->where('id_empresa_almacen', $idEmpresaAlmacen)
            ->first();

        if (!$existencia) {
            $id = DB::table('modelo_existencias')->insertGetId([
                'id_modelo'          => $idModelo,
                'id_empresa_almacen' => $idEmpresaAlmacen,
                'stock_inicial'      => 0,
                'stock'              => 0,
                'stock_anterior'     => 0,
                'created_at'         => now(),
                'updated_at'         => now()
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
    public static function obtenerOcrearCosto(int $idModelo, int $idEmpresaAlmacen)
    {
        $costo = DB::table('modelo_costo')
            ->where('id_modelo', $idModelo)
            ->where('id_empresa_almacen', $idEmpresaAlmacen)
            ->first();

        if (!$costo) {
            $id = DB::table('modelo_costo')->insertGetId([
                'id_modelo'          => $idModelo,
                'id_empresa_almacen' => $idEmpresaAlmacen,
                'stock_anterior'     => 0,
                'costo_inicial'      => 0,
                'costo_promedio'     => 0,
                'ultimo_costo'       => 0,
                'created_at'         => now(),
                'updated_at'         => now()
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
            'updated_at'                => now()
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
        $almacenSalida = $documento->id_almacen_principal_empresa;
        $existenciaSalida = self::obtenerOcrearExistencia($mov->id_modelo, $almacenSalida);
        $costoSalida = self::obtenerOcrearCosto($mov->id_modelo, $almacenSalida);

        $stockAnteriorSalida = $existenciaSalida->stock;
        $cantidad = $mov->cantidad;
        $precioUnitario = $mov->precio * ($documento->tipo_cambio ?? 1);
        $totalMovimiento = round($cantidad * $precioUnitario, 2);

        $nuevoStockSalida = $stockAnteriorSalida - $cantidad;
        if ($nuevoStockSalida < 0) {
            $nuevoStockSalida = 0;
        }

        if ($nuevoStockSalida != $stockAnteriorSalida) {
            DB::table('modelo_existencias')
                ->where('id', $existenciaSalida->id)
                ->update([
                    'stock_anterior' => $stockAnteriorSalida,
                    'stock'          => $nuevoStockSalida,
                    'updated_at'     => now()
                ]);
        }
        // Actualizamos el costo (normalmente en traspaso no se recalcula).
        DB::table('modelo_costo')
            ->where('id', $costoSalida->id)
            ->update([
                'stock_anterior' => $stockAnteriorSalida,
                'updated_at'     => now()
            ]);

        // Registrar salida en el kardex.
        DB::table('modelo_kardex')->insert([
            'id_modelo'                 => $mov->id_modelo,
            'id_documento'              => $mov->id_documento,
            'id_tipo_documento'         => $documento->id_tipo,
            'id_fase'                   => $documento->id_fase,
            'id_empresa_almacen'        => $almacenSalida,
            'id_empresa_almacen_salida' => $documento->id_almacen_secundario_empresa,
            'afecta_costo'              => 0,
            'cantidad'                  => $cantidad,
            'costo'                     => $precioUnitario,
            'total'                     => $totalMovimiento,
            'stock_anterior'            => $stockAnteriorSalida,
            'costo_promedio'            => $costoSalida->costo_promedio,
            'created_at'                => $mov->created_at,
            'updated_at'                => now()
        ]);

        // Procesar entrada (almacén secundario)
        $almacenEntrada = $documento->id_almacen_secundario_empresa;
        if (!$almacenEntrada) {
            return;
        }
        $existenciaEntrada = self::obtenerOcrearExistencia($mov->id_modelo, $almacenEntrada);
        $costoEntrada = self::obtenerOcrearCosto($mov->id_modelo, $almacenEntrada);
        $stockAnteriorEntrada = $existenciaEntrada->stock;
        $nuevoStockEntrada = $stockAnteriorEntrada + $cantidad;

        if ($nuevoStockEntrada != $stockAnteriorEntrada) {
            DB::table('modelo_existencias')
                ->where('id', $existenciaEntrada->id)
                ->update([
                    'stock_anterior' => $stockAnteriorEntrada,
                    'stock'          => $nuevoStockEntrada,
                    'updated_at'     => now()
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
            'updated_at'                => now()
        ]);
    }

    /**
     * Obtiene la existencia de un producto en un almacén mediante el SP sp_calcularExistenciaProducto.
     *
     * La función llama al SP, el cual retorna la existencia, pendientes y stock final.
     * Si ocurre algún error (por ejemplo, SKU no encontrado o stock=0), se retorna un objeto con el error.
     *
     * @param string $sku       SKU del producto.
     * @param int $idAlmacen ID del almacén.
     * @return \stdClass      Objeto con la información de existencia y pendientes.
     */
    public static function existenciaProducto(string $sku, int $idAlmacen)
    {
        // Creamos un objeto de respuesta inicial.
        $response = new \stdClass();
        $response->error = 0;
        $response->mensaje = '';
        $response->stock = 0;
        $response->pendientesVenta = 0;
        $response->pendientes_pretransferencia_secundario = 0;
        $response->pendientes_recibir = 0;
        $response->stock_final_existenciaProducto = 0;

        try {
            // Llamamos al Stored Procedure sp_calcularExistenciaProducto
            $results = DB::select("CALL sp_calcularExistenciaProducto(?, ?)", [
                $sku,
                $idAlmacen
            ]);

            // Verificamos que se haya retornado al menos una fila
            if (count($results) > 0) {
                $row = $results[0];  // Tomamos la primera fila

                // Si el SP reporta error, se propaga el mensaje
                if ($row->error == 1) {
                    $response->error = 1;
                    $response->mensaje = $row->mensaje;
                } else {
                    // Se asignan los valores devueltos por el SP
                    $response->error = (int) $row->error;
                    $response->mensaje = $row->mensaje;
                    $response->stock = (int) $row->stock;
                    $response->pendientesVenta = (int) $row->pendientesVenta;
                    $response->pendientes_pretransferencia_secundario = (int) $row->pendientes_pretransferencia_secundario;
                    $response->pendientes_recibir = (int) $row->pendientes_recibir;
                    $response->existencia = (int) $row->stock_final_existenciaProducto;
                }
            } else {
                $response->error = 1;
                $response->mensaje = "No se obtuvo respuesta del Stored Procedure sp_calcularExistenciaProducto.";
            }
        } catch (\Exception $e) {
            // Capturamos y retornamos cualquier error en la ejecución del SP.
            $response->error = 1;
            $response->mensaje = "Error al llamar SP: " . $e->getMessage();
        }

        return $response;
    }

    /**
     * Calcula el stock disponible final a partir de la existencia base y pendientes adicionales,
     * utilizando el Stored Procedure sp_calcularStockDisponible.
     *
     * Si no se pasa el parámetro de existencia (in_existencia_producto), el SP recalcula la existencia internamente.
     *
     * @param string $sku                SKU del producto.
     * @param int $idAlmacen          ID del almacén.
     * @param int|null $existenciaProducto Existencia base ya calculada (stock_final_existenciaProducto); puede ser NULL.
     * @return \stdClass Objeto con stock disponible y pendientes adicionales.
     */
    public static function stockDisponible(string $sku, int $idAlmacen, int $existenciaProducto = null)
    {
        $response = new \stdClass();
        $response->error = 0;
        $response->mensaje = '';
        $response->stock_disponible = 0;
        $response->pendientes_bo = 0;
        $response->pendientes_surtir = 0;
        $response->pendientes_importar = 0;
        $response->pendientes_pretransferencia_principal = 0;

        try {
            // Llamada al SP sp_calcularStockDisponible
            $results = DB::select("CALL sp_calcularStockDisponible(?, ?, ?)", [
                $sku,
                $idAlmacen,
                $existenciaProducto
            ]);

            if (count($results) > 0) {
                $row = $results[0];

                if (isset($row->error) && $row->error == 1) {
                    $response->error = 1;
                    $response->mensaje = $row->mensaje;
                } else {
                    $response->stock_disponible = (int) $row->stock_disponible;
                    $response->pendientes_bo = (int) $row->pendientes_bo;
                    $response->pendientes_surtir = (int) $row->pendientes_surtir;
                    $response->pendientes_importar = (int) $row->pendientes_importar;
                    $response->pendientes_pretransferencia_principal = (int) $row->pendientes_pretransferencia_principal;
                }
            } else {
                $response->error = 1;
                $response->mensaje = "No se obtuvo respuesta del Stored Procedure sp_calcularStockDisponible.";
            }
        } catch (\Exception $e) {
            $response->error = 1;
            $response->mensaje = "Error al llamar al SP sp_calcularStockDisponible: " . $e->getMessage();
        }

        return $response;
    }

    /**
     * Envuelve la llamada al SP sp_calcularExistenciaCompleta para obtener toda la información
     * de existencia y pendientes de un producto.
     *
     * @param string $sku       SKU del producto.
     * @param int $idAlmacen ID del almacén.
     * @return \stdClass      Objeto con los datos: stock, pendientes, stock_final_existenciaProducto y stock_disponible.
     */
    public static function obtenerExistencia(string $sku, int $idAlmacen)
    {
        $response = new \stdClass();
        $response->error = 0;
        $response->mensaje = '';
        $response->stock = 0;
        $response->pendientesVenta = 0;
        $response->pendientes_pretransferencia_secundario = 0;
        $response->pendientes_recibir = 0;
        $response->pendientes_bo = 0;
        $response->pendientes_surtir = 0;
        $response->pendientes_importar = 0;
        $response->pendientes_pretransferencia_principal = 0;
        $response->stock_final_existenciaProducto = 0;
        $response->stock_disponible = 0;

        try {
            // Llamada al SP sp_calcularExistenciaCompleta
            $results = DB::select("CALL sp_calcularExistenciaCompleta(?, ?)", [
                $sku,
                $idAlmacen,
            ]);

            if (count($results) > 0) {
                $row = $results[0];

                if ($row->error == 1) {
                    $response->error = 1;
                    $response->mensaje = $row->mensaje;
                } else {
                    $response->error = (int) $row->error;
                    $response->mensaje = $row->mensaje;
                    $response->stock = (int) $row->stock;
                    $response->pendientesVenta = (int) $row->pendientesVenta;
                    $response->pendientes_pretransferencia_secundario = (int) $row->pendientes_pretransferencia_secundario;
                    $response->pendientes_recibir = (int) $row->pendientes_recibir;
                    $response->pendientes_bo = (int) $row->pendientes_bo;
                    $response->pendientes_surtir = (int) $row->pendientes_surtir;
                    $response->pendientes_importar = (int) $row->pendientes_importar;
                    $response->pendientes_pretransferencia_principal = (int) $row->pendientes_pretransferencia_principal;
                    $response->stock_final_existenciaProducto = (int) $row->stock_final_existenciaProducto;
                    $response->stock_disponible = (int) $row->stock_disponible;
                }
            } else {
                $response->error = 1;
                $response->mensaje = "No se obtuvo respuesta del Stored Procedure sp_calcularExistenciaCompleta.";
            }
        } catch (\Exception $e) {
            $response->error = 1;
            $response->mensaje = "Error al llamar al SP sp_calcularExistenciaCompleta: " . $e->getMessage();
        }

        return $response;
    }
}
