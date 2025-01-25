<?php

namespace App\Http\Controllers;

use DB;
use Illuminate\Support\Carbon;

class PDAController extends Controller
{
    #RECEPCION
    public function pda_recepcion_data()
    {
        set_time_limit(0);
        $documentos = $this->general_ordenes_raw_data("documento.id_fase = 606");
        $empresas = DB::table('empresa')
            ->select('id', 'bd', 'empresa')
            ->where('status', '=', 1)
            ->where('id', '!=', 0)
            ->get();

        $usuarios = DB::table('usuario')
            ->select('usuario.authy', 'usuario.nombre', 'nivel.nivel')
            ->join('usuario_subnivel_nivel', 'usuario.id', '=', 'usuario_subnivel_nivel.id_usuario')
            ->join('subnivel_nivel', 'usuario_subnivel_nivel.id_subnivel_nivel', '=', 'subnivel_nivel.id')
            ->join('nivel', 'subnivel_nivel.id_nivel', '=', 'nivel.id')
            ->join('subnivel', 'subnivel_nivel.id_subnivel', '=', 'subnivel.id')
            ->where(function ($query) {
                $query->where('nivel.nivel', '=', 'COMPRAS')
                    ->where('subnivel.subnivel', '=', 'ADMINISTRADOR');
            })
            ->orWhere('nivel.nivel', '=', 'ADMINISTRADOR')
            ->where('usuario.id', '!=', 1)
            ->groupBy('usuario.id')
            ->get();

        return response()->json([
            'code' => 200,
            'documentos' => $documentos,
            'empresas' => $empresas,
            'usuarios' => $usuarios,
        ]);
    }

    #PICKING

    #INVENTARIO

    #GENERAL
    private function general_ordenes_raw_data($extra_data)
    {
        set_time_limit(0);
        $twoMonthsAgo = Carbon::now()->format('m');

        // $twoMonthsAgo = Carbon::now()->subMonths(2)->format('m');

        $monthString = '2024-' . $twoMonthsAgo . '-01 00:00:00';

        $documentos = DB::table('documento')
            ->select(
                'documento.id',
                'documento.id_fase',
                'documento.factura_serie',
                'documento.factura_folio',
                'documento.autorizado',
                'documento.observacion',
                'documento.info_extra',
                'documento.comentario AS extranjero',
                'documento.importado',
                'documento.created_at',
                'documento.expired_at AS fecha_pago',
                'documento.finished_at',
                'documento.arrived_at AS fecha_entrega',
                'documento.uuid',
                'usuario.nombre',
                'documento_entidad.id_erp',
                'documento_entidad.rfc',
                'documento_entidad.razon_social',
                'documento_periodo.id AS id_periodo',
                'documento_periodo.periodo',
                'documento_fase.fase',
                'documento.tipo_cambio',
                'moneda.id AS id_moneda',
                'moneda.moneda',
                'empresa.bd AS empresa',
                'empresa.empresa AS empresa_nombre',
                'almacen.almacen',
                DB::raw('0 as agrupar')
            )
            ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', '=', 'empresa_almacen.id')
            ->join('movimiento', 'documento.id', '=', 'movimiento.id_documento')
            ->leftJoin('documento_recepcion', 'movimiento.id', '=', 'documento_recepcion.id_movimiento')
            ->join('empresa', 'empresa_almacen.id_empresa', '=', 'empresa.id')
            ->join('almacen', 'empresa_almacen.id_almacen', '=', 'almacen.id')
            ->join('documento_fase', 'documento.id_fase', '=', 'documento_fase.id')
            ->join('usuario', 'documento.id_usuario', '=', 'usuario.id')
            ->join('documento_periodo', 'documento.id_periodo', '=', 'documento_periodo.id')
            ->join('moneda', 'documento.id_moneda', '=', 'moneda.id')
            ->leftJoin('documento_entidad_re', 'documento.id', '=', 'documento_entidad_re.id_documento')
            ->leftJoin('documento_entidad', 'documento_entidad_re.id_entidad', '=', 'documento_entidad.id')
            ->where('documento.id_tipo', 0)
            ->where('documento.created_at', '>=', $monthString)
            ->whereRaw($extra_data)
            ->groupBy('documento.id')
            ->orderByDesc('documento.id')
            ->get();

        $documentIds = $documentos->pluck('id');

        $productos = DB::table('movimiento')
            ->select(
                'movimiento.id_documento',
                'modelo.id AS id_modelo',
                'modelo.sku AS codigo',
                'modelo.serie',
                'modelo.cat1',
                'modelo.cat2',
                'modelo.cat3',
                'modelo.caducidad',
                'movimiento.id',
                'movimiento.cantidad',
                'movimiento.cantidad_aceptada AS cantidad_recepcionada_anterior',
                DB::raw('0 AS cantidad_recepcionada'),
                'movimiento.comentario as descripcion',
                DB::raw('IF(movimiento.descuento = 0, ROUND(movimiento.precio, 8), ROUND((movimiento.precio * movimiento.descuento) / 100, 8)) AS costo'),
                'movimiento.modificacion AS condicion',
                'movimiento.addenda AS marketplace'
            )
            ->join('modelo', 'movimiento.id_modelo', '=', 'modelo.id')
            ->whereIn('movimiento.id_documento', $documentIds)
            ->get();

        $archivosAnteriores = DB::table('documento_archivo')
            ->whereIn('id_documento', $documentIds)
            ->where('status', 1)
            ->get();

        $seguimientos = DB::table('seguimiento')
            ->select('seguimiento.*', 'usuario.nombre', 'seguimiento.id_documento')
            ->join('usuario', 'seguimiento.id_usuario', '=', 'usuario.id')
            ->whereIn('seguimiento.id_documento', $documentIds)
            ->get();

        $recepciones = DB::table("documento_recepcion")
            ->select("documento_recepcion.documento_erp", "documento_recepcion.documento_erp_compra", "usuario.nombre", "documento_recepcion.created_at")
            ->join("movimiento", "documento_recepcion.id_movimiento", "=", "movimiento.id")
            ->join("usuario", "documento_recepcion.id_usuario", "=", "usuario.id")
            ->whereIn("movimiento.id_documento", $documentIds)
            ->groupBy('documento_erp')
            ->get();

        foreach ($documentos as $documento) {
            $documento->productos = $productos->where('id_documento', $documento->id)->values();
            $documento->archivos_anteriores = $archivosAnteriores->where('id_documento', $documento->id)->values();
            $documento->seguimiento = $seguimientos->where('id_documento', $documento->id)->values();
            $documento->recepciones = $recepciones->where('id_documento', $documento->id)->values();

            $documento->proveedor = new \stdClass();
            $documento->proveedor->id = $documento->id_erp;
            $documento->proveedor->rfc = $documento->rfc;
            $documento->proveedor->razon = $documento->razon_social;
            $documento->proveedor->telefono = 0;
            $documento->proveedor->email = "";
            $documento->total = 0;
            $documento->fecha_entrega = !str_contains($documento->fecha_entrega, '0000-00-00') ? date("Y-m-d", strtotime($documento->fecha_entrega)) : "";

            $documento->odc = DB::table("documento")
                ->select("documento.id")
                ->where("documento.id_fase", ">", 603)
                ->where("documento.observacion", $documento->id)
                ->get()
                ->first();

            $documento->odc = $documento->odc ? $documento->odc->id : 0;

            foreach ($documento->recepciones as $recepcion) {
                $recepcion->productos = DB::table("documento_recepcion")
                    ->select("modelo.sku", "modelo.descripcion", "documento_recepcion.cantidad")
                    ->join("movimiento", "documento_recepcion.id_movimiento", "=", "movimiento.id")
                    ->join("modelo", "movimiento.id_modelo", "=", "modelo.id")
                    ->where("documento_recepcion.documento_erp", $recepcion->documento_erp)
                    ->where("movimiento.id_documento", $documento->id)
                    ->get()
                    ->toArray();
            }

            $documento->total = $documento->productos->sum(function ($producto) {
                return (int) $producto->cantidad * (float) $producto->costo;
            });

            foreach ($documento->productos as $producto) {

                if (strlen($producto->codigo) < 5) {
                    $producto->oculto = str_repeat('*', strlen($producto->codigo) - 2) . substr($producto->codigo, -2);
                } else {
                    $producto->oculto = str_repeat('*', strlen($producto->codigo) - 5) . substr($producto->codigo, -5);
                }

                if ($producto->serie) {
                    $producto->series = DB::table("movimiento_producto")
                        ->join("producto", "movimiento_producto.id_producto", "=", "producto.id")
                        ->where("movimiento_producto.id_movimiento", $producto->id)
                        ->select("producto.id", "producto.serie", "producto.fecha_caducidad")
                        ->get()
                        ->toArray();

                    $producto->cantidad_recepcionada_anterior = count($producto->series);
                }
            }

            $documento->total = round($documento->total * 1.16, 2);

            unset($documento->id_erp, $documento->rfc);
        }

        return $documentos;
    }
}
