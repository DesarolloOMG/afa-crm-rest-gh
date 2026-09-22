<?php

namespace App\Http\Services\Nexfira;

use GuzzleHttp\Client;
use Throwable;

class NexfiraClient
{
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function createDocumentRequest(array $payload, $idempotencyKey)
    {
        return $this->jsonRequest('POST', '/api/v1/document-requests', [
            'headers' => ['Idempotency-Key' => $idempotencyKey],
            'json' => $payload,
        ], [200, 202]);
    }

    public function getDocumentRequest($requestId)
    {
        return $this->jsonRequest(
            'GET',
            '/api/v1/integration/document-requests/' . rawurlencode($requestId),
            [],
            [200]
        );
    }

    public function downloadDocument($requestId, $format)
    {
        if (!in_array($format, ['xml', 'pdf'], true)) {
            throw new NexfiraApiException('Formato de documento Nexfira no soportado.', 400, 'invalid_format');
        }

        $response = $this->request(
            'GET',
            '/api/v1/integration/document-requests/' . rawurlencode($requestId) . '/documents/' . $format,
            [],
            [200]
        );

        return (string) $response->getBody();
    }

    public function getPaymentBalance($requestId, $fiscal = false)
    {
        return $this->jsonRequest('GET', '/api/v1/integration/document-requests/'
            . rawurlencode($requestId) . ($fiscal ? '/payment-fiscal-balance' : '/payment-balance'), [], [200]);
    }

    private function jsonRequest($method, $path, array $options, array $successStatuses)
    {
        $response = $this->request($method, $path, $options, $successStatuses);
        $raw = (string) $response->getBody();
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            throw new NexfiraApiException('Nexfira respondió JSON inválido.', 502, 'invalid_response');
        }

        return $data;
    }

    private function request($method, $path, array $options, array $successStatuses)
    {
        $baseUrl = rtrim((string) config('nexfira.base_url'), '/');
        $token = trim((string) config('nexfira.integration_token'));

        if ($baseUrl === '' || $token === '') {
            throw new NexfiraApiException(
                'Falta configurar NEXFIRA_BASE_URL o NEXFIRA_INTEGRATION_TOKEN.',
                503,
                'nexfira_not_configured'
            );
        }

        $headers = isset($options['headers']) ? $options['headers'] : [];
        $options['headers'] = array_merge([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json, application/xml, application/pdf',
        ], $headers);
        $options['http_errors'] = false;
        $options['connect_timeout'] = 5;
        $options['timeout'] = max(1, (int) config('nexfira.request_timeout', 30));
        $options['verify'] = true;

        try {
            $response = $this->client->request($method, $baseUrl . $path, $options);
        } catch (Throwable $e) {
            throw new NexfiraApiException('No fue posible comunicarse con Nexfira.', 503, 'nexfira_unavailable');
        }

        $status = (int) $response->getStatusCode();
        if (in_array($status, $successStatuses, true)) {
            return $response;
        }

        $raw = (string) $response->getBody();
        $error = json_decode($raw, true);
        $message = is_array($error) && !empty($error['message'])
            ? $error['message']
            : 'Nexfira rechazó la solicitud.';

        throw new NexfiraApiException(
            $message,
            $status,
            is_array($error) ? ($error['code'] ?? null) : null,
            is_array($error) ? ($error['correlationId'] ?? null) : null,
            is_array($error) && is_array($error['errors'] ?? null) ? $error['errors'] : [],
            is_array($error) ? $error : []
        );
    }
}
