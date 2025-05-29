<?php /** @noinspection PhpUndefinedMethodInspection */

namespace App\Http\Services;

use App\Models\Usuario;
use App\Models\UsuarioEmpresa;
use App\Models\UsuarioMarketplaceArea;
use App\Models\UsuarioSubnivelNivel;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mailgun\Mailgun;
use Throwable;

class UsuarioService
{
    /**
     * @throws Throwable
     */
    public static function crearUsuario($data, $auth)
    {
        DB::beginTransaction();

        try {
            $contrasena = GeneralService::randomString();

            $usuario = Usuario::create([
                'nombre' => mb_strtoupper($data->nombre, 'UTF-8'),
                'email' => $data->email,
                'contrasena' => Hash::make($contrasena),
                'tag' => $contrasena,
                'celular' => $data->celular
            ]);

            self::sincronizarRelaciones($usuario->id, $data);

            DB::table('usuario')
                ->where('id', $usuario->id)
                ->whereNull('area')
                ->update(['area' => $data->division]);

            $division = DB::table('division')
                ->where('division', mb_strtoupper($data->division, 'UTF-8'))
                ->first();

            if (!$division) {
                throw new Exception("División no encontrada");
            }

            DB::table('usuario_division')->insert([
                'id_usuario' => $usuario->id,
                'id_division' => $division->id,
            ]);

            foreach ($data->empresa_almacen as $idEmpresaAlmacen) {
                DB::table('usuario_empresa_almacen')->insert([
                    'id_usuario' => $usuario->id,
                    'id_empresa_almacen' => $idEmpresaAlmacen,
                ]);
            }

            DB::commit();

        } catch (Throwable $e) {
            DB::rollBack();
            return response()->json([
                "message" => "No se pudo crear el usuario. " . self::logVariableLocation(),
                "error" => $e->getMessage()
            ], 500);
        }

        $creador = Usuario::find($auth->id);

        $view = view('email.notificacion_usuario_creado')->with([
            "usuario" => $data->nombre,
            "creador" => $creador->nombre,
            "correo" => $data->email,
            "contrasena" => $contrasena,
            "anio" => date("Y")
        ]);

        try {
            $mg = Mailgun::create(config("mailgun.token"));
            $mg->messages()->send(
                config("mailgun.domain"),
                array(
                    'from' => config("mailgun.email_from"),
                    'to' => $data->email,
                    'subject' => "Tu nuevo usuario para CRM AFA Innovations",
                    'html' => $view->render()
                )
            );
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                "message" => "No se pudo enviar el correo. " . self::logVariableLocation(),
                "error" => $e->getMessage()
            ], 500);
        }

        return $contrasena;
    }

    private static function sincronizarRelaciones($userId, $data)
    {
        UsuarioEmpresa::where("id_usuario", $userId)->delete();
        UsuarioSubnivelNivel::where("id_usuario", $userId)->delete();
        UsuarioMarketplaceArea::where("id_usuario", $userId)->delete();

        foreach ($data->empresas as $empresa) {
            UsuarioEmpresa::create([
                'id_usuario' => $userId,
                'id_empresa' => $empresa
            ]);
        }

        foreach ($data->subniveles as $subnivel) {
            UsuarioSubnivelNivel::create([
                'id_usuario' => $userId,
                'id_subnivel_nivel' => $subnivel
            ]);
        }

        foreach ($data->marketplaces as $marketplace) {
            UsuarioMarketplaceArea::create([
                'id_usuario' => $userId,
                'id_marketplace_area' => $marketplace
            ]);
        }
    }

    public static function logVariableLocation(): string
    {
        // $log = self::logVariableLocation();
        $sis = 'BE'; //Front o Back
        $ini = 'US'; //Primera letra del Controlador y Letra de la seguna Palabra: Controller, service
        $fin = 'RIO'; //Últimas 3 letras del primer nombre del archivo *comPRAcontroller
        $trace = debug_backtrace()[0];
        return ('<br> Código de Error: ' . $sis . $ini . $trace['line'] . $fin);
    }

    /**
     * @throws Throwable
     */
    public static function actualizarUsuario($data): bool
    {
        DB::beginTransaction();

        try {
            $usuario = Usuario::find($data->id);

            if (!$usuario) {
                return false;
            }

            $usuario->update([
                'nombre' => mb_strtoupper($data->nombre, 'UTF-8'),
                'email' => $data->email,
                'celular' => $data->celular,
            ]);

            self::sincronizarRelaciones($data->id, $data);

            DB::table('usuario')->where('id', $data->id)->update([
                'area' => $data->division
            ]);

            $division = DB::table('division')
                ->where('division', mb_strtoupper($data->division, 'UTF-8'))
                ->first();

            if ($division) {
                DB::table('usuario_division')
                    ->where('id_usuario', $data->id)
                    ->update(['id_division' => $division->id]);
            }

            $actuales = DB::table('usuario_empresa_almacen')
                ->where('id_usuario', $data->id)
                ->pluck('id_empresa_almacen')
                ->toArray();

            $addItems = array_diff($data->empresa_almacen, $actuales);
            foreach ($addItems as $item) {
                DB::table('usuario_empresa_almacen')->insert([
                    'id_usuario' => $data->id,
                    'id_empresa_almacen' => $item,
                ]);
            }

            $deleteItems = array_diff($actuales, $data->empresa_almacen);
            if (!empty($deleteItems)) {
                DB::table('usuario_empresa_almacen')
                    ->where('id_usuario', $data->id)
                    ->whereIn('id_empresa_almacen', $deleteItems)
                    ->delete();
            }

            DB::commit();

            return true;

        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
