# Autenticación y autorizaciones con TOTP

AFA usa códigos TOTP locales de seis dígitos, SHA-1 y períodos de 30 segundos. El mismo estándar funciona con Google Authenticator, Microsoft Authenticator y Authy; ninguna de esas aplicaciones es un proveedor obligatorio.

## Alcance

- El primer login correcto muestra un QR con vigencia de diez minutos. El registro queda habilitado únicamente después de validar el primer código.
- Los siguientes logins requieren contraseña y el código actual de la aplicación.
- La recuperación de contraseña requiere el TOTP ya configurado.
- Las confirmaciones sensibles que antes enviaban un código por WhatsApp ahora validan el TOTP del usuario autenticado o del usuario seleccionado para autorizar.
- Un código aceptado no puede reutilizarse. Cinco intentos fallidos bloquean las verificaciones durante cinco minutos.

`usuario_totp` guarda el secreto cifrado, el estado de alta, el último intervalo utilizado y los contadores de bloqueo. El secreto no forma parte de `usuario`, del JWT ni de las respuestas una vez terminada el alta.

Twilio ya no participa en códigos de autenticación o autorización. La dependencia se conserva porque AFA todavía envía notificaciones reales de tickets y mensajes de Mercado Libre por WhatsApp.

## Despliegue

1. Respaldar `APP_KEY` y conservar exactamente la misma clave en todas las instancias; cambiarla vuelve ilegibles los secretos cifrados.
2. Aplicar únicamente la migración `2026_09_09_130000_create_usuario_totp_table.php` antes de publicar el frontend.
3. Publicar backend y Angular coordinadamente.
4. Confirmar HTTPS y sincronización NTP del servidor.
5. Probar con una cuenta controlada: alta por QR, segundo login, rechazo de código repetido y una autorización sensible.

Las sesiones que ya existían no reciben el QR hasta el siguiente login. Mientras un usuario no haya completado esa alta, no podrá autorizar operaciones sensibles ni recuperar su contraseña.

## Recuperación por pérdida del dispositivo

No existe una ruta HTTP para reiniciar TOTP. Un operador debe verificar la identidad por un canal independiente, documentar el ticket y eliminar exclusivamente la fila exacta de `usuario_totp` dentro de una transacción. En el siguiente login, el usuario configura un QR nuevo con su contraseña. Nunca se debe mostrar, copiar o pedir el secreto cifrado ni hacer reinicios masivos.

## Verificación local

```powershell
php vendor/bin/phpunit --filter TotpAuthenticationTest
```

La prueba usa SQLite en memoria; no apunta a la base de negocio ni llama a Twilio.
