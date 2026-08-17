# Pay-me Gateway para WooCommerce — Versión 1.4.3

**Fecha de versión:** 17 de agosto de 2026  
**Versión anterior:** 1.4.0  
**Tipo de versión:** Release compatible hacia atrás  
**Estado:** Lista para validación final en staging y empaquetado

## Resumen

Pay-me Gateway 1.4.3 incorpora una nueva sección administrativa para configurar los datos de facturación enviados a Pay-me.

Cada campo configurable puede utilizar el valor dinámico recibido desde el checkout de WooCommerce o un valor estático definido por el comercio. La resolución se aplica antes de validar la compra y antes de construir el payload final.

La versión conserva los eventos, advertencias y errores operativos en la consola del navegador, pero no imprime payloads, respuestas completas ni datos de facturación.

## Mejoras principales

### 1. Configuración de datos del payload

Se agregó la sección **Configuración de datos del payload** en:

**WooCommerce > Ajustes > Pagos > Pay-me**

Los siguientes campos pueden configurarse individualmente:

- Nombre.
- Apellido.
- Correo electrónico.
- Código telefónico.
- Teléfono.
- Dirección.
- Dirección adicional.
- Ciudad.
- Estado o provincia.
- País.
- Código postal.

Cada campo dispone de dos modos:

- **Dinámico:** utiliza el valor recibido desde el checkout.
- **Estático:** utiliza el valor definido en la configuración del plugin.

Los campos que no tengan una configuración guardada permanecen en modo dinámico para conservar el comportamiento existente.

### 2. Nueva interfaz administrativa

- Tabla compacta para visualizar el campo y su ruta dentro del payload.
- Selector de modo dinámico o estático.
- Campo de valor visible únicamente cuando se selecciona el modo estático.
- Validación que impide guardar valores estáticos vacíos.
- Diseño responsive con desplazamiento horizontal controlado en pantallas pequeñas.
- Persistencia explícita de la configuración para distintas versiones de WooCommerce.

### 3. Resolución y validación del checkout

- Los valores estáticos reemplazan los datos dinámicos antes de validar el checkout.
- WooCommerce y el payload final utilizan los mismos valores resueltos.
- Los campos dinámicos obligatorios continúan validándose desde el formulario de compra.
- Los campos configurados como estáticos no dependen de que exista un input equivalente en el checkout.
- Los valores se normalizan y sanitizan antes de enviarse a Pay-me.
- El correo electrónico y el teléfono reciben sanitización específica para su formato.

### 4. País configurable

- Se retiró el valor fijo `PE` utilizado como respaldo automático.
- El país ahora se obtiene del checkout o del valor estático configurado por el comercio.
- Si el país es obligatorio y no puede resolverse, el checkout informa el campo faltante antes de iniciar la sesión de pago.

### 5. Persistencia y compatibilidad

- La configuración se guarda mediante las opciones del gateway de WooCommerce.
- Se agregó una opción dedicada como respaldo para evitar pérdidas cuando WooCommerce reconstruye los ajustes del método de pago.
- Las configuraciones guardadas con la estructura anterior se normalizan automáticamente.
- No se requiere una migración manual de base de datos.

### 6. Consola y protección de datos

La consola conserva información operativa útil, como:

- Inicio de la validación del checkout.
- Intentos duplicados bloqueados.
- Errores de carga del SDK o de sus estilos.
- Errores de conexión AJAX.
- Resultado operativo de un extorno.

No se imprimen en la consola del navegador:

- Payloads completos o procesados.
- Datos de facturación del comprador.
- Respuestas completas del servidor.
- Tokens, nonces o credenciales.
- Configuraciones estáticas del comercio.

También se retiraron los datos de trazabilidad del payload de las respuestas AJAX enviadas al navegador.

Los logs internos de WooCommerce continúan sujetos a la opción **Habilitar logs de debug** y no se muestran en la consola del comprador.

## Archivos principales modificados

```text
assets/css/payme-admin.css
assets/js/payme-checkout.js
includes/class-wc-payme-gateway.php
payme-gateway.php
```

## Archivo incorporado

```text
assets/js/payme-admin-payload.js
```

El archivo administra la interacción, validación y serialización de la nueva configuración del payload dentro del panel de WooCommerce.

## Compatibilidad

- WordPress: 5.0 o superior, según la cabecera del plugin.
- PHP: 7.4 o superior.
- WooCommerce: 3.0 o superior.
- WooCommerce HPOS declarado como compatible.
- Checkout clásico y WooCommerce Blocks.
- WordPress Multisite.
- Configuraciones existentes sin campos personalizados continúan operando en modo dinámico.
- No se requiere migración manual de base de datos.

## Actualización desde 1.4.0

1. Realizar una copia de seguridad de los archivos y la base de datos.
2. Empaquetar la carpeta raíz completa del plugin.
3. Evitar incluir archivos locales como `.DS_Store` o `*.bak`.
4. Instalar el ZIP desde **Plugins > Añadir plugin > Subir plugin**.
5. Sustituir la versión instalada cuando WordPress lo solicite.
6. Confirmar que las credenciales, monedas y métodos de pago existentes se mantienen.
7. Abrir **WooCommerce > Ajustes > Pagos > Pay-me**.
8. Revisar la sección **Configuración de datos del payload**.
9. Guardar los ajustes y limpiar las cachés de página, CDN y optimización de assets.

## Checklist de validación antes de producción

- [ ] Ejecutar `php -l` sobre los archivos PHP en un entorno con PHP 7.4 o superior.
- [ ] Activar o actualizar el plugin sin errores ni avisos en WordPress.
- [ ] Confirmar que todos los campos aparecen inicialmente en modo dinámico.
- [ ] Guardar un campo en modo estático y verificar que persista al recargar la página.
- [ ] Confirmar que no sea posible guardar un valor estático vacío.
- [ ] Probar un pago utilizando únicamente valores dinámicos del checkout.
- [ ] Probar un pago utilizando uno o más valores estáticos.
- [ ] Validar el país dinámico y el país estático.
- [ ] Probar el checkout clásico y WooCommerce Blocks.
- [ ] Verificar los métodos de pago habilitados en sandbox.
- [ ] Validar callback, firma S2S, conciliación y extorno.
- [ ] Confirmar que la consola muestre eventos y errores operativos.
- [ ] Confirmar que la consola no muestre payloads, respuestas completas ni datos del comprador.
- [ ] Probar la interfaz administrativa en escritorio y móvil.

## Validaciones estáticas realizadas

- Sintaxis de `assets/js/payme-checkout.js` verificada con Node.js.
- Sintaxis de `assets/js/payme-admin-payload.js` verificada con Node.js.
- Diff revisado sin errores de espacios.
- Búsqueda de trazabilidad del payload realizada sobre el código activo.
- Archivos `*.bak` excluidos mediante `.gitignore`.

La validación de sintaxis PHP debe completarse en un entorno que tenga disponible el ejecutable `php`.

## Versionado

La versión publicada del plugin se actualizó en la cabecera y en la constante interna:

```text
1.4.3
```

### Historial inmediato

| Versión | Estado | Resumen |
|---|---|---|
| 1.4.3 | Actual | Configuración dinámica o estática de datos del payload y trazabilidad segura en consola. |
| 1.4.0 | Anterior | Pantalla homologada de resultado y generación segura del número de operación. |

## Recomendación final

Antes de pasar a producción, completar el checklist en sandbox o staging con las credenciales, monedas y métodos habilitados para el comercio.

No registrar payloads completos en la consola del navegador. Para soporte, utilizar únicamente eventos operativos y errores sin datos sensibles.
