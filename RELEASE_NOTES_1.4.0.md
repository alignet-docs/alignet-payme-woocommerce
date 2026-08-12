# Pay-me Gateway para WooCommerce — Versión 1.4.0

**Fecha de versión:** 11 de agosto de 2026  
**Versión anterior:** 1.3.7  
**Tipo de versión:** Minor release compatible hacia atrás  
**Estado:** Lista para empaquetado y validación final en staging

## Resumen

Pay-me Gateway 1.4.0 incorpora una pantalla homologada de resultado de pago y generación segura del número de operación.

La versión conserva los flujos existentes de autorización, callbacks, notificaciones S2S y extornos, modificándolos únicamente donde fue necesario para identificar correctamente cada operación, interpretar el estado financiero y presentar el resultado al comprador.

## Mejoras principales

### 1. Número de operación Pay-me

- `merchant_operation_number` exclusivamente numérico.
- Generación aleatoria de 9 dígitos, dentro del rango permitido de 7 a 12 caracteres.
- No utiliza el ID interno ni un correlativo simple de WooCommerce.
- Verificación previa contra las operaciones del plugin.
- Reserva atómica en `wp_payme_transactions` antes de enviarlo a Pay-me.
- Protección adicional mediante el índice único de la tabla.
- Reintentos seguros ante una posible colisión.
- Persistencia en la transacción y en metadata interna del pedido.
- Diferenciación explícita entre:
  - `merchant_operation_number`: operación enviada a Pay-me.
  - `order_id`: identificador interno de WooCommerce.
  - `transaction_id`: identificador devuelto por Pay-me.

### 2. Validación del estado financiero

- Una respuesta HTTP o un `success` del SDK no autoriza por sí solo el pago.
- La autorización se reconoce estrictamente cuando `transaction.state` es `AUTORIZADO`.
- Una respuesta exitosa sin estado financiero se conserva como pendiente.
- Estados interpretados por la interfaz:
  - `AUTORIZADO`
  - `PENDIENTE`
  - `DENEGADO`
  - `INVALIDO`
  - `EXTORNADO`
- Se mantienen los mecanismos existentes de conciliación y firma S2S.

### 3. Pantalla homologada de resultado

Se incorporó un componente único para:

- Tarjeta de crédito o débito.
- Yape.
- Transferencia bancaria.
- QR.
- Cuotéalo.
- PagoEfectivo.

La pantalla puede presentar, cuando estén disponibles:

- Estado de la operación.
- Monto y moneda.
- Método de pago.
- N.º de operación Pay-me.
- ID de transacción Pay-me.
- Código de autorización.
- Fecha de expiración.
- URL para continuar el pago.
- Imagen e identificador QR.
- Código CIP y URL de instrucciones.

El ID de transacción incluye un botón accesible para copiarlo. No se muestran datos 3DS, CAVV, ECI, BIN completo, credenciales ni otros campos reservados para soporte o logs.

La pantalla es neutral para el comprador: no muestra el nombre ni el logo del proveedor de pagos por defecto. El comercio puede agregar su propio logo mediante el filtro de presentación documentado.

### 4. Diseño responsive y personalizable

- Componente adaptable a escritorio y dispositivos móviles.
- Selectores aislados con prefijo `.payme-result`.
- Sin selectores globales de elementos como `button`, `h1`, `p` o `table`.
- Variantes independientes:
  - `.payme-result--authorized`
  - `.payme-result--pending`
  - `.payme-result--denied`
  - `.payme-result--reversed`
- Variables CSS para colores, tipografía, bordes, ancho, fondos y sombra.
- Herencia predeterminada de tipografía, colores de texto, fondo, bordes y botones desde el theme o los presets globales de WordPress.
- Compatibilidad visual mejorada con WooCommerce, Elementor, themes y otros plugins.

Archivos principales:

- `/assets/css/payme-result.css`
- `/assets/js/payme-result.js`
- `/templates/payment-result.php`
- `/includes/class-wc-payme-result.php`

La guía completa está disponible en `/PAYME_RESULT_CUSTOMIZATION.md`.

### 5. Filtros de personalización

Se incorporaron los siguientes filtros de WordPress:

- `payme_result_primary_color`
- `payme_result_logo`
- `payme_result_return_button_text`
- `payme_result_return_url`
- `payme_result_custom_css_class`
- `payme_result_data`

Estos filtros permiten personalizar la presentación desde un Child Theme o plugin propio sin editar el core de Pay-me Gateway.

### 6. Additional Fields

La sección **Additional Fields** se muestra deshabilitada con la indicación **Más adelante**. En esta versión no presenta inputs, no guarda configuración del comercio y no agrega campos personalizados al payload.

Se conserva el comportamiento compatible anterior:

```json
"additional_fields": {
  "cms": "WordPress",
  "tipo": "embedded"
}
```

### 7. Compatibilidad S2S y datos asíncronos

- Una operación reservada que todavía no tenga pedido devuelve `409` al webhook para solicitar un reintento, manteniendo el tratamiento previo de condiciones de carrera.
- La respuesta S2S verificada tiene precedencia sobre los datos del navegador.
- Se conservan campos de presentación previamente recibidos, como QR, CIP y expiración.
- Las notificaciones pendientes actualizan el registro sin marcar la operación como autorizada.
- Los registros auxiliares de extorno no reemplazan el número numérico original mostrado al comprador.

## Archivos incorporados

```text
PAYME_RESULT_CUSTOMIZATION.md
RELEASE_NOTES_1.4.0.md
assets/css/payme-result.css
assets/js/payme-result.js
includes/class-wc-payme-result.php
templates/payment-result.php
```

## Archivos reemplazados

La implementación anterior de la página de pedido recibido fue sustituida por el nuevo componente:

```text
assets/css/payme-order-received.css
assets/js/payme-order-received.js
```

Estos archivos antiguos ya no forman parte de la versión 1.4.0.

## Compatibilidad

- WordPress: 5.0 o superior, según la cabecera actual del plugin.
- PHP: 7.4 o superior.
- WooCommerce: 3.0 o superior.
- WooCommerce HPOS declarado como compatible.
- Checkout clásico y WooCommerce Blocks.
- WordPress Multisite.
- Additional Fields configurables reservados para una versión posterior.
- No se requiere migración manual de base de datos.

## Actualización desde 1.3.7

1. Realizar una copia de seguridad de archivos y base de datos.
2. Comprimir la carpeta raíz `payme-gateway` completa.
3. Evitar incluir archivos de desarrollo como `.DS_Store` o `*.bak` en el ZIP de distribución.
4. Instalar el ZIP desde **Plugins > Añadir plugin > Subir plugin**.
5. Sustituir la versión instalada cuando WordPress lo solicite.
6. Confirmar que las credenciales, monedas y métodos de pago existentes se mantienen.
7. Revisar y guardar **WooCommerce > Ajustes > Pagos > Pay-me**.
8. Confirmar que **Additional Fields** aparezca como **Más adelante**.
9. Limpiar cachés de página, CDN y optimización de assets.

La estructura esperada del paquete es:

```text
payme-gateway/
├── payme-gateway.php
├── PAYME_RESULT_CUSTOMIZATION.md
├── RELEASE_NOTES_1.4.0.md
├── assets/
├── includes/
└── templates/
```

## Checklist de validación antes de producción

- [ ] Ejecutar `php -l` sobre los archivos PHP en un entorno con PHP disponible.
- [ ] Activar el plugin sin errores ni avisos en WordPress.
- [ ] Confirmar que Additional Fields no muestre inputs y figure como Más adelante.
- [ ] Confirmar que el payload conserve únicamente los campos técnicos actuales.
- [ ] Realizar una operación autorizada con tarjeta.
- [ ] Realizar una operación pendiente con QR, transferencia o PagoEfectivo.
- [ ] Validar una operación denegada o inválida.
- [ ] Validar la visualización de una operación extornada.
- [ ] Confirmar el botón para copiar el ID de transacción.
- [ ] Probar la pantalla en escritorio y móvil.
- [ ] Verificar callback, firma S2S, conciliación y extorno en sandbox.
- [ ] Revisar logs y confirmar que no se muestran datos técnicos al comprador.

## Validaciones estáticas realizadas

- Sintaxis del JavaScript nuevo verificada con Node.js.
- Balance de bloques CSS comprobado.
- Selectores de la pantalla de resultado revisados para evitar reglas globales.
- Additional Fields configurables deshabilitados en interfaz y payload.

## Versionado

La versión utiliza [Semantic Versioning](https://semver.org/):

- **1**: línea principal compatible del plugin.
- **4**: nuevas funcionalidades compatibles hacia atrás.
- **0**: primera publicación de esta línea funcional.

### Historial inmediato

| Versión | Estado | Resumen |
|---|---|---|
| 1.4.0 | Actual | Resultado homologado y operación segura; Additional Fields reservado para más adelante. |
| 1.3.7 | Anterior | Base funcional previa a estas mejoras. |

## Recomendación final

No editar directamente los archivos core del plugin después de instalarlo. Las personalizaciones visuales deben implementarse mediante CSS externo, Child Theme, Personalizador de WordPress o los filtros documentados.

Antes de pasar a producción, completar el checklist en sandbox o staging con las credenciales y métodos habilitados para cada comercio.
