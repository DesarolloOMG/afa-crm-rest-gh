# Notas de crédito de facturas externas desde AFA

Contrato verificado el 20/10/2026:
https://hub.nexfira.mx/docs/contracts/integration-external-invoices.openapi.json

La integración con Bearer admite `POST /api/v1/integration/external-invoices/preview`
y `POST /api/v1/integration/external-invoices`. Requiere el permiso explícito
`external-invoices.import`, `documents.create` y grants I/E para el emisor.
La importación no verifica vigencia SAT: el operador debe confirmar vigencia,
saldos netos por concepto y siguiente parcialidad. La apertura es inmutable.

## Recorrido

En Facturación individual, pestaña Notas de crédito, **Preparar factura origen**
descarga el XML ya registrado, verifica identidad y solicita vista previa al proveedor.
El operador revisa UUID, ambiente, receptor y conceptos, captura saldos y una nota
de conciliación y confirma ambas declaraciones. **Confirmar factura origen** importa
la evidencia, sin emitir otra factura ni cambiar el UUID de la venta original.
Después se habilita **Timbrar NC**, con vista previa y envío explícito habituales.

El CRM conserva `proveedor=external`, payload original, archivos y UUID del ingreso.
Registra la respuesta de importación en `response_payload.afa_external_import`,
con hash del XML, usuario y fecha. La NC usa el requestId devuelto por Nexfira,
nunca el UUID fiscal como antecedentId. No requiere migración.

Los saldos se consultan de nuevo al enviar la NC. Las partidas externas se identifican
por descripción exacta, clave SAT, unidad, precio y tratamiento fiscal, no por posición.
Si no hay una correspondencia verificable, se bloquea el envío para conciliar.
Un antecedente externo con saldo no disponible no reutiliza la excepción histórica
para facturas PUE sin administración de saldo.

## Incidente NC 40680

La vista previa real del XML de la venta 23220 devuelve HTTP 422,
`invalid_fiscal_xml`, sin detalle de validación. Referencia de diagnóstico del
20/10/2026: `cd80a5b3-8c44-460e-9c37-ad7d0cd8d6ef`.
El archivo se identificó como CFDI 4.0 de ingreso del emisor AIN210708MR3.
No se modificó el XML, no se confirmó una apertura ni se envió esta NC.
El proveedor debe aclarar/corregir ese rechazo para concluir este caso específico.

## Validación

Pruebas locales cubren revisión sin importación, confirmaciones obligatorias,
XML cambiado, saldo excedido, rechazo del proveedor e importación con posterior
envío de NC utilizando las partidas y el antecedente importados. La respuesta
`pending_approval` de las pruebas es simulada; no acredita un timbrado real.
