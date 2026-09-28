# Entrega local · AFA Móvil 0.1.0

* [APK Android de pruebas](afa-movil-0.1.0-debug.apk), Android 8 o posterior, aproximadamente 20.5 MB.
* [Proyecto Kotlin/Compose](afa-movil-0.1.0-source.zip). Descomprimir y abrir la carpeta `android` en Android Studio.
* [Alcance, exclusiones y uso](../../android/README.md).
* [Resultados y SHA-256](verification.json).

Compilación y lint satisfactorios; 39 pruebas de lógica/contratos y 9 recorridos de interfaz satisfactorios en emulador Android 15. La firma de depuración y el manifiesto se verificaron con `apksigner` y `aapt`; los resultados están en `apk-signature.txt` y `apk-metadata.txt`.

Los recorridos usan datos ficticios exclusivamente en el APK de pruebas instrumentadas. El APK entregado inicia con autenticación contra el backend configurado y no contiene una cuenta demo ni datos de prueba operativos. No se realizaron operaciones en producción ni pruebas en un teléfono físico.

Capturas del emulador:

* [Inicio](verification/home.png)
* [Búsqueda en tarjetas](verification/document-search.png)
* [Detalle vertical](verification/document-detail.png)
* [Detalle horizontal](verification/document-landscape.png)
* [Venta por pasos](verification/sale-products.png)
* [Facturación](verification/billing-preview.png)

El código no incluye el módulo de paqueterías en construcción ni los bloques ocultos/desconectados especificados en el alcance. El archivo ZIP no incluye SDK local, cachés de compilación, claves de firma ni credenciales.
