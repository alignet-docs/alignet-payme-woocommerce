# Pay-me WooCommerce Plugin

Plugin oficial de Pay-me (Alignet) para integrar pagos en WooCommerce de forma rápida, segura y con una experiencia de checkout flexible.

## Descarga

Descarga la última versión del plugin desde GitHub Releases:

<https://github.com/alignet-docs/alignet-payme-woocommerce/releases/latest>

Si deseas ver todas las versiones publicadas:

<https://github.com/alignet-docs/alignet-payme-woocommerce/releases>

Para instalar el plugin en WordPress, descarga el archivo `.zip` adjunto en cada release.

Importante: no utilices `Code > Download ZIP` si ya existe un `.zip` oficial publicado en `Releases`.

## ¿Qué soluciones brinda?

Este plugin te ayuda a:

- Integrar Pay-me en WooCommerce sin desarrollo personalizado.
- Aceptar múltiples métodos de pago desde una sola pasarela.
- Ofrecer pagos con tarjeta, Yape, QR, transferencia bancaria, Cuotéalo BCP y PagoEfectivo.
- Mostrar los métodos de pago en modo embebido o en modal.
- Elegir si los métodos se presentan juntos en una sola pasarela o por separado.
- Confirmar pagos asíncronos mediante notificaciones S2S (server-to-server).
- Gestionar extornos/reembolsos desde WooCommerce.
- Consultar transacciones, logs y estadísticas desde el panel de administración.
- Usar el plugin tanto en checkout clásico como en WooCommerce Blocks.
- Trabajar con compatibilidad para HPOS de WooCommerce.

## Requisitos

- WordPress 5.0 o superior
- WooCommerce 3.0 o superior
- WooCommerce probado hasta 8.5
- PHP 7.4 o superior
- WooCommerce activo
- Credenciales activas de Pay-me:
  - `client_id`
  - `client_secret`
  - `merchant_code`

## Métodos de pago soportados

- Tarjeta de crédito y débito
- Yape
- Código QR
- Transferencia bancaria
- Cuotéalo BCP
- PagoEfectivo

## Instalación rápida

1. Descarga el archivo `.zip` desde la sección **Releases**.
2. Ingresa a tu panel de WordPress.
3. Ve a `Plugins > Añadir nuevo > Subir plugin`.
4. Selecciona el archivo `.zip` descargado.
5. Haz clic en `Instalar ahora`.
6. Activa el plugin.
7. Verifica que WooCommerce esté instalado y activo.

## Configuración

1. Ve a `WooCommerce > Ajustes > Pagos`.
2. Busca y selecciona `Payme`.
3. Habilita el método de pago.
4. Elige el ambiente de trabajo:
   - `Sandbox` para pruebas
   - `Producción` para ventas reales
5. Ingresa tus credenciales:
   - `client_id`
   - `client_secret`
   - `merchant_code`
6. Configura el país y la moneda.
7. Selecciona los métodos de pago que deseas mostrar.
8. Define el modo de visualización:
   - embebido en el checkout
   - modal en la misma página
9. Copia la `URL S2S` generada por el plugin y compártela con Pay-me si tu flujo requiere confirmación asíncrona.
10. Guarda los cambios.

## Notas importantes

- En producción se recomienda usar HTTPS y un certificado SSL válido.
- El estado final de algunos pagos puede confirmarse de manera asíncrona vía callback S2S, especialmente en métodos como QR, PagoEfectivo o transferencia bancaria.
- El modo debug debe utilizarse solo durante pruebas o troubleshooting.
- Para actualizar el plugin, descarga una nueva versión desde `Releases` e instálala sobre la versión existente.

## Soporte

Si necesitas ayuda o deseas reportar un problema, utiliza el repositorio de GitHub:

- Repositorio: <https://github.com/alignet-docs/alignet-payme-woocommerce>
- Releases: <https://github.com/alignet-docs/alignet-payme-woocommerce/releases>
- Issues: <https://github.com/alignet-docs/alignet-payme-woocommerce/issues>

Al reportar un incidente, incluye de ser posible:

- versión del plugin
- versión de WordPress
- versión de WooCommerce
- versión de PHP
- ambiente usado (`sandbox` o `producción`)
- logs o capturas del error

Importante: nunca compartas públicamente tu `client_secret`.

## Licencia

Este plugin se distribuye bajo licencia `GPL v2 or later`.
