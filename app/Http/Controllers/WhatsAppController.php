<?php /** @noinspection PhpUnused */

namespace App\Http\Controllers;


use App\Http\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppController extends Controller
{
    public function whatsapp_validate(Request $request): JsonResponse
    {
        $authJson = $request->input('auth');
        $auth = json_decode($authJson, true);

        if (!isset($auth['id'], $auth['celular'])) {
            return response()->json([
                'code' => 400,
                'message' => 'Parámetros incompletos: id y celular requeridos',
            ], 400);
        }

        $whatsappService = new WhatsAppService();
        $response_whatsapp_service = $whatsappService->sendCode($auth['id'], $auth['celular']);

        $status = $response_whatsapp_service->getStatusCode();
        $responseData = $response_whatsapp_service->getData();

        return response()->json([
            'code' => $status,
            'message' => $responseData->message ?? 'Error al enviar el código',
            'data' => $responseData
        ], $status);
    }

}
