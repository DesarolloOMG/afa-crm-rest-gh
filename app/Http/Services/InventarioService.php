<?php

namespace App\Http\Services;

use DB;

class InventarioService
{
    /**
     * Procesa un documento y actualiza inventario, costo y kardex.
     * Recibe sólo el ID del documento y se encarga de buscar su tipo, sus movimientos,
     * y de aplicar las actualizaciones de inventario y costo según corresponda.
     *
     * @param int $idDocumento   ID del documento a procesar.
     * @return \Illuminate\Http\JsonResponse   Respuesta en formato JSON con el resultado.
     */
    public function aplicarMovimiento(int $idDocumento)
    {
        // Iniciamos una transacción para que si algo falla, se reviertan los cambios.
        DB::beginTransaction();
        try {
            // 1. Buscamos el documento por su ID.
            $documento = DB::table('documento')->where('id', $idDocumento)->first();
            if (!$documento) {
                return response()->json([
                    'code' => 404,
                    'message' => 'Documento no encontrado.'
                ], 404);
            }

            // 2. Obtenemos el tipo de documento para saber qué se debe hacer (suma, resta, afecta costo, etc.).
            $docTipo = DB::table('documento_tipo')->where('id', $documento->id_tipo)->first();
            if (!$docTipo) {
                return response()->json([
                    'code' => 404,
                    'message' => 'Tipo de documento no encontrado.'
                ], 404);
            }

            // 3. Obtenemos los movimientos asociados al documento.
            $movimientos = DB::table('movimiento')->where('id_documento', $idDocumento)->get();
            if ($movimientos->isEmpty()) {
                DB::commit();
                return response()->json([
                    'code'    => 200,
                    'message' => 'No hay movimientos para este documento.'
                ], 200);
            }

            // 4. Procesamos cada movimiento del documento.
            foreach ($movimientos as $mov) {
                // Si es un traspaso, se procesa de forma especial.
                if ($docTipo->tipo == 'TRASPASO' || $docTipo->id == 5) {
                    $this->procesarTraspaso($mov, $documento);
                    continue; // Salimos de este ciclo y seguimos con el siguiente movimiento.
                }

                // Usamos el almacén principal del documento para las operaciones.
                $almacen = $documento->id_almacen_principal_empresa;

                // Obtenemos (o creamos) la existencia y costo para este producto en el almacén.
                $existencia = $this->obtenerOcrearExistencia($mov->id_modelo, $almacen);
                $costo = $this->obtenerOcrearCosto($mov->id_modelo, $almacen);

                // Guardamos los valores actuales (para poder registrar en el kardex después).
                $stockAnterior = $existencia->stock;
                $costoPromAnterior = $costo->costo_promedio;

                // Calculamos la cantidad, el precio unitario (considerando tipo de cambio) y el total.
                $cantidad = $mov->cantidad;
                $precioUnitario = $mov->precio * ($documento->tipo_cambio ?? 1);
                $totalMovimiento = round($cantidad * $precioUnitario, 2);

                // Inicializamos el nuevo stock con el valor actual.
                $nuevoStock = $stockAnterior;

                // Si el documento suma inventario, agregamos la cantidad.
                if ($docTipo->sumainventario == 1) {
                    $nuevoStock = $stockAnterior + $cantidad;
                }

                // Si el documento resta inventario, se le quita la cantidad.
                if ($docTipo->restainventario == 1) {
                    $nuevoStock = $stockAnterior - $cantidad;
                    if ($nuevoStock < 0) {
                        $nuevoStock = 0; // Nunca dejamos que el stock sea negativo.
                    }
                }

                // Actualizamos el registro de existencia solo si hubo un cambio.
                if ($nuevoStock != $stockAnterior) {
                    DB::table('modelo_existencias')
                        ->where('id', $existencia->id)
                        ->update([
                            'stock_anterior' => $stockAnterior,
                            'stock'          => $nuevoStock,
                            'updated_at'     => now()
                        ]);
                }

                // Solo actualizamos el costo si el documento afecta el costo.
                if ($docTipo->afectaCosto == 1) {
                    // Calculamos el nuevo costo promedio.
                    if ($stockAnterior <= 0) {
                        // Si no había stock, el nuevo costo es el precio de este movimiento.
                        $nuevoCostoPromedio = $precioUnitario;
                    } else {
                        $montoAnterior = $stockAnterior * $costoPromAnterior;
                        $montoActual = $cantidad * $precioUnitario;
                        $nuevoCostoPromedio = ($montoAnterior + $montoActual) / ($stockAnterior + $cantidad);
                    }
                    // Actualizamos el registro de costo solo si hay un cambio en el promedio.
                    if ($nuevoCostoPromedio != $costoPromAnterior) {
                        DB::table('modelo_costo')
                            ->where('id', $costo->id)
                            ->update([
                                'stock_anterior' => $stockAnterior,
                                'costo_promedio' => $nuevoCostoPromedio,
                                'ultimo_costo'   => $costoPromAnterior, // Guardamos el costo anterior como referencia.
                                'updated_at'     => now()
                            ]);
                    }
                }

                // Registramos el movimiento en el kardex.
                $this->insertarKardex(
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

            DB::commit();
            return response()->json([
                'code'    => 200,
                'message' => 'Documento procesado exitosamente.'
            ], 200);
        } catch (\Exception $e) {
            // Si algo falla, revertimos todos los cambios.
            DB::rollBack();
            return response()->json([
                'code'    => 500,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Busca o crea el registro de existencia (modelo_existencias) para un producto y un almacén.
     * Si no existe, lo crea con stock en 0.
     *
     * @param int $idModelo          ID del producto.
     * @param int $idEmpresaAlmacen  ID del almacén.
     * @return object                Registro de existencia.
     */
    private function obtenerOcrearExistencia(int $idModelo, int $idEmpresaAlmacen)
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
                'created_at'         => $this->now(),
                'updated_at'         => now()
            ]);
            $existencia = DB::table('modelo_existencias')->where('id', $id)->first();
        }
        return $existencia;
    }

    /**
     * Busca o crea el registro de costo (modelo_costo) para un producto y un almacén.
     * Si no existe, lo crea con costo en 0.
     *
     * @param int $idModelo          ID del producto.
     * @param int $idEmpresaAlmacen  ID del almacén.
     * @return object                Registro de costo.
     */
    private function obtenerOcrearCosto($idModelo, $idEmpresaAlmacen)
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
     * Inserta un registro en el kardex (modelo_kardex).
     * Aquí se guarda toda la info del movimiento, como cantidad, costo, total, stock anterior, etc.
     *
     * @param object $mov             Movimiento actual.
     * @param object $documento       Documento al que pertenece el movimiento.
     * @param int $cantidad        Cantidad del movimiento.
     * @param int $stockAnterior   Stock antes del movimiento.
     * @param int $afectaCosto     Bandera que indica si afecta costo (1 = sí, 0 = no).
     * @param float $costoPromedio   Nuevo costo promedio (o el anterior si no afecta).
     * @param float $precioUnitario  Precio unitario del movimiento.
     * @param float $totalMovimiento Total calculado del movimiento.
     */
    private function insertarKardex(
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
            'id_empresa_almacen_salida' => $documento->id_almacen_secundario_empresa ?? null,
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
     * Procesa un traspaso, que es un caso especial:
     * - Se resta en el almacén principal.
     * - Se suma en el almacén secundario.
     * Se registran ambos movimientos en el kardex.
     *
     * @param object $mov        Movimiento actual.
     * @param object $documento  Documento al que pertenece el movimiento.
     */
    private function procesarTraspaso($mov, $documento)
    {
        // 1. Procesamos la salida (almacén principal).
        $almacenSalida = $documento->id_almacen_principal_empresa;
        $existenciaSalida = $this->obtenerOcrearExistencia($mov->id_modelo, $almacenSalida);
        $costoSalida = $this->obtenerOcrearCosto($mov->id_modelo, $almacenSalida);

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
        // Actualizamos el costo (aunque en traspaso generalmente no se recalcula).
        DB::table('modelo_costo')
            ->where('id', $costoSalida->id)
            ->update([
                'stock_anterior' => $stockAnteriorSalida,
                'updated_at'     => now()
            ]);

        // Registramos la salida en el kardex.
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

        // 2. Procesamos la entrada (almacén secundario).
        $almacenEntrada = $documento->id_almacen_secundario_empresa;
        if (!$almacenEntrada) {
            // Si no hay almacén secundario, salimos.
            return;
        }
        $existenciaEntrada = $this->obtenerOcrearExistencia($mov->id_modelo, $almacenEntrada);
        $costoEntrada = $this->obtenerOcrearCosto($mov->id_modelo, $almacenEntrada);
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

        // Registramos la entrada en el kardex.
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
}
