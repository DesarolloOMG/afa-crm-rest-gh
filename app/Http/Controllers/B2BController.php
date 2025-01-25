<?php

namespace App\Http\Controllers;

use DB;
use Illuminate\Http\Request;
use App\Http\Services\ExelDelNorteService;
use App\Http\Services\CTService;

class B2BController extends Controller
{
    public function b2b_get_proveedores()
    {
        set_time_limit(0);

        $proveedores = DB::table('modelo_proveedor')->where('status', 1)->get()->toArray();
        foreach ($proveedores as $proveedor) {
            $proveedor->almacenes = DB::table('modelo_proveedor_almacen')->where('id_modelo_proveedor', $proveedor->id)->get();
        }

        if (!empty($proveedores)) {
            return response()->json([
                'code' => 200,
                'proveedores' => $proveedores
            ]);
        } else {
            return response()->json([
                'code' => 500,
                'message' => 'No se encontraron proveedores'
            ]);
        }
    }

    public function b2b_crear_proveedor(Request $request)
    {
        set_time_limit(0);
        $proveedor = json_decode($request->input("proveedor"));
        $almacenes = json_decode($request->input("almacenes"));

        try {
            DB::beginTransaction();

            $idProveedor = DB::table('modelo_proveedor')->insertGetId([
                'rfc' => $proveedor->rfc,
                'razon_social' => $proveedor->razon_social,
                'correo' => $proveedor->correo,
                'api' => $proveedor->api ? 1 : 0,
            ]);

            foreach ($almacenes as $almacen) {
                DB::table('modelo_proveedor_almacen')->insert([
                    'id_modelo_proveedor' => $idProveedor,
                    'id_almacen' => $almacen->id_almacen,
                    'id_locacion' => $almacen->id_locacion,
                    'locacion' => $almacen->locacion,
                    'calle' => $almacen->calle,
                    'numero' => $almacen->numero,
                    'numero_int' => $almacen->numero_int,
                    'colonia' => $almacen->colonia,
                    'ciudad' => $almacen->ciudad,
                    'estado' => $almacen->estado,
                    'codigo_postal' => $almacen->codigo_postal,
                    'referencia' => $almacen->referencia,
                    'contacto' => $almacen->contacto,
                    'correo' => $almacen->correo,
                    'telefono' => $almacen->telefono,
                    'status' => $almacen->status ? 1 : 0,
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => "Proveedor creado correctamente",
                'code' => 200
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => $e->getMessage(),
                'code' => 500
            ]);
        }
    }

    public function b2b_editar_proveedor(Request $request)
    {
        set_time_limit(0);
        $proveedor = json_decode($request->input("proveedor"));
        $almacenes = json_decode($request->input("almacenes"));
        try {
            DB::beginTransaction();

            DB::table('modelo_proveedor')->where('id', $proveedor->id)->update([
                'rfc' => $proveedor->rfc,
                'razon_social' => $proveedor->razon_social,
                'correo' => $proveedor->correo,
                'api' => $proveedor->api ? 1 : 0,
            ]);

            foreach ($almacenes as $almacen) {
                DB::table('modelo_proveedor_almacen')->where('id', $almacen->id)->update([
                    'id_almacen' => $almacen->id_almacen,
                    'id_locacion' => $almacen->id_locacion,
                    'locacion' => $almacen->locacion,
                    'calle' => $almacen->calle,
                    'numero' => $almacen->numero,
                    'numero_int' => $almacen->numero_int,
                    'colonia' => $almacen->colonia,
                    'ciudad' => $almacen->ciudad,
                    'estado' => $almacen->estado,
                    'codigo_postal' => $almacen->codigo_postal,
                    'referencia' => $almacen->referencia,
                    'contacto' => $almacen->contacto,
                    'correo' => $almacen->correo,
                    'telefono' => $almacen->telefono,
                    'status' => $almacen->status ? 1 : 0,
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => "Proveedor editado correctamente",
                'code' => 200
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => $e->getMessage(),
                'code' => 500
            ]);
        }
    }

    public function b2b_eliminar_proveedor($proveedor)
    {
        set_time_limit(0);

        try {
            DB::beginTransaction();

            DB::table('modelo_proveedor')->where('id', $proveedor)->update([
                'status' => 0,
            ]);

            DB::table('modelo_proveedor_almacen')->where('id_modelo_proveedor', $proveedor)->update([
                'status' => 0,
            ]);

            DB::commit();

            return response()->json([
                'message' => "Proveedor eliminado correctamente",
                'code' => 200
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => $e->getMessage(),
                'code' => 500
            ]);
        }
    }

    //!API

    public function b2b_importar_productos(Request $request)
    {
        set_time_limit(0);
        $res = new \stdClass();

        $proveedor = json_decode($request->input("proveedor"));

        $existe_api = DB::table('modelo_proveedor')->where('id', $proveedor->id)->where('api', 1)->pluck('api_data')->first();

        if (empty($existe_api)) {
            $res->error = 1;
            $res->mensaje = 'El proveedor no tiene una API configurada';
        }

        switch ($existe_api) {
            case 'exel':
                $res = (array) ExelDelNorteService::newConsultarProductos();
                break;

            case 'ct':
                $res = (array) CTService::newConsultarProductos();
                break;

            default:
                $res->error = 1;
                $res->mensaje = 'No hay opción configurada para ese proveedor';
                break;
        }

        return response()->json([
            'res' => $res,
        ]);
    }

    public function b2b_actualizar_productos(Request $request)
    {
        set_time_limit(0);
        $res = new \stdClass();

        $proveedor = json_decode($request->input("proveedor"));

        $existe_api = DB::table('modelo_proveedor')->where('id', $proveedor->id)->where('api', 1)->pluck('api_data')->first();

        if (empty($existe_api)) {
            $res->error = 1;
            $res->mensaje = 'El proveedor no tiene una API configurada';
        }

        switch ($existe_api) {
            case 'exel':
                $res = (array) ExelDelNorteService::newConsultaPreciosYExistencias();
                break;

            case 'ct':
                $alm = (array) CTService::newConsultarAlmacenes();
                $res = (array) CTService::newConsultarProductos();
                break;

            default:
                $res->error = 1;
                $res->mensaje = 'No hay opción configurada para ese proveedor';

                break;
        }
        return response()->json([
            'res' => $res,
        ]);
    }

    //!EXCEL

    public function b2b_importar_productos_excel(Request $request)
    {
        set_time_limit(0);
        $res = new \stdClass();

        $proveedor = json_decode($request->input("proveedor"));
        $data = json_decode($request->input("data"));

        if (empty($data)) {
            $res->error = 1;
            $res->mensaje = 'Datos vacios';
        }

        return response()->json([
            'a' => $proveedor,
            'b' => $data
        ]);
    }

    public function b2b_actualizar_productos_excel(Request $request)
    {
        set_time_limit(0);
        $res = new \stdClass();

        $proveedor = json_decode($request->input("proveedor"));
        $data = json_decode($request->input("data"));

        if (empty($data)) {
            $res->error = 1;
            $res->mensaje = 'Datos vacios';
        }

        return response()->json([
            'a' => $proveedor,
            'b' => $data
        ]);

        return response()->json([
            'res' => $res,
        ]);
    }
    //!GESTION

    public function b2b_productos_data(Request $request)
    {
        set_time_limit(0);
        $data = json_decode($request->input('data'));

        if ($data->criterio) {
            $productos = DB::table("modelo_proveedor_producto")
                ->where("id_producto", $data->criterio)
                ->where('id_modelo_proveedor', $data->proveedor)
                ->get()
                ->toArray();

            if (empty($productos)) {

                $productos = DB::table("modelo_proveedor_producto")
                    ->where("codigo_proveedor", "LIKE", "%" . $data->criterio . "%")
                    ->where('id_modelo_proveedor', $data->proveedor)
                    ->get()
                    ->toArray();

                if (empty($productos)) {
                    $productos = DB::table("modelo_proveedor_producto")
                        ->where("codigo_barra", "LIKE", "%" . $data->criterio . "%")
                        ->where('id_modelo_proveedor', $data->proveedor)
                        ->get()
                        ->toArray();

                    if (empty($productos)) {
                        $productos = DB::table("modelo_proveedor_producto")
                            ->where("descripcion", "LIKE", "%" . $data->criterio . "%")
                            ->where('id_modelo_proveedor', $data->proveedor)
                            ->get()
                            ->toArray();
                    }
                    if (empty($productos)) {
                        return response()->json([
                            'code' => 500,
                            'productos' => $productos,
                            'message' => 'No se encontraron productos con este criterio'
                        ]);
                    }
                }
            }
        } else {
            $productos = DB::table("modelo_proveedor_producto")
                ->where('id_modelo_proveedor', $data->proveedor)
                ->get()
                ->toArray();
        }

        foreach ($productos as $key) {
            $key->precioLista = number_format($key->precioLista, 2, '.', '');
            $key->modelo = [];
            $key->relacionado = 0;

            if ($key->id_modelo) {
                $key->modelo = DB::table('modelo')->where('id', $key->id_modelo)->get()->first();
                $key->relacionado = 1;
            }
        }

        return response()->json([
            'code' => 200,
            'productos' => $productos
        ]);
    }

    public function b2b_productos_guardar(Request $request)
    {
        set_time_limit(0);
        $auth = json_decode($request->auth);
        $producto = json_decode($request->input('producto'));
        $relacion = json_decode($request->input('relacion'));

        $existe_producto = DB::table('modelo_proveedor_producto')->where('id', $producto->id)->get()->first();

        if ($existe_producto) {
            try {
                DB::beginTransaction();

                DB::table('modelo_proveedor_producto')->where('id', $producto->id)->update([
                    'actualizar' => $producto->actualizar ? 1 : 0,
                ]);

                if (!empty($relacion)) {
                    DB::table('modelo_proveedor_producto')->where('id', $producto->id)->update([
                        'id_modelo' => $relacion->id,
                        'nuevo' => 0,
                    ]);
                }

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();

                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => 500
                ]);
            }
        } else {
            return response()->json([
                'message' => 'No se encuentra el producto del proveedor',
                'code' => 500
            ]);
        }


        return response()->json([
            'message' => "Producto Actualizado correctamente",
            'code' => 200
        ]);
    }
}
