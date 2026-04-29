/**
 * Payme Order Received Page - Enhanced User Experience
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Detect order-received page by multiple methods
        var isOrderReceived = $('body').hasClass('woocommerce-order-received')
            || window.location.href.indexOf('order-received') !== -1
            || window.location.search.indexOf('order-received') !== -1
            || $('.woocommerce-order-overview').length > 0
            || $('.woocommerce-thankyou-order-received').length > 0;

        if (!isOrderReceived) return;

        // Small delay to ensure WooCommerce has rendered its content
        setTimeout(function() {
            PaymeOrderReceived.init();
        }, 200);
    });

    var PaymeOrderReceived = {

        init: function() {
            this.addSuccessBanner();
            this.hideOriginalTitle();
            this.enhanceOverview();
            this.enhanceOrderTable();
            this.enhanceBillingAddress();
            this.addBackToShopButton();
        },

        hideOriginalTitle: function() {
            // Hide "Order received" / "Pedido recibido" / "Checkout" headings
            $('h1, h2').each(function() {
                var text = $(this).text().trim().toLowerCase();
                if (text === 'order received' || text === 'pedido recibido' || text === 'checkout') {
                    $(this).hide();
                }
            });
            // Hide default thankyou paragraph
            $('.woocommerce-thankyou-order-received').hide();
        },

        addSuccessBanner: function() {
            var banner = this.getBannerHtml();

            // 1. Classic WooCommerce: insert before the thankyou paragraph
            var $thankyou = $('.woocommerce-thankyou-order-received').first();
            if ($thankyou.length) {
                $thankyou.before(banner);
                return;
            }

            // 2. Try .woocommerce-order wrapper
            var $order = $('.woocommerce-order').first();
            if ($order.length) {
                $order.prepend(banner);
                return;
            }

            // 3. Try overview section parent
            var $overview = $('.woocommerce-order-overview').first();
            if ($overview.length) {
                $overview.before(banner);
                return;
            }

            // 4. Try order details parent
            var $details = $('.woocommerce-order-details').first();
            if ($details.length) {
                $details.before(banner);
                return;
            }

            // 5. Blocks / generic: find the "Thank you" text node and insert before it
            var inserted = false;
            $('p, div').each(function() {
                var text = $(this).text().trim().toLowerCase();
                if (!inserted && (
                    text.indexOf('thank you') !== -1 ||
                    text.indexOf('your order has been received') !== -1 ||
                    text.indexOf('gracias') !== -1 ||
                    text.indexOf('tu pedido ha sido recibido') !== -1
                )) {
                    $(this).before(banner);
                    inserted = true;
                    return false;
                }
            });
            if (inserted) return;

            // 6. Last resort: prepend to main content area
            var $content = $('.entry-content, .wp-block-post-content, .site-main').first();
            if ($content.length) {
                $content.prepend(banner);
            }
        },

        enhanceOverview: function() {
            var $overview = $('.woocommerce-order-overview');
            if (!$overview.length) return;

            $overview.addClass('payme-order-overview');
            $overview.find('li').each(function() {
                $(this).addClass('payme-overview-item');
                $(this).find('strong').addClass('payme-overview-value');
            });
        },

        enhanceOrderTable: function() {
            var $table = $('.woocommerce-table--order-details');
            if (!$table.length) return;

            $table.addClass('payme-order-table');
            $table.closest('section, .woocommerce-order-details').addClass('payme-order-details-section');
        },

        enhanceBillingAddress: function() {
            var $billing = $('.woocommerce-customer-details');
            if (!$billing.length) return;

            $billing.addClass('payme-customer-details');
        },

        getBannerHtml: function() {
            var status = (typeof payme_order !== 'undefined' && payme_order.status) ? payme_order.status : '';
            var isPending = status === 'on-hold' || status === 'pending';
            var isFailed = status === 'failed' || status === 'cancelled';

            if (isFailed) {
                return '<div class="payme-success-banner payme-error-banner" style="background:linear-gradient(135deg,#fef2f2 0%,#fee2e2 100%);border-color:#ef4444;">' +
                    '<div class="payme-success-banner__icon" style="color:#ef4444;">✕</div>' +
                    '<h2 class="payme-success-banner__title" style="color:#991b1b;">Pago Denegado</h2>' +
                    '<p class="payme-success-banner__subtitle" style="color:#b91c1c;">Tu pago no pudo ser procesado. La transacción fue rechazada.</p>' +
                    '</div>';
            }

            if (isPending) {
                return '<div class="payme-success-banner payme-pending-banner">' +
                    '<div class="payme-success-banner__icon">⏳</div>' +
                    '<h2 class="payme-success-banner__title">Pedido Pendiente de Pago</h2>' +
                    '<p class="payme-success-banner__subtitle">Tu pedido ha sido registrado. Completa el pago para confirmarlo.</p>' +
                    '</div>';
            }

            return '<div class="payme-success-banner">' +
                '<div class="payme-success-banner__icon">✓</div>' +
                '<h2 class="payme-success-banner__title">¡Pedido Confirmado!</h2>' +
                '<p class="payme-success-banner__subtitle">Tu pago ha sido procesado exitosamente</p>' +
                '</div>';
        },

        addBackToShopButton: function() {
            var $last = $('.woocommerce-customer-details, .woocommerce-order-details').last();
            if (!$last.length) {
                $last = $('.woocommerce-order-overview').parent();
            }
            if (!$last.length) return;

            var shopUrl = '/shop/';
            if (typeof wc_cart_params !== 'undefined' && wc_cart_params.shop_url) {
                shopUrl = wc_cart_params.shop_url;
            }

            var btn = '<div class="payme-back-to-shop">' +
                '<a href="' + shopUrl + '" class="payme-btn-shop">← Volver a la tienda</a>' +
                '</div>';

            $last.after(btn);
        }
    };

})(jQuery);
