# Personalización de la pantalla de resultado Pay-me

## Introducción

El plugin incluye un componente único de resultado para tarjeta, Yape, transferencia bancaria, QR, Cuotéalo y PagoEfectivo. Sus estilos predeterminados pueden personalizarse sin cambiar la autorización, los callbacks ni la integración S2S de Pay-me.

Las personalizaciones deben agregarse fuera del plugin para que una actualización no las sobrescriba.

## Ubicación de los estilos

La hoja de estilos de la pantalla está en:

`/assets/css/payme-result.css`

El HTML se genera en:

`/templates/payment-result.php`

La preparación segura de los datos y los filtros de WordPress están en:

`/includes/class-wc-payme-result.php`

El comportamiento del botón para copiar el ID de transacción está en:

`/assets/js/payme-result.js`

Estas rutas son relativas a la carpeta raíz del plugin `payme-gateway`.

## Variables CSS disponibles

Las variables están definidas sobre `.payme-result`, por lo que pueden sobrescribirse para un comercio o una página concreta sin afectar al resto del sitio.

| Variable | Función | Valor predeterminado |
|---|---|---|
| `--payme-primary-color` | Color principal heredado del preset del theme; puede sobrescribirse. | `var(--wp--preset--color--primary, currentColor)` |
| `--payme-success-color` | Color de operaciones autorizadas. | `#16A572` |
| `--payme-warning-color` | Color de operaciones pendientes. | `#F5A623` |
| `--payme-error-color` | Color de operaciones denegadas o inválidas. | `#DC3545` |
| `--payme-reversed-color` | Color de operaciones extornadas. | `#667085` |
| `--payme-text-color` | Color principal heredado del theme. | `var(--wp--preset--color--contrast, inherit)` |
| `--payme-secondary-text-color` | Color secundario heredado, mostrado con menor opacidad. | `inherit` |
| `--payme-background-color` | Fondo base del theme o fondo transparente. | `var(--wp--preset--color--base, transparent)` |
| `--payme-subtle-background-color` | Fondo sutil del theme para la sección de monto. | `var(--wp--preset--color--tertiary, rgba(127, 127, 127, 0.06))` |
| `--payme-border-color` | Color de borde heredable con fallback neutro. | `var(--wp--preset--color--contrast-3, rgba(127, 127, 127, 0.24))` |
| `--payme-border-radius` | Radio base de cards y botones. | `12px` |
| `--payme-font-family` | Tipografía del componente. | `inherit` |
| `--payme-container-width` | Ancho máximo del componente. | `720px` |
| `--payme-shadow` | Sombra de la tarjeta. | `0 12px 32px rgba(16, 24, 40, 0.08)` |

`--payme-state-color` y `--payme-state-soft-color` son variables internas que cada modificador de estado asigna a partir de la paleta anterior. También pueden sobrescribirse para una variante muy específica.

## Ejemplo de personalización

Este CSS puede colocarse en el theme, el Child Theme, el Personalizador de WordPress o una hoja propia del comercio:

```css
.payme-result {
  --payme-primary-color: #0066CC;
  --payme-success-color: #159957;
  --payme-border-radius: 16px;
  --payme-font-family: "Inter", sans-serif;
  --payme-container-width: 760px;
}
```

Para limitar la configuración a una clase propia agregada por filtro:

```css
.payme-result.mi-comercio-resultado {
  --payme-primary-color: #6B3DF5;
  --payme-background-color: #FCFCFF;
}
```

## Modificar botones

El botón principal usa `.payme-result__primary-button`. El botón de regreso pasa a ser secundario cuando existe una acción para continuar el pago y usa `.payme-result__secondary-button`.

```css
.payme-result__primary-button {
  min-height: 52px;
  border-radius: 999px;
  text-transform: uppercase;
}

.payme-result__secondary-button {
  color: #0066CC;
  border-color: #0066CC;
}
```

El botón para copiar el ID de transacción usa `.payme-result__copy-button`.

## Modificar estados

Cada estado financiero tiene un modificador independiente. No se determina por el código HTTP, sino principalmente por `transaction.state`.

```css
.payme-result--authorized {
  --payme-state-color: #117A52;
  --payme-state-soft-color: #E8F7F0;
}

.payme-result--pending {
  --payme-state-color: #B56A00;
  --payme-state-soft-color: #FFF4DD;
}

.payme-result--denied {
  --payme-state-color: #B42318;
  --payme-state-soft-color: #FEECEB;
}

.payme-result--reversed {
  --payme-state-color: #475467;
  --payme-state-soft-color: #F2F4F7;
}
```

`INVALIDO` se presenta como denegado. `EXTORNADO` se presenta con la variante reversed.

## Hooks y filtros disponibles

Los filtros se agregan en `functions.php` de un Child Theme o en un plugin propio del comercio. Evite incluir datos sensibles o técnicos en textos visibles al comprador.

### Color principal

```php
add_filter('payme_result_primary_color', function ($color, $order, $response) {
    return '#0066CC';
}, 10, 3);
```

### Logo

La pantalla no muestra ningún logo por defecto. Devuelva la URL HTTPS del logo del comercio para mostrarlo:

```php
add_filter('payme_result_logo', function ($logo_url) {
    return get_stylesheet_directory_uri() . '/assets/logo-checkout.svg';
});
```

### Texto y URL del botón de regreso

```php
add_filter('payme_result_return_button_text', function ($text) {
    return 'Seguir comprando';
});

add_filter('payme_result_return_url', function ($url) {
    return home_url('/productos/');
});
```

### Clase CSS adicional

```php
add_filter('payme_result_custom_css_class', function ($class) {
    return trim($class . ' mi-comercio-resultado');
});
```

Las clases pasan por saneamiento antes de imprimirse. También existe `payme_result_data` para ajustar datos de presentación ya procesados. Debe usarse con cuidado: no agregue CAVV, ECI, BIN completo, datos 3DS, credenciales ni identificadores internos.

## Recomendación importante

No se recomienda editar directamente los archivos core del plugin. Una actualización futura podría sobrescribir los cambios.

Utilice una de estas alternativas:

- CSS adicional del theme.
- Un Child Theme.
- El Personalizador de WordPress.
- Un archivo CSS propio del comercio encolado por WordPress.
- Los hooks y filtros del plugin documentados arriba.

El CSS del componente está prefijado con `.payme-result` para reducir colisiones con WooCommerce, Elementor, themes y otros plugins. Mantenga el mismo criterio al agregar reglas propias y evite selectores globales como `button`, `h1`, `p` o `table`.
