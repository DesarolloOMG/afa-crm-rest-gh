# AFA Móvil

Aplicación Android nativa en Kotlin y Jetpack Compose. Usa el backend existente del CRM; no carga Angular en un WebView. Interfaz en español, resultados en tarjetas, detalle por secciones, formularios por pasos, navegación Atrás y selección de archivos de Android.

## Abrir, compilar e instalar

Abre **esta carpeta `android`** como proyecto en Android Studio. Requiere SDK 36 y Java 17 o superior. `local.properties` contiene la ruta del SDK de cada equipo y no se versiona.

En este equipo:

```powershell
$env:JAVA_HOME='C:\Program Files\Android\Android Studio\jbr'
$env:Path="$env:JAVA_HOME\bin;$env:Path"
.\gradlew.bat :app:testDebugUnitTest :app:lintDebug :app:assembleDebug
```

APK: `app/build/outputs/apk/debug/app-debug.apk`. Android 8.0 o posterior. Versión actual `0.2.0-debug` (código 2), paquete `com.afainnova.crm.debug`. Está firmado con la clave de depuración del entorno; **es una entrega de pruebas**, no una publicación en Play Store. Para distribución estable se debe configurar una clave de firma propia de AFA; no reutilizar claves de otras aplicaciones.

El servidor se define en `app/build.gradle.kts`: `https://rest.afainnova.com/`. El APK operativo inicia en login y no incorpora cuentas de prueba, contraseñas, credenciales de Dropbox ni del proveedor fiscal. Las respuestas ficticias están exclusivamente en `src/androidTest` y `src/test`.

## Funcionalidad implementada

### Diseño móvil 0.2.0

* Navegación azul oscuro, títulos en negrita y secciones con marcador azul. Inicio agrupado en operación, inventario y configuración; cada módulo tiene icono y acento de color.
* Acciones principales sólidas en azul; acciones secundarias con fondo azul claro y flecha; acciones destructivas en rojo. Guardar/cancelar permanecen en una barra fija, también con el teclado abierto.
* Campos blancos con borde visible, etiquetas destacadas y estado de enfoque azul. Selectores y fechas tienen una zona de icono propia; interruptores se presentan dentro de una fila delimitada.
* Acordeones con cabecera azul oscuro al abrirse, control +/− y texto «Ver/Ocultar información». El contenido se muestra sobre blanco con etiquetas y valores diferenciados. El estado de apertura se conserva al recrear la pantalla y se anuncia a accesibilidad.
* Tarjetas de resultados con tipo de registro, identidad, estado cuando existe, importe destacado y acceso «Ver detalle». Ventas tienen pasos numerados y total en un bloque de alto contraste; facturación mantiene visibles el receptor y la acción principal.

El rediseño conserva los contratos, permisos y alcance funcional de la versión inicial. No añade funciones pendientes del front.

| Sección | Flujos nativos |
|---|---|
| Documentos | Los diez criterios del front: pedido, número de venta, NC, RFC, nombre, correo, referencia, observación, comentario y guía. Venta y nota de crédito son resultados distintos. Detalle, productos/series, guías, seguimiento, movimientos, archivos, PDF/XML, garantía, NC vinculada, información de Mercado Libre, cliente fiscal y refacturación. |
| Soporte | Crear con adjuntos, historial y filtros, pendientes de asignación, asignar técnico, pendientes de resolución, iniciar revisión, resolver y abrir archivos. |
| Series | Búsqueda, historial por empresa, movimientos e impresión de etiqueta en las impresoras del servidor. |
| Consulta de productos | Empresa/almacén, existencia, etiquetas, detalle, imágenes/archivos cuando el servidor los devuelve, kardex con fechas y tipo de documento, Excel, últimos movimientos y antigüedad, sinónimos y previsualizar/aplicar costo para administradores. |
| Gestión de productos | Crear/editar SKU, descripción, tipo, series, caducidad, reacondicionado, costos, dimensiones, claves SAT, categorías, precios por empresa, vínculos con productos de proveedores, imágenes y Excel de códigos/precios del formulario de gestión. |
| Clientes y proveedores | Buscar, crear y editar datos fiscales, contacto, país, condición de pago, relación cliente/proveedor y límite de crédito del cliente. |
| Ventas | Crear por pasos, consultar/importar la venta de Mercado Libre conectada en el front, elegir cliente y productos, promociones, validar existencia y crédito, editar por pedido respetando fases, adjuntos/seguimiento, condiciones de pago, cotización de envío en edición, eliminar con código del autorizador, listar pendientes y convertir pedido a venta. |
| Facturación | Drop/Full, ventas/NC, búsqueda y paginación, selección entre páginas o por IDs, previsualización, serie/folio editables, método/forma de pago, información global, individual, global por venta/producto, vincular CFDI externo PDF/XML, consultar estado y continuar desde documentos de refacturación. |
| Usuarios | Crear/editar, empresas, permisos por módulo, marketplaces/áreas y almacenes; desactivar con confirmación. |
| Marketplaces | Crear/editar área, empresa, utilidad, series, opciones de API/guía y acceso a credenciales con autorización. |
| Almacenes | Listar, crear y eliminar con confirmación. No se inventa un flujo de edición que el front no ofrece. |

Los módulos y acciones utilizan los permisos del usuario. Facturación requiere explícitamente nivel 11/subnivel 36: tener nivel de administrador no sustituye ese permiso. El servidor conserva la autoridad para autorizar cada operación.

## Alcance excluido por instrucción del usuario

Sólo se migraron flujos conectados del front revisado en `E:\afa-crm-angular-gh`.

* **Gestión de paqueterías:** la pantalla `configuracion/sistema/paqueteria/paqueteria.component.html` contiene `app-under-construction`. Las paqueterías existentes sí se seleccionan para el envío de una venta.
* **Registro de cobro/cuentas dentro de crear venta:** `venta/venta/crear/crear.component.html` lo mantiene dentro de un bloque `hidden`; las listas de cuentas no tienen carga conectada en ese componente. Se conservan el plazo de pago y los datos de cobro que ya devuelve/importa el flujo; no se expone el formulario oculto ni la creación de cuentas bancarias.
* **Adaptadores de importación dormidos:** el `switch` activo de `crear.component.ts` sólo conecta Mercado Libre y `buscarVenta` restringe esa consulta a marketplace 1. No se activan por cuenta propia los métodos sueltos de otros proveedores.
* **Botón antiguo “Generar nota de crédito” del detalle:** el template pasa `final_data.documento` a una función que lo interpreta como `refacturado` y bloquea todos los IDs válidos. No se traslada ese botón roto. Se incluyen consulta/PDF de NC existentes y generación de NC mediante el flujo definido de refacturación.
* No se migran módulos fuera de lo solicitado, como compras, picking, packing, reportes generales ni las pantallas independientes de importación/categorías/sinónimos del catálogo.

## Reglas relevantes

* Sesión guardada con Android Keystore AES/GCM; caducidad del JWT y cierre ante 401. MFA y configuración inicial mediante QR. No se guarda la contraseña. Pantalla protegida frente a capturas durante login o consulta de credenciales.
* El middleware existente recibe el JWT en el parámetro `token`. No se registran URLs autenticadas. `Encoding.DATA`, `FIELDS` y `JSON` reproducen las tres variantes del backend; el código de autorización de refacturación permanece en el cuerpo, separado del JWT de sesión.
* No hay reintento automático de escrituras. Guardado protegido contra doble toque y confirmación para operaciones relevantes. Atrás cancela consultas lentas; las escrituras ya enviadas esperan el resultado.
* Una solicitud de facturación aceptada es **pendiente**, no un CFDI terminado. Antes de enviar se vuelve a consultar la selección. Un documento con solicitud activa o `uncertain` debe actualizar/conciliar su estado antes de un nuevo envío. La app no asigna fases fiscales ni fabrica UUID.
* CFDI externo: se comprueba XML, namespace, timbre, UUID y tipo I/E; el backend valida su correspondencia fiscal. El parser rechaza entidades externas/DOCTYPE. Adjuntos individuales de hasta 12 MB, lectura limitada, intercambio con FileProvider y selector de Android.
* Cálculos monetarios con `BigDecimal`. El guardado conserva el total original del marketplace, calcula `total_user`, comprueba existencia agregada por SKU y marca baja utilidad siguiendo el flujo de autorización.
* Al editar se conserva el ID solicitado del pedido, aunque las columnas combinadas de la respuesta traigan otro `id`; también se preservan entidad, líneas, precios y datos no editables por fase.
* Las respuestas y formularios sólo viven en memoria durante la sesión; no hay cola de ventas offline ni timbrado sin conexión. La sesión permanece cifrada, pero un borrador no enviado puede perderse si Android termina el proceso.

## Pruebas y evidencia

`ContractTest` y `WorkflowTest` cubren codificaciones HTTP, separación venta/NC, permisos, conservación de datos al editar, límites de adjuntos, XML, baja utilidad, stock agregado, cancelación de consultas, errores recuperables, autorización de eliminación y prevención de duplicados.

`MobileFlowTest` ejecuta los recorridos nativos en un emulador Android 15 con transporte ficticio: tarjetas/detalle largo, recreación/rotación, NC, crear/asignar ticket, formularios, almacenes, series/productos, asistente de ventas y solicitud fiscal pendiente. No realiza llamadas al servidor real.

```powershell
.\gradlew.bat :app:assembleDebug :app:assembleDebugAndroidTest
adb -s emulator-5556 install -r app/build/outputs/apk/debug/app-debug.apk
adb -s emulator-5556 install -r app/build/outputs/apk/androidTest/debug/app-debug-androidTest.apk
adb -s emulator-5556 shell am instrument -w com.afainnova.crm.debug.test/androidx.test.runner.AndroidJUnitRunner
```

Entrega inicial: `../artifacts/android-20260928/`. APK, código fuente, reportes y capturas del rediseño 0.2.0: `../artifacts/android-ui-20260929/`. Las capturas muestran datos ficticios identificados como prueba.

**Límite de la evidencia:** compilación, lint, contratos y recorridos simulados no demuestran autenticación con una cuenta real, compatibilidad con todas las respuestas de producción, timbrado efectivo, recepción de notificaciones ni comportamiento en un teléfono físico. No se desplegaron cambios al servidor ni se ejecutaron ventas, cancelaciones o timbrados reales durante esta implementación.

## Organización

`CrmViewModel.kt` contiene estado, navegación y coordinación de flujos. `data/CrmRepository.kt` adapta endpoints; `Forms.kt` define campos de los formularios; `MarketplaceImport.kt` mapea Mercado Libre; `FileRules.kt` valida archivos. `ui/` contiene componentes y pantallas Compose. `MainActivity` integra selección/apertura/compartición de archivos y ciclo de vida.
