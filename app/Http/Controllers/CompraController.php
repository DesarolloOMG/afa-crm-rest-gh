<?php
/** @noinspection PhpUndefinedMethodInspection */
/** @noinspection PhpRedundantOptionalArgumentInspection */
/** @noinspection PhpUndefinedFieldInspection */
/** @noinspection PhpUnused */
/** @noinspection PhpComposerExtensionStubsInspection */

namespace App\Http\Controllers;

use App\Events\PusherEvent;
use App\Http\Services\CompraService;
use App\Http\Services\DocumentoService;
use App\Http\Services\DropboxService;
use App\Http\Services\InventarioService;
use App\Models\DocumentoEntidad;
use App\Models\DocumentoEntidadUpdates;
use Crabbly\Fpdf\Fpdf;
use Exception;
use Httpful\Exception\ConnectionErrorException;
use Httpful\Mime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Mailgun\Mailgun;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use stdClass;
use Throwable;

class CompraController extends Controller
{
    /* Compra > Compra */
    public function compra_compra_crear(Request $request): JsonResponse
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        $data->serie_documento = (property_exists($data, "serie_documento")) ? $data->serie_documento : "";

        $existe_documento = DB::table('documento')
            ->join('documento_entidad', 'documento.id_entidad', '=', 'documento_entidad.id')
            ->where('documento.id_tipo', 1)
            ->where('factura_folio', trim($data->folio))
            ->where('factura_serie', trim($data->serie_documento))
            ->where('documento.id_almacen_principal_empresa', (int)$data->almacen)
            ->where('documento_entidad.rfc', $data->proveedor->rfc)
            ->select('factura_folio')
            ->get();

        if (count($existe_documento) > 0) {
            return response()->json([
                'code' => 500,
                'message' => "Ya éxiste una compra con el folio proporcionado y serie proporcionado del proveedor " . $data->proveedor->razon . "."
            ]);
        }

        if (strpos(TRIM($data->proveedor->rfc), 'XEXX010101000') === false) {
            $entidad = $data->proveedor->id;
        } else {
            //Preguntar
            $entidad = DB::table('documento_entidad')->insertGetId([
                'tipo' => 2,
                'razon_social' => mb_strtoupper($data->proveedor->razon, 'UTF-8'),
                'rfc' => mb_strtoupper($data->proveedor->rfc, 'UTF-8'),
                'telefono' => !empty($data->proveedor->telefono) && !is_null($data->proveedor->telefono) ? $data->proveedor->telefono : "",
                'correo' => !empty($data->proveedor->email) && !is_null($data->proveedor->email) ? $data->proveedor->email : ""
            ]);
        }

        try {
            $documento = DB::table('documento')->insertGetId([
                'id_tipo' => 1,
                'id_almacen_principal_empresa' => (int)$data->almacen,
                'id_periodo' => $data->periodo,
                'id_cfdi' => $data->uso_cfdi,
                'id_usuario' => $auth->id,
                'id_moneda' => $data->moneda,
                'id_paqueteria' => 6,
                'id_fase' => 100,
                'id_entidad' => $entidad,
                'factura_serie' => $data->serie_documento,
                'factura_folio' => $data->folio,
                'tipo_cambio' => $data->tipo_cambio,
                'referencia' => $data->recepcion != 0 ? "Compra creada a partir de la recepción de compra con el ID " . $data->recepcion : "N/A",
                'observacion' => $data->recepcion != 0 ? "1" : "N/A",
                'info_extra' => 'N/A',
                'comentario' => $data->metodo_pago,
                'pedimento' => json_encode($data->pedimento),
                'uuid' => (array_key_exists("uuid", $data)) ? $data->uuid : 'N/A',
                'expired_at' => $data->fecha
            ]);

            foreach ($data->productos as $producto) {
                /** @noinspection PhpUnusedLocalVariableInspection */
                $movimiento = DB::table('movimiento')->insertGetId([
                    'id_documento' => $documento,
                    'id_modelo' => $producto->id,
                    'cantidad' => $producto->cantidad,
                    'precio' => $producto->costo,
                    'garantia' => 0,
                    'modificacion' => 'N/A',
                    'comentario' => $producto->descripcion,
                    'regalo' => 0
                ]);
            }

            if ($data->recepcion != 0) {
                $recepciones = explode(",", $data->recepcion);

                DB::table("documento_recepcion")->whereIn("documento_erp", $recepciones)->update([
                    "documento_erp_compra" => $documento
                ]);
            }

            InventarioService::aplicarMovimiento($documento);
        } catch (Exception $e) {
            if (isset($documento)) {
                DB::table('documento')->where(['id' => $documento])->delete();
            }

            return response()->json([
                'code' => 500,
                'message' => "No fue posible crear la compra, error: " . $e->getMessage()
            ]);
        }

        if (!empty($data->seguimiento)) {
            DB::table("seguimiento")->insert([
                "id_documento" => $documento,
                "id_usuario" => $auth->id,
                "seguimiento" => $data->seguimiento
            ]);
        }

        return response()->json([
            'code' => 200,
            'message' => "Compra creada correctamente, podrás visualizarla en el historial."
        ]);
    }

    public function compra_compra_crear_data(Request $request): JsonResponse
    {
        /** @noinspection PhpUndefinedFieldInspection */
        $auth = json_decode($request->auth);

        $empresas = DB::select("SELECT
                                    empresa.id,
                                    empresa.bd,
                                    empresa.empresa
                                FROM empresa
                                INNER JOIN usuario_empresa ON empresa.id = usuario_empresa.id_empresa
                                WHERE empresa.status = 1 AND empresa.id != 0
                                AND usuario_empresa.id_usuario = " . $auth->id);
        $monedas = DB::select("SELECT id, moneda FROM moneda");
        $metodos = DB::select("SELECT * FROM metodo_pago");
        $periodos = DB::select("SELECT id, periodo FROM documento_periodo WHERE status = 1");
        $usos_cfdi = DB::select("SELECT * FROM documento_uso_cfdi");
        $tipos = DB::select("SELECT id, tipo FROM modelo_tipo");

        $usuarios_whats = DB::select("SELECT
                                    usuario.id,
                                    usuario.nombre,
                                    usuario.celular,
                                    nivel.nivel
                                FROM usuario
                                INNER JOIN usuario_subnivel_nivel ON usuario.id = usuario_subnivel_nivel.id_usuario
                                INNER JOIN subnivel_nivel ON usuario_subnivel_nivel.id_subnivel_nivel = subnivel_nivel.id
                                INNER JOIN nivel ON subnivel_nivel.id_nivel = nivel.id
                                INNER JOIN subnivel ON subnivel_nivel.id_subnivel = subnivel.id
                                WHERE (nivel.nivel = 'COMPRAS' AND subnivel.subnivel = 'ADMINISTRADOR')
                                OR nivel.nivel = 'ADMINISTRADOR'
                                AND usuario.id != 1
                                GROUP BY usuario.id");

        foreach ($empresas as $empresa) {
            $almacenes = DB::select("SELECT
                                        empresa_almacen.id,
                                        almacen.almacen
                                    FROM empresa_almacen
                                    INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                                    WHERE empresa_almacen.id_empresa = " . $empresa->id . "
                                    AND almacen.status = 1
                                    AND almacen.id != 0
                                    ORDER BY almacen.almacen");

            $empresa->almacenes = $almacenes;
        }

        return response()->json([
            'code' => 200,
            'empresas' => $empresas,
            'monedas' => $monedas,
            'metodos' => $metodos,
            'periodos' => $periodos,
            'usos' => $usos_cfdi,
            'tipos' => $tipos,
            'usuarios_whats' => $usuarios_whats
        ]);
    }

    public function compra_compra_crear_usuario($criterio): JsonResponse
    {
        $usuarios = DB::select("SELECT id, nombre FROM usuario WHERE nombre LIKE '%" . $criterio . "%'");

        return response()->json([
            'code' => 200,
            'usuarios' => $usuarios
        ]);
    }

    /** @noinspection PhpParamsInspection */
    public function compra_compra_crear_get_recepcion($recepcion): JsonResponse
    {
        $recepciones = explode(",", $recepcion);

        $compra_recepcion = DB::table("documento_recepcion")
            ->select("documento_erp", "documento_erp_compra")
            ->whereIn("documento_erp", $recepciones)
            ->get()
            ->toArray();

        foreach ($compra_recepcion as $compra) {
            if ($compra->documento_erp_compra != 'N/A') {
                return response()->json([
                    "code" => 500,
                    "message" => "La recepcion " . $compra->documento_erp . " ya cuenta con una compra creada con el ID " . $compra->documento_erp_compra
                ]);
            }
        }

        $documento = DB::table("documento_recepcion")
            ->select("movimiento.id_documento AS documento")
            ->join("movimiento", "documento_recepcion.id_movimiento", "=", "movimiento.id")
            ->where("documento_recepcion.documento_erp", $recepciones[0])
            ->first();

        $informacion = DB::table("documento")
            ->select("documento.id_almacen_principal_empresa", "empresa.bd", "documento_entidad.rfc", "documento_entidad.razon_social")
            ->join("documento_entidad", "documento.id_entidad", "=", "documento_entidad.id")
            ->join("empresa_almacen", "documento.id_almacen_principal_empresa", "=", "empresa_almacen.id")
            ->join("empresa", "empresa_almacen.id_empresa", "=", "empresa.id")
            ->where("documento.id", $documento->documento)
            ->first();

        $informacion->productos = DB::table("documento_recepcion")
            ->select("modelo.id", DB::raw("SUM(documento_recepcion.cantidad) AS cantidad"), "modelo.sku AS codigo", "modelo.descripcion", DB::raw("AVG(movimiento.precio) AS costo"))
            ->join("movimiento", "documento_recepcion.id_movimiento", "=", "movimiento.id")
            ->join("modelo", "movimiento.id_modelo", "=", "modelo.id")
            ->whereIn("documento_recepcion.documento_erp", $recepciones)
            ->groupBy("modelo.sku")
            ->get()
            ->toArray();

        return response()->json([
            "code" => 200,
            "data" => $informacion
        ]);
    }

    public function compra_compra_editar_data($serie, $folio): JsonResponse
    {
        $serie = $serie == 'na' ? '' : $serie;

        $informacion = DB::select("SELECT 
                                    documento.*,
                                    empresa_almacen.id_empresa,
                                    empresa.bd
                                FROM documento 
                                INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                WHERE documento.id_tipo = 1
                                AND factura_serie = '" . $serie . "' 
                                AND factura_folio = '" . $folio . "' 
                                ORDER BY created_at DESC LIMIT 1");

        if (empty($informacion)) {
            return response()->json([
                'code' => 404,
                'message' => "No se encontró ninguna compra con el folio y serie proporcionado."
            ]);
        }

        $informacion = $informacion[0];

        $proveedor = DB::select("SELECT
                                    documento_entidad.*
                                FROM documento
                                INNER JOIN documento_entidad ON documento.id_entidad = documento_entidad.id
                                WHERE documento.id = " . $informacion->id);

        if (empty($proveedor)) {
            return response()->json([
                'code' => 404,
                'message' => "No se encontró información sobre el proveedor de la compra."
            ]);
        }

        $informacion->proveedor = $proveedor[0];

        $productos = DB::select("SELECT
                                    movimiento.id,
                                    movimiento.precio as costo,
                                    movimiento.cantidad,
                                    movimiento.comentario AS descripcion_2,
                                    modelo.sku as codigo,
                                    modelo.descripcion,
                                    modelo.serie,
                                    modelo.peso,
                                    modelo.alto,
                                    modelo.ancho,
                                    modelo.largo,
                                    modelo.costo_extra,
                                    modelo.id_tipo as tipo,
                                    modelo.clave_sat,
                                    modelo.clave_unidad,
                                    0 AS existe
                                FROM movimiento
                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                WHERE movimiento.id_documento = " . $informacion->id);

        if (empty($productos)) {
            return response()->json([
                'code' => 404,
                'message' => "No se encontró información sobre los productos de la compra."
            ]);
        }

        foreach ($productos as $producto) {
            $producto->serie = array();
        }

        $informacion->productos = $productos;

        $informacion->seguimiento = DB::select("SELECT
                                                    seguimiento.*, 
                                                    usuario.nombre 
                                                FROM seguimiento 
                                                INNER JOIN usuario ON seguimiento.id_usuario = usuario.id 
                                                WHERE id_documento = " . $informacion->id);

        return response()->json([
            'code' => 200,
            'informacion' => $informacion
        ]);
    }

    public function compra_compra_editar_guardar(Request $request): JsonResponse
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        $data->serie_documento = (property_exists($data, "serie_documento")) ? $data->serie_documento : "";

        $existe_documento = DB::select("SELECT
                                            factura_folio
                                        FROM documento
                                        INNER JOIN documento_entidad ON documento.id_entidad = documento_entidad.id
                                        WHERE documento.id_tipo = 1
                                        AND documento.factura_folio = '" . TRIM($data->folio) . "'
                                        AND documento.factura_serie = '" . TRIM($data->serie_documento) . "'
                                        AND documento_entidad.rfc = '" . $data->proveedor->rfc . "'
                                        AND documento.id_almacen_principal_empresa = " . $data->almacen . "
                                        AND documento.id != " . $data->documento);

        if (!empty($existe_documento)) {
            return response()->json([
                'code' => 500,
                'message' => "Ya éxiste una compra con el folio proporcionado y serie proporcionado del proveedor " . $data->proveedor->razon . "."
            ]);
        }

        if (strpos(TRIM($data->proveedor->rfc), 'XEXX010101000') === false) {
            $entidad = $data->proveedor->id;
        } else {
            $entidad = DB::table('documento_entidad')->insertGetId([
                'tipo' => 2,
                'razon_social' => mb_strtoupper($data->proveedor->razon, 'UTF-8'),
                'rfc' => mb_strtoupper($data->proveedor->rfc, 'UTF-8'),
                'telefono' => !empty($data->proveedor->telefono) && !is_null($data->proveedor->telefono) ? $data->proveedor->telefono : "",
                'correo' => !empty($data->proveedor->email) && !is_null($data->proveedor->email) ? $data->proveedor->email : ""
            ]);
        }

        DB::table('documento')->where(['id' => $data->documento])->update([
            'id_almacen_principal_empresa' => $data->almacen,
            'id_periodo' => $data->periodo,
            'id_cfdi' => $data->uso_cfdi,
            'id_moneda' => $data->moneda,
            'factura_serie' => $data->serie_documento,
            'factura_folio' => $data->folio,
            'tipo_cambio' => $data->tipo_cambio,
            'id_entidad' => $entidad,
            'referencia' => 'N/A',
            'observacion' => 'N/A',
            'info_extra' => 'N/A',
            'comentario' => $data->metodo_pago,
            'expired_at' => $data->fecha
        ]);

        $movimientos_documento = DB::select("SELECT id, id_modelo, cantidad, 0 AS editado FROM movimiento WHERE id_documento = " . $data->documento);

        /** @noinspection PhpUnusedLocalVariableInspection */
        foreach ($data->productos as $index => $producto) {
            DB::table('movimiento')->where(['id' => $producto->id])->update([
                'id_modelo' => $producto->id,
                'cantidad' => $producto->cantidad,
                'precio' => $producto->costo
            ]);

            foreach ($movimientos_documento as $movimiento) {
                if ($movimiento->id == $producto->id) {
                    $movimiento->editado = 1;
                }
            }
        }

        foreach ($movimientos_documento as $movimiento) {
            if (!$movimiento->editado) {
                DB::table('movimiento')->where(['id' => $movimiento->id])->delete();
            }
        }

        DB::table('documento_updates_by')->insert([
            'id_documento' => $data->documento,
            'id_usuario' => $auth->id
        ]);

        if (!empty($data->usuarios)) {
            $usuarios = array();

            $notificacion['titulo'] = "Nueva compra";
            $notificacion['message'] = "Se ha editado correctamente la compra con el folio " . $data->folio . ", podrás visualizar los detalles en la sección de reportes.";
            $notificacion['tipo'] = "success"; // success, warning, danger
            $notificacion['link'] = "/compra/compra/historial/" . $data->documento;

            $notificacion_id = DB::table('notificacion')->insertGetId([
                'data' => json_encode($notificacion)
            ]);

            $notificacion['id'] = $notificacion_id;

            foreach ($data->usuarios as $usuario) {
                DB::table('notificacion_usuario')->insert([
                    'id_usuario' => $usuario->id,
                    'id_notificacion' => $notificacion_id
                ]);

                $usuarios[] = $usuario->id;
            }

            if (!empty($usuarios)) {
                $notificacion['usuario'] = $usuarios;

//                event(new PusherEvent(json_encode($notificacion)));
            }
        }

        if (!empty($data->seguimiento)) {
            DB::table("seguimiento")->insert([
                "id_documento" => $data->documento,
                "id_usuario" => $auth->id,
                "seguimiento" => $data->seguimiento
            ]);
        }

        return response()->json([
            'code' => 200,
            'message' => "Compra editada correctamente, podrás visualizarla en el historial."
        ]);
    }

    public function compra_compra_crear_producto(Request $request): JsonResponse
    {
        $descripcion = $request->input('descripcion');

        $productos = DB::select("SELECT
                                    modelo.sku
                                FROM modelo_descripcion
                                INNER JOIN modelo ON modelo_descripcion.id_modelo = modelo.id
                                WHERE modelo_descripcion.descripcion = '" . $descripcion . "'");

        return response()->json([
            'code' => 200,
            'productos' => $productos
        ]);
    }

    public function compra_compra_crear_uuid(Request $request): JsonResponse
    {
        $uuid = $request->input('uuid');

        $compras = DB::select("SELECT factura_serie, factura_folio FROM documento WHERE uuid = '" . $uuid . "' AND status = 1");

        if (!empty($compras)) {
            return response()->json([
                'code' => 500,
                'message' => "Ya existe una compra registrada con el UUID del XML, " . $compras[0]->factura_serie . " " . $compras[0]->factura_folio
            ]);
        }

        return response()->json([
            'code' => 200
        ]);
    }

    public function compra_compra_corroborar_data(): JsonResponse
    {
        $compras = $this->compras_raw_data("AND documento.id_fase = 91");

        return response()->json([
            'code' => 200,
            'ordenes' => $compras
        ]);
    }

    private function compras_raw_data($extra_data): array
    {
        $compras = DB::select("SELECT
                                    documento.id,
                                    documento.id_almacen_principal_empresa,
                                    documento.factura_serie AS serie,
                                    documento.factura_folio AS folio,
                                    documento.id_fase,
                                    documento.credito,
                                    documento.documento_extra,
                                    documento.created_at AS expired_at,
                                    documento_fase.fase,
                                    documento.comentario,
                                    documento.tipo_cambio,
                                    documento.uuid,
                                    documento_periodo.periodo,
                                    documento.finished_at,
                                    documento.expired_at as expired_at_2,
                                    moneda.moneda,
                                    almacen.almacen,
                                    empresa.empresa,
                                    empresa.bd,
                                    usuario.nombre
                                FROM documento
                                INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                INNER JOIN documento_periodo ON documento.id_periodo = documento_periodo.id
                                INNER JOIN moneda ON documento.id_moneda = moneda.id
                                INNER JOIN usuario ON documento.id_usuario = usuario.id
                                INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                                INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                INNER JOIN documento_fase ON documento.id_fase = documento_fase.id
                                AND documento.id_tipo = 1
                                AND documento.status = 1
                                " . $extra_data . "
                                GROUP BY documento.id");

        foreach ($compras as $compra) {
            $total_documento = 0;

            $proveedor = DB::table('documento')
                ->join('documento_entidad', 'documento_entidad.id', '=', 'documento.id_entidad')
                ->where('documento.id', $compra->id)
                ->whereIn('documento_entidad.tipo', [2, 3])
                ->select('documento_entidad.razon_social', 'documento_entidad.rfc')
                ->first();

            $compra->proveedor = $proveedor ? $proveedor->razon_social : 'Sin proveedor';
            $compra->rfc = $proveedor ? $proveedor->rfc : 'Sin proveedor';


            $compra->ediciones = DB::select("SELECT
                                                usuario.nombre,
                                                documento_updates_by.created_at
                                            FROM documento
                                            INNER JOIN documento_updates_by ON documento_updates_by.id_documento = documento.id
                                            INNER JOIN usuario ON documento_updates_by.id_usuario = usuario.id
                                            WHERE documento_updates_by.id_documento = " . $compra->id);

            $compra->seguimiento = DB::select("SELECT
                                                    seguimiento.*, 
                                                    usuario.nombre 
                                                FROM seguimiento 
                                                INNER JOIN usuario ON seguimiento.id_usuario = usuario.id 
                                                WHERE id_documento = " . $compra->id);

            $compra->productos = DB::select("SELECT
                                                movimiento.id,
                                                movimiento.cantidad,
                                                movimiento.cantidad_aceptada,
                                                movimiento.precio AS costo,
                                                movimiento.comentario AS descripcion_2,
                                                modelo.id AS id_modelo,
                                                modelo.sku,
                                                modelo.descripcion,
                                                modelo.serie,
                                                modelo.ancho,
                                                modelo.largo,
                                                modelo.alto,
                                                modelo.costo_extra,
                                                modelo.cat1,
                                                modelo.cat2,
                                                modelo.cat3,
                                                modelo.cat4,
                                                0 AS almacen,
                                                1 AS existe
                                            FROM movimiento
                                            INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                            WHERE movimiento.id_documento = " . $compra->id);

            $compra->ordenes = DB::select("SELECT
                                                documento.id
                                            FROM documento
                                            INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                            INNER JOIN documento_recepcion ON movimiento.id = documento_recepcion.id_movimiento
                                            WHERE documento.id_tipo = 0
                                            AND documento_recepcion.documento_erp_compra = '" . $compra->documento_extra . "'
                                            GROUP BY documento.id");

            /** @noinspection PhpUnusedLocalVariableInspection */
            foreach ($compra->productos as $k => $producto) {
                $total_documento += (int)$producto->cantidad * (float)$producto->costo;

                if ($producto->serie) {
                    $series = DB::select("SELECT
                                        1 AS status,
                                        producto.id,
                                        producto.serie
                                    FROM movimiento_producto
                                    INNER JOIN producto ON movimiento_producto.id_producto = producto.id
                                    WHERE movimiento_producto.id_movimiento = " . $producto->id);

                    $producto->series = $series;
                }

                $producto->sinonimos = DB::table("modelo_sinonimo")
                    ->select("codigo")
                    ->where("id_modelo", $producto->id)
                    ->pluck("codigo");
            }

            $compra->total = round($total_documento * 1.16, 2);
        }

        return $compras;
    }

    public function compra_compra_corroborar_guardar(Request $request): JsonResponse
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        DB::table('movimiento')->where(['id_documento' => $data->documento])->delete();

        foreach ($data->productos as $producto) {
            $existe_producto = DB::select("SELECT id FROM modelo WHERE sku = '" . $producto->sku . "'");

            $producto->descripcion = str_replace('"', '', $producto->descripcion);
            $producto->descripcion = str_replace("'", "", $producto->descripcion);

            if (empty($existe_producto)) {
                $modelo = DB::table('modelo')->insertGetId([
                    'sku' => trim($producto->sku),
                    'descripcion' => trim($producto->descripcion),
                    'costo' => 0,
                    'alto' => 0,
                    'ancho' => 0,
                    'largo' => 0,
                    'peso' => 0
                ]);
            } else {
                $modelo = $existe_producto[0]->id;
            }

            DB::table('movimiento')->insertGetId([
                'id_documento' => $data->documento,
                'id_modelo' => $modelo,
                'cantidad' => $producto->cantidad,
                'cantidad_aceptada' => $producto->cantidad_aceptada,
                'precio' => $producto->costo,
                'garantia' => 0,
                'modificacion' => 'N/A',
                'comentario' => $producto->descripcion,
                'regalo' => 0
            ]);

            if (property_exists($producto, "sinonimos")) {
                foreach ($producto->sinonimos as $sinonimo) {
                    $existe_sinonimo = DB::table("modelo_sinonimo")
                        ->select("id")
                        ->where("id_modelo", $modelo)
                        ->first();

                    if (empty($existe_sinonimo)) {
                        DB::table("modelo_sinonimo")->insert([
                            "id_modelo" => $modelo,
                            "codigo" => $sinonimo
                        ]);
                    }
                }
            }
        }

        DB::table('documento')->where(['id' => $data->documento])->update([
            'id_fase' => 92
        ]);

        if (!empty($data->seguimiento)) {
            DB::table("seguimiento")->insert([
                "id_documento" => $data->documento,
                "id_usuario" => $auth->id,
                "seguimiento" => $data->seguimiento
            ]);
        }

        return response()->json([
            'code' => 200,
            'message' => "Compra corroborada correctamente"
        ]);
    }

    public function compra_compra_autorizar_data(): JsonResponse
    {
        $tipos = DB::select("SELECT id, tipo FROM modelo_tipo");
        $compras = $this->compras_raw_data("AND documento.id_fase = 92");

        return response()->json([
            'code' => 200,
            'tipos' => $tipos,
            'ordenes' => $compras
        ]);
    }

    public function compra_compra_autorizar_guardar(Request $request): JsonResponse
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        if (!$data->autorizar) {
            DB::table('documento')->where(['id' => $data->documento])->update([
                'id_fase' => 91
            ]);

            return response()->json([
                'code' => 200,
                'message' => "La compra fue regresada a la fase de corroborar."
            ]);
        }

        foreach ($data->productos as $producto) {
            $existe_sku = DB::select("SELECT id FROM modelo WHERE sku = '" . $producto->sku . "'");

            if (empty($existe_sku)) {
                return response()->json([
                    'code' => 500,
                    'message' => "No se encontró el producto con el codigo " . $producto->sku
                ]);
            }

            $existe_descripcion = DB::select("SELECT id FROM modelo_descripcion WHERE descripcion = '" . trim($producto->descripcion) . "'");

            if (empty($existe_descripcion)) {
                DB::table('modelo_descripcion')->insert([
                    'id_modelo' => $existe_sku[0]->id,
                    'descripcion' => trim(utf8_encode($producto->descripcion))
                ]);
            }

            DB::table('modelo')->where(['id' => $existe_sku[0]->id])->update([
                'costo_extra' => $producto->costo_extra
            ]);

            DB::table('movimiento')->where(['id' => $producto->id])->update([
                'id_modelo' => $existe_sku[0]->id,
                'precio' => $producto->costo,
                'cantidad_aceptada' => 0
            ]);
        }

        DB::table('documento')->where(['id' => $data->documento])->update([
            'id_fase' => 93,
            'autorizado_by' => $auth->id
        ]);

        if (!empty($data->seguimiento)) {
            DB::table("seguimiento")->insert([
                "id_documento" => $data->documento,
                "id_usuario" => $auth->id,
                "seguimiento" => $data->seguimiento
            ]);
        }

        return response()->json([
            'code' => 200,
            'message' => "Compra autorizada correctamente."
        ]);
    }

    public function compra_compra_autorizar_cancelar($documento, Request $request): JsonResponse
    {
        $auth = json_decode($request->auth);
        $info_documento = DB::select("SELECT id_fase FROM documento WHERE id = " . $documento . " AND status = 1 AND id_tipo = 1");

        if (empty($info_documento)) {
            return response()->json([
                'code' => 404,
                'message' => "No se encontró información del documento."
            ]);
        }

        if ($info_documento[0]->id_fase > 92) {
            return response()->json([
                'code' => 500,
                'message' => "No es posible cancelar compras que ya han sido importadas a Comercial."
            ]);
        }

        DB::table('documento')->where(['id' => $documento])->update([
            'canceled_by' => $auth->id,
            'status' => 0
        ]);

        return response()->json([
            'code' => 200,
            'message' => "Documento cancelado correctamente"
        ]);
    }

    public function compra_compra_pendiente_data(): JsonResponse
    {
        $empresas = DB::select("SELECT id, bd, empresa FROM empresa WHERE status = 1 AND id != 0");
        $compras = $this->compras_raw_data("AND documento.id_fase = 93");

        foreach ($empresas as $empresa) {
            $almacenes = DB::select("SELECT
                                        empresa_almacen.id,
                                        almacen.almacen
                                    FROM empresa_almacen
                                    INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                                    WHERE empresa_almacen.id_empresa = " . $empresa->id . "
                                    AND almacen.status = 1
                                    AND almacen.id != 0
                                    ORDER BY almacen.almacen ");

            $empresa->almacenes = $almacenes;
        }

        return response()->json([
            'code' => 200,
            'ordenes' => $compras,
            'empresas' => $empresas
        ]);
    }

    public function compra_compra_pendiente_confirmar(Request $request): JsonResponse
    {
        $documento = $request->input('documento');
        $seriesJson = $request->input('series');
        $series = json_decode($seriesJson, true);
        $array = [];
        $seriesUnicas = [];

        // Verificar duplicidades en el array recibido
        foreach ($series as $serie) {
            if (in_array($serie['serie'], $seriesUnicas)) {
                $object = new stdClass();
                $object->serie = $serie['serie'];
                $object->status = 0; // Marcar como duplicada
            } else {
                $seriesUnicas[] = $serie['serie'];
                $object = new stdClass();
                $object->serie = $serie['serie'];
                $object->status = 1; // Asumir inicialmente que es válida
            }
            $array[] = $object;
        }

        // Verificación de existencia en la base de datos para series no duplicadas
        foreach ($array as $object) {
            if ($object->status == 1) { // Solo verificar las que aún no están marcadas como duplicadas
                $trimmedSerie = trim($object->serie);
                $existe = DB::table('documento')
                    ->join('movimiento', 'documento.id', '=', 'movimiento.id_documento')
                    ->join('movimiento_producto', 'movimiento.id', '=', 'movimiento_producto.id_movimiento')
                    ->join('producto', 'movimiento_producto.id_producto', '=', 'producto.id')
                    ->where('producto.serie', $trimmedSerie)
                    ->where('movimiento.id_documento', '!=', $documento)
                    ->exists();

                if ($existe) {
                    $object->status = 0; // Marcar como existente en el sistema
                }
            }
        }

        return response()->json([
            'code' => 200,
            'series' => $array
        ]);
    }

    public function compra_compra_pendiente_guardar(Request $request): JsonResponse
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);
        $terminada = 1;

        $almacen = DB::select("SELECT
                                empresa_almacen.id_almacen,
                                documento.id_almacen_principal_empresa
                            FROM documento
                            INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                            WHERE documento.id = " . $data->documento);

        if (empty($almacen)) {
            return response()->json([
                'code' => 500,
                'message' => "No se encontró el almacén del documento, favor de contactar a un administrador."
            ]);
        }

        foreach ($data->productos as $producto) {
            if ($producto->serie) {
                $total_series = 0;

                foreach ($producto->series as $serie) {
                    $movimiento = DB::table('movimiento')->where('id', $producto->id)->first();
                    $serie->serie = str_replace(["'", '\\'], '', $serie->serie);
                    if ($serie->status) $total_series++;

                    if ($serie->status && $serie->id == 0) {
                        $existe_serie = DB::select("SELECT id, status FROM producto WHERE serie = '" . TRIM($serie->serie) . "'");

                        if (!empty($existe_serie)) {
                            DB::table("producto")->where(["id" => $existe_serie[0]->id])->update([
                                "id_almacen" => $almacen[0]->id_almacen,
                                "id_modelo" => $movimiento->id_modelo,
                                "status" => 1
                            ]);
                        } else {
                            $id_serie = DB::table('producto')->insertGetId([
                                'id_almacen' => $almacen[0]->id_almacen,
                                "id_modelo" => $movimiento->id_modelo,
                                'serie' => $serie->serie,
                                'status' => 1
                            ]);
                        }

                        /** @noinspection PhpUndefinedVariableInspection */
                        DB::table('movimiento_producto')->insert([
                            'id_movimiento' => $producto->id,
                            'id_producto' => empty($existe_serie) ? $id_serie : $existe_serie[0]->id
                        ]);
                    }
                }

                $producto->series = DB::select("SELECT
                                        1 AS status,
                                        producto.id,
                                        producto.serie
                                    FROM movimiento_producto
                                    INNER JOIN producto ON movimiento_producto.id_producto = producto.id
                                    WHERE movimiento_producto.id_movimiento = " . $producto->id);

                $producto->cantidad_recibida = $total_series;

                if ($total_series != $producto->cantidad) $terminada = 0;
            } else {
                $completa = $producto->cantidad_aceptada == $producto->cantidad ? 1 : 0;

                DB::table('movimiento')->where(['id' => $producto->id])->update([
                    'completa' => $completa,
                    'cantidad_aceptada' => $producto->cantidad_aceptada
                ]);

                if (!$completa) {
                    $terminada = 0;
                }

                $producto->cantidad_recibida = $producto->cantidad_aceptada;
            }
        }

        if ($terminada) {
            DB::table('documento')->where(['id' => $data->documento])->update([
                'finished_at' => date("Y-m-d H:i:s"),
                'id_fase' => 94
            ]);
        }

        if (!empty($data->seguimiento)) {
            DB::table("seguimiento")->insert([
                "id_documento" => $data->documento,
                "id_usuario" => $auth->id,
                "seguimiento" => $data->seguimiento
            ]);
        }

        $info_compra = DB::select("SELECT
                                        documento.factura_folio,
                                        documento_entidad.razon_social
                                    FROM documento
                                    INNER JOIN documento_entidad ON documento.id_entidad = documento_entidad.id
                                    WHERE documento.id = " . $data->documento);

        if (!empty($info_compra)) {
            $pdf = new Fpdf();

            $pdf->AddPage('');
            $pdf->SetFont('Arial', 'B', 15);
            $pdf->SetTextColor(69, 90, 100);

            $pdf->Cell(0, 10, utf8_decode(mb_strtoupper("RECIBO DE ALMACÉN", 'UTF-8')), 0, 0, 'C');

            $pdf->Ln(5);
            $pdf->Cell(0, 10, "OMG INTERNATIONAL S.A DE C.V.", 0, 0, 'C');

            $pdf->Ln(25);

            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(240, 0, "PROVEEDOR", 0, 0);

            $pdf->Ln(5);

            $pdf->SetFont('Arial', '', 12);
            $pdf->Cell(135, 0, utf8_decode(mb_strtoupper($info_compra[0]->razon_social, 'UTF-8')), 0, 0);
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(20, 0, utf8_decode(mb_strtoupper("COMPRA", 'UTF-8')), 0, 0);
            $pdf->SetFont('Arial', '', 12);
            $pdf->Cell(0, 0, utf8_decode(mb_strtoupper($info_compra[0]->factura_folio, 'UTF-8')), 0, 0);

            $pdf->Ln(15);

            $pdf->SetFont('Arial', 'B', 8);
            $pdf->Cell(40, 10, "CODIGO", "T");
            $pdf->Cell(110, 10, "DESCRIPCION", "T");
            $pdf->Cell(20, 10, "CANTIDAD", "T");
            $pdf->Cell(20, 10, "RECIBIDA", "T");
            $pdf->Ln();

            $pdf->SetFont('Arial', '', 8);

            foreach ($data->productos as $producto) {
                $producto_data = DB::select("SELECT
                                                modelo.sku,
                                                modelo.descripcion
                                            FROM movimiento
                                            INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                            WHERE movimiento.id = " . $producto->id);

                $pdf->Cell(40, 10, $producto_data[0]->sku, "T");
                $pdf->Cell(110, 10, (strlen($producto_data[0]->descripcion) > 60) ? substr($producto_data[0]->descripcion, 0, 60) . " .." : $producto_data[0]->descripcion, "T");
                $pdf->Cell(20, 10, $producto->cantidad, "T");
                $pdf->Cell(20, 10, $producto->cantidad_recibida, "T");
                $pdf->Ln();
            }

            $pdf->Ln(30);
            $pdf->Cell(100, 0, utf8_decode(mb_strtoupper("RECIBIÓ: _______________________________________________________", 'UTF-8')), 0, 0);

            $pdf->Ln(10);
            $pdf->Cell(100, 0, utf8_decode(mb_strtoupper("FECHA: _______________________________________________________", 'UTF-8')), 0, 0);

            $pdf_name = uniqid() . ".pdf";
            $pdf_data = $pdf->Output($pdf_name, 'S');
            $file_name = uniqid() . ".pdf";

            $json['file'] = base64_encode($pdf_data);
            $json['name'] = $file_name;
        }

        $json['code'] = 200;
        $json['productos'] = $data->productos;
        $json['message'] = ($terminada) ? "Compra finalizada correctamente" : "Compra actualizada correctamente";
        $json['terminada'] = $terminada;

        return response()->json($json);
    }

    /**
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    public function compra_compra_historial_data(Request $request): JsonResponse
    {
        $data = json_decode($request->input('data'));

        $consulta = empty($data->folio) ? "AND documento.created_at BETWEEN '" . $data->inicial . " 00:00:00' AND '" . $data->final . " 23:59:59'" : "AND (documento.factura_folio = '" . trim($data->folio) . "' OR documento.id = '" . trim($data->folio) . "')";

        if (!empty($data->producto)) {
            $consulta .= " AND modelo.sku = '" . $data->producto . "'";
        }

        $compras = $this->compras_raw_data($consulta);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setTitle("HISTORIAL DE COMPRAS");
        $fila = 2;

        $spreadsheet->getActiveSheet()->getStyle('A1:V1')->getFont()->setBold(1)->getColor()->setARGB('000000'); # Cabecera en negritas con color negro
        $spreadsheet->getActiveSheet()->getStyle('A1:V1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('4CB9CD');

        # Cabecera
        $sheet->setCellValue('A1', 'FOLIO');
        $sheet->setCellValue('B1', 'PROVEEDOR');
        $sheet->setCellValue('C1', 'EMPRESA');
        $sheet->setCellValue('D1', 'ALMACEN');
        $sheet->setCellValue('E1', 'PERIODO');
        $sheet->setCellValue('F1', 'MONEDA');
        $sheet->setCellValue('G1', 'T.C');
        $sheet->setCellValue('H1', 'TOTAL');
        $sheet->setCellValue('I1', 'UUID');
        $sheet->setCellValue('J1', 'EXPIRACIÓN');
        $sheet->setCellValue('K1', 'CODIGO');
        $sheet->setCellValue('L1', 'DESCRIPCIÓN');
        $sheet->setCellValue('M1', 'CANTIDAD');
        $sheet->setCellValue('N1', 'COSTO');
        $sheet->setCellValue('O1', 'MARCA');
        $sheet->setCellValue('P1', 'CATEGORIA');
        $sheet->setCellValue('Q1', 'SUBCATEGORIA');
        $sheet->setCellValue('R1', 'VERTICAL');
        $sheet->setCellValue('S1', 'FASE');
        $sheet->setCellValue('T1', 'FECHA DE RECEPCIÓN');
        $sheet->setCellValue('U1', 'ODC RELACIONADAS');
        $sheet->setCellValue('V1', 'CREADOR');

        foreach ($compras as $compra) {
            $ordenes = array();

            $sheet->setCellValue('A' . $fila, $compra->serie . " " . $compra->folio);
            $sheet->setCellValue('B' . $fila, $compra->proveedor);
            $sheet->setCellValue('C' . $fila, $compra->empresa);
            $sheet->setCellValue('D' . $fila, $compra->almacen);
            $sheet->setCellValue('E' . $fila, $compra->periodo);
            $sheet->setCellValue('F' . $fila, $compra->moneda);
            $sheet->setCellValue('G' . $fila, $compra->tipo_cambio);
            $sheet->setCellValue('H' . $fila, $compra->total);
            $sheet->setCellValue('I' . $fila, $compra->uuid);
            $sheet->setCellValue('J' . $fila, $compra->expired_at_2);
            $sheet->setCellValue('S' . $fila, $compra->fase);
            $sheet->setCellValue('T' . $fila, $compra->finished_at);
            $sheet->setCellValue('U' . $fila, 'N/A');
            $sheet->setCellValue('V' . $fila, $compra->nombre);

            if (!empty($compra->ordenes)) {
                $ordenes = [];

                foreach ($compra->ordenes as $orden) {
                    $ordenes[] = $orden->id;
                }
            }

            $spreadsheet->getActiveSheet()->getStyle("G" . $fila . ":H" . $fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)');

            foreach ($compra->productos as $producto) {
                $sheet->setCellValue('A' . $fila, $compra->serie . " " . $compra->folio);
                $sheet->setCellValue('B' . $fila, $compra->proveedor);
                $sheet->setCellValue('C' . $fila, $compra->empresa);
                $sheet->setCellValue('D' . $fila, $compra->almacen);
                $sheet->setCellValue('E' . $fila, $compra->periodo);
                $sheet->setCellValue('F' . $fila, $compra->moneda);
                $sheet->setCellValue('G' . $fila, $compra->tipo_cambio);
                $sheet->setCellValue('H' . $fila, $compra->total);
                $sheet->setCellValue('I' . $fila, $compra->uuid);
                $sheet->setCellValue('J' . $fila, $compra->expired_at_2);
                $sheet->setCellValue('R' . $fila, $compra->almacen);
                $sheet->setCellValue('K' . $fila, $producto->sku);
                $sheet->setCellValue('L' . $fila, $producto->descripcion);
                $sheet->setCellValue('M' . $fila, $producto->cantidad);
                $sheet->setCellValue('N' . $fila, $producto->costo);
                $sheet->setCellValue('O' . $fila, $producto->cat2);
                $sheet->setCellValue('P' . $fila, $producto->cat1);
                $sheet->setCellValue('Q' . $fila, $producto->cat3);
                $sheet->setCellValue('R' . $fila, $producto->cat4);
                $sheet->setCellValue('S' . $fila, $compra->fase);
                $sheet->setCellValue('T' . $fila, $compra->finished_at);
                $sheet->setCellValue('V' . $fila, $compra->nombre);

                if (!empty($ordenes)) {
                    $sheet->setCellValue('U' . $fila, implode(",", $ordenes));
                }

                $spreadsheet->getActiveSheet()->getStyle("G" . $fila . ":H" . $fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)');
                $sheet->getCellByColumnAndRow(11, $fila)->setValueExplicit($producto->sku, DataType::TYPE_STRING);
                $spreadsheet->getActiveSheet()->getStyle("N" . $fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)');

                $fila++;
            }

            $fila++;

            if (count($compra->productos) > 0) {
                $fila--;
            }
        }

        foreach (range('A', 'V') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save('compra.xlsx');

        $json['code'] = 200;
        $json['ordenes'] = $compras;
        $json['excel'] = base64_encode(file_get_contents('compra.xlsx'));

        unlink('compra.xlsx');

        return response()->json($json);
    }

    public function compra_compra_historial_guardar(Request $request): JsonResponse
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        if ($data->permitido_editar) {
            foreach ($data->productos as $producto) {
                DB::table('modelo')->where(['id' => $producto->id_modelo])->update([
                    'costo_extra' => $producto->costo_extra
                ]);
            }
        }

        if ($data->actualizar_uuid) {
            $id_documento = DB::select("SELECT documento_extra, factura_serie, factura_folio FROM documento WHERE id = " . $data->documento);

            $id_documento = $id_documento[0];

            if ($id_documento->documento_extra == 'N/A' || $id_documento->documento_extra == '') {
                $response = @json_decode(file_get_contents(config('webservice.url') . 'FacturaCompra/Consulta/7/Serie/' . $id_documento->factura_serie . '/' . $id_documento->factura_folio));

                if (empty($response)) {
                    return response()->json([
                        'code' => 500,
                        'message' => "No se encontró informaciónd de la compra en Comercial."
                    ]);
                }

                if (COUNT($response->body) > 1) {
                    return response()->json([
                        'code' => 500,
                        'message' => "Se encontró más de una compra con el mismo folio y serie, favor de verificar."
                    ]);
                }

                $id_documento->documento_extra = $response->body[0]->documento;
            }

            $bd = DB::select("SELECT
                                empresa.bd
                            FROM documento
                            INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                            INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                            WHERE documento.id = " . $data->documento)[0]->bd;

            try {
                $array_compra = [
                    "bd" => $bd,
                    "password" => config("webservice.token"),
                    "documento" => $id_documento->documento_extra,
                    "uuid" => $data->uuid
                ];

                $response = \Httpful\Request::post('http://201.7.208.53:11903/api/adminpro/Compra/Add/UUID/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw')
                    ->body($array_compra, Mime::FORM)
                    ->send();

                $response_raw = $response->raw_body;
                $response = @json_decode($response_raw);

                if (empty($response)) {
                    return response()->json([
                        'code' => 500,
                        'message' => "No fue posible actualizar el UUID de la compra, error desconocido",
                        'raw' => $response_raw
                    ]);
                }

                if ($response->error == 1) {
                    return response()->json([
                        'code' => 500,
                        'message' => "Ocurrió un error al actualizar el UUID de la compra en Comercial, mensaje de error: " . $response->mensaje
                    ]);
                }
            } catch (Exception $e) {
                return response()->json([
                    'code' => 500,
                    'message' => "Ocurrió un error al actualizar el UUID de la compra en Comercial, mensaje de error: " . $e->getMessage()
                ]);
            }

            DB::table('documento')->where(['id' => $data->documento])->update([
                'uuid' => $data->uuid,
                'documento_extra' => $id_documento->documento_extra
            ]);

            return response()->json([
                'code' => 200,
                'message' => "UUID actualizado correctamente"
            ]);
        }

        if (!empty($data->seguimiento)) {
            DB::table("seguimiento")->insert([
                "id_documento" => $data->documento,
                "id_usuario" => $auth->id,
                "seguimiento" => $data->seguimiento
            ]);
        }

        return response()->json([
            'code' => 200,
            'message' => "Costo extra actualizado correctamente."
        ]);
    }

    /* Compra > Orden */

    /**
     * @throws ConnectionErrorException
     */
    public function compra_compra_historial_saldar(Request $request): JsonResponse
    {
        $data = json_decode($request->input("data"));

        $info_compra = DB::select("SELECT
                                            empresa_almacen.id_erp,
                                            empresa.bd,
                                            documento.factura_serie,
                                            documento.factura_folio,
                                            documento.uuid
                                        FROM documento
                                        INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                        INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                        WHERE documento.id = " . $data->compra);

        if (empty($info_compra)) {
            return response()->json([
                "code" => 500,
                "message" => "No se encontró el almacén de la compra, favor de contactar a un administrador"
            ]);
        }

        $info_compra = $info_compra[0];

        if (empty($data->uuid_relacionado)) {
            $data->uuid_relacionado = $info_compra->uuid;
        }

        if (empty($data->uuid_relacionado)) {
            return response()->json([
                "code" => 500,
                "message" => "No se encontró el UUID relacionado en el XML y la compra no contiene UUID, favor de asignarle un UUID a la compra para poder aplicar la NC."
            ]);
        }

        $nota_data = array(
            "bd" => $info_compra->bd,
            "password" => config("webservice.token"),
            "serie" => $data->serie,
            "folio" => $data->folio,
            "fecha" => $data->fecha,
            "uuid" => $data->uuid,
            "uuid_relacion" => $data->uuid_relacionado,
            "proveedor" => $data->proveedor,
            "titulo" => "Devolucion de mercancia de factura compra " . $info_compra->factura_serie . " " . $info_compra->factura_folio,
            "almacen" => $info_compra->id_erp,
            "moneda" => $data->moneda,
            "tc" => $data->tipo_cambio,
            "metodo_pago" => $data->metodo_pago,
            "forma_pago" => $data->forma_pago,
            "uso_cfdi" => $data->uso_cfdi,
            "comentarios" => "",
            "subtotal" => $data->subtotal,
            "total" => $data->total,
            "iva" => $data->iva,
            "productos" => json_encode($data->productos)
        );

        $crear_nc_compra = \Httpful\Request::post(config('webservice.url') . 'DevolucionCompra/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw')
            ->body($nota_data, Mime::FORM)
            ->send();

        $crear_nc_compra_raw = $crear_nc_compra->raw_body;
        $crear_nc_compra = @json_decode($crear_nc_compra_raw);

        if (empty($crear_nc_compra)) {
            return response()->json([
                "code" => 500,
                "message" => "Ocurrió un error al crear la nota de credito, favor de contactar a un administrador",
                "data" => $nota_data,
                "raw" => $crear_nc_compra_raw
            ]);
        }

        if ($crear_nc_compra->error) {
            return response()->json([
                "code" => 500,
                "message" => "Ocurrió un error al crear la nota de credito, favor de contactar a un administrador, mensaje de error: " . $crear_nc_compra->mensaje,
                "data" => $nota_data
            ]);
        }

        DB::table("documento")->where(["id" => $data->compra])->update([
            "id_fase" => 94,
            "nota" => $crear_nc_compra->id
        ]);

        return response()->json([
            "code" => 200,
            "message" => "Nota de credito creada correctamente"
        ]);
    }

    public function compra_orden_requisicion_data(): JsonResponse
    {
        $marketplaces = DB::table('marketplace_area')
            ->join('marketplace', 'marketplace_area.id_marketplace', '=', 'marketplace.id')
            ->select('marketplace.marketplace', 'marketplace_area.id')
            ->get();

        /** @noinspection PhpUnusedLocalVariableInspection */
        $requisiciones = DB::select("SELECT
                                        SUM(movimiento.precio * movimiento.cantidad) AS total
                                    FROM documento
                                    INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                    WHERE documento.id_tipo = 0
                                    AND documento.id_fase < 602
                                    AND documento.autorizado = 1
                                    AND documento.status = 1
                                    AND documento.created_at BETWEEN '" . date("Y-m-d", strtotime("monday this week")) . " 00:00:00' AND '" . date("Y-m-d", strtotime("sunday this week")) . " 23:59:59'")[0]->total;

        /** @noinspection PhpUnusedLocalVariableInspection */
        $ordenes = DB::select("SELECT
                                        SUM(movimiento.precio * movimiento.cantidad) AS total
                                    FROM documento
                                    INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                    WHERE documento.id_tipo = 0
                                    AND documento.id_fase BETWEEN 603 AND 607
                                    AND documento.status = 1
                                    AND documento.created_at BETWEEN '" . date("Y-m-d", strtotime("monday this week")) . " 00:00:00' AND '" . date("Y-m-d", strtotime("sunday this week")) . " 23:59:59'")[0]->total;

        return response()->json([
            'code' => 200,
            'marketplaces' => $marketplaces
        ]);
    }

    public function compra_orden_requisicion(Request $request): JsonResponse
    {
        $data = json_decode($request->input("data"));
        $auth = json_decode($request->auth);

        $documento = DB::table('documento')->insertGetId([
            'id_almacen_principal_empresa' => 0,
            'id_almacen_secundario_empresa' => 0,
            'id_tipo' => 0,
            'id_marketplace_area' => $data->marketplace_area,
            'id_usuario' => $auth->id,
            'id_fase' => 601,
            'autorizado' => 0,
            'referencia' => 'N/A',
            'info_extra' => 'N/A'
        ]);

        $existe_temporal = DB::select("SELECT id FROM modelo WHERE sku = 'TEMPORAL'");

        if (empty($existe_temporal)) {
            $modelo_id = DB::table('modelo')->insertGetId([
                'id_tipo' => 1,
                'sku' => 'TEMPORAL',
                'descripcion' => 'PRODUCTO TEMPORAL'
            ]);

        } else {
            $modelo_id = $existe_temporal[0]->id;
        }

        foreach ($data->productos as $producto) {
            DB::table('movimiento')->insertGetId([
                'id_documento' => $documento,
                'id_modelo' => $modelo_id,
                'cantidad' => $producto->cantidad,
                'precio' => $producto->precio,
                'modificacion' => $producto->condicion,
                'comentario' => $producto->descripcion,
                'addenda' => $producto->marketplace
            ]);
        }

        if (!empty($data->seguimiento)) {
            DB::table('seguimiento')->insert([
                'id_documento' => $documento,
                'id_usuario' => $auth->id,
                'seguimiento' => $data->seguimiento
            ]);
        }

        $nombre = DB::select("SELECT nombre FROM usuario WHERE id = " . $auth->id)[0]->nombre;

        $view = view('email.notificacion_requisicion_crear')->with([
            'anio' => date('Y'),
            'nombre' => $nombre,
            'productos' => $data->productos,
            'documento' => $documento,
            'seguimiento' => $data->seguimiento,
        ]);

        try {
            $emails = "";
            $notificados = array();

            $usuarios = DB::select("SELECT
                                        usuario.id,
                                        usuario.email
                                    FROM usuario
                                    INNER JOIN usuario_subnivel_nivel ON usuario.id = usuario_subnivel_nivel.id_usuario
                                    INNER JOIN subnivel_nivel ON usuario_subnivel_nivel.id_subnivel_nivel = subnivel_nivel.id
                                    WHERE subnivel_nivel.id_nivel = 8 AND subnivel_nivel.id_subnivel = 1");

            foreach ($usuarios as $usuario) {
                /** @noinspection PhpUnusedLocalVariableInspection */
                $emails .= $usuario->email . ";";

                $notificados[] = $usuario->id;
            }

            $mg = Mailgun::create("key-ff8657eb0bb864245bfff77c95c21bef");
            $domain = "omg.com.mx";
            $mg->messages()->send($domain, array('from' => 'CRM OMG International <crm@omg.com.mx>',
                'to' => 'desarrollo1@omg.com.mx',
                'subject' => 'Nueva requisición para orden de compra.',
                'html' => $view->render()));

            $notificacion['titulo'] = "Requisición generada";
            $notificacion['message'] = "Se ha generado una nueva requisición para una orden de compra por el usuario " . $nombre . " con el ID " . $documento . ".";
            $notificacion['tipo'] = "success"; // success, warning, danger
            $notificacion['link'] = "/compra/orden/autorizacion-requisicion";

            $notificacion_id = DB::table('notificacion')->insertGetId([
                'data' => json_encode($notificacion)
            ]);

            foreach ($notificados as $usuario) {
                DB::table('notificacion_usuario')->insert([
                    'id_usuario' => $usuario,
                    'id_notificacion' => $notificacion_id
                ]);
            }

            $notificacion['id'] = $notificacion_id;
            $notificacion['usuario'] = $notificados;

//            event(new PusherEvent(json_encode($notificacion)));
        } catch (Exception $e) {
            return response()->json([
                'code' => 200,
                'message' => "La requisición fue creada correctamente con el ID " . $documento . " pero no fue posible enviar las notifiaciones, mensaje de error: " . $e->getMessage()
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'code' => 200,
                'message' => "La requisición fue creada correctamente con el ID " . $documento . " pero no fue posible enviar las notifiaciones, mensaje de error: " . $e->getMessage()
            ]);
        }

        return response()->json([
            'code' => 200,
            'message' => 'Requisición creada correctamente con el ID ' . $documento
        ]);
    }

    public function compra_orden_autorizacion_requisicion_data(): JsonResponse
    {
        $documentos = $this->ordenes_raw_data("AND documento.id_fase = 601");

        return response()->json([
            'code' => 200,
            'documentos' => $documentos
        ]);
    }

    private function ordenes_raw_data($extra_data): array
    {
        set_time_limit(0);

        $query = DB::table('documento')
            ->select([
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
            ])
            ->join('empresa_almacen', 'documento.id_almacen_principal_empresa', '=', 'empresa_almacen.id')
            ->join('movimiento', 'documento.id', '=', 'movimiento.id_documento')
            ->leftJoin('documento_recepcion', 'movimiento.id', '=', 'documento_recepcion.id_movimiento')
            ->join('empresa', 'empresa_almacen.id_empresa', '=', 'empresa.id')
            ->join('almacen', 'empresa_almacen.id_almacen', '=', 'almacen.id')
            ->join('documento_fase', 'documento.id_fase', '=', 'documento_fase.id')
            ->join('usuario', 'documento.id_usuario', '=', 'usuario.id')
            ->join('documento_periodo', 'documento.id_periodo', '=', 'documento_periodo.id')
            ->join('moneda', 'documento.id_moneda', '=', 'moneda.id')
            ->leftJoin('documento_entidad', 'documento.id_entidad', '=', 'documento_entidad.id')
            ->where('documento.id_tipo', 0);

        if (!empty($extra_data)) {
            $query->whereRaw("1=1 $extra_data");
        }

        $documentos = $query->groupBy((array)'documento.id')->get()->toArray();

        $documentoIds = array_map(function ($d) {
            return $d->id;
        }, $documentos);

        $productos = DB::table('movimiento')
            ->join('modelo', 'movimiento.id_modelo', '=', 'modelo.id')
            ->select(
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
                'movimiento.comentario AS descripcion',
                DB::raw('IF(movimiento.descuento = 0, ROUND(movimiento.precio, 8), ROUND((movimiento.precio * movimiento.descuento) / 100, 8)) AS costo'),
                'movimiento.modificacion AS condicion',
                'movimiento.addenda AS marketplace',
                'movimiento.id_documento'
            )
            ->whereIn('movimiento.id_documento', $documentoIds)
            ->get()
            ->groupBy('id_documento');

        $seguimientos = DB::table('seguimiento')
            ->join('usuario', 'seguimiento.id_usuario', '=', 'usuario.id')
            ->select('seguimiento.*', 'usuario.nombre')
            ->whereIn('seguimiento.id_documento', $documentoIds)
            ->get()
            ->groupBy('id_documento');


        $archivos = DB::table('documento_archivo')
            ->where('status', 1)
            ->whereIn('id_documento', $documentoIds)
            ->get()
            ->groupBy('id_documento');


        $recepciones = DB::table('documento_recepcion')
            ->join('movimiento', 'documento_recepcion.id_movimiento', '=', 'movimiento.id')
            ->join('usuario', 'documento_recepcion.id_usuario', '=', 'usuario.id')
            ->select(
                'movimiento.id_documento',
                'documento_recepcion.documento_erp',
                'documento_recepcion.documento_erp_compra',
                'usuario.nombre',
                'documento_recepcion.created_at'
            )
            ->whereIn('movimiento.id_documento', $documentoIds)
            ->get();

        $recepcionesPorDocumento = [];
        foreach ($recepciones as $r) {
            $docId = $r->id_documento;
            $docErp = $r->documento_erp;

            if (!isset($recepcionesPorDocumento[$docId])) {
                $recepcionesPorDocumento[$docId] = [];
            }

            if (!isset($recepcionesPorDocumento[$docId][$docErp])) {
                $recepcionesPorDocumento[$docId][$docErp] = (object)[
                    'documento_erp' => $r->documento_erp,
                    'documento_erp_compra' => $r->documento_erp_compra,
                    'nombre' => $r->nombre,
                    'created_at' => $r->created_at,
                    'productos' => []
                ];
            }
        }

        $recepcionProductos = DB::table("documento_recepcion")
            ->join("movimiento", "documento_recepcion.id_movimiento", "=", "movimiento.id")
            ->join("modelo", "movimiento.id_modelo", "=", "modelo.id")
            ->select(
                "movimiento.id_documento",
                "documento_recepcion.documento_erp",
                "modelo.sku",
                "modelo.descripcion",
                "documento_recepcion.cantidad"
            )
            ->whereIn("movimiento.id_documento", $documentoIds)
            ->get();

        foreach ($recepcionProductos as $p) {
            $docId = $p->id_documento;
            $docErp = $p->documento_erp;

            if (isset($recepcionesPorDocumento[$docId][$docErp])) {
                $recepcionesPorDocumento[$docId][$docErp]->productos[] = [
                    'sku' => $p->sku,
                    'descripcion' => $p->descripcion,
                    'cantidad' => $p->cantidad,
                ];
            }
        }

        $movimientoIds = collect($productos)->flatten()->map(function ($producto) {
            return $producto->id;
        })->toArray();

        $seriesPorMovimiento = DB::table("movimiento_producto")
            ->join("producto", "movimiento_producto.id_producto", "=", "producto.id")
            ->whereIn("movimiento_producto.id_movimiento", $movimientoIds)
            ->select("movimiento_producto.id_movimiento", "producto.id", "producto.serie", "producto.fecha_caducidad")
            ->get()
            ->groupBy("id_movimiento");

        foreach ($documentos as $documento) {
            $documento->productos = $productos[$documento->id] ?? [];

            $documento->seguimiento = $seguimientos[$documento->id] ?? [];

            $documento->archivos_anteriores = $archivos[$documento->id] ?? [];

            $documento->recepciones = isset($recepcionesPorDocumento[$documento->id])
                ? array_values($recepcionesPorDocumento[$documento->id])
                : [];

            $documento->proveedor = new stdClass();
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
                ->where("documento.observacion", "=", $documento->id)
                ->get()
                ->first();

            $documento->odc = $documento->odc ? $documento->odc->id : 0;

            foreach ($documento->productos as $producto) {
                $documento->total += (int)$producto->cantidad * (float)$producto->costo;

                if (strlen($producto->codigo) < 5) {
                    $ultimos2 = substr($producto->codigo, -2);
                    $asteriscos = str_repeat('*', strlen($producto->codigo) - 2);

                    $producto->oculto = $asteriscos . $ultimos2;
                } else {
                    $ultimos5 = substr($producto->codigo, -5);
                    $asteriscos = str_repeat('*', strlen($producto->codigo) - 5);

                    $producto->oculto = $asteriscos . $ultimos5;
                }

                if ($producto->serie) {
                    $producto->series = $seriesPorMovimiento[$producto->id] ?? [];
                    $producto->cantidad_recepcionada_anterior = count($producto->series);
                }
            }

            $documento->total = round($documento->total * 1.16, 2);

            unset($documento->id_erp);
            unset($documento->rfc);
        }

        return $documentos;
    }

    public function compra_orden_autorizacion_requisicion_guardar(Request $request): JsonResponse
    {
        $auth = json_decode($request->auth);
        $documento = $request->input('documento');
        $seguimiento = $request->input('seguimiento');

        DB::table('documento')->where(['id' => $documento])->update([
            'id_fase' => 603
        ]);

        if (!empty($seguimiento)) {
            DB::table('seguimiento')->insert([
                'id_documento' => $documento,
                'id_usuario' => $auth->id,
                'seguimiento' => $seguimiento
            ]);
        }

        $usuario = DB::table('usuario')
            ->select('id', 'nombre', 'email')
            ->where('id', $auth->id)
            ->first();

        $view = view('email.notificacion_requisicion_autorizacion')->with([
            'anio' => date('Y'),
            'nombre' => $usuario->nombre,
            'documento' => $documento,
            'seguimiento' => $seguimiento,
        ]);

        try {
            $mg = Mailgun::create("key-ff8657eb0bb864245bfff77c95c21bef");
            $domain = "omg.com.mx";
            $mg->messages()->send($domain, array(
                'from' => 'CRM OMG International <crm@omg.com.mx>',
                'to' => $usuario->email,
                'subject' => 'Requisición con el ID ' . $documento . '.',
                'html' => $view->render()
            ));

            $notificacion['titulo'] = "Requisición autorizada";
            $notificacion['message'] = "Tu requisición con el ID " . $documento . " ha sido autorizada";
            $notificacion['tipo'] = "success";
            $notificacion['link'] = "/compra/orden/historial";

            $notificacion_id = DB::table('notificacion')->insertGetId([
                'data' => json_encode($notificacion)
            ]);

            DB::table('notificacion_usuario')->insert([
                'id_usuario' => $usuario->id,
                'id_notificacion' => $notificacion_id
            ]);

            $notificacion['id'] = $notificacion_id;
            $notificacion['usuario'] = $usuario->id;

//            event(new PusherEvent(json_encode($notificacion)));
        } catch (Exception $e) {
            return response()->json([
                'code' => 200,
                'message' => "La requisición fue autorizada correctamente pero no fue posible enviar las notifiaciones, mensaje de error: " . $e->getMessage()
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'code' => 200,
                'message' => "La requisición fue autorizada correctamente pero no fue posible enviar las notifiaciones, mensaje de error: " . $e->getMessage()
            ]);
        }

        return response()->json([
            'code' => 200,
            'message' => "Documento autorizado correctamente."
        ]);
    }

    public function compra_orden_autorizacion_requisicion_cancelar(Request $request): JsonResponse
    {
        $seguimiento = $request->input('seguimiento');
        $documento = $request->input('documento');
        $auth = json_decode($request->auth);

        DB::table('documento')->where(['id' => $documento])->update([
            'status' => 0,
            'canceled_by' => $auth->id
        ]);

        DB::table('seguimiento')->insert([
            'id_documento' => $documento,
            'id_usuario' => $auth->id,
            'seguimiento' => $seguimiento
        ]);

        return response()->json([
            'code' => 200,
            'message' => "Documento cancelada correctamente."
        ]);
    }

    public function compra_orden_orden_data(): JsonResponse
    {
        $empresas = DB::select("SELECT id, bd, rfc, empresa FROM empresa WHERE status = 1 AND id != 0");
        $periodos = DB::select("SELECT id, periodo_en FROM documento_periodo WHERE status = 1");
        $monedas = DB::select("SELECT id, moneda FROM moneda");
        $usos_cfdi = DB::table("documento_uso_cfdi")
            ->select("id", "codigo", "descripcion")
            ->get()
            ->toArray();
        $metodos_pago = DB::table("metodo_pago")
            ->select("codigo", "metodo_pago")
            ->get()
            ->toArray();

        $documentos = $this->ordenes_raw_data("AND documento.id_fase = 603");

        foreach ($empresas as $empresa) {
            $almacenes = DB::select("SELECT
                                        empresa_almacen.id,
                                        almacen.almacen
                                    FROM empresa_almacen
                                    INNER JOIN almacen ON empresa_almacen.id_almacen = almacen.id
                                    WHERE empresa_almacen.id_empresa = " . $empresa->id . "
                                    AND almacen.status = 1
                                    AND almacen.id != 0
                                    ORDER BY almacen.almacen");

            $empresa->almacenes = $almacenes;
        }

        return response()->json([
            'code' => 200,
            'empresas' => $empresas,
            'periodos' => $periodos,
            'monedas' => $monedas,
            'usos_cfdi' => $usos_cfdi,
            "metodos_pago" => $metodos_pago,
            'documentos' => $documentos,
        ]);
    }

    public function compra_orden_orden_crear(Request $request): JsonResponse
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);

        $archivos = $data->archivos;

        unset($data->archivos);

        $documento = DB::table('documento')->insertGetId([
            'id_almacen_principal_empresa' => $data->almacen,
            'id_moneda' => $data->moneda,
            'id_periodo' => $data->periodo,
            'id_tipo' => 0,
            'id_marketplace_area' => 1,
            'id_usuario' => $auth->id,
            'id_fase' => 606,
            'id_entidad' => $data->proveedor->id,
            'tipo_cambio' => $data->tipo_cambio,
            'observacion' => implode(',', $data->documentos),
            'comentario' => (property_exists($data, "extranjero") && !is_null($data->extranjero)) ? $data->extranjero : "",
            'referencia' => (property_exists($data, "invoice") && !is_null($data->invoice)) ? $data->invoice : "",
            'info_extra' => json_encode($data),
            'arrived_at' => date("Y-m-d", strtotime($data->fecha_entrega))
        ]);

        foreach ($data->productos as $producto) {
            $existe_codigo = DB::select("SELECT id FROM modelo WHERE sku = '" . $producto->codigo . "'");

            if (empty($existe_codigo)) {
                $modelo_id = DB::table('modelo')->insertGetId([
                    'id_tipo' => 1,
                    'sku' => $producto->codigo,
                    'descripcion' => $producto->descripcion
                ]);

            } else {
                $modelo_id = $existe_codigo[0]->id;
            }

            DB::table('movimiento')->insertGetId([
                'id_documento' => $documento,
                'id_modelo' => $modelo_id,
                'cantidad' => $producto->cantidad,
                'precio' => $producto->costo,
                'descuento' => $producto->descuento,
                'modificacion' => '',
                'comentario' => $producto->descripcion . " \n " . $producto->comentario,
                'addenda' => ''
            ]);
        }

        try {
            foreach ($archivos as $archivo) {
                if ($archivo->nombre != "" && $archivo->data != "") {
                    $archivo_data = base64_decode(preg_replace('#^data:' . $archivo->tipo . '/\w+;base64,#i', '', $archivo->data));
                    $archivo->nombre = "INVOICE_" . $archivo->nombre;

                    $dropboxService = new DropboxService();
                    $response = $dropboxService->uploadFile('/' . $archivo->nombre, $archivo_data, false);

                    DB::table('documento_archivo')->insert([
                        'id_documento' => $documento,
                        'id_usuario' => $auth->id,
                        'nombre' => $archivo->nombre,
                        'dropbox' => $response['id']
                    ]);
                }
            }

        } catch (Exception $e) {
            DB::table('documento')->where(['id' => $documento])->delete();

            return response()->json([
                'code' => 500,
                'message' => "No fue posible subir los archivos a dropbox, pedido cancelado, favor de contactar a un administrador. Mensaje de error: " . $e->getMessage()
            ]);
        }

        foreach ($data->documentos as $documento_relacionado) {
            $seguimientos = DB::select("SELECT id_usuario, seguimiento FROM seguimiento WHERE id_documento = " . $documento_relacionado);

            foreach ($seguimientos as $seguimiento) {
                DB::table('seguimiento')->insert([
                    'id_usuario' => $seguimiento->id_usuario,
                    'id_documento' => $documento,
                    'seguimiento' => $seguimiento->seguimiento
                ]);
            }

            DB::table('documento')->where(['id' => $documento_relacionado])->update([
                'status' => 0
            ]);
        }

        $json['code'] = 200;
        $json['message'] = "Orden de compra creada correctamente con el ID " . $documento;

        $pdf = self::ordenes_generar_pdf($documento, $auth);

        if ($pdf->error) {
            $json['message'] .= " . No fue posible generar el PDF, mensaje de error: " . $pdf->mensaje;

            return response()->json($json);
        }

        $json['file'] = $pdf->data;
        $json['name'] = $pdf->name;

        return response()->json($json);
    }

    private function ordenes_generar_pdf($documento, $auth): stdClass
    {
        $response = new stdClass();

        $informacion_documento = DB::select("SELECT
                                                documento.id,
                                                documento.info_extra,
                                                documento.tipo_cambio,
                                                documento.created_at,
                                                documento_periodo.periodo_en,
                                                documento_entidad.rfc,
                                                documento_entidad.razon_social,
                                                moneda.moneda,
                                                usuario.firma,
                                                empresa.logo_odc
                                            FROM documento
                                            INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                            INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                            INNER JOIN documento_entidad ON documento.id_entidad = documento_entidad.id
                                            INNER JOIN documento_periodo ON documento.id_periodo = documento_periodo.id
                                            INNER JOIN moneda ON documento.id_moneda = moneda.id
                                            INNER JOIN usuario ON documento.id_usuario = usuario.id
                                            WHERE documento.id = " . $documento);

        if (empty($informacion_documento)) {
            $response->error = 1;
            $response->mensaje = "No se encontró información del documento para generar el PDF.";

            return $response;
        }

        $productos = DB::table("movimiento")
            ->select("modelo.sku AS codigo", "modelo.np", "modelo.descripcion", "movimiento.comentario", "movimiento.cantidad", "movimiento.precio AS costo", "movimiento.cantidad", "movimiento.descuento")
            ->join("modelo", "movimiento.id_modelo", "=", "modelo.id")
            ->where("movimiento.id_documento", $documento)
            ->get()
            ->toArray();

        if (empty($productos)) {
            $response->error = 1;
            $response->mensaje = "No se encontraron productos de la ODC.";

            return $response;
        }

        $informacion_documento = $informacion_documento[0];
        $informacion_documento->info_extra = json_decode($informacion_documento->info_extra);
        $impuesto = "1." . $informacion_documento->info_extra->impuesto;

        $pdf = new Fpdf();

        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 20);
        $pdf->SetTextColor(69, 90, 100);
        $pdf->Cell(110, 35, "PURCHASE ORDER");

        if ($informacion_documento->logo_odc != 'N/A') {
            $pdf->Image($informacion_documento->logo_odc, 5, 0, 70, 25, 'png');
        }

        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(40, 5, "Date", 1, false, 'C');
        $pdf->Cell(40, 5, "P.O #", 1, false, 'C');
        $pdf->Ln();

        $pdf->Cell(110, 8, "");
        $pdf->Cell(40, 8, date('d/m/Y', strtotime($informacion_documento->created_at)), 1, false, 'C');
        $pdf->Cell(40, 8, $informacion_documento->id, 1, false, 'C');
        $pdf->Ln(20);

        $pdf->Cell(25, 8, "Supplier", 1, false, 'L');
        $pdf->Cell(95, 8, $informacion_documento->razon_social, 1, false, 'L');
        $pdf->Ln();
        $pdf->Cell(95, 8, "Bill To", 1, false, 'L');
        $pdf->Cell(95, 8, "Ship To", 1, false, 'L');
        $pdf->Ln(8);

        $billto_breaks = explode("\n", $informacion_documento->info_extra->billto);
        $shipto_breaks = explode("\n", $informacion_documento->info_extra->shipto);

        $current_heigth_ship_bill = 0;
        $current_height_product = 38;

        if (count($billto_breaks) > count($shipto_breaks)) {
            foreach ($billto_breaks as $index => $billto) {
                $pdf->Cell(95, 5, substr($billto, 0, 50), 'LR', false, 'L');
                $pdf->Cell(95, 5, isset($shipto_breaks[$index]) ? substr($shipto_breaks[$index], 0, 50) : '', 'LR', false, 'L');
                $pdf->Ln();

                $current_heigth_ship_bill += 5;
                $current_height_product += 5;
            }
        } else {
            foreach ($shipto_breaks as $index => $shipto) {
                $pdf->Cell(95, 5, isset($billto_breaks[$index]) ? substr($billto_breaks[$index], 0, 50) : '', 'LR', false, 'L');
                $pdf->Cell(95, 5, substr($shipto, 0, 50), 'LR', false, 'L');
                $pdf->Ln();

                $current_heigth_ship_bill += 5;
                $current_height_product += 5;
            }
        }

        $remainingHeight = max(0, 30 - $current_heigth_ship_bill);
        if ($remainingHeight > 0) {
            $pdf->Cell(95, $remainingHeight, '', 'LBR');
            $pdf->Cell(95, $remainingHeight, '', 'LBR');
            $pdf->Ln();
        }

        $pdf->Ln(3);
        $pdf->Cell(38, 7, "Sales Order #", 1, false, 'C');
        $pdf->Cell(38, 7, "Terms", 1, false, 'C');
        $pdf->Cell(38, 7, "Rep", 1, false, 'C');
        $pdf->Cell(38, 7, "Currency / Rate", 1, false, 'C');
        $pdf->Cell(38, 7, "Incoterm", 1, false, 'C');
        $pdf->Ln();

        $nombre_exploded = explode(" ", $auth->nombre);

        $pdf->Cell(38, 12, $informacion_documento->info_extra->invoice, 1, false, 'C');
        $pdf->Cell(38, 12, substr($informacion_documento->periodo_en, 0, 10), 1, false, 'C');
        $pdf->Cell(38, 12, substr($nombre_exploded[0], 0, 1) . (count($nombre_exploded) > 1 ? substr($nombre_exploded[1], 0, 1) : ""), 1, false, 'C');
        $pdf->Cell(38, 12, $informacion_documento->moneda . " / " . $informacion_documento->tipo_cambio, 1, false, 'C');
        $pdf->Cell(38, 12, $informacion_documento->info_extra->fob, 1, false, 'C');
        $pdf->Ln(15);

        $product_qty_height = 10;
        $product_code_height = 25;
        $product_description_height = 81;
        $product_cost_height = 24;
        $product_discount_height = 25;
        $product_total_height = 25;

        $pdf->Cell($product_qty_height, 7, "Qty", 1, false, 'C');
        $pdf->Cell($product_code_height, 7, "Item Code", 1, false, 'C');
        $pdf->Cell($product_description_height, 7, "Description", 1, false, 'C');
        $pdf->Cell($product_cost_height, 7, "Price Each", 1, false, 'C');
        $pdf->Cell($product_discount_height, 7, "Discount", 1, false, 'C');
        $pdf->Cell($product_total_height, 7, "Amount", 1, false, 'C');
        $pdf->Ln();

        $current_height_product += 35;

        $total = 0;
        $total_discount = 0;

        $pdf->SetFont('Arial', '', 6);

        foreach ($productos as $producto) {
            $producto->descripcion = $producto->comentario;
            $largo_descripcion = strlen($producto->descripcion);

            $pdf->Cell($product_qty_height, 5, $producto->cantidad, 'LR', false, 'C');
            $pdf->Cell($product_code_height, 5, substr($producto->codigo, 0, 20), 'LR', false, 'C');
            $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 0, 60), 'LR', false, 'C');
            $pdf->Cell($product_cost_height, 5, "$ " . number_format($producto->costo, 2, '.', ','), 'LR', false, 'C');
            $pdf->Cell($product_discount_height, 5, "$ " . number_format($producto->descuento > 0 ? ($producto->descuento * $producto->costo) / 100 : 0, 2, '.', ','), 'LR', false, 'C');
            $pdf->Cell($product_total_height, 5, "$ " . number_format((float)$producto->cantidad * (float)$producto->costo, 2, '.', ','), 'LR', false, 'C');
            $pdf->Ln();

            $current_height_product += 5;

            if ($largo_descripcion > 60) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, substr($producto->np, 0, 10), 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 60, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 120) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 120, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 180) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 180, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 240) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 240, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 300) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 300, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 360) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 360, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 420) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 420, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 480) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 480, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 540) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 540, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 600) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 600, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 660) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 660, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 720) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 720, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 780) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 780, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 840) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 840, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 900) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 900, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 960) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 960, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 1020) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 1020, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 1080) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 1080, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 1140) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 1140, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 1200) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 1200, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 1260) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 1260, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            if ($largo_descripcion > 1380) {
                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_description_height, 5, substr($producto->descripcion, 1380, 60), 'LR', false, 'C');
                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
                $pdf->Ln();

                $current_height_product += 5;
            }

            //            if ($producto->np != 'N/A') {
            //                $pdf->Cell($product_qty_height, 5, "", 'LR', false, 'C');
            //                $pdf->Cell($product_code_height, 5, "", 'LR', false, 'C');
            //                $pdf->Cell($product_description_height, 5, $producto->np, 'LR', false, 'C');
            //                $pdf->Cell($product_cost_height, 5, "", 'LR', false, 'C');
            //                $pdf->Cell($product_discount_height, 5, "", 'LR', false, 'C');
            //                $pdf->Cell($product_total_height, 5, "", 'LR', false, 'C');
            //                $pdf->Ln();
            //
            //                $current_height_product += 5;
            //            }

            //            if ($current_height_product >= 260) {
            //                $pdf->Cell($product_qty_height, 5, "", 'LRB', false, 'C');
            //                $pdf->Cell($product_code_height, 5, "", 'LRB', false, 'C');
            //                $pdf->Cell($product_description_height, 5, "", 'LRB', false, 'C');
            //                $pdf->Cell($product_cost_height, 5, "", 'LRB', false, 'C');
            //                $pdf->Cell($product_discount_height, 5, "", 'LRB', false, 'C');
            //                $pdf->Cell($product_total_height, 5, "", 'LRB', false, 'C');
            //                $pdf->Ln();
            //
            //                # $pdf->addPage();
            //
            //                $pdf->Cell($product_qty_height, 5, "", 'LRT', false, 'C');
            //                $pdf->Cell($product_code_height, 5, "", 'LRT', false, 'C');
            //                $pdf->Cell($product_description_height, 5, "", 'LRT', false, 'C');
            //                $pdf->Cell($product_cost_height, 5, "", 'LRT', false, 'C');
            //                $pdf->Cell($product_discount_height, 5, "", 'LRT', false, 'C');
            //                $pdf->Cell($product_total_height, 5, "", 'LRT', false, 'C');
            //                $pdf->Ln();
            //
            //                # $current_height_product = 5;
            //            }

            $total += (float)$producto->cantidad * (float)$producto->costo;
            $total_discount += $producto->descuento > 0 ? (($producto->cantidad * (float)$producto->costo) * $producto->descuento / 100) : 0;
        }

        $total = $total * (float)$impuesto;

        $pdf->Cell($product_qty_height, 10, "", 'LBR', false, 'C');
        $pdf->Cell($product_code_height, 10, "", 'LBR', false, 'C');
        $pdf->Cell($product_description_height, 10, "", 'LBR', false, 'C');
        $pdf->Cell($product_cost_height, 10, "", 'LBR', false, 'C');
        $pdf->Cell($product_discount_height, 10, "", 'LBR', false, 'C');
        $pdf->Cell($product_total_height, 10, "", 'LBR', false, 'C');
        $pdf->Ln(15);

        $current_height_product += 15;

        $pdf->SetFont('Arial', '', 10);

        $pdf->Cell(130, 7, "Thanks you for your business", 1, false, 'L');
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(30, 7, "Subtotal", "TLB", false, 'R');
        $pdf->Cell(30, 7, "$ " . number_format($total / (float)$impuesto, 2, '.', ','), "TRB", false, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Ln();
        $current_height_product += 5;

        $pdf->Cell(130, 7, "Special Comments ", 1, false, 'L');
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(30, 7, "Tax", "TLB", false, 'R');
        $pdf->Cell(30, 7, "$ " . number_format($total - ($total / (float)$impuesto), 2, '.', ','), "TRB", false, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Ln();
        $current_height_product += 5;

        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(130, 7, substr($informacion_documento->info_extra->comentarios, 0, 90), 0, false, 'L');
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(30, 7, "Total", "TLB", false, 'R');
        $pdf->Cell(30, 7, "$ " . number_format($total, 2, '.', ','), "TRB", false, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Ln();
        $current_height_product += 5;

        if (strlen($informacion_documento->info_extra->comentarios) > 90) {
            $pdf->SetFont('Arial', '', 8);
            $pdf->Cell(130, 7, substr($informacion_documento->info_extra->comentarios, 90, 90), 0, false, 'L');
        } else {
            $pdf->Cell(130, 7, "", 0, false, 'L');
        }
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(30, 7, "Discount", "TLB", false, 'R');
        $pdf->Cell(30, 7, "$ " . number_format($total_discount, 2, '.', ','), "TRB", false, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Ln();
        $current_height_product += 5;

        if (strlen($informacion_documento->info_extra->comentarios) > 180) {
            $pdf->SetFont('Arial', '', 8);
            $pdf->Cell(130, 7, substr($informacion_documento->info_extra->comentarios, 180, 90), 0, false, 'L');
        } else {
            $pdf->Cell(130, 7, "", 0, false, 'L');
        }
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(30, 7, "Total w discount", "TLB", false, 'R');
        $pdf->Cell(30, 7, "$ " . number_format($total - $total_discount, 2, '.', ','), "TRB", false, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Ln();
        $current_height_product += 5;

        if (strlen($informacion_documento->info_extra->comentarios) > 270) {
            $pdf->SetFont('Arial', '', 8);
            $pdf->Cell(130, 7, substr($informacion_documento->info_extra->comentarios, 270, 90), 0, false, 'L');
            $pdf->Ln();
            $current_height_product += 5;
        }

        if (strlen($informacion_documento->info_extra->comentarios) > 360) {
            $pdf->Cell(130, 7, substr($informacion_documento->info_extra->comentarios, 360, 90), 0, false, 'L');
            $pdf->Ln();
            $current_height_product += 5;
        }

        if (strlen($informacion_documento->info_extra->comentarios) > 450) {
            $pdf->Cell(130, 7, substr($informacion_documento->info_extra->comentarios, 450, 90), 0, false, 'L');
            $pdf->Ln();
            $current_height_product += 5;
        }

        if (strlen($informacion_documento->info_extra->comentarios) > 540) {
            $pdf->Cell(130, 7, substr($informacion_documento->info_extra->comentarios, 540, 90), 0, false, 'L');
            $pdf->Ln();
            $current_height_product += 5;
        }

        if (strlen($informacion_documento->info_extra->comentarios) > 630) {
            $pdf->Cell(130, 7, substr($informacion_documento->info_extra->comentarios, 630, 90), 0, false, 'L');
            $pdf->Ln();
            $current_height_product += 5;
        }

        if (strlen($informacion_documento->info_extra->comentarios) > 720) {
            $pdf->Cell(130, 7, substr($informacion_documento->info_extra->comentarios, 720, 90), 0, false, 'L');
            $pdf->Ln();
            $current_height_product += 5;
        }

        if (strlen($informacion_documento->info_extra->comentarios) > 810) {
            $pdf->Cell(130, 7, substr($informacion_documento->info_extra->comentarios, 810, 90), 0, false, 'L');
            $pdf->Ln();
            $current_height_product += 5;
        }

        if (strlen($informacion_documento->info_extra->comentarios) > 900) {
            $pdf->Cell(130, 7, substr($informacion_documento->info_extra->comentarios, 900, 90), 0, false, 'L');
            $pdf->Ln();
            $current_height_product += 5;
        }

        if (strlen($informacion_documento->info_extra->comentarios) > 990) {
            $pdf->Cell(130, 7, substr($informacion_documento->info_extra->comentarios, 990, 90), 0, false, 'L');
            $pdf->Ln();
            $current_height_product += 5;
        }

        if (strlen($informacion_documento->info_extra->comentarios) > 1080) {
            $pdf->Cell(130, 7, substr($informacion_documento->info_extra->comentarios, 1080, 90), 0, false, 'L');
            $pdf->Ln();
            $current_height_product += 5;
        }

        if (strlen($informacion_documento->info_extra->comentarios) > 1170) {
            $pdf->Cell(130, 7, substr($informacion_documento->info_extra->comentarios, 1170, 90), 0, false, 'L');
            $pdf->Ln();
            /** @noinspection PhpUnusedLocalVariableInspection */
            $current_height_product += 5;
        }

        if ($informacion_documento->firma != 'N/A') {
            $pdf->SetFont('Arial', '', 10);
            $pdf->SetXY(90, 250); // position of text3
            $pdf->Write(0, 'Authorized By');

            $pdf->Image($informacion_documento->firma, 50, 250, 100, 40, 'png');
        }

        $pdf_name = uniqid() . ".pdf";
        $pdf_data = $pdf->Output($pdf_name, 'S');
        $file_name = "INVOICE_" . $informacion_documento->info_extra->invoice . "_" . $informacion_documento->id . ".pdf";

        $response->error = 0;
        $response->data = base64_encode($pdf_data);
        $response->name = $file_name;

        return $response;
    }

    public function compra_orden_modificacion_data(): JsonResponse
    {
        $documentos = $this->ordenes_raw_data("AND documento.id_fase = 606");
        $empresas = DB::select("SELECT id, bd, empresa FROM empresa WHERE status = 1 AND id != 0");
        $periodos = DB::select("SELECT id, periodo_en AS periodo FROM documento_periodo WHERE status = 1");
        $monedas = DB::select("SELECT id, moneda FROM moneda");

        return response()->json([
            'code' => 200,
            'documentos' => $documentos,
            'empresas' => $empresas,
            'periodos' => $periodos,
            'monedas' => $monedas
        ]);
    }

    /** @noinspection PhpUnusedLocalVariableInspection */

    public function compra_orden_modificacion_eliminar($documento, $eliminar): JsonResponse
    {
        if (!$eliminar) {
            $requisiciones = DB::select("SELECT observacion FROM documento WHERE id = " . $documento);

            if (!empty($requisiciones)) {
                $requisiciones = explode(',', $requisiciones[0]->observacion);

                foreach ($requisiciones as $requisicion) {
                    DB::table('documento')->where(['id' => $requisicion])->update([
                        'status' => 1
                    ]);
                }
            }
        }

        DB::table('documento')->where(['id' => $documento])->update([
            'status' => 0
        ]);

        return response()->json([
            'code' => 200,
            'message' => "OC eliminada correctamente."
        ]);
    }

    public function compra_orden_modificacion_guardar(Request $request): JsonResponse
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);
        $proveedor = DB::table('documento_entidad')
            ->select('id')
            ->where('rfc', $data->proveedor->rfc)
            ->whereIn('tipo', [2, 3])
            ->first();

        if (!$proveedor) {
            $proveedor_id = DB::table('documento_entidad')->insertGetId([
                'tipo' => 2,
                'razon_social' => mb_strtoupper($data->proveedor->razon, 'UTF-8'),
                'rfc' => mb_strtoupper($data->proveedor->rfc, 'UTF-8'),
                'telefono' => $data->proveedor->telefono,
                'correo' => $data->proveedor->email,
            ]);
        } else {
            $proveedor_id = $proveedor->id;
        }


        try {
            foreach ($data->archivos as $archivo) {
                if ($archivo->nombre != "" && $archivo->data != "") {
                    $archivo_data = base64_decode(preg_replace('#^data:' . $archivo->tipo . '/\w+;base64,#i', '', $archivo->data));
                    $archivo->nombre = "INVOICE_" . $archivo->nombre;

                    $dropboxService = new DropboxService();
                    $response = $dropboxService->uploadFile('/' . $archivo->nombre, $archivo_data, false);

                    DB::table('documento_archivo')->insert([
                        'id_documento' => $data->id,
                        'id_usuario' => $auth->id,
                        'nombre' => $archivo->nombre,
                        'dropbox' => $response['id']
                    ]);
                }
            }
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => "No fue posible subir los archivos a dropbox, favor de contactar a un administrador. Mensaje de error: " . $e->getMessage()
            ]);
        }

        DB::table('documento')->where(['id' => $data->id])->update([
            'id_entidad' => $proveedor_id
        ]);

        $info_extra = json_decode(DB::select("SELECT info_extra FROM documento WHERE id = " . $data->id)[0]->info_extra);
        $info_extra->productos = $data->productos;
        $info_extra->invoice = $data->invoice;
        $info_extra->fob = $data->fob;
        $info_extra->billto = $data->billto;
        $info_extra->shipto = $data->shipto;
        $info_extra->comentarios = $data->comentarios;

        DB::table('documento')->where(['id' => $data->id])->update([
            'id_moneda' => $data->moneda,
            'id_periodo' => $data->periodo,
            'comentario' => $data->extranjero,
            'info_extra' => json_encode($info_extra)
        ]);

        DB::table('movimiento')->where(['id_documento' => $data->id])->delete();

        foreach ($data->productos as $producto) {
            $existe_codigo = DB::select("SELECT id FROM modelo WHERE sku = '" . $producto->codigo . "'");

            if (empty($existe_codigo)) {
                $modelo_id = DB::table('modelo')->insertGetId([
                    'id_tipo' => 1,
                    'sku' => $producto->codigo,
                    'descripcion' => $producto->descripcion
                ]);

            } else {
                $modelo_id = $existe_codigo[0]->id;
            }

            DB::table('movimiento')->insertGetId([
                'id_documento' => $data->id,
                'id_modelo' => $modelo_id,
                'cantidad' => $producto->cantidad,
                'precio' => $producto->costo,
                'modificacion' => '',
                'comentario' => $producto->descripcion,
                'addenda' => ''
            ]);
        }

        $json['code'] = 200;
        $json['message'] = "OC editada correctamente.";

        $pdf = self::ordenes_generar_pdf($data->id, $auth);

        if ($pdf->error) {
            $json['message'] .= " . No fue posible generar el PDF, mensaje de error: " . $pdf->mensaje;

            return response()->json($json);
        }

        $json['file'] = $pdf->data;
        $json['name'] = $pdf->name;

        return response()->json($json);
    }

    public function compra_orden_recepcion_data(): JsonResponse
    {
        set_time_limit(0);
        $documentos = $this->ordenes_raw_data("AND documento.id_fase = 606");
        $empresas = DB::table('empresa')
            ->select('id', 'bd', 'empresa')
            ->where('status', 1)
            ->where('id', '!=', 0)
            ->get();
        $usuarios = DB::select("SELECT
                                    usuario.id,
                                    usuario.nombre,
                                    usuario.celular,
                                    nivel.nivel
                                FROM usuario
                                INNER JOIN usuario_subnivel_nivel ON usuario.id = usuario_subnivel_nivel.id_usuario
                                INNER JOIN subnivel_nivel ON usuario_subnivel_nivel.id_subnivel_nivel = subnivel_nivel.id
                                INNER JOIN nivel ON subnivel_nivel.id_nivel = nivel.id
                                INNER JOIN subnivel ON subnivel_nivel.id_subnivel = subnivel.id
                                WHERE (nivel.nivel = 'COMPRAS' AND subnivel.subnivel = 'ADMINISTRADOR')
                                OR nivel.nivel = 'ADMINISTRADOR'
                                AND usuario.id != 1
                                GROUP BY usuario.id");

        return response()->json([
            'code' => 200,
            'documentos' => $documentos,
            'empresas' => $empresas,
            'usuarios' => $usuarios,
        ]);
    }

    public function compra_orden_recepcion_guardar(Request $request): JsonResponse
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);
        $terminada = 1;
        $movimientos_recepcionados = array();

        $documento_fase = DB::table("documento")
            ->select("id_fase")
            ->where("id", $data->id)
            ->first();

        if (empty($documento_fase)) {
            return response()->json([
                "code" => 404,
                "message" => "No se encontró el documento solicitado para su recepción"
            ]);
        }

        if ($documento_fase->id_fase == 607) {
            return response()->json([
                "code" => 404,
                "message" => "La ODC ya fué recepcionada"
            ]);
        }

        DB::table("seguimiento")->insert([
            "id_documento" => $data->id,
            "id_usuario" => $auth->id,
            "seguimiento" => empty($data->seguimiento) ? "Sin seguimiento escrito" : $data->seguimiento
        ]);

        try {
            foreach ($data->archivos as $archivo) {
                if ($archivo->nombre != "" && $archivo->data != "") {
                    $archivo_data = base64_decode(preg_replace('#^data:' . $archivo->tipo . '/\w+;base64,#i', '', $archivo->data));

                    $response = \Httpful\Request::post(config("webservice.dropbox") . '2/files/upload')
                        ->addHeader('Authorization', "Bearer " . config("keys.dropbox"))
                        ->addHeader('Dropbox-API-Arg', '{ "path": "/' . $archivo->nombre . '" , "mode": "add", "autorename": true}')
                        ->addHeader('Content-Type', 'application/octet-stream')
                        ->body($archivo_data)
                        ->send();

                    DB::table('documento_archivo')->insert([
                        'id_documento' => $data->id,
                        'id_usuario' => $auth->id,
                        'nombre' => $archivo->nombre,
                        'dropbox' => $response->body->id
                    ]);
                }
            }
        } catch (Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => "No fue posible subir los archivos a dropbox, actualización cancelada, favor de contactar a un administrador. Mensaje de error: " . $e->getMessage()
            ]);
        }

        if ($data->finalizar) {
            DB::table('documento')->where(['id' => $data->id])->update([
                'finished_at' => date("Y-m-d H:i:s"),
                'id_fase' => 607
            ]);

            return response()->json([
                "code" => 200,
                "message" => "ODC finalizada correctamente",
                "terminada" => 1
            ]);
        }

        $almacen = DB::select("SELECT
                                empresa_almacen.id_almacen,
                                documento.id_almacen_principal_empresa
                            FROM documento
                            INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                            WHERE documento.id = " . $data->id);

        if (empty($almacen)) {
            return response()->json([
                'code' => 500,
                'message' => "No se encontró el almacén del documento, favor de contactar a un administrador."
            ]);
        }

        foreach ($data->productos as $producto) {
            if ($producto->serie) {
                if (count($producto->series) > $producto->cantidad) {
                    return response()->json([
                        "code" => 500,
                        "message" => "La cantidad de series registrada excede la cantidad de la orden de compra"
                    ]);
                }
            }
        }

        $simulacion_documento_erp = uniqid();

        foreach ($data->productos as $producto) {
            if ($producto->serie) {
                $total_series = 0;

                foreach ($producto->series as $serie) {
                    $movimiento = DB::table('movimiento')->where('id', $producto->id)->first();
                    $serie->serie = str_replace(["'", '\\'], '', $serie->serie);
                    if ($serie->id == 0) $total_series++;

                    if ($serie->id == 0) {
                        $existe_serie = DB::select("SELECT id, status FROM producto WHERE serie = '" . TRIM($serie->serie) . "'");

                        if (!empty($existe_serie)) {
                            DB::table("producto")->where(["id" => $existe_serie[0]->id])->update([
                                "id_almacen" => $almacen[0]->id_almacen,
                                "id_modelo" => $movimiento->id_modelo,
                                "status" => 1,
                                "fecha_caducidad" => (property_exists($serie, "fecha_caducidad")) ? $serie->fecha_caducidad : null
                            ]);
                        } else {
                            $id_serie = DB::table('producto')->insertGetId([
                                'id_almacen' => $almacen[0]->id_almacen,
                                'id_modelo' => $movimiento->id_modelo,
                                'serie' => $serie->serie,
                                'status' => 1,
                                "fecha_caducidad" => (property_exists($serie, "fecha_caducidad")) ? $serie->fecha_caducidad : null
                            ]);
                        }

                        /** @noinspection PhpUnusedLocalVariableInspection */
                        /** @noinspection PhpUndefinedVariableInspection */
                        $movimiento_producto = DB::table('movimiento_producto')->insertGetId([
                            'id_movimiento' => $producto->id,
                            'id_producto' => empty($existe_serie) ? $id_serie : $existe_serie[0]->id
                        ]);
                    }
                }

                $producto->series = DB::select("SELECT
                                        1 AS status,
                                        producto.id,
                                        producto.serie
                                    FROM movimiento_producto
                                    INNER JOIN producto ON movimiento_producto.id_producto = producto.id
                                    WHERE movimiento_producto.id_movimiento = " . $producto->id);

                $producto->cantidad_recibida = $total_series;
            } else {
                DB::table('movimiento')->where(['id' => $producto->id])->update([
                    'cantidad_aceptada' => (int)$producto->cantidad_recepcionada_anterior + (int)$producto->cantidad_recepcionada
                ]);

                /** @noinspection PhpUnusedLocalVariableInspection */
                $cantidad_recepcionada_total = DB::table("movimiento")
                    ->select("cantidad", "cantidad_aceptada")
                    ->where("id", $producto->id)
                    ->first();

                $producto->cantidad_recibida = (int)$producto->cantidad_recepcionada;
            }

            if ($producto->cantidad_recibida > 0) {
                $aplicar_recepcion = InventarioService::procesarRecepcion($producto->id, $producto->cantidad_recibida);

                $movimiento_recepcionado = DB::table("documento_recepcion")->insertGetId([
                    "id_usuario" => $auth->id,
                    "id_movimiento" => $producto->id,
                    "cantidad" => $producto->cantidad_recibida,
                    "documento_erp" => $simulacion_documento_erp,
                    "afectado" => $aplicar_recepcion->error ? 0 : 1,
                ]);

                $movimientos_recepcionados[] = $movimiento_recepcionado;
            }
        }

        if ($movimientos_recepcionados == 0) {
            return response()->json([
                "code" => 200,
                "message" => "Seguimiento guardado correctamente",
                "terminada" => 0
            ]);
        }

        foreach ($data->productos as $producto) {
            if ($producto->serie) {
                $total_series_recepcionadas = DB::select("SELECT
                                                            COUNT(*) AS cantidad
                                                        FROM movimiento
                                                        INNER JOIN movimiento_producto ON movimiento.id = movimiento_producto.id_movimiento
                                                        INNER JOIN producto ON movimiento_producto.id_producto = producto.id
                                                        WHERE movimiento.id = " . $producto->id)[0]->cantidad;

                if ($total_series_recepcionadas != $producto->cantidad) {
                    $terminada = 0;
                }
            } else {
                $cantidad_recepcionada_total = DB::table("movimiento")
                    ->select("cantidad", "cantidad_aceptada")
                    ->where("id", $producto->id)
                    ->first();

                if ($cantidad_recepcionada_total->cantidad_aceptada < $cantidad_recepcionada_total->cantidad) {
                    $terminada = 0;
                }
            }
        }

        if ($terminada) {
            DB::table('documento')->where(['id' => $data->id])->update([
                'finished_at' => date("Y-m-d H:i:s"),
                'id_fase' => 607
            ]);
        }

        $json = array();

        if (count($movimientos_recepcionados) > 0) {
            $pdf = self::ordenes_recepcion_pdf($data->id, $simulacion_documento_erp, $auth->id);

            $json["file"] = base64_encode($pdf->data);
            $json["name"] = $pdf->name;
        }

        $json["code"] = 200;
        $json["productos"] = $data->productos;
        $json["message"] = $terminada ? "Compra finalizada correctamente" : "Compra actualizada correctamente";
        $json["terminada"] = $terminada;

        return response()->json($json);
    }

    private function ordenes_recepcion_pdf($documento, $recepcion_erp, $user_id): stdClass
    {
        $info_compra = DB::select("SELECT
                                        documento.factura_folio,
                                        documento.created_at,
                                        documento_entidad.razon_social,
                                        empresa.sello_recibido,
                                        empresa.empresa
                                    FROM documento
                                    INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                                    INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                                    INNER JOIN documento_entidad ON documento.id_entidad = documento_entidad.id
                                    WHERE documento.id = " . $documento);

        $info_compra = $info_compra[0];

        $productos = DB::table("documento_recepcion")
            ->select("movimiento.id", "modelo.sku", "modelo.descripcion", "movimiento.cantidad", "documento_recepcion.cantidad AS cantidad_recibida", "movimiento.cantidad_aceptada AS cantidad_recibida_total")
            ->join("movimiento", "documento_recepcion.id_movimiento", "=", "movimiento.id")
            ->join("modelo", "movimiento.id_modelo", "=", "modelo.id")
            ->where("documento_recepcion.documento_erp", $recepcion_erp)
            ->get()
            ->toArray();

        $fecha_recepcion = DB::table("documento_recepcion")
            ->where("documento_erp", $recepcion_erp)
            ->first();

        $pdf = new Fpdf();

        $pdf->AddPage('');
        $pdf->SetFont('Arial', 'B', 15);
        $pdf->SetTextColor(69, 90, 100);

        $pdf->Cell(0, 10, utf8_decode(mb_strtoupper("RECIBO DE ALMACÉN", 'UTF-8')), 0, 0, 'C');

        $pdf->Ln(5);
        $pdf->Cell(0, 10, $info_compra->empresa, 0, 0, 'C');

        $pdf->Ln(25);

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(240, 0, "PROVEEDOR", 0, 0);

        $pdf->Ln(5);

        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(135, 0, utf8_decode(mb_strtoupper($info_compra->razon_social, 'UTF-8')), 0, 0);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(20, 0, utf8_decode(mb_strtoupper("ODC", 'UTF-8')), 0, 0);
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(0, 0, utf8_decode(mb_strtoupper($documento, 'UTF-8')), 0, 0);

        $pdf->Ln(15);

        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(30, 10, "CODIGO", "T");
        $pdf->Cell(105, 10, "DESCRIPCION", "T");
        $pdf->Cell(20, 10, "CANTIDAD", "T");
        $pdf->Cell(20, 10, "RECIBIDA", "T");
        $pdf->Cell(20, 10, "T. RECIBIDA", "T");
        $pdf->Ln();

        $pdf->SetFont('Arial', '', 8);

        foreach ($productos as $producto) {
            $total_recibido = DB::table("documento_recepcion")
                ->where("id_movimiento", $producto->id)
                ->sum("cantidad");

            $pdf->Cell(30, 10, $producto->sku, "T");
            $pdf->Cell(105, 10, substr($producto->descripcion, 0, 60), "T");
            $pdf->Cell(20, 10, $producto->cantidad, "T");
            $pdf->Cell(20, 10, $producto->cantidad_recibida, "T");
            $pdf->Cell(20, 10, $total_recibido, "T");
            $pdf->Ln();

            if (strlen($producto->descripcion) > 60) {
                $pdf->Cell(30, 10, "");
                $pdf->Cell(105, 10, substr($producto->descripcion, 60, 60));
                $pdf->Cell(20, 10, "");
                $pdf->Cell(20, 10, "");
                $pdf->Cell(20, 10, "");
                $pdf->Ln();
            }
        }

        setlocale(LC_ALL, "es_MX");

        $pdf->Ln(150);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(100, 0, utf8_decode(mb_strtoupper("FECHA: " . strftime("%d de %B del %Y", strtotime($fecha_recepcion->created_at)), 'UTF-8')), 0, 0);
        $pdf->SetFont('Arial', '', 12);

        $pdf->Ln(10);
        $pdf->Cell(100, 0, utf8_decode(mb_strtoupper("RECIBIÓ: _______________________________________________________", 'UTF-8')), 0, 0);

        #Imagen de firma, aqui va la firmar que cada usuario tenga
        $firma_usuario = DB::table("usuario")
            ->select("firma")
            ->where("id", $user_id)
            ->first();

        if ($firma_usuario->firma != 'N/A') {
            $pdf->Image($firma_usuario->firma, 50, 240, 100, 40, 'png');
        }

        if ($info_compra->sello_recibido != 'N/A') {
            $pdf->Image($info_compra->sello_recibido, 70, 180, 100, 40, 'png');
        }

        $pdf_name = uniqid() . ".pdf";
        $pdf_data = $pdf->Output($pdf_name, 'S');
        $file_name = uniqid() . ".pdf";

        $pdf_data_o = new stdClass();
        $pdf_data_o->name = $file_name;
        $pdf_data_o->data = $pdf_data;

        return $pdf_data_o;
    }

    /**
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    public function compra_orden_historial_data(Request $request): JsonResponse
    {
        set_time_limit(0);

        $data = json_decode($request->input("data"));

        $impresoras = DB::table("impresora")
            ->where("status", 1)
            ->where("tamanio", "2x1")
            ->get()
            ->toArray();

        if (empty($data->documento)) {
            $query = "AND documento.created_at BETWEEN '" . $data->fecha_inicial . " 00:00:00' AND '" . $data->fecha_final . " 23:59:59'";

            $documentos = $this->ordenes_raw_data($query);
        } else {
            $query = "AND documento.id = " . $data->documento;

            $documentos = $this->ordenes_raw_data($query);

            if (empty($documentos)) {
                $query = "AND documento_recepcion.documento_erp = " . $data->documento;

                $documentos = $this->ordenes_raw_data($query);
            }
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setTitle("HISTORIAL DE COMPRAS");
        $fila = 2;

        $spreadsheet->getActiveSheet()->getStyle('A1:V1')->getFont()->setBold(1)->getColor()->setARGB('000000'); # Cabecera en negritas con color negro
        $spreadsheet->getActiveSheet()->getStyle('A1:V1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('4CB9CD');

        # Cabecera
        $sheet->setCellValue('A1', 'DOCUMENTO');
        $sheet->setCellValue('B1', 'PROVEEDOR');
        $sheet->setCellValue('C1', 'EMPRESA');
        $sheet->setCellValue('D1', 'ALMACEN');
        $sheet->setCellValue('E1', 'PERIODO');
        $sheet->setCellValue('F1', 'MONEDA');
        $sheet->setCellValue('G1', 'T.C');
        $sheet->setCellValue('H1', 'IMPUESTO');
        $sheet->setCellValue('I1', 'TOTAL');
        $sheet->setCellValue('J1', 'UUID');
        $sheet->setCellValue('K1', 'EXPIRACIÓN');
        $sheet->setCellValue('L1', 'CODIGO');
        $sheet->setCellValue('M1', 'DESCRIPCIÓN');
        $sheet->setCellValue('N1', 'CANTIDAD');
        $sheet->setCellValue('O1', 'RECEPCIONADA');
        $sheet->setCellValue('P1', 'COSTO');
        $sheet->setCellValue('Q1', 'MARCA');
        $sheet->setCellValue('R1', 'CATEGORIA');
        $sheet->setCellValue('S1', 'SUBCATEGORIA');
        $sheet->setCellValue('T1', 'FASE');
        $sheet->setCellValue('U1', 'FECHA DE RECEPCIÓN');
        $sheet->setCellValue('V1', 'FECHA DE CREACION');

        foreach ($documentos as $documento) {
            $documento_extra_data = json_decode($documento->info_extra);

            $sheet->setCellValue('A' . $fila, $documento->id);
            $sheet->setCellValue('B' . $fila, $documento->razon_social);
            $sheet->setCellValue('C' . $fila, $documento->empresa_nombre);
            $sheet->setCellValue('D' . $fila, $documento->almacen);
            $sheet->setCellValue('E' . $fila, $documento->periodo);
            $sheet->setCellValue('F' . $fila, $documento->moneda);
            $sheet->setCellValue('G' . $fila, $documento->tipo_cambio);
            $sheet->setCellValue('H' . $fila, is_object($documento_extra_data) ? property_exists($documento_extra_data, "impuesto") ? $documento_extra_data->impuesto . " %" : "N/E" : "N/E");
            $sheet->setCellValue('I' . $fila, $documento->total);
            $sheet->setCellValue('J' . $fila, $documento->uuid);
            $sheet->setCellValue('K' . $fila, $documento->fecha_pago);
            $sheet->setCellValue('T' . $fila, $documento->fase);
            $sheet->setCellValue('U' . $fila, $documento->finished_at);
            $sheet->setCellValue('V' . $fila, $documento->created_at);

            $spreadsheet->getActiveSheet()->getStyle("G" . $fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)');
            $spreadsheet->getActiveSheet()->getStyle("I" . $fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)');

            foreach ($documento->productos as $producto) {
                $sheet->setCellValue('A' . $fila, $documento->id);
                $sheet->setCellValue('B' . $fila, $documento->razon_social);
                $sheet->setCellValue('C' . $fila, $documento->empresa_nombre);
                $sheet->setCellValue('D' . $fila, $documento->almacen);
                $sheet->setCellValue('E' . $fila, $documento->periodo);
                $sheet->setCellValue('F' . $fila, $documento->moneda);
                $sheet->setCellValue('G' . $fila, $documento->tipo_cambio);
                $sheet->setCellValue('H' . $fila, is_object($documento_extra_data) ? property_exists($documento_extra_data, "impuesto") ? $documento_extra_data->impuesto . " %" : "N/E" : "N/E");
                $sheet->setCellValue('I' . $fila, $documento->total);
                $sheet->setCellValue('J' . $fila, $documento->uuid);
                $sheet->setCellValue('K' . $fila, $documento->fecha_pago);
                $sheet->setCellValue('L' . $fila, $producto->codigo);
                $sheet->setCellValue('M' . $fila, $producto->descripcion);
                $sheet->setCellValue('N' . $fila, $producto->cantidad);
                $sheet->setCellValue('O' . $fila, $producto->cantidad_recepcionada_anterior);
                $sheet->setCellValue('P' . $fila, $producto->costo);
                $sheet->setCellValue('Q' . $fila, $producto->cat2);
                $sheet->setCellValue('R' . $fila, $producto->cat1);
                $sheet->setCellValue('S' . $fila, $producto->cat3);
                $sheet->setCellValue('T' . $fila, $documento->fase);
                $sheet->setCellValue('U' . $fila, $documento->finished_at);
                $sheet->setCellValue('V' . $fila, $documento->created_at);

                $spreadsheet->getActiveSheet()->getStyle("G" . $fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)');
                $spreadsheet->getActiveSheet()->getStyle("I" . $fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)');
                $sheet->getCellByColumnAndRow(12, $fila)->setValueExplicit($producto->codigo, DataType::TYPE_STRING);
                $spreadsheet->getActiveSheet()->getStyle("P" . $fila)->getNumberFormat()->setFormatCode('_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)');

                $fila++;
            }

            $fila++;

            if (count($documento->productos) > 0) {
                $fila--;
            }
        }

        foreach (range('A', 'V') as $columna) {
            $spreadsheet->getActiveSheet()->getColumnDimension($columna)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save('odc.xlsx');

        $json['code'] = 200;
        $json['documentos'] = $documentos;
        $json['excel'] = base64_encode(file_get_contents('odc.xlsx'));
        $json['impresoras'] = $impresoras;

        unlink('odc.xlsx');

        return response()->json($json);
    }

    /* Compra > producto */

    public function compra_orden_historial_descargar($documento, Request $request): JsonResponse
    {
        $auth = json_decode($request->auth);

        $pdf = self::ordenes_generar_pdf($documento, $auth);

        if ($pdf->error) {
            return response()->json([
                'code' => 500,
                'message' => "No fue posible generar el PDF, mensaje de error: " . $pdf->mensaje
            ]);
        }

        return response()->json([
            'code' => 200,
            'file' => $pdf->data,
            'name' => $pdf->name
        ]);
    }

    public function compra_orden_historial_descargar_recepcion_pdf($recepcion): JsonResponse
    {
        $documento = DB::table("documento_recepcion")
            ->select("movimiento.id_documento", "documento_recepcion.id_usuario")
            ->join("movimiento", "documento_recepcion.id_movimiento", "=", "movimiento.id")
            ->where("documento_recepcion.documento_erp", $recepcion)
            ->first();

        $pdf = self::ordenes_recepcion_pdf($documento->id_documento, $recepcion, $documento->id_usuario);

        return response()->json([
            "code" => 200,
            "message" => "¡PDF generado correctamente!",
            "file" => base64_encode($pdf->data),
            "name" => $pdf->name
        ]);
    }

    public function compra_orden_historial_guardar(Request $request): JsonResponse
    {
        $seguimiento = $request->input('seguimiento');
        $documento = $request->input('documento');
        $auth = json_decode($request->auth);

        DB::table('seguimiento')->insert([
            'id_documento' => $documento,
            'id_usuario' => $auth->id,
            'seguimiento' => $seguimiento
        ]);

        return response()->json([
            'code' => 200,
            'message' => "Seguimiento guardado correctamente."
        ]);
    }

    public function compra_orden_historial_crear_orden_copia(Request $request): JsonResponse
    {
        $data = json_decode($request->input("data"));
        $auth = json_decode($request->auth);

        $informacion_documento = DB::table("documento")
            ->where("id", $data->id)
            ->first();

        if (!$informacion_documento) {
            return response()->json([
                "code" => 404,
                "message" => "No se necontró el documento original para crear su copia"
            ]);
        }

        $entidad_documento = DB::table("documento")
            ->select("id_entidad")
            ->where("id", $data->id)
            ->first();

        if (!$entidad_documento) {
            return response()->json([
                "code" => 404,
                "message" => "No se necontró la entidad del documento original para crear su copia"
            ]);
        }

        $documento = DB::table('documento')->insertGetId([
            'id_almacen_principal_empresa' => $informacion_documento->id_almacen_principal_empresa,
            'id_almacen_secundario_empresa' => $informacion_documento->id_almacen_secundario_empresa,
            'id_moneda' => $informacion_documento->id_moneda,
            'id_periodo' => $informacion_documento->id_periodo,
            'id_tipo' => $informacion_documento->id_tipo,
            'id_marketplace_area' => $informacion_documento->id_marketplace_area,
            'id_usuario' => $auth->id,
            'id_fase' => 606,
            'id_entidad' => $entidad_documento->id_entidad,
            'tipo_cambio' => $informacion_documento->tipo_cambio,
            'observacion' => $data->id,
            'comentario' => $informacion_documento->comentario,
            'referencia' => $informacion_documento->referencia,
            'info_extra' => $informacion_documento->info_extra
        ]);

        foreach ($data->productos as $producto) {
            $existe_codigo = DB::select("SELECT id FROM modelo WHERE sku = '" . $producto->codigo . "'");

            if (empty($existe_codigo)) {
                $modelo_id = DB::table('modelo')->insertGetId([
                    'id_tipo' => 1,
                    'sku' => $producto->codigo,
                    'descripcion' => $producto->descripcion
                ]);

            } else {
                $modelo_id = $existe_codigo[0]->id;
            }

            DB::table('movimiento')->insert([
                'id_documento' => $documento,
                'id_modelo' => $modelo_id,
                'cantidad' => $producto->cantidad,
                'precio' => $producto->costo,
                'modificacion' => '',
                'comentario' => $producto->descripcion,
                'addenda' => ''
            ]);
        }

        DB::table('seguimiento')->insert([
            'id_usuario' => $auth->id,
            'id_documento' => $documento,
            'seguimiento' => "ODC creada a partir de la ODC con el ID " . $data->id
        ]);

        $archivos = DB::table("documento_archivo")
            ->where("id_documento", $data->id)
            ->get()
            ->toArray();

        foreach ($archivos as $archivo) {
            DB::table("documento_archivo")->insert([
                "id_documento" => $documento,
                "id_usuario" => $auth->id,
                "id_impresora" => $archivo->id_impresora,
                "nombre" => $archivo->nombre,
                "dropbox" => $archivo->dropbox,
                "tipo" => $archivo->tipo,
                "status" => $archivo->status
            ]);
        }

        $crear_orden_compra = DocumentoService::crearOrdenCompra($documento);

        if ($crear_orden_compra->error) {
            DB::table('documento')->where(['id' => $documento])->delete();

            return response()->json([
                'code' => 500,
                'message' => $crear_orden_compra->mensaje,
                'raw' => property_exists($crear_orden_compra, "raw") ? $crear_orden_compra->raw : 0
            ]);
        }

        $json['code'] = 200;
        $json['message'] = "Orden de compra creada correctamente con el ID " . $documento;

        $pdf = self::ordenes_generar_pdf($documento, $auth);

        if ($pdf->error) {
            $json['message'] .= " . No fue posible generar el PDF, mensaje de error: " . $pdf->mensaje;

            return response()->json($json);
        }

        $json['file'] = $pdf->data;
        $json['name'] = $pdf->name;

        return response()->json($json);
    }

    public function compra_producto_gestion_data(Request $request): JsonResponse
    {
        $auth = json_decode($request->auth);

        $empresas = DB::select("SELECT empresa.id, empresa.bd, empresa.empresa FROM empresa INNER JOIN usuario_empresa ON empresa.id = usuario_empresa.id_empresa WHERE empresa.status = 1 AND empresa.id != 0 AND usuario_empresa.id_usuario = " . $auth->id);
        $proveedores = DB::select("SELECT id, razon_social FROM modelo_proveedor WHERE status = 1 AND id != 0 AND id != 4");

        $tipos = DB::select("SELECT id, tipo FROM modelo_tipo");
        $categorias_uno = DB::select("SELECT categoria FROM modelo_categoria WHERE tipo = 1 ORDER BY categoria DESC");
        $categorias_dos = DB::select("SELECT categoria FROM modelo_categoria WHERE tipo = 2 ORDER BY categoria DESC");
        $categorias_tres = DB::select("SELECT categoria FROM modelo_categoria WHERE tipo = 3 ORDER BY categoria DESC");
        $categorias_cuatro = DB::select("SELECT categoria FROM modelo_categoria WHERE tipo = 4 ORDER BY categoria DESC");

        return response()->json([
            'code' => 200,
            'tipos' => $tipos,
            'empresas' => $empresas,
            'proveedores' => $proveedores,
            'categorias_uno' => $categorias_uno,
            'categorias_dos' => $categorias_dos,
            'categorias_tres' => $categorias_tres,
            'categorias_cuatro' => $categorias_cuatro
        ]);
    }

    public function compra_producto_gestion_producto(Request $request): JsonResponse
    {
        set_time_limit(0);
        $data = json_decode($request->input('data'));
        $tipos = DB::select("SELECT id, tipo FROM modelo_tipo");

        $productos = DB::table("modelo")
            ->join("modelo_tipo", "modelo.id_tipo", "=", "modelo_tipo.id")
            ->select("modelo.*", "modelo_tipo.tipo AS tipo_text", "modelo.id_tipo AS tipo")
            ->where("status", 1)
            ->where("sku", $data->criterio)
            ->get()
            ->toArray();

        if (empty($productos)) {
            $productos = DB::table("modelo")
                ->join("modelo_tipo", "modelo.id_tipo", "=", "modelo_tipo.id")
                ->select("modelo.*", "modelo_tipo.tipo AS tipo_text", "modelo.id_tipo AS tipo")
                ->where("status", 1)
                ->where("descripcion", "LIKE", "%" . $data->criterio . "%")
                ->get()
                ->toArray();

            if (empty($productos)) {
                $sinonimo = DB::table("modelo_sinonimo")
                    ->join("modelo", "modelo_sinonimo.id_modelo", "=", "modelo.id")
                    ->select("modelo.sku")
                    ->where("modelo_sinonimo.codigo", trim($data->criterio))
                    ->first();

                if (!empty($sinonimo)) {
                    $productos = DB::table("modelo")
                        ->join("modelo_tipo", "modelo.id_tipo", "=", "modelo_tipo.id")
                        ->select("modelo.*", "modelo_tipo.tipo AS tipo_text", "modelo.id_tipo AS tipo")
                        ->where("status", 1)
                        ->where("sku", $sinonimo->sku)
                        ->get()
                        ->toArray();
                }
            }
        }

        foreach ($productos as $producto) {
            $producto->precios_empresa = DB::table("modelo_precio")
                ->select("id_empresa", "precio")
                ->where("id_modelo", $producto->id)
                ->get()
                ->toArray();

            $producto->proveedores = DB::table("modelo_proveedor")
                ->select("id", "razon_social")
                ->where("status", 1)
                ->where("id", "<>", 0)
                ->get()
                ->toArray();

            foreach ($producto->proveedores as $proveedor) {
                $proveedor->productos = array();
                $proveedor->producto_text = "";

                $existe_codigo_proveedor = DB::table("modelo_proveedor_producto")
                    ->select("id")
                    ->where("id_modelo_proveedor", $proveedor->id)
                    ->where("id_modelo", $producto->id)
                    ->first();

                $proveedor->producto = empty($existe_codigo_proveedor) ? "" : $existe_codigo_proveedor->id;
            }

            $amazon = DB::select("SELECT codigo, descripcion FROM modelo_amazon WHERE id_modelo = " . $producto->id);

            $amazon_data = new stdClass();

            $amazon_data->codigo = empty($amazon) ? "" : $amazon[0]->codigo;
            $amazon_data->descripcion = empty($amazon) ? "" : $amazon[0]->descripcion;

            $producto->amazon = $amazon_data;

            $producto->imagenes_anteriores = DB::select("SELECT nombre, dropbox FROM modelo_imagen WHERE id_modelo = " . $producto->id);

            $producto->producto_exel = empty($producto_exel) ? "" : $producto_exel->id;
        }

        return response()->json([
            'code' => 200,
            'tipos' => $tipos,
            'productos' => $productos
        ]);
    }

    public function compra_producto_gestion_productos(Request $request): JsonResponse
    {
        set_time_limit(0);
        $data = json_decode($request->input('data'));
        $tipos = DB::select("SELECT id, tipo FROM modelo_tipo");
        $productosArray = array(); // Initialize the array to store results

        foreach ($data as $criterio) {

            $productos = DB::table("modelo")
                ->join("modelo_tipo", "modelo.id_tipo", "=", "modelo_tipo.id")
                ->select("modelo.*", "modelo_tipo.tipo AS tipo_text", "modelo.id_tipo AS tipo")
                ->where("status", 1)
                ->where("sku", $criterio)
                ->get()
                ->toArray();

            if (empty($productos)) {
                $productos = DB::table("modelo")
                    ->join("modelo_tipo", "modelo.id_tipo", "=", "modelo_tipo.id")
                    ->select("modelo.*", "modelo_tipo.tipo AS tipo_text", "modelo.id_tipo AS tipo")
                    ->where("status", 1)
                    ->where("descripcion", "LIKE", "%" . $criterio . "%")
                    ->get()
                    ->toArray();

                if (empty($productos)) {
                    $sinonimo = DB::table("modelo_sinonimo")
                        ->join("modelo", "modelo_sinonimo.id_modelo", "=", "modelo.id")
                        ->select("modelo.sku")
                        ->where("modelo_sinonimo.codigo", trim($criterio))
                        ->first();

                    if (!empty($sinonimo)) {
                        $productos = DB::table("modelo")
                            ->join("modelo_tipo", "modelo.id_tipo", "=", "modelo_tipo.id")
                            ->select("modelo.*", "modelo_tipo.tipo AS tipo_text", "modelo.id_tipo AS tipo")
                            ->where("status", 1)
                            ->where("sku", $sinonimo->sku)
                            ->get()
                            ->toArray();
                    }
                }
            }

            foreach ($productos as $producto) {
                $producto->precios_empresa = DB::table("modelo_precio")
                    ->select("id_empresa", "precio")
                    ->where("id_modelo", $producto->id)
                    ->get()
                    ->toArray();

                $producto->proveedores = DB::table("modelo_proveedor")
                    ->select("id", "razon_social")
                    ->where("status", 1)
                    ->where("id", "<>", 0)
                    ->get()
                    ->toArray();

                foreach ($producto->proveedores as $proveedor) {
                    $proveedor->productos = array();
                    $proveedor->producto_text = "";

                    $existe_codigo_proveedor = DB::table("modelo_proveedor_producto")
                        ->select("id")
                        ->where("id_modelo_proveedor", $proveedor->id)
                        ->where("id_modelo", $producto->id)
                        ->first();

                    $proveedor->producto = empty($existe_codigo_proveedor) ? "" : $existe_codigo_proveedor->id;
                }

                $amazon = DB::select("SELECT codigo, descripcion FROM modelo_amazon WHERE id_modelo = " . $producto->id);

                $amazon_data = new stdClass();

                $amazon_data->codigo = empty($amazon) ? "" : $amazon[0]->codigo;
                $amazon_data->descripcion = empty($amazon) ? "" : $amazon[0]->descripcion;

                $producto->amazon = $amazon_data;

                $producto->imagenes_anteriores = DB::select("SELECT nombre, dropbox FROM modelo_imagen WHERE id_modelo = " . $producto->id);

                $producto->producto_exel = empty($producto_exel) ? "" : $producto_exel->id;
            }
            array_push($productosArray, ...$productos);
        }
        return response()->json([
            'code' => 200,
            'tipos' => $tipos,
            'productos' => $productosArray
        ]);
    }

    public function compra_producto_buscar_codigo_sat(Request $request): JsonResponse
    {
        $criterio = $request->input('criterio');
        $existe_codigo = DB::table('modelo_sat')->where('clave_sat', trim($criterio))->first();

        if (empty($existe_codigo)) {
            $existe_descripcion = DB::table('modelo_sat')->where('descripcion', 'like', '%' . trim($criterio) . '%')->get();

            if (empty($existe_descripcion)) {
                $existe_sinonimo = DB::table('modelo_sinonimo')->where('sinonimos', 'like', '%' . trim($criterio) . '%')->get();

                if (empty($existe_sinonimo)) {
                    return response()->json([
                        'code' => 500,
                        'message' => "No se encontro el codigo sat con el criterio proporcionado."
                    ]);
                } else {
                    return response()->json([
                        'code' => 200,
                        'message' => "Codigo encontrado.",
                        'data' => $existe_sinonimo
                    ]);
                }
            } else {
                return response()->json([
                    'code' => 200,
                    'message' => "Codigo encontrado.",
                    'data' => $existe_descripcion
                ]);
            }
        } else {
            return response()->json([
                'code' => 200,
                'message' => "Codigo encontrado.",
                'data' => $existe_codigo
            ]);
        }
    }

    /* Compra > Categoria */

    public function compra_producto_gestion_crear(Request $request): JsonResponse
    {
        set_time_limit(0);
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);
        $empresaId = $request->input('empresa');

        $empresa = DB::table('empresa')->find($empresaId);
        if (!$empresa) {
            return response()->json(['message' => 'No se encontró información de la empresa proporcionada'], 404);
        }

        DB::beginTransaction();

        try {
            $modeloId = CompraService::guardarModelo($data, $auth);
            CompraService::sincronizarProveedores($data->proveedores, $modeloId);
            CompraService::gestionarPrecios($data->precio, $data->sku, $modeloId, $auth);

            DB::commit();
            return response()->json(['code' => 200, 'message' => 'Producto actualizado / agregado correctamente']);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['code' => 500, 'message' => 'Error al procesar: ' . $e->getMessage()], 500);
        }
    }

    public function compra_producto_gestion_imagen($dropbox): JsonResponse
    {
        DB::table('modelo_imagen')->where(['dropbox' => $dropbox])->delete();

        return response()->json([
            'code' => 200
        ]);
    }

    public function compra_producto_gestion_producto_proveedor(Request $request): JsonResponse
    {
        $data = json_decode($request->input("data"));

        $productos = DB::table("modelo_proveedor_producto")
            ->where("id", $data->producto)
            ->where("id_modelo_proveedor", $data->proveedor)
            ->where("activo", 1)
            ->get()
            ->toArray();

        if (empty($productos)) {
            $productos = DB::table("modelo_proveedor_producto")
                ->where("id_producto", $data->producto)
                ->where("id_modelo_proveedor", $data->proveedor)
                ->where("activo", 1)
                ->get()
                ->toArray();

            if (empty($productos)) {
                $productos = DB::table("modelo_proveedor_producto")
                    ->where("descripcion", "like", "%" . $data->producto . "%")
                    ->where("id_modelo_proveedor", $data->proveedor)
                    ->where("activo", 1)
                    ->get()
                    ->toArray();
            }
        }

        return response()->json([
            "code" => 200,
            "data" => $productos
        ]);
    }

    /**
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    public function compra_producto_importacion_crear(Request $request): JsonResponse
    {
        $data = json_decode($request->input('data'));

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $contador_fila = 2;

        $spreadsheet->getActiveSheet()->getStyle('A1:H1')->getFont()->setBold(1)->getColor();

        # Cabecera
        $sheet->setCellValue('A1', 'CÓDIGO');
        $sheet->setCellValue('B1', 'MPN');
        $sheet->setCellValue('C1', 'DESCRIPCIÓN');
        $sheet->setCellValue('D1', 'TIPO');
        $sheet->setCellValue('E1', 'SERIE');
        $sheet->setCellValue('F1', 'CADUCIDAD');
        $sheet->setCellValue('G1', 'SAT');
        $sheet->setCellValue('H1', 'RESULTADO');

        $spreadsheet->getActiveSheet()->getStyle('A1:H1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('5FE4DB'); # Fondo de la cabecera de color azul

        foreach ($data->productos as $producto) {
            $medidas = explode("X", $producto->medidas);
            $alto = (array_key_exists('0', $medidas)) ? (!empty($medidas[0])) ? $medidas[0] : '10' : '10';
            $ancho = (array_key_exists('1', $medidas)) ? (!empty($medidas[1])) ? $medidas[1] : '10' : '10';
            $largo = (array_key_exists('2', $medidas)) ? (!empty($medidas[2])) ? $medidas[2] : '10' : '10';
            $resultado = "";

            $existe_producto_crm = DB::select("SELECT id FROM modelo WHERE sku = '" . $producto->codigo . "'");

            if (empty($existe_producto_crm)) {
                try {
                    /** @noinspection PhpUnusedLocalVariableInspection */
                    $modelo_id = DB::table('modelo')->insertGetId([
                        'id_tipo' => $producto->tipo,
                        'sku' => $producto->codigo,
                        'descripcion' => $producto->descripcion,
                        'costo' => 0,
                        'alto' => $alto,
                        'ancho' => $ancho,
                        'largo' => $largo,
                        'peso' => 1,
                        'serie' => $producto->serie,
                        'clave_sat' => $producto->sat,
                        'unidad' => 'PIEZA',
                        'clave_unidad' => $producto->claveunidad,
                        'refurbished' => 0,
                        'np' => $producto->mpn,
                        'cat1' => $producto->cat1,
                        'cat2' => $producto->cat2,
                        'cat3' => $producto->cat3,
                        'cat4' => $producto->cat4,
                        'caducidad' => $producto->caducidad
                    ]);

                    $resultado .= "Producto creado correctamente en CRM.";
                } catch (Exception $e) {
                    $resultado .= "Error al crear el producto en CRM, Error: " . $e->getMessage();
                }
            } else {
                DB::table('modelo')->where(['id' => $existe_producto_crm[0]->id])->update([
                    'descripcion' => $producto->descripcion,
                    'costo' => 0,
                    'alto' => $alto,
                    'ancho' => $ancho,
                    'largo' => $largo,
                    'peso' => 1,
                    'serie' => $producto->serie,
                    'clave_sat' => $producto->sat,
                    'unidad' => 'PIEZA',
                    'clave_unidad' => $producto->claveunidad,
                    'refurbished' => 0,
                    'np' => $producto->mpn,
                    'cat1' => $producto->cat1,
                    'cat2' => $producto->cat2,
                    'cat3' => $producto->cat3,
                    'cat4' => $producto->cat4,
                    'caducidad' => $producto->caducidad

                ]);

                $resultado .= "Producto actualizado correctamente.";
            }

            $sheet->setCellValue('A' . $contador_fila, $producto->codigo);
            $sheet->setCellValue('B' . $contador_fila, $producto->mpn);
            $sheet->setCellValue('C' . $contador_fila, $producto->descripcion);
            $sheet->setCellValue('D' . $contador_fila, $producto->tipo == 1 ? 'Producto' : 'Servicio');
            $sheet->setCellValue('E' . $contador_fila, $producto->serie == 1 ? 'Si' : 'No');
            $sheet->setCellValue('F' . $contador_fila, $producto->caducidad == 1 ? 'Si' : 'No');
            $sheet->setCellValue('G' . $contador_fila, $producto->sat);
            $sheet->setCellValue('H' . $contador_fila, $resultado);

            $sheet->getCellByColumnAndRow(1, $contador_fila)->setValueExplicit($producto->codigo, DataType::TYPE_STRING);

            $contador_fila++;
        }

        # Poner en automatico el ancho de la columna dependiendo el texto que esté dentro
        $spreadsheet->getActiveSheet()->getColumnDimension('A')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('B')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('C')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('D')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('E')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('F')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('G')->setAutoSize(true);
        $spreadsheet->getActiveSheet()->getColumnDimension('H')->setAutoSize(true);

        $writer = new Xlsx($spreadsheet);
        $writer->save('reporte_importacion_producto.xlsx');

        $json['code'] = 200;
        $json['message'] = "Importación terminada correctamente, se descargara un excel con el detalle de cada producto.";
        $json['excel'] = base64_encode(file_get_contents('reporte_importacion_producto.xlsx'));

        unlink('reporte_importacion_producto.xlsx');

        return response()->json($json);
    }

    public function compra_producto_categoria_get_data(): JsonResponse
    {
        return response()->json([
            'code' => 200,
            'data' => $this->rawinfo_categorias()
        ]);
    }

    /* Compra > producto > buscar */

    public function rawinfo_categorias(): array
    {
        $categorias = DB::select("SELECT * FROM modelo_categoria");
        $categorias_uno = DB::select("SELECT id, categoria FROM modelo_categoria WHERE tipo = 1 ORDER BY categoria DESC");
        $categorias_dos = DB::select("SELECT id, categoria FROM modelo_categoria WHERE tipo = 2 ORDER BY categoria DESC");
        $categorias_tres = DB::select("SELECT id, categoria FROM modelo_categoria WHERE tipo = 3 ORDER BY categoria DESC");
        $categorias_cuatro = DB::select("SELECT id, categoria FROM modelo_categoria WHERE tipo = 4 ORDER BY categoria DESC");

        return [
            "categorias" => $categorias,
            "categorias_uno" => $categorias_uno,
            "categorias_dos" => $categorias_dos,
            "categorias_tres" => $categorias_tres,
            "categorias_cuatro" => $categorias_cuatro
        ];
    }


    /* Compra > presupuesto */

    public function compra_producto_categoria_post_crear(Request $request): JsonResponse
    {
        $data = json_decode($request->input('data'));
        $raw_data = $request->input('data');

        $validator = Validator::make(json_decode($raw_data, true), [
            'tipo' => "required",
            'categoria' => "required"
        ]);

        if (!$validator->passes()) {
            return response()->json([
                'code' => 500,
                'message' => implode("; ", $validator->errors()->all())
            ]);
        }

        $existe = DB::select("SELECT id FROM modelo_categoria WHERE tipo = '" . $data->tipo . "' AND categoria = '" . $data->categoria . "'");

        if (!empty($existe)) {
            return response()->json([
                'code' => 500,
                'message' => "Ya existe una categoria registrada con el mismo nombre"
            ]);
        }

        if ($data->id != 0) {
            DB::table('modelo_categoria')->where(['id' => $data->id])->update([
                'tipo' => $data->tipo,
                'categoria' => mb_strtoupper($data->categoria, 'UTF-8')
            ]);
        } else {
            DB::table('modelo_categoria')->insert([
                'tipo' => $data->tipo,
                'categoria' => mb_strtoupper($data->categoria, 'UTF-8')
            ]);
        }

        return response()->json([
            'code' => 200,
            'message' => $data->id == 0 ? "Categoria creada correctamente" : "Categoria editada correctamente",
            'data' => $this->rawinfo_categorias()
        ]);
    }

    public function compra_producto_sinonimo_post_producto(Request $request): JsonResponse
    {
        $criterio = $request->input("data");

        $productos = DB::table("modelo")
            ->select("id", "sku", "descripcion")
            ->where("sku", $criterio)
            ->get()
            ->toArray();

        if (empty($productos)) {
            $productos = DB::table("modelo")
                ->select("id", "sku", "descripcion")
                ->where("descripcion", "like", "%" . $criterio . "%")
                ->get();
        }

        foreach ($productos as $producto) {
            $producto->sinonimos = DB::table("modelo_sinonimo")
                ->select("codigo")
                ->where("id_modelo", $producto->id)
                ->pluck("codigo");
        }

        return response()->json([
            "code" => 200,
            "productos" => $productos
        ]);
    }

    /* Compra > Tipo de cambio */

    public function compra_producto_sinonimo_post_guardar(Request $request): JsonResponse
    {
        $data = json_decode($request->input("data"));
        $auth = json_decode($request->auth);

        DB::table("modelo_sinonimo")
            ->where("id_modelo", $data->id)
            ->delete();

        foreach ($data->sinonimos as $sinonimo) {
            DB::table("modelo_sinonimo")->insert([
                "id_usuario" => $auth->id,
                "id_modelo" => $data->id,
                "codigo" => $sinonimo
            ]);
        }

        return response()->json([
            "code" => 200,
            "message" => "Sinonimos agregados correctamente"
        ]);
    }

    public function compra_producto_sinonimo_post_sinonimo(Request $request): JsonResponse
    {
        $data = $request->input("data");

        $sinonimo = DB::table("modelo_sinonimo")
            ->join("modelo", "modelo_sinonimo.id_modelo", "=", "modelo.id")
            ->select("modelo.sku")
            ->where("modelo_sinonimo.codigo", trim($data))
            ->first();

        return response()->json([
            "code" => 200,
            "sinonimo" => empty($sinonimo) ? trim($data) : $sinonimo->sku
        ]);
    }

    /* Compra backorder */

    public function compra_producto_buscar($criterio): JsonResponse
    {
        $criterio = urldecode(trim($criterio));

        $productos = DB::table("modelo")
            ->join("modelo_tipo", "modelo.id_tipo", "=", "modelo_tipo.id")
            ->select("modelo.*", "modelo_tipo.tipo AS tipo_text", "modelo.id_tipo AS tipo")
            ->where("modelo.status", 1)
            ->where("modelo.sku", $criterio)
            ->get()
            ->toArray();

        if (empty($productos)) {
            $palabras = preg_split('/\s+/', $criterio);

            $query = DB::table("modelo")
                ->join("modelo_tipo", "modelo.id_tipo", "=", "modelo_tipo.id")
                ->select("modelo.*", "modelo_tipo.tipo AS tipo_text", "modelo.id_tipo AS tipo")
                ->where("modelo.status", 1);

            foreach ($palabras as $palabra) {
                $query->where("modelo.descripcion", "LIKE", "%" . $palabra . "%");
            }

            $productos = $query->get()->toArray();

            if (empty($productos)) {
                $sinonimo = DB::table("modelo_sinonimo")
                    ->join("modelo", "modelo_sinonimo.id_modelo", "=", "modelo.id")
                    ->select("modelo.sku")
                    ->where("modelo_sinonimo.codigo", $criterio)
                    ->first();

                if (!empty($sinonimo)) {
                    $productos = DB::table("modelo")
                        ->join("modelo_tipo", "modelo.id_tipo", "=", "modelo_tipo.id")
                        ->select("modelo.*", "modelo_tipo.tipo AS tipo_text", "modelo.id_tipo AS tipo")
                        ->where("modelo.status", 1)
                        ->where("modelo.sku", $sinonimo->sku)
                        ->get()
                        ->toArray();
                }
            }
        }

        return response()->json([
            "code" => 200,
            "data" => $productos
        ]);
    }

    /* Compra proveedor */

    public function compra_presupuesto_data(): JsonResponse
    {
        $presupuesto = DB::select("SELECT presupuesto FROM documento_presupuesto WHERE created_at BETWEEN '" . date("Y-m-d", strtotime("monday this week")) . " 00:00:00' AND '" . date("Y-m-d", strtotime("sunday this week")) . " 23:59:59' ORDER BY created_at DESC");

        return response()->json([
            'code' => 200,
            'presupuesto' => empty($presupuesto) ? 0 : $presupuesto[0]->presupuesto
        ]);
    }

    public function compra_presupuesto_guardar($presupuesto): JsonResponse
    {
        DB::table('documento_presupuesto')->insert([
            'presupuesto' => $presupuesto
        ]);

        return response()->json([
            'code' => 200,
            'message' => "Presupuesto definido correctamente."
        ]);
    }

    public function compra_tipo_cambio_data(): JsonResponse
    {
//        $tipo_cambio = DB::select("SELECT tipo_cambio FROM documento_tipo_cambio ORDER BY created_at DESC");

        return response()->json([
            'code' => 200,
            'tc' => DocumentoService::tipo_cambio()
        ]);
    }

    public function compra_tipo_cambio_guardar($tc): JsonResponse
    {
        DB::table('documento_tipo_cambio')->insert([
            'tipo_cambio' => (float)$tc
        ]);

        return response()->json([
            'code' => 200,
            'message' => "Tipo de cambio definido correctamente."
        ]);
    }

    public function compra_compra_backorder(): JsonResponse
    {
        $publicaciones = DB::select("SELECT
                                        marketplace_publicacion.id,
                                        marketplace_publicacion.publicacion_id,
                                        marketplace_publicacion.publicacion,
                                        marketplace_publicacion.tee
                                    FROM documento
                                    INNER JOIN marketplace_publicacion ON documento.mkt_publicacion = marketplace_publicacion.publicacion_id
                                    WHERE documento.id_fase = 1
                                    AND documento.id_tipo = 2
                                    AND documento.status = 1
                                    GROUP BY marketplace_publicacion.publicacion_id");

        foreach ($publicaciones as $publicacion) {
            $publicacion->productos = DB::select("SELECT
                                                    modelo.id,
                                                    modelo.sku,
                                                    modelo.descripcion
                                                FROM marketplace_publicacion_producto
                                                INNER JOIN modelo ON marketplace_publicacion_producto.id_modelo = modelo.id
                                                WHERE marketplace_publicacion_producto.id_publicacion = " . $publicacion->id);

            foreach ($publicacion->productos as $producto) {
                $producto->ventas = new stdClass();

                $ventas = DB::select("SELECT
                                        movimiento.cantidad,
                                        SUBSTRING_INDEX(documento.mkt_created_at, 'T', 1) AS fecha
                                    FROM documento
                                    INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                    WHERE documento.id_fase = 1
                                    AND documento.id_tipo = 2
                                    AND documento.status = 1
                                    AND movimiento.id_modelo = " . $producto->id);

                foreach ($ventas as $venta) {
                    $fecha = $venta->fecha;

                    if (property_exists($producto->ventas, $venta->fecha)) {
                        $producto->ventas->$fecha->cantidad += $venta->cantidad;
                    } else {
                        $fecha_entrega = date('Y-m-d', strtotime($fecha . ' + ' . $publicacion->tee . ' days'));

                        $fecha_actual = time();
                        $fecha_entrega = strtotime($fecha_entrega);
                        $diferencia = $fecha_entrega - $fecha_actual;

                        $producto->ventas->$fecha = new stdClass();
                        $producto->ventas->$fecha->fecha = $fecha;
                        $producto->ventas->$fecha->cantidad = $venta->cantidad;
                        $producto->ventas->$fecha->resta = floor($diferencia / (60 * 60 * 24));
                    }
                }

                $existencia = InventarioService::existenciaProducto($producto->sku, 1); # Solo almacén de vidriera y emperesa OMG

                $producto->existencia = $existencia->error ? 0 : $existencia->disponible;
            }
        }

        return response()->json([
            'code' => 200,
            'publicaciones' => $publicaciones
        ]);
    }

    public function compra_proveedor_data(): JsonResponse
    {
        $regimenes = DB::table("cat_regimen")->get();
        $paises = DB::table("cat_pais")->get();
        $periodos = DB::table("documento_periodo")->where('status', 1)->get();

        return response()->json([
            "code" => 200,
            "regimenes" => $regimenes,
            "paises" => $paises,
            "condiciones" => $periodos
        ]);
    }

    public function compra_proveedor_get_data($criterio): JsonResponse
    {
        $criterio = urldecode($criterio);

        $proveedores = DB::table('documento_entidad')
            ->where('rfc', $criterio)
            ->whereIn('tipo', [2, 3])
            ->where('status', 1)
            ->get();

        if ($proveedores->isEmpty()) {
            $proveedores = DB::table('documento_entidad')
                ->where('razon_social', 'like', '%' . $criterio . '%')
                ->whereIn('tipo', [2, 3])
                ->where('status', 1)
                ->get();
        }

        foreach ($proveedores as $proveedor) {
            if (!empty($proveedor->info_extra)) {
                $info_extra = json_decode($proveedor->info_extra);
                $proveedor->pais = $info_extra->pais ?? '';
                $proveedor->regimen = $info_extra->regimen ?? '';
            } else {
                $proveedor->pais = '';
                $proveedor->regimen = '';
            }
        }

        return response()->json([
            'code' => 200,
            'data' => $proveedores
        ]);
    }


    /* Pedimento */

    public function compra_proveedor_post_guardar(Request $request): JsonResponse
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);
        $raw_data = $request->input('data');

        $validator = Validator::make(json_decode($raw_data, true), [
            'empresa' => 'required',
            'pais' => 'required',
            'regimen' => 'required',
            'razon_social' => 'required|max:150',
            'rfc' => 'required|max:13',
            'correo' => 'nullable|email',
            'telefono' => 'nullable|max:20',
            'telefono_alt' => 'nullable|max:20',
        ]);

        if (!$validator->passes()) {
            return response()->json([
                'code' => 500,
                'message' => implode("; ", $validator->errors()->all())
            ]);
        }

        $existe_proveedor = DB::table('documento_entidad')
            ->where('rfc', trim($data->rfc))
            ->whereIn('tipo', [2, 3])
            ->where('status', 1)
            ->first();

        $info_extra = (object)[
            'pais' => $data->pais,
            'regimen' => $data->regimen,
        ];

        $payload = [
            'tipo' => $data->alt ? 3 : 2,
            'id_erp' => 0,
            'razon_social' => mb_strtoupper(trim($data->razon_social), 'UTF-8'),
            'rfc' => mb_strtoupper(trim($data->rfc), 'UTF-8'),
            'telefono' => mb_strtoupper(trim($data->telefono ?? ''), 'UTF-8'),
            'telefono_alt' => mb_strtoupper(trim($data->telefono_alt ?? ''), 'UTF-8'),
            'correo' => trim($data->correo ?? ''),
            'info_extra' => json_encode($info_extra),
            'regimen' => $data->regimen,
            'regimen_id' => $data->regimen,
            'pais' => $data->pais,
            'regimen_letra' => $data->fiscal ?? '',
            'codigo_postal_fiscal' => $data->codigo_postal_fiscal ?? null,
        ];

        if (empty($existe_proveedor)) {
            $payload['created_by_user'] = $auth->id;
            $entidad_id = DB::table('documento_entidad')->insertGetId($payload);

            $mensaje = "Entidad creada correctamente";
        } else {
            $payload['updated_by_user'] = $auth->id;
            DB::table('documento_entidad')->where('id', $existe_proveedor->id)->update($payload);

            $mensaje = "Entidad actualizada correctamente";
        }

        return response()->json([
            'code' => 200,
            'message' => $mensaje,
            'raw' => $raw_data,
            'data' => $entidad_id ?? '',
        ]);
    }

    public function compra_cliente_get_data(string $criterio): JsonResponse
    {
        $criterio = urldecode($criterio);

        $proveedores = DB::table('documento_entidad')
            ->where('rfc', $criterio)
            ->whereIn('tipo', [1, 3])
            ->where('status', 1)
            ->where('id', '!=', 0)
            ->get();

        if ($proveedores->isEmpty()) {
            $proveedores = DB::table('documento_entidad')
                ->where('razon_social', 'like', "%$criterio%")
                ->whereIn('tipo', [1, 3])
                ->where('status', 1)
                ->where('id', '!=', 0)
                ->get();
        }

        $proveedores->transform(function ($proveedor) {
            $info_extra = json_decode($proveedor->info_extra ?? '', true);

            $proveedor->pais = $info_extra['pais'] ?? '';
            $proveedor->regimen = $info_extra['regimen'] ?? '';

            return $proveedor;
        });

        return response()->json([
            'code' => 200,
            'data' => $proveedores->values(),
        ]);
    }

    public function compra_cliente_post_guardar(Request $request): JsonResponse
    {
        $data = json_decode($request->input('data'));
        $auth = json_decode($request->auth);
        $raw_data = $request->input('data');

        $validator = Validator::make(json_decode($raw_data, true), [
            'empresa' => "required",
            'pais' => "required",
            'regimen' => "required",
            'razon_social' => "required|max:150",
            'rfc' => "required|max:13",
            'correo' => "email",
            'telefono' => "max:20",
            'telefono_alt' => "max:20",
            'condicion' => "required|numeric",
            'limite' => "required|numeric",
            'codigo_postal_fiscal' => "required|numeric"
        ]);

        if (!$validator->passes()) {
            return response()->json([
                'code' => 500,
                'message' => implode("; ", $validator->errors()->all())
            ]);
        }

        $info_extra = new stdClass();
        $info_extra->pais = $data->pais;
        $info_extra->regimen = $data->regimen;
        $info_extra->fiscal = $data->fiscal;

        if ($data->id == 0) {
            $existe_cliente = DocumentoEntidad::where("rfc", trim($data->rfc))
                ->where("status", 1)
                ->whereIn("tipo", [1, 3])
                ->first();

            if (!$existe_cliente) {
                $entidad_id = DocumentoEntidad::insertGetId([
                    'tipo' => $data->alt ? 3 : 1,
                    'id_erp' => 0,
                    'regimen_id' => $data->regimen,
                    'regimen' => $data->regimen,
                    'razon_social' => mb_strtoupper(trim($data->razon_social), 'UTF-8'),
                    'rfc' => mb_strtoupper(trim($data->rfc), 'UTF-8'),
                    'telefono' => mb_strtoupper(trim($data->telefono), 'UTF-8'),
                    'telefono_alt' => mb_strtoupper(trim($data->telefono_alt), 'UTF-8'),
                    'correo' => trim($data->correo),
                    'info_extra' => json_encode($info_extra),
                    'limite' => $data->limite,
                    'condicion' => $data->condicion,
                    'codigo_postal_fiscal' => $data->codigo_postal_fiscal,
                    'pais' => $data->pais,
                    'regimen_letra' => $data->fiscal ?? '',
                    'created_by_user' => $auth->id
                ]);

                $old_data = DocumentoEntidad::find($entidad_id);
            } else {
                $old_data = $existe_cliente;

                DocumentoEntidad::where(['id' => $existe_cliente->id])->update([
                    'tipo' => $data->alt ? 3 : 1,
                    'regimen_id' => $data->regimen,
                    'regimen' => $data->regimen,
                    'razon_social' => mb_strtoupper(trim($data->razon_social), 'UTF-8'),
                    'rfc' => mb_strtoupper(trim($data->rfc), 'UTF-8'),
                    'telefono' => mb_strtoupper(trim($data->telefono), 'UTF-8'),
                    'telefono_alt' => mb_strtoupper(trim($data->telefono_alt), 'UTF-8'),
                    'correo' => trim($data->correo),
                    'info_extra' => json_encode($info_extra),
                    'limite' => $data->limite,
                    'condicion' => $data->condicion,
                    'codigo_postal_fiscal' => $data->codigo_postal_fiscal,
                    'pais' => $data->pais,
                    'regimen_letra' => $data->fiscal ?? '',
                    'updated_by_user' => $auth->id
                ]);

                $entidad_id = $existe_cliente->id;
            }
        } else {
            $old_data = DocumentoEntidad::find($data->id);

            DocumentoEntidad::where(['id' => $data->id])->update([
                'regimen_id' => $data->regimen,
                'regimen' => $data->regimen,
                'razon_social' => mb_strtoupper(trim($data->razon_social), 'UTF-8'),
                'rfc' => mb_strtoupper(trim($data->rfc), 'UTF-8'),
                'telefono' => mb_strtoupper(trim($data->telefono), 'UTF-8'),
                'telefono_alt' => mb_strtoupper(trim($data->telefono_alt), 'UTF-8'),
                'correo' => trim($data->correo),
                'info_extra' => json_encode($info_extra),
                'limite' => $data->limite,
                'condicion' => $data->condicion,
                'codigo_postal_fiscal' => $data->codigo_postal_fiscal,
                'pais' => $data->pais,
                'regimen_letra' => $data->fiscal ?? '',
                'updated_by_user' => $auth->id
            ]);

            $entidad_id = $data->id;
        }

        $entidad_data = DocumentoEntidad::find($entidad_id);

        DocumentoEntidadUpdates::insert([
            "id_usuario" => $auth->id,
            "id_entidad" => $entidad_data->id,
            "old_data" => $old_data,
            "new_data" => $entidad_data
        ]);

        return response()->json([
            'code' => 200,
            'message' => $data->id == 0 ? "Entidad creada correctamente" : "Entidad actualizada correctamente"
        ]);
    }

    /** @noinspection PhpParamsInspection */

    /**
     * @throws ConnectionErrorException
     */
    public function rawinfo_compra_uuid(): string
    {
        set_time_limit(0);

        $compras = DB::select("SELECT
                                documento.documento_extra, 
                                documento.uuid,
                                empresa.bd
                            FROM documento 
                            INNER JOIN empresa_almacen ON documento.id_almacen_principal_empresa = empresa_almacen.id
                            INNER JOIN empresa ON empresa_almacen.id_empresa = empresa.id
                            WHERE documento.created_at LIKE '%2019%' 
                            AND documento.documento_extra != 'N/A' 
                            AND documento.documento_extra != '' 
                            AND documento.UUID != 'N/A' 
                            AND documento.UUID != ''");

        foreach ($compras as $compra) {
            $array_compra = [
                "bd" => $compra->bd,
                "password" => config("webservice.token"),
                "documento" => $compra->documento_extra,
                "uuid" => $compra->uuid
            ];

            \Httpful\Request::post(config('webservice.url') . 'Compra/Add/UUID/UTKFJKkk3mPc8LbJYmy6KO1ZPgp7Xyiyc1DTGrw')->body($array_compra, Mime::FORM)->send();
        }

        return "Terminado";
    }

    public function rawinfo_compra_huawei(): array
    {
        $compras = DB::select("SELECT id FROM documento WHERE factura_serie = 'E' AND status = 1 AND id_fase = 93");

        foreach ($compras as $compra) {
            $productos = DB::select("SELECT
                                        modelo.serie,
                                        movimiento.id,
                                        movimiento.id_modelo,
                                        movimiento.cantidad
                                    FROM movimiento
                                    INNER JOIN modelo ON movimiento.id_modelo = modelo.id
                                    WHERE movimiento.id_documento = " . $compra->id);

            foreach ($productos as $producto) {
                if ($producto->serie) {
                    /** @noinspection PhpUnusedLocalVariableInspection */
                    $series = DB::select("SELECT
                                            COUNT(producto.*) AS cantidad
                                        FROM documento
                                        INNER JOIN movimiento ON documento.id = movimiento.id_documento
                                        INNER JOIN movimiento_producto ON movimiento.id = movimiento_producto.id_movimiento
                                        INNER JOIN producto ON movimiento_producto.id_producto = producto.id
                                        WHERE documento.id_tipo = 2
                                        AND documento.id_almacen_principal_empresa = 34
                                        AND documento.id_fase > 4
                                        AND movimiento.id_modelo = " . $producto->id_modelo . "
                                        AND producto.status = 1");
                }
            }
        }

        return $compras;
    }

    public function compra_pedimento_crear_get_data(): JsonResponse
    {
        $empresas = DB::table("empresa")
            ->select("id", "bd", "empresa")
            ->where("id", "<>", 0)
            ->get()
            ->toArray();

        $monedas = DB::table("moneda")
            ->get()
            ->toArray();

        return response()->json([
            "code" => 200,
            "empresas" => $empresas,
            "monedas" => $monedas
        ]);
    }
}
