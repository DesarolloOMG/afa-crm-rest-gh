<?php

namespace App\Http\Controllers;

use App\Http\Services\RefacturacionService;
use App\Http\Services\WhatsAppService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class RefacturacionController extends Controller
{
    private $service;

    public function __construct(RefacturacionService $service)
    {
        $this->service = $service;
    }

    public function preview(Request $request, $documento)
    {
        return $this->handle(function () use ($request, $documento) {
            $this->authorizedUser($request);
            return ['code' => 200, 'data' => $this->service->preview((int) $documento)];
        });
    }

    public function crear(Request $request)
    {
        return $this->handle(function () use ($request) {
            $userId = $this->authorizedUser($request);
            $data = $request->all();
            if (isset($data['data']) && is_string($data['data'])) {
                $data = json_decode($data['data'], true);
            }
            if (!is_array($data) || empty($data['documento']) || empty($data['receptor']) || !is_array($data['receptor'])) {
                throw new InvalidArgumentException('Indica la venta y los datos fiscales del nuevo cliente.');
            }
            $result = $this->service->create((int) $data['documento'], $data['receptor'], $userId, function () use ($userId, $data) {
                $validation = WhatsAppService::validateCode($userId, (string) ($data['token'] ?? ''));
                if ($validation->error) {
                    throw new AuthorizationException(strip_tags($validation->mensaje));
                }
            });
            return ['code' => 200, 'message' => 'Refacturación preparada. Continúa con el timbrado individual de la NC y del pedido nuevo.', 'data' => $result];
        });
    }

    public function cliente(Request $request, $documento)
    {
        return $this->handle(function () use ($request, $documento) {
            $userId = $this->authorizedUser($request);
            if ($request->isMethod('GET')) {
                return ['code' => 200, 'data' => $this->service->previewClient((int) $documento)];
            }
            $recipient = $request->input('receptor');
            if (!is_array($recipient)) { throw new InvalidArgumentException('Captura los datos fiscales del cliente.'); }
            return ['code' => 200, 'data' => $this->service->updateClient((int) $documento, $recipient, $userId)];
        });
    }

    private function authorizedUser(Request $request)
    {
        $auth = is_string($request->auth) ? json_decode($request->auth) : $request->auth;
        $id = (int) ($auth->id ?? 0);
        if (!$id || !DB::table('usuario_subnivel_nivel as u')
            ->join('subnivel_nivel as n', 'n.id', '=', 'u.id_subnivel_nivel')
            ->join('subnivel as s', 's.id', '=', 'n.id_subnivel')
            ->where('u.id_usuario', $id)->where('n.id_nivel', 11)->where('n.id_subnivel', 36)
            ->where('s.status', 1)->exists()) {
            throw new AuthorizationException('Necesitas el permiso Contabilidad: Facturación y Timbrado para refacturar.');
        }
        return $id;
    }

    private function handle(callable $action)
    {
        try {
            return response()->json($action());
        } catch (AuthorizationException $e) {
            return response()->json(['code' => 403, 'message' => $e->getMessage()], 403);
        } catch (InvalidArgumentException $e) {
            return response()->json(['code' => 422, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            Log::error('Refacturación contable fallida', ['exception' => $e]);
            return response()->json(['code' => 500, 'message' => 'No se completó la refacturación. Puedes reintentar: no se duplicará la operación.'], 500);
        }
    }
}
