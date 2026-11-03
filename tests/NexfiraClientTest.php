<?php

use App\Http\Services\Nexfira\NexfiraClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

class NexfiraClientTest extends TestCase
{
    public function testInconsistentTotalsAreRejectedBeforeAnyHttpRequest()
    {
        $mock = new MockHandler([]);
        $client = new NexfiraClient(new Client(['handler' => HandlerStack::create($mock)]));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('total fiscal');
        $client->createDocumentRequest(['kind' => 'CFDI_I', 'content' => [
            'items' => [['quantity' => '1', 'unitPrice' => '100.00', 'discount' => '0.00', 'taxes' => []]],
            'expectedTotals' => ['subtotal' => '100.00', 'discount' => '0.00', 'transfers' => '0.00',
                'withholdings' => '0.00', 'total' => '100.01'],
        ]], 'must-not-be-sent');
    }

    public function testSendsOpaqueBearerAndIdempotencyKey()
    {
        config([
            'nexfira.base_url' => 'https://hub.example.test',
            'nexfira.integration_token' => 'hub_int_test.secret',
        ]);
        $history = [];
        $mock = new MockHandler([
            new Response(202, ['Content-Type' => 'application/json'], json_encode([
                'requestId' => '123e4567-e89b-42d3-a456-426614174000',
                'status' => 'pending_approval',
                'version' => 1,
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $client = new NexfiraClient(new Client(['handler' => $stack]));

        $result = $client->createDocumentRequest(['schemaVersion' => '1.0'], 'afa-test-key');

        $this->assertSame('pending_approval', $result['status']);
        $this->assertSame('Bearer hub_int_test.secret', $history[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame('afa-test-key', $history[0]['request']->getHeaderLine('Idempotency-Key'));
        $this->assertSame('/api/v1/document-requests', $history[0]['request']->getUri()->getPath());
    }
}
