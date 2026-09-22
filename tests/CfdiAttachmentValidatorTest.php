<?php

use App\Http\Services\Nexfira\CfdiAttachmentValidator;

class CfdiAttachmentValidatorTest extends TestCase
{
    public function testValidatesPdfXmlUuidTotalAndHashes()
    {
        $uuid = '123E4567-E89B-42D3-A456-426614174000';
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Version="4.0" Serie="AFA" Folio="12345" Total="116.00">'
            . '<cfdi:Complemento><tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" UUID="' . $uuid . '" /></cfdi:Complemento>'
            . '</cfdi:Comprobante>';
        $pdf = "%PDF-1.4\ncontenido";
        $validator = new CfdiAttachmentValidator();

        $result = $validator->validate($pdf, $xml, $uuid, 116.00);

        $this->assertSame($uuid, $result['uuid']);
        $this->assertSame('AFA', $result['serie']);
        $this->assertSame('12345', $result['folio']);
        $this->assertSame(hash('sha256', $xml), $result['xml_sha256']);
        $this->assertSame(hash('sha256', $pdf), $result['pdf_sha256']);
    }

    public function testRejectsMismatchedUuid()
    {
        $xml = '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Total="116.00">'
            . '<cfdi:Complemento><tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" UUID="123E4567-E89B-42D3-A456-426614174000" /></cfdi:Complemento>'
            . '</cfdi:Comprobante>';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no coincide');
        (new CfdiAttachmentValidator())->validate('%PDF-1.4', $xml, '223E4567-E89B-42D3-A456-426614174000', 116.00);
    }
}
