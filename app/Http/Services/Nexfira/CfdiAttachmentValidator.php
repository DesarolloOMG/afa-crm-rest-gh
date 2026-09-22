<?php

namespace App\Http\Services\Nexfira;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;

class CfdiAttachmentValidator
{
    const MAX_FILE_BYTES = 10485760;

    public function decodeDataUrl($value, $type)
    {
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('El archivo ' . strtoupper($type) . ' es obligatorio.');
        }

        $encoded = $value;
        $comma = strpos($value, ',');
        if (strpos($value, 'data:') === 0) {
            if ($comma === false || stripos(substr($value, 0, $comma), ';base64') === false) {
                throw new InvalidArgumentException('El archivo ' . strtoupper($type) . ' no usa Base64 válido.');
            }
            $encoded = substr($value, $comma + 1);
        }

        $decoded = base64_decode($encoded, true);
        if ($decoded === false || $decoded === '') {
            throw new InvalidArgumentException('No fue posible decodificar el archivo ' . strtoupper($type) . '.');
        }
        if (strlen($decoded) > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException('El archivo ' . strtoupper($type) . ' excede 10 MiB.');
        }

        return $decoded;
    }

    public function validate($pdf, $xml, $expectedUuid = null, $expectedTotal = null)
    {
        if (substr($pdf, 0, 5) !== '%PDF-') {
            throw new InvalidArgumentException('El archivo PDF no tiene una cabecera válida.');
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new InvalidArgumentException('El XML no es un CFDI legible.');
        }

        $xpath = new DOMXPath($dom);
        $comprobante = $xpath->query('/*[local-name()="Comprobante"]')->item(0);
        $timbre = $xpath->query('//*[local-name()="TimbreFiscalDigital"]')->item(0);
        if (!$comprobante || !$timbre) {
            throw new InvalidArgumentException('El XML no contiene Comprobante y TimbreFiscalDigital.');
        }

        $uuid = strtoupper(trim((string) $timbre->getAttribute('UUID')));
        if (!preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[1-5][0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/', $uuid)) {
            throw new InvalidArgumentException('El XML no contiene un UUID fiscal válido.');
        }
        if ($expectedUuid && strtoupper(trim((string) $expectedUuid)) !== $uuid) {
            throw new InvalidArgumentException('El UUID capturado no coincide con el TimbreFiscalDigital del XML.');
        }

        $total = $comprobante->getAttribute('Total');
        if ($total === '' || !is_numeric($total)) {
            throw new InvalidArgumentException('El XML no contiene un total fiscal válido.');
        }
        if ($expectedTotal !== null && abs((float) $expectedTotal - (float) $total) > 0.05) {
            throw new InvalidArgumentException(
                'El total del XML $' . number_format((float) $total, 2, '.', '') .
                ' no coincide con las ventas seleccionadas $' . number_format((float) $expectedTotal, 2, '.', '') . '.'
            );
        }

        $related = [];
        foreach ($xpath->query('/*[local-name()="Comprobante"]/*[local-name()="CfdiRelacionados"]/*[local-name()="CfdiRelacionado"]') as $node) {
            $related[] = strtoupper(trim($node->getAttribute('UUID')));
        }
        $receiver = $xpath->query('/*[local-name()="Comprobante"]/*[local-name()="Receptor"]')->item(0);

        return [
            'uuid' => $uuid,
            'type' => strtoupper(trim($comprobante->getAttribute('TipoDeComprobante'))),
            'currency' => strtoupper(trim($comprobante->getAttribute('Moneda'))),
            'receiver_rfc' => $receiver ? strtoupper(trim($receiver->getAttribute('Rfc'))) : '',
            'related_uuids' => $related,
            'serie' => trim((string) $comprobante->getAttribute('Serie')),
            'folio' => trim((string) $comprobante->getAttribute('Folio')),
            'total' => number_format((float) $total, 2, '.', ''),
            'xml_sha256' => hash('sha256', $xml),
            'pdf_sha256' => hash('sha256', $pdf),
        ];
    }
}
