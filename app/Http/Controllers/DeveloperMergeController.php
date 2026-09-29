<?php

namespace App\Http\Controllers;

use App\Http\Services\DeveloperMergeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class DeveloperMergeController extends Controller
{
    private $service;

    public function __construct(DeveloperMergeService $service)
    {
        $this->service = $service;
    }

    public function search(Request $request, $kind)
    {
        return $this->handle($request, $kind, 'search');
    }

    public function inspect(Request $request, $kind)
    {
        return $this->handle($request, $kind, 'inspect');
    }

    public function merge(Request $request, $kind)
    {
        return $this->handle($request, $kind, 'merge');
    }

    private function handle(Request $request, $kind, $action)
    {
        try {
            $auth = is_string($request->auth) ? json_decode($request->auth) : $request->auth;
            $userId = (int) ($auth->id ?? 0);
            $allowed = $userId && DB::table('usuario_subnivel_nivel as u')
                ->join('subnivel_nivel as n', 'n.id', '=', 'u.id_subnivel_nivel')
                ->join('subnivel as s', 's.id', '=', 'n.id_subnivel')
                ->where('u.id_usuario', $userId)->where('n.id_nivel', 6)
                ->where('n.id_subnivel', 1)->where('s.status', 1)->exists();
            if (!$allowed) {
                throw new AuthorizationException('Necesitas el permiso de Configuración / DEV.');
            }
            if ($action === 'search') {
                $data = $this->service->search($kind, $request->input('query', ''));
            } elseif ($action === 'inspect') {
                $data = $this->service->inspect($kind, $request->input('keep_id'), $request->input('remove_id'));
            } else {
                $data = $this->service->merge($kind, $request->all(), $userId);
            }
            return response()->json(['code' => 200, 'data' => $data]);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            Log::error('Conciliación DEV fallida', ['kind' => $kind, 'exception' => $e]);
            return response()->json(['message' => 'No se completó la conciliación. No se guardaron cambios; consulta de nuevo antes de reintentar.'], 500);
        }
    }
}
