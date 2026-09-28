<?php

namespace App\Http\Controllers;

use App\Http\Services\Nexfira\NexfiraLocalResetService;
use App\Http\Services\Nexfira\NexfiraApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class DeveloperNexfiraController extends Controller
{
    private $service;

    public function __construct(NexfiraLocalResetService $service)
    {
        $this->service = $service;
    }

    public function inspect(Request $request, $documento)
    {
        return $this->handle($request, $documento, false);
    }

    public function reset(Request $request, $documento)
    {
        return $this->handle($request, $documento, true);
    }

    private function handle(Request $request, $documento, $write)
    {
        try {
            $auth = is_string($request->auth) ? json_decode($request->auth) : $request->auth;
            $id = $auth->id ?? 0;
            $allowed = $id && DB::table('usuario_subnivel_nivel as u')
                ->join('subnivel_nivel as n', 'n.id', '=', 'u.id_subnivel_nivel')
                ->join('subnivel as s', 's.id', '=', 'n.id_subnivel')
                ->where('u.id_usuario', $id)->where('n.id_nivel', 6)->where('n.id_subnivel', 1)->where('s.status', 1)->exists();
            if (!$allowed) {
                throw new AuthorizationException('Necesitas el permiso de Configuración / DEV para liberar intentos de Nexfira.');
            }
            if (!ctype_digit((string) $documento) || (int) $documento < 1) {
                throw new InvalidArgumentException('Ingresa un ID de documento válido.');
            }
            $manual = $request->input('manual_confirmation', []);
            if (!is_array($manual)) {
                throw new InvalidArgumentException('La confirmación manual no tiene un formato válido.');
            }
            $data = $write ? $this->service->reset((int) $documento, (int) $request->input('request_id'),
                $request->input('confirmation_token'), $request->input('reason'), (int) $id, $manual)
                : $this->service->inspect((int) $documento);
            return response()->json(['code' => 200, 'data' => $data]);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (NexfiraApiException $e) {
            return response()->json(['message' => 'No se pudo verificar el rechazo en Nexfira. No se modificó el intento.',
                'nexfira_code' => $e->getApiCode(), 'correlation_id' => $e->getCorrelationId()], 502);
        } catch (Throwable $e) {
            Log::error('Liberación local Nexfira: ' . get_class($e));
            return response()->json(['message' => 'No se pudo completar la operación. Consulta de nuevo antes de reintentar.'], 500);
        }
    }
}
