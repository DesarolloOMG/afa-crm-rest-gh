
# 📚 API - Módulo Contabilidad

> Documentación oficial del módulo Contabilidad.
> Actualizado a Junio 2025.

---

## 🗂️ Índice rápido

- [Facturas](#facturas)
- [Compras y Gastos](#compras-y-gastos)
- [Estado de cuenta](#estado-de-cuenta)
- [Ingresos y Egresos](#ingresos-y-egresos)
- [Globalizar/Desglobalizar](#globalizardesglobalizar-documentos)
- [Tesorería](#tesorería)

---

## 💼 Facturas

### 1. Obtener facturas pendientes

`POST /contabilidad/facturas/pendiente/data`

**Body:**  
```json
{}
```

**Respuesta:**
```json
{
  "code": 200,
  "ventas": [ { ... } ]
}
```

---

### 2. Obtener ingresos/NC disponibles para saldar

`GET /contabilidad/facturas/saldar/data`

**Respuesta:**
```json
{
  "code": 200,
  "ingresos": [ ... ],
  "documentos": [ ... ]
}
```

---

### 3. Aplicar ingreso a documentos

`POST /contabilidad/facturas/saldar/guardar`

**Body:**
```json
{
  "id_ingreso": 1,
  "documentos": [
    { "id": 55, "monto": 1200.00, "moneda": "MXN", "tipo_cambio_aplicado": 1 }
  ]
}
```
**Respuesta:**
```json
{
  "code": 200,
  "msg": "Ingreso aplicado correctamente",
  "resultados": [ ... ]
}
```

---

### 4. Buscar relación ingreso-documento

`GET /contabilidad/facturas/dessaldar/data?busqueda={folio_ingreso_o_id_documento}`

**Respuesta:**
```json
{
  "code": 200,
  "tipo": "ingreso",
  "ingreso": { ... },
  "documentos": [ ... ]
}
```

---

### 5. Quitar la relación (dessaldar)

`POST /contabilidad/facturas/dessaldar/guardar`

**Body:**
```json
{
  "id_ingreso": 1,
  "id_documento": 55
}
```

**Respuesta:**
```json
{
  "code": 200,
  "msg": "Monto dessaldado correctamente",
  "nuevo_saldo": 1000.00
}
```

---

## 🧾 Compras y Gastos

### 1. Buscar proveedor

`POST /contabilidad/compras-gastos/gasto/proveedor/buscar`

**Body:**
```json
{
  "query": "Proveedor SA"
}
```
**Respuesta:**
```json
{
  "code": 200,
  "proveedores": [ { ... } ]
}
```

---

### 2. Crear gasto

`POST /contabilidad/compras-gastos/gasto/crear`

**Body:**
```json
{
  "data": "{...}" 
}
```
**Respuesta:**
```json
{
  "code": 200,
  "message": "Gasto creado correctamente.",
  "id_documento": 122
}
```

---

### 3. Obtener compras/egresos del proveedor

`POST /contabilidad/compras-gastos/compras/data`

**Body:**
```json
{
  "razon_social": "Proveedor SA"
}
```
**Respuesta:**
```json
{
  "code": 200,
  "proveedores": [ ... ],
  "documentos": [ ... ],
  "egresos": [ ... ]
}
```

---

### 4. Aplicar egreso a documento

`POST /contabilidad/compras-gastos/compras/aplicar`

**Body:**
```json
{
  "id_egreso": 2,
  "id_documento": 55,
  "monto": 1000
}
```
**Respuesta:**
```json
{
  "code": 200,
  "msg": "Egreso aplicado correctamente",
  "resultados": [ ... ]
}
```

---

## 📊 Estado de cuenta

### 1. Reporte estado de cuenta facturas (Excel)

`POST /contabilidad/estado/factura/reporte`

**Body:**
```json
{
  "data": {
    "entidad": { "tipo": "Clientes", "select": "RFC123" },
    "fecha_inicial": "2025-01-01",
    "fecha_final": "2025-06-28"
  }
}
```
**Respuesta:**
```json
{
  "code": 200,
  "excel": "archivo_base64...",
  "facturas": [ ... ]
}
```

---

### 2. Reporte estado de cuenta ingresos (Excel)

`POST /contabilidad/estado/ingreso/reporte`

**Body:**
```json
{
  "data": {
    "entidad": { "tipo": "Clientes", "select": "RFC123" },
    "fecha_inicio": "2025-01-01",
    "fecha_final": "2025-06-28"
  }
}
```
**Respuesta:**
```json
{
  "code": 200,
  "excel": "archivo_base64...",
  "ingresos": [ ... ]
}
```

---

## 💸 Ingresos y Egresos

### 1. Catálogos para crear ingresos/egresos

`GET /contabilidad/ingreso/generar/data`

**Respuesta:**
```json
{
  "code": 200,
  "afectaciones": [ ... ],
  "entidades": [ ... ],
  "formas_pago": [ ... ],
  "divisas": [ ... ],
  "entidades_financieras": [ ... ],
  "bancos": [ ... ],
  "tipos_entidad_financiera": [ ... ]
}
```

---

### 2. Crear ingreso/egreso

`POST /contabilidad/ingreso/generar/crear`

**Body:**
```json
{
  "monto": 1500.50,
  "id_tipo_afectacion": 1,
  "fecha_operacion": "2025-06-28",
  "id_moneda": 1,
  "entidad_origen": 7,
  "entidad_destino": 9
}
```
**Respuesta:**
```json
{
  "code": 200,
  "message": "Flujo registrado correctamente.",
  "id_movimiento": 112
}
```

---

### 3. Editar cliente/proveedor de ingreso/egreso

`POST /contabilidad/ingreso/editar/cliente`

**Body:**
```json
{
  "data": {
    "movimiento": "FOLIO123",
    "cliente": { "rfc": "RFC987" }
  }
}
```
**Respuesta:**
```json
{
  "code": 200,
  "message": "Ingreso/Egreso editado correctamente."
}
```

---

### 4. Catálogos de historial de movimientos

`GET /contabilidad/ingreso/historial/data`

**Respuesta:**
```json
{
  "code": 200,
  "entidades_financieras": [ ... ],
  "tipos_afectacion": [ ... ]
}
```

---

### 5. Buscar movimientos por filtros

`POST /contabilidad/ingreso/historial/buscar`

**Body:**
```json
{
  "cuenta": 1,
  "tipo_afectacion": 2,
  "fecha_inicio": "2025-01-01",
  "fecha_final": "2025-06-28",
  "folio": "FOLIO123"
}
```
**Respuesta:**
```json
{
  "code": 200,
  "excel": "archivo_base64...",
  "movimientos": [ ... ]
}
```

---

## 🌐 Globalizar/Desglobalizar documentos

### 1. Globalizar documentos

`POST /contabilidad/globalizar`
```json
{
  "uuid": "uuid-123",
  "documentos": [10, 11, 12]
}
```
**Respuesta:**
```json
{
  "code": 200,
  "documentos_afectados": [10,12],
  "documentos_no_afectados": [11]
}
```

### 2. Desglobalizar documentos

`POST /contabilidad/desglobalizar`
```json
{
  "uuid": "uuid-123"
}
```
**Respuesta:**
```json
{
  "code": 200,
  "documentos_afectados": [10,12],
  "documentos_no_afectados": []
}
```

---

## 🏦 Tesorería

### 1. Listar monedas

`GET /contabilidad/tesoreria/data`

### 2. Buscar bancos por nombre

`POST /contabilidad/tesoreria/bancos/buscar`
```json
{ "banco": "Santander" }
```

### 3. Crear cuenta bancaria

`POST /contabilidad/tesoreria/cuenta/crear`
```json
{
  "nombre": "Mi cuenta MXN",
  "id_banco": 1,
  "id_moneda": 1,
  "no_cuenta": "1234567890",
  "clabe": "002010077777777777",
  "sucursal": "Sucursal Centro"
}
```

### 4. Editar cuenta bancaria

`POST /contabilidad/tesoreria/cuenta/editar`
```json
{
  "id": 2,
  "nombre": "Cuenta nueva",
  "id_banco": 2,
  "id_moneda": 1,
  "clabe": "002010077777777777",
  "no_cuenta": "999999",
  "sucursal": "Sucursal Norte"
}
```

### 5. Eliminar cuenta bancaria

`DELETE /contabilidad/tesoreria/cuenta/eliminar/2`

---

### 6. (Idem para cajas chicas, acreedores, deudores y bancos. Ver las rutas en la tabla principal).

---

## 📝 Notas y convenciones

- Todas las respuestas llevan siempre `code` y `message` o el listado solicitado.
- Todos los endpoints requieren autenticación (token JWT u otro).
- Los reportes (Excel) se retornan como campo `excel` en base64.
- Fechas en formato `YYYY-MM-DD`.
- **Cualquier duda, consulta o ajuste, comunicar con el área de TI.**

---

## ✉️ ¿Dudas, mejoras o bugs?
Levantar ticket en el sistema interno de soporte o contactar a desarrollo.

---
