jQuery(document).ready(function ($) {
    'use strict';

    if (typeof payme_params === 'undefined') {
        return;
    }

    var HEURISTICS = {
        first_name: ['nombre', 'first_name', 'firstname', 'fname'],
        last_name: ['apellido', 'surname', 'last_name'],
        email: ['email', 'correo', 'mail'],
        phone: ['phone', 'telefono', 'celular', 'móvil', 'movil'],
        address_1: ['address', 'direccion', 'dirección', 'calle', 'street', 'dir'],
        city: ['city', 'ciudad', 'distrito', 'provincia', 'district'],
        state: ['state', 'estado', 'departamento', 'provincia', 'region']
    };
    var NON_BILLING_SIGNALS = [
        'shipping', 'envio', 'regalo', 'gift', 'nota', 'note',
        'order', 'coupon', 'cupon', 'comment', 'comentario',
        'account', 'cuenta', 'password', 'company', 'empresa'
    ];
    // The Flex card layout needs its desktop canvas to avoid collapsing the
    // expiration/CVV/name fields into the SDK's extra-small breakpoint. When
    // it does not fit, the modal viewport scrolls instead of scaling the SDK.
    var PAYME_POPUP_NATURAL_WIDTH = 560;

    var payme = {
        paymentForm: null,
        isInitializing: false,
        isInitialized: false,
        flexLoaded: false,
        flexLoading: false,
        flexCssLoaded: false,
        flexCssLoading: false,
        _paymentDataRequestSeq: 0,
        _activeFlexCleanup: null,
        _fieldCache: {},
        _fieldCacheTime: 0,
        _refreshTimer: null,
        _popupPreloadTimer: null,

        /**
         * Invalidates any payment-data response that is still in flight.
         * A Flex nonce belongs to exactly one SDK initialization, so an AJAX
         * response from an older checkout state must never be mounted later.
         */
        invalidateFlexSession: function () {
            this._paymentDataRequestSeq++;
            if (this._activeFlexCleanup) {
                this._activeFlexCleanup();
            }
            this.popupPaymentData = null;
            this.isInitialized = false;
            this.isInitializing = false;
        },

        setPopupPreloadState: function (isLoading) {
            if (payme_params.display_mode !== 'popup') return;
            var $btn = $('#place_order');
            if (!$btn.length) return;
            if (!$btn.data('wc-original-text')) $btn.data('wc-original-text', $btn.text());
            if (isLoading) {
                $btn.prop('disabled', true).text('Preparando pago...');
            } else {
                $btn.prop('disabled', false).text($btn.data('wc-original-text') || 'Realizar el pedido');
            }
        },

        preloadPaymentPopup: function () {
            if (payme_params.display_mode !== 'popup') return;
            if (this.popupPaymentData) {
                this.setPopupPreloadState(false);
                return;
            }
            if (this.isInitializing) {
                this.setPopupPreloadState(true);
                return;
            }

            if (payme_params.payment_type === 'separado' && !this.selectedMethod) {
                var checked = document.querySelector('input[name="payme_selected_method"]:checked');
                if (checked) this.selectedMethod = checked.value;
            }

            var container = this.getPaymeContainer();
            if (!container || (payme_params.payment_type === 'separado' && !this.selectedMethod)) {
                this.setPopupPreloadState(true);
                return;
            }

            this.setPopupPreloadState(true);
            this.getPaymentData(container);
        },

        queuePopupPreload: function (delay) {
            if (payme_params.display_mode !== 'popup') return;
            this.setPopupPreloadState(true);
            clearTimeout(this._popupPreloadTimer);
            this._popupPreloadTimer = setTimeout(function () {
                payme.preloadPaymentPopup();
            }, typeof delay === 'number' ? delay : 150);
        },

        loadFlexSDK: function (callback, errorCallback) {
            // WordPress themes and page builders can load their form CSS after
            // wp_enqueue_style(). Append the official Flex stylesheet last so
            // Pay-me controls its own inputs without plugin-specific overrides.
            if (!this.flexCssLoaded) {
                if (this.flexCssLoading) {
                    var cssWait = document.querySelector('link[data-payme-flex-runtime-css]');
                    if (cssWait) {
                        cssWait.addEventListener('load', function () {
                            payme.loadFlexSDK(callback, errorCallback);
                        }, { once: true });
                        cssWait.addEventListener('error', function () {
                            if (typeof errorCallback === 'function') errorCallback();
                        }, { once: true });
                    }
                    return;
                }

                this.flexCssLoading = true;
                var runtimeCss = document.createElement('link');
                runtimeCss.rel = 'stylesheet';
                runtimeCss.type = 'text/css';
                runtimeCss.href = payme_params.flex_css_url;
                runtimeCss.setAttribute('data-payme-flex-runtime-css', 'true');
                runtimeCss.onload = function () {
                    payme.flexCssLoaded = true;
                    payme.flexCssLoading = false;
                    payme.loadFlexSDK(callback, errorCallback);
                };
                runtimeCss.onerror = function () {
                    payme.flexCssLoading = false;
                    console.error('[Payme] No se pudo cargar la hoja de estilos oficial de Flex');
                    if (typeof errorCallback === 'function') errorCallback();
                };
                document.head.appendChild(runtimeCss);
                return;
            }

            if (this.flexLoaded && typeof FlexPaymentForms !== 'undefined') {
                callback();
                return;
            }
            if (this.flexLoading) {
                var self = this;
                var check = setInterval(function () {
                    if (self.flexLoaded) {
                        clearInterval(check);
                        callback();
                    }
                }, 100);
                return;
            }
            this.flexLoading = true;
            var self = this;
            // Cargar JS dinámicamente (CSS ya se carga desde PHP)
            var script = document.createElement('script');
            script.src = payme_params.flex_js_url;
            script.onload = function () {
                self.flexLoaded = true;
                self.flexLoading = false;
                callback();
            };
            script.onerror = function () {
                self.flexLoading = false;
                console.error('[Payme] No se pudo cargar el SDK de Flex');
                if (typeof errorCallback === 'function') errorCallback();
            };
            document.body.appendChild(script);
        },

        init: function () {
            this.detectCheckoutType();
            this.bindEvents();
        },

        detectCheckoutType: function () {
            if (document.querySelector('.wc-block-checkout')) {
                // Blocks checkout — don't initialize classic support
                return;
            } else {
                this.initClassicSupport();
            }

            // [NUEVO] Ejecutar escaneo visual inmediatamente en la carga para 
            // reemplazar cualquier mensaje estático de PHP con el feedback hiper-detallado de JS
            this.refreshBillingState();
        },

        /**
         * styleNativePlaceOrder — Aplica el estilo visual del botón #place_order.
         *
         * Modo JUNTO (embedded + un gateway): el botón nativo es el trigger de validación WC.
         *   → se muestra estilizado como botón Payme.
         * Modo SEPARADO (un gateway por método): el botón nativo NO se necesita porque
         *   cada sub-método tiene su propio botón de pago interno del SDK.
         *   → se oculta para evitar que dispare checkout_place_order_payme inesperadamente.
         */
        styleNativePlaceOrder: function () {
            if (document.querySelector('.wc-block-checkout')) return;

            this.syncThemePalette();

            var selected = $('input[name="payment_method"]:checked').val();
            var btn = $('#place_order');
            var terms = $('.woocommerce-terms-and-conditions-wrapper');

            if (selected === 'payme') {
                // Junto popup & Separado popup: show styled native button (triggers the modal flow)
                if (payme_params.display_mode === 'popup') {
                    btn.show();
                    // Do not inject aggressive inline styles. Let the merchant's theme handle
                    // the look and feel of the checkout place order button.
                    // btn.removeAttr('style'); // Removido para evitar conflictos con temas que dependen de estilos inline

                    // We can optionally prefix the text or just leave it native.
                    if (!btn.data('wc-original-text')) btn.data('wc-original-text', btn.text());
                    btn.text(btn.data('wc-original-text') || 'Realizar pedido');

                    terms.show();
                } else {
                    // Separado OR junto embebido: hide native button
                    // (SDK has its own payment button per sub-method or within the form)
                    if (btn.length) btn[0].style.setProperty('display', 'none', 'important');
                    terms.show();
                }
            } else {
                btn.show();
                btn.removeAttr('style');
                btn.text(btn.data('wc-original-text') || 'Realizar el pedido');
                terms.show();
            }
        },

        /**
         * Copies the merchant theme's primary checkout button palette into the
         * Pay-me method selector. CSS variables remain as a fallback for themes
         * that render their checkout button later.
         */
        syncThemePalette: function () {
            var root = document.getElementById('payme-payment-methods-root');
            var overlay = document.getElementById('payme-modal-overlay');
            var targets = [root, overlay].filter(function (target) { return !!target; });
            if (!targets.length || !window.getComputedStyle) return;

            var selectors = [
                '#place_order',
                '.wc-block-components-checkout-place-order-button',
                '.checkout-button',
                '.single_add_to_cart_button',
                '.wp-element-button'
            ];
            var source = null;

            for (var i = 0; i < selectors.length; i++) {
                source = document.querySelector(selectors[i]);
                if (source) break;
            }
            if (!source) return;

            var styles = window.getComputedStyle(source);
            var bodyStyles = document.body ? window.getComputedStyle(document.body) : null;
            var accent = styles.backgroundColor;
            var foreground = styles.color;
            var fontFamily = bodyStyles && bodyStyles.fontFamily ? bodyStyles.fontFamily : styles.fontFamily;
            var isTransparent = !accent
                || accent === 'transparent'
                || /^rgba\([^)]*,\s*0(?:\.0+)?\)$/.test(accent);

            targets.forEach(function (target) {
                if (!isTransparent) {
                    target.style.setProperty('--payme-theme-accent', accent);
                }
                if (foreground && foreground !== 'transparent') {
                    target.style.setProperty('--payme-theme-on-accent', foreground);
                }
                if (fontFamily) {
                    target.style.setProperty('--payme-theme-font', fontFamily);
                }
            });
        },

        initBlocksSupport: function () {
            this.monitorBlocksPaymentSelection();
        },

        initClassicSupport: function () {
            // Save WC original button text before we ever touch it
            var $btn = $('#place_order');
            if ($btn.length && !$btn.data('wc-original-text')) {
                $btn.data('wc-original-text', $btn.text());
            }

            // Single unified listener for payment method changes
            $('body').on('change', 'input[name="payment_method"]', this.handlePaymentMethodChange.bind(this));

            // VINCULACIÓN CRÍTICA: Capturar el evento nativo del submit de WooCommerce (delegado en body por si el form es reemplazado)
            $('body').on('checkout_place_order_payme', function (e) {
                return payme.handleCheckoutSubmit(e);
            });

            // Billing field listener: refreshes banner + smart junto embebido retry
            // Uses 'change' ONLY (not blur): change fires only when value actually changes.
            // Debounced at 1000ms to avoid firing during rapid typing.
            var billingFields = '#billing_first_name, #billing_last_name, #billing_email, #billing_address_1, #billing_city, #billing_phone, input[name*="billing_"]';
            $('body').on('change', billingFields, function () {
                var selected = $('input[name="payment_method"]:checked').val();
                if (selected !== 'payme') return;

                payme.refreshBillingState();

                if (payme_params.display_mode === 'popup') {
                    payme.invalidateFlexSession();
                    payme.setPopupPreloadState(true);
                    payme.queuePopupPreload(600);
                    return;
                }

                // Junto embebido only: retry if previous validation had failed
                if (payme_params.payment_type !== 'separado'
                    && payme_params.display_mode !== 'popup'
                    && payme._juntoValidationFailed
                    && !payme.isInitialized
                    && !payme._initJuntoInProgress) {
                    clearTimeout(payme._sdkRetryTimer);
                    payme._sdkRetryTimer = setTimeout(function () {
                        payme._initJuntoEmbebido();
                    }, 1000);
                }
            });

            $(document.body).on('updated_checkout', function () {
                // Reset SDK state — WC re-renders the checkout HTML
                payme.invalidateFlexSession();
                payme._juntoValidationFailed = false;
                // NOTE: do NOT reset _initJuntoInProgress here — if a validation
                // AJAX is in flight when updated_checkout fires, we should not
                // start a new one until the current one completes.

                // Re-save button text (WC may have reset it)
                var $b = $('#place_order');
                if ($b.length && !$b.data('wc-original-text')) {
                    $b.data('wc-original-text', $b.text());
                }

                var selected = $('input[name="payment_method"]:checked').val();
                if (selected === 'payme') {
                    payme.refreshBillingState();
                    payme.styleNativePlaceOrder();
                    if (payme_params.payment_type === 'separado') {
                        setTimeout(function () { payme.bindSeparateMethodCards(); }, 100);
                    } else if (payme_params.display_mode !== 'popup' && !payme._initJuntoInProgress) {
                        setTimeout(function () { payme._initJuntoEmbebido(); }, 100);
                    }
                    if (payme_params.display_mode === 'popup') payme.queuePopupPreload(150);
                } else {
                    payme.styleNativePlaceOrder();
                }
            });

            // On page load: init state immediately (reduced from 800ms)
            setTimeout(function () {
                var $b = $('#place_order');
                if ($b.length && !$b.data('wc-original-text')) {
                    $b.data('wc-original-text', $b.text());
                }
                var selected = $('input[name="payment_method"]:checked').val();
                if (selected === 'payme') {
                    payme.refreshBillingState();
                    payme.styleNativePlaceOrder();
                    if (payme_params.payment_type === 'separado') {
                        payme.bindSeparateMethodCards();
                    } else if (payme_params.display_mode !== 'popup') {
                        payme._initJuntoEmbebido();
                    }
                    if (payme_params.display_mode === 'popup') payme.queuePopupPreload(150);
                }
            }, 100);

            // Popup secondary button: trigger #place_order click so our handler fires
            $('body').on('click', '#payme-classic-popup-btn', function (e) {
                e.preventDefault();
                $('#place_order').trigger('click');
            });
        },

        monitorBlocksPaymentSelection: function () {
            var observer = new MutationObserver(function (mutations) {
                mutations.forEach(function (mutation) {
                    if (mutation.type === 'childList') {
                        var paymeInput = document.querySelector('input[value="payme"]:checked');
                        if (paymeInput && !payme.isInitialized) {
                            setTimeout(function () {
                                payme.initializePaymeFlex();
                            }, 200);
                        } else if (!paymeInput && payme.isInitialized) {
                            payme.hidePaymentForm();
                        }
                    }
                });
            });

            observer.observe(document.body, {
                childList: true,
                subtree: true
            });

            var checkInterval = setInterval(function () {
                var paymeInput = document.querySelector('input[value="payme"]:checked');
                var container = document.getElementById('payme-flex-container') || document.querySelector('.payme-junto-mode');

                if (paymeInput && container && !payme.isInitialized) {
                    payme.initializePaymeFlex();
                } else if (!paymeInput && payme.isInitialized) {
                    payme.hidePaymentForm();
                }
            }, 2000);

            this.blocksInterval = checkInterval;
        },

        bindEvents: function () {
            // CRITICAL: WooCommerce uses jQuery's triggerHandler() to fire
            // 'checkout_place_order_payme'. triggerHandler() does NOT bubble up
            // the DOM, so event DELEGATION (binding to document.body with a selector)
            // WILL NEVER WORK. We must bind directly to form.checkout itself.
            // We also rebind after updated_checkout because WC can re-render the form.
            function bindPaymeEvent() {
                $('form.checkout')
                    .off('checkout_place_order_payme.payme') // remove previous to avoid duplicates
                    .on('checkout_place_order_payme.payme', function (e) {
                        return payme.handleCheckoutSubmit(e);
                    });
            }

            bindPaymeEvent(); // initial bind

            // Rebind every time WC re-renders the checkout (address change, coupon, etc.)
            $(document.body).on('updated_checkout', function () {
                bindPaymeEvent();
            });
        },

        handlePaymentMethodChange: function () {
            var selectedMethod = $('input[name="payment_method"]:checked').val();
            var self = this;

            if (selectedMethod === 'payme') {
                this.invalidateFlexSession();
                this.paymentForm = null;
                this._juntoValidationFailed = false;

                this.refreshBillingState();
                this.styleNativePlaceOrder();

                if (payme_params.payment_type === 'separado') {
                    // Separado: bind card handlers (SDK loads per-card after validation)
                    setTimeout(function () { self.bindSeparateMethodCards(); }, 200);
                } else if (payme_params.display_mode !== 'popup') {
                    // Junto embebido: auto-mount SDK with pre-validation
                    setTimeout(function () { self._initJuntoEmbebido(); }, 200);
                }
                if (payme_params.display_mode === 'popup') this.queuePopupPreload(250);
                // Popup: session/assets preload now; Flex mounts only on the click.
            } else {
                this.hidePaymentForm();
                this.styleNativePlaceOrder();
            }
        },

        /**
         * _initJuntoEmbebido — Junto embebido auto-init flow.
         */
        _initJuntoEmbebido: function () {
            var self = this;

            // Guard: prevent concurrent/recursive calls
            if (self._initJuntoInProgress) return;
            self._initJuntoInProgress = true;

            var $form = $('form.checkout');

            // No classic form (Blocks checkout) — mount directly
            if (!$form.length) {
                self._initJuntoInProgress = false;
                self.initializePaymeFlex();
                return;
            }

            // UNIFIED FAST LOAD: We no longer do pre-validation AJAX here.
            // initializePaymeFlex -> getPaymentData will do validation and session in 1 request.
            self._initJuntoInProgress = false;
            self._juntoValidationFailed = false;
            self._skipClientValidation = true; // PHP unified validation will handle it
            self.initializePaymeFlex();
        },

        /**
         * bindSeparateMethodCards — Binds click handlers to Payme sub-method cards
         * (Tarjeta, Yape, QR, etc.) for separado mode.
         *
         * Each card click runs WC validation first (payme_validate_checkout),
         * and only mounts the Flex SDK if validation passes.
         * This ensures woocommerce_checkout_process is respected before payment.
         */
        bindSeparateMethodCards: function () {
            var self = this;
            this.syncThemePalette();
            var methodOptions = document.querySelectorAll('input[name="payme_selected_method"]');
            if (!methodOptions.length) return;

            methodOptions.forEach(function (option) {
                // Clone node to remove any previously attached listeners
                var fresh = option.cloneNode(true);
                option.parentNode.replaceChild(fresh, option);

                fresh.addEventListener('change', function () {
                    if (!fresh.checked) return;

                    // Visual: update selected card immediately
                    document.querySelectorAll('.payme-method-card').forEach(function (c) {
                        c.classList.remove('selected');
                    });
                    var card = fresh.closest('.payme-method-card');
                    if (card) card.classList.add('selected');

                    // Hide / clear all sub-method SDK containers
                    document.querySelectorAll('.payme-embedded-form[id^="payme-payment-form-"]').forEach(function (f) {
                        f.style.display = 'none';
                        f.innerHTML = '';
                    });

                    self.selectedMethod = fresh.value;

                    // Popup: selecting the method prepares the session, but does
                    // not open or initialize Flex until the final user click.
                    if (payme_params.display_mode === 'popup') {
                        self.invalidateFlexSession();
                        self.setPopupPreloadState(true);
                        self.queuePopupPreload(100);
                        return;
                    }

                    // Run WC validation before mounting the SDK (solo para Embebido)
                    var $form = $('form.checkout');
                    if (!$form.length) {
                        // No classic form found — load SDK directly (fallback)
                        self._loadSeparateSDK(fresh.value);
                        return;
                    }

                    // Block the button visually while we validate
                    var $btn = $('#place_order');
                    $btn.prop('disabled', true).text('\u23f3 Verificando...');
                    $('.payme-validation-errors').remove();

                    // UNIFIED FAST LOAD: We no longer do pre-validation AJAX here.
                    // _loadSeparateSDK -> getPaymentData will do validation and session in 1 request.
                    self._skipClientValidation = true;
                    self._loadSeparateSDK(fresh.value);
                });
            });
        },

        /**
         * _loadSeparateSDK — Mounts the Flex SDK inside the container for the given sub-method.
         */
        _loadSeparateSDK: function (methodValue) {
            var container = document.getElementById('payme-payment-form-' + methodValue)
                || document.getElementById('payme-flex-container');
            if (!container) return;

            container.style.display = 'block';
            this.isInitialized = false;
            this.isInitializing = false;
            this.getPaymentData(container);
        },

        /**
         * handleCheckoutSubmit — intercepta el evento checkout_place_order_payme de WC.
         *
         * IMPORTANT: Este evento dispara ANTES de que WC haga su AJAX de validación.
         * Por eso debemos correr la validación nativa nosotros mismos via nuestro
         * endpoint PHP payme_validate_checkout, que dispara woocommerce_checkout_process
         * con el POST data real del formulario.
         *
         * Flujo:
         * 1. Serializar el formulario de checkout.
         * 2. POST a payme_validate_checkout.
         * 3a. Si hay errores → inyectarlos en el DOM como WC lo haría y abortar.
         * 3b. Si todo OK → montar el Flex SDK.
         * Retornar false siempre previene que WC haga su propio POST.
         */
        handleCheckoutSubmit: function (e) {
            console.info('[Pay-me] Inicio de validación del checkout.');

            // Separado EMBEBIDO mode: payment is triggered exclusively by method card clicks
            // The #place_order button is hidden, so this handler shouldn't fire. If it does return true.
            // Separado POPUP mode: we DO need to handle it here!
            if (payme_params.payment_type === 'separado' && payme_params.display_mode !== 'popup') {
                return true;
            }

            // Separado POPUP mode fallback validador:
            if (payme_params.payment_type === 'separado' && payme_params.display_mode === 'popup') {
                if (!payme.selectedMethod) {
                    var checked = document.querySelector('input[name="payme_selected_method"]:checked');
                    if (checked) {
                        payme.selectedMethod = checked.value;
                    } else {
                        alert('Selecciona un sub-método de pago antes de continuar.');
                        return false;
                    }
                }
            }

            // Prevent double-execution
            if (payme.isInitializing) {
                console.warn('[Pay-me] Se evitó una inicialización duplicada.');
                return false;
            }

            var $form = $('form.checkout');
            if (!$form.length) {
                console.warn('[Pay-me] No se encontró el formulario del checkout.');
                return false;
            }

            // Block the button visually while we validate
            var $btn = $('#place_order');
            $btn.prop('disabled', true).text('⏳ Verificando...');

            // Remove any previous Payme-injected error block
            $('.payme-validation-errors').remove();

            payme.isInitializing = true;

            // UNIFIED FAST LOAD: We no longer do pre-validation AJAX here.
            // initializePaymeFlex -> getPaymentData will do validation and session in 1 request.
            payme._skipClientValidation = true;

            // Popup mode (Junto O Separado)
            if (payme_params.display_mode === 'popup') {
                if (!payme.popupPaymentData) {
                    payme.setPopupPreloadState(true);
                    payme.queuePopupPreload(0);
                } else {
                    payme.isInitializing = false;
                    $btn.prop('disabled', false);
                    payme.styleNativePlaceOrder && payme.styleNativePlaceOrder();
                    payme.openPaymentPopup();
                }
                return false;
            }

            // Embedded mode: if SDK already mounted, just keep it
            if (payme.isInitialized && payme.paymentForm) {
                payme.isInitializing = false;
                $btn.prop('disabled', false);
                payme.styleNativePlaceOrder && payme.styleNativePlaceOrder();
                return false;
            }

            // First time: mount the Flex SDK
            payme.isInitializing = false; // Reset before initializing SDK
            payme.initializePaymeFlex();

            return false; // Always block WC's own submit — we handle it
        },

        initializePaymeFlex: function () {
            if (this.isInitialized) {
                return;
            }

            // NOTE: billing validation was already done via the payme_validate_checkout
            // AJAX endpoint in handleCheckoutSubmit. Do NOT repeat it here for classic checkout
            // because a failure here shows nothing to the user (silent block).
            // For Blocks checkout we still gate here since there's no AJAX pre-validation.
            if (document.querySelector('.wc-block-checkout')) {
                var billingError = this.validateBilling();
                if (billingError) {
                    var container = this.getPaymeContainer();
                    if (container) {
                        this.showBillingWarning(container, billingError);
                    }
                    return;
                }
            }

            var container = this.getPaymeContainer();
            if (!container) {
                this.isInitialized = false;
                return;
            }

            this.isInitialized = true;

            // Check if we're in separate methods mode
            var separateMethods = document.querySelector('.payme-methods-list');
            if (separateMethods) {
                this.handleSeparateMethods(container);
                return;
            }

            this.getPaymentData(container);
        },

        handleSeparateMethods: function (container) {
            var self = this;
            var methodOptions = document.querySelectorAll('input[name="payme_selected_method"]');

            methodOptions.forEach(function (option) {
                option.addEventListener('change', function () {
                    if (option.checked) {
                        // Hide all payment forms
                        document.querySelectorAll('.payme-embedded-form').forEach(function (form) {
                            if (form.id.startsWith('payme-payment-form-')) {
                                form.style.display = 'none';
                                form.innerHTML = '';
                            }
                        });

                        // Update visual selection
                        document.querySelectorAll('.payme-method-card').forEach(function (card) {
                            card.classList.remove('selected');
                        });

                        var selectedCard = option.closest('.payme-method-card');
                        if (selectedCard) {
                            selectedCard.classList.add('selected');
                        }

                        var methodContainer = document.getElementById('payme-payment-form-' + option.value);
                        if (methodContainer) {
                            methodContainer.style.display = 'block';
                            self.selectedMethod = option.value;
                            self.getPaymentData(methodContainer);
                        } else {
                            var flexContainer = document.getElementById('payme-flex-container');
                            if (flexContainer) {
                                flexContainer.style.display = 'block';
                                self.selectedMethod = option.value;
                                self.getPaymentData(flexContainer);
                            }
                        }
                    }
                });
            });

            // Auto-select first method if available
            var firstMethod = document.querySelector('input[name="payme_selected_method"]:checked');
            if (firstMethod) {
                var firstCard = firstMethod.closest('.payme-method-card');
                if (firstCard) {
                    firstCard.classList.add('selected');
                }

                var methodContainer = document.getElementById('payme-payment-form-' + firstMethod.value);
                if (methodContainer) {
                    methodContainer.style.display = 'block';
                    this.selectedMethod = firstMethod.value;
                    this.getPaymentData(methodContainer);
                } else {
                    var flexContainer = document.getElementById('payme-flex-container');
                    if (flexContainer) {
                        flexContainer.style.display = 'block';
                        this.selectedMethod = firstMethod.value;
                        this.getPaymentData(flexContainer);
                    }
                }
            }
        },

        getPaymeContainer: function () {
            // If in Separado Embebido mode, find the active method's container
            if (this.selectedMethod) {
                var methodContainer = document.getElementById('payme-payment-form-' + this.selectedMethod);
                if (methodContainer && methodContainer.style.display !== 'none') {
                    return methodContainer;
                }
            }

            var container = document.getElementById('payme-flex-container');
            if (container) {
                return container;
            }

            container = document.getElementById('payme-payment-form');
            if (container) {
                container.id = 'payme-flex-container';
                return container;
            }

            var paymeMethod = document.querySelector('input[value="payme"]:checked');
            if (paymeMethod) {
                var paymentBox = paymeMethod.closest('.wc-block-components-radio-control__option') ||
                    paymeMethod.closest('.payment_method_payme') ||
                    paymeMethod.closest('li');

                if (paymentBox) {
                    var existingContainer = paymentBox.querySelector('#payme-flex-container');
                    if (existingContainer) {
                        return existingContainer;
                    }

                    var placeholder = paymentBox.querySelector('#payme-payment-form-blocks');
                    if (placeholder) {
                        placeholder.remove();
                    }

                    container = document.createElement('div');
                    container.id = 'payme-flex-container';
                    container.className = 'payme-embedded-form';
                    paymentBox.appendChild(container);
                    return container;
                }
            }

            return null;
        },

        showBillingWarning: function (container, message) {
            $('.payme-validation-errors').remove();
            var warningHtml = '<div class="payme-validation-errors woocommerce-error" style="margin-bottom:20px; font-weight:600;">' + message + '</div>';

            // In popup mode, the container is hidden, so we MUST prepend to the checkout form instead
            if (payme_params.display_mode === 'popup') {
                var $target = $('.woocommerce-notices-wrapper').first();
                var $form = $('form.checkout');
                if (!$target.length && $form.length) {
                    $('<div class="woocommerce-notices-wrapper"></div>').insertBefore($form);
                    $target = $form.prev('.woocommerce-notices-wrapper');
                }
                if ($target.length) {
                    $target.html(warningHtml);
                    $('html, body').animate({ scrollTop: $target.offset().top - 100 }, 400);
                }
            } else {
                $(container).prepend(warningHtml);
            }
        },

        showError: function (container, message) {
            $('.payme-validation-errors').remove();
            var warningHtml = '<div class="payme-validation-errors woocommerce-error" style="margin-bottom:20px; font-weight:600;">' + message + '</div>';
            if (payme_params.display_mode === 'popup') {
                var $target = $('.woocommerce-notices-wrapper').first();
                var $form = $('form.checkout');
                if (!$target.length && $form.length) {
                    $('<div class="woocommerce-notices-wrapper"></div>').insertBefore($form);
                    $target = $form.prev('.woocommerce-notices-wrapper');
                }
                if ($target.length) {
                    $target.html(warningHtml);
                    $('html, body').animate({ scrollTop: $target.offset().top - 100 }, 400);
                }
            } else {
                $(container).prepend(warningHtml);
            }
        },

        getPaymentData: function (container) {
            if (this.isInitializing) {
                console.warn('[Pay-me] Se evitó una solicitud paralela de sesión bancaria.');
                return;
            }
            this.isInitializing = true;
            // Only the latest request may initialize Flex. This also guarantees
            // that every accepted initialization receives its own fresh nonce.
            var requestId = ++this._paymentDataRequestSeq;

            // Garantizar ancho real del padre antes de montar el SDK //
            var parent = container.parentElement;
            if (parent) {
                var parentWidth = parent.getBoundingClientRect().width;
                if (parentWidth > 0) {
                    container.style.width = parentWidth + 'px';
                    void container.offsetWidth;
                    container.style.width = '100%';
                    void container.offsetWidth;
                }
            }

            // Validate billing fields before requesting payment data.
            // Skipped when _skipClientValidation is true (junto embebido already
            // ran the more authoritative PHP-side woocommerce_checkout_process).
            if (!this._skipClientValidation) {
                var billingError = this.validateBilling();
                if (billingError) {
                    this.isInitialized = false; // allow retry on next attempt
                    this.isInitializing = false;
                    this.showBillingWarning(container, billingError);
                    if (payme_params.display_mode === 'popup') {
                        this.setPopupPreloadState(true);
                    } else {
                        $('#place_order').prop('disabled', false).text($('#place_order').data('wc-original-text') || 'Place Order');
                    }
                    return;
                }
            }
            this._skipClientValidation = false; // reset flag after use

            var orderData = this.collectOrderData();

            if (this.selectedMethod) {
                orderData.selected_method = this.selectedMethod;
            }

            var $form = $('form.checkout');
            var formData = $form.length ? $form.serialize() : '';

            var self = this;
            $.ajax({
                url: payme_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'payme_get_payment_data',
                    nonce: payme_params.nonce,
                    order_data: JSON.stringify(orderData),
                    form_data: formData,
                    store_in_session: '1'
                },
                success: function (response) {
                    if (requestId !== self._paymentDataRequestSeq) {
                        return;
                    }
                    try {
                        $('.payme-validation-errors').remove();

                        if (response.success && response.data) {
                            self.loadPaymeFlex(container, response.data);
                        } else {
                            self.isInitialized = false; // allow retry

                            // Handle unified validation errors (WC style)
                            if (response.data && response.data.messages) {
                                var errorHtml = response.data.messages;
                                var $target = $('.woocommerce-notices-wrapper').first();
                                if (!$target.length && $form.length) {
                                    $('<div class="woocommerce-notices-wrapper"></div>').insertBefore($form);
                                    $target = $form.prev('.woocommerce-notices-wrapper');
                                }
                                if ($target.length) {
                                    $target.html('<div class="payme-validation-errors">' + errorHtml + '</div>');
                                    $('html, body').animate({ scrollTop: $target.offset().top - 100 }, 400);
                                }

                                // Let the system know validation failed so it can auto-retry later if needed
                                self._juntoValidationFailed = true;

                                // Re-enable place order button if we blocked it
                                $('#place_order').prop('disabled', false);
                                self.styleNativePlaceOrder && self.styleNativePlaceOrder();
                            } else {
                                // Standard API error
                                self.showError(container, (response.data && response.data.message) || payme_params.messages.error);
                                $('#place_order').prop('disabled', false);
                                self.styleNativePlaceOrder && self.styleNativePlaceOrder();
                            }
                            self.setPopupPreloadState(true);
                        }
                    } finally {
                        setTimeout(function () {
                            if (requestId !== self._paymentDataRequestSeq) return;
                            self.isInitializing = false;
                            if (response.success && response.data) {
                                self.isInitialized = true;
                            }
                        }, 150);
                    }
                },
                error: function (xhr, status, error) {
                    if (requestId !== self._paymentDataRequestSeq) return;
                    self.isInitialized = false; // allow retry on next attempt
                    self.isInitializing = false;
                    $('#place_order').prop('disabled', false);
                    self.styleNativePlaceOrder && self.styleNativePlaceOrder();
                    self.setPopupPreloadState(true);
                    console.error('[Payme] Error AJAX al obtener los datos de pago:', status, error);
                    self.showError(container, payme_params.messages.error);
                }
            });
        },

        isNodeVisible: function (el) {
            if (!el) return false;

            // Allow WooCommerce Select2 dropdowns which are visually hidden (1x1 pixels)
            var isSelect2 = el.classList && el.classList.contains('select2-hidden-accessible');

            // Unattached text nodes don't have bounding boxes, and unattached elements fail checks
            if (!isSelect2 && el.offsetParent === null && el !== document.body && el !== document.documentElement) {
                return false;
            }

            var current = el;
            while (current && current !== document.body) {
                if (current.classList && (current.classList.contains('processing') || current.classList.contains('blockUI'))) {
                    return true;
                }
                var style = window.getComputedStyle(current);
                if (style.display === 'none') {
                    return false;
                }
                if (!isSelect2 && (style.visibility === 'hidden' || style.opacity === '0')) {
                    return false;
                }
                current = current.parentElement;
            }

            if (!isSelect2) {
                var rect = el.getBoundingClientRect();
                if (rect.width < 2 || rect.height < 2) {
                    return false;
                }
            }
            return true;
        },

        findBillingNode: function (fieldKey) {
            var now = Date.now();
            if (this._fieldCache[fieldKey] !== undefined && (now - this._fieldCacheTime) < 5000) {
                return this._fieldCache[fieldKey];
            }

            var underscoreKey = 'billing_' + fieldKey;
            var hyphenKey = 'billing-' + fieldKey;
            var bracketKey = 'billing[' + fieldKey + ']';

            var selectors = [
                '#' + underscoreKey,
                '#' + hyphenKey,
                '#' + fieldKey,
                'input[name="' + underscoreKey + '"]',
                'input[name="' + hyphenKey + '"]',
                'input[name="' + bracketKey + '"]',
                'input[name="' + fieldKey + '"]',
                'select[name="' + underscoreKey + '"]',
                'select[name="' + hyphenKey + '"]',
                'select[name="' + bracketKey + '"]',
                'select[name="' + fieldKey + '"]',
                'select#' + underscoreKey,
                'select#' + hyphenKey,
                'select#' + fieldKey
            ];

            for (var i = 0; i < selectors.length; i++) {
                var el = document.querySelector(selectors[i]);
                if (el && this.isNodeVisible(el)) {
                    this._fieldCache[fieldKey] = el;
                    return el;
                }
            }

            var allFields = document.querySelectorAll('input[name*="' + fieldKey + '"], select[name*="' + fieldKey + '"], input[id*="' + fieldKey + '"], select[id*="' + fieldKey + '"]');
            for (var j = 0; j < allFields.length; j++) {
                if (this.isNodeVisible(allFields[j])) {
                    this._fieldCache[fieldKey] = allFields[j];
                    return allFields[j];
                }
            }

            var keywords = HEURISTICS[fieldKey];
            if (keywords && keywords.length > 0) {
                var inputs = document.querySelectorAll('form.checkout input[type="text"], form.checkout input[type="email"], form.checkout input[type="tel"], form.checkout select');
                for (var j = 0; j < inputs.length; j++) {
                    if (!this.isNodeVisible(inputs[j])) continue;

                    var inputName = (inputs[j].name || '').toLowerCase();
                    var inputId = (inputs[j].id || '').toLowerCase();
                    var fullRef = inputName + ' ' + inputId;

                    var isNonBilling = false;
                    for (var n = 0; n < NON_BILLING_SIGNALS.length; n++) {
                        if (fullRef.indexOf(NON_BILLING_SIGNALS[n]) !== -1) {
                            isNonBilling = true;
                            break;
                        }
                    }
                    if (isNonBilling) continue;

                    for (var k = 0; k < keywords.length; k++) {
                        if (inputName.indexOf(keywords[k]) !== -1 || inputId.indexOf(keywords[k]) !== -1) {
                            this._fieldCache[fieldKey] = inputs[j];
                            return inputs[j];
                        }
                    }
                }
            }

            this._fieldCache[fieldKey] = null;
            return null;
        },

        findBillingFieldValue: function (fieldKey) {
            var node = this.findBillingNode(fieldKey);
            return (node && node.value !== undefined) ? node.value.trim() : '';
        },

        billingFieldExists: function (fieldKey) {
            return this.findBillingNode(fieldKey) !== null;
        },

        validateBilling: function () {
            if (document.querySelector('.wc-block-checkout')) return null;

            var requiredFields = {};

            var configurableFields = {
                'first_name': { parameter: 'first_name', label: 'Nombre' },
                'last_name': { parameter: 'last_name', label: 'Apellido' },
                'email': { parameter: 'email', label: 'Correo electrónico' },
                'phone': { parameter: 'phone', label: 'Teléfono' },
                'address_1': { parameter: 'address', label: 'Dirección' },
                'city': { parameter: 'city', label: 'Ciudad' },
                'state': { parameter: 'state', label: 'Estado/Provincia' },
                'country': { parameter: 'country', label: 'País' }
            };
            var configuredModes = payme_params.payload_field_modes || {};
            Object.keys(configurableFields).forEach(function (billingKey) {
                var field = configurableFields[billingKey];
                if (configuredModes[field.parameter] !== 'static') {
                    requiredFields[billingKey] = field.label;
                }
            });

            var missingUser = [];  // Field present, but empty
            var missingStore = []; // Field completely hidden or not in DOM

            for (var key in requiredFields) {
                if (this.billingFieldExists(key)) {
                    var val = this.findBillingFieldValue(key);
                    if (!val || val.trim() === '') {
                        missingUser.push(requiredFields[key]);
                    }
                } else {
                    missingStore.push(requiredFields[key]);
                }
            }

            if (missingStore.length > 0) {
                return 'Alerta Técnica de Integración: Se han detectado campos obligatorios ausentes o inaccesibles en tu diseño de checkout (' + missingStore.join(', ') + '). Por motivos de seguridad, Pay-me requiere que estos campos existan y sean visibles para tus clientes.';
            }

            if (missingUser.length > 0) {
                return 'Completa tus datos de facturación: ' + missingUser.join(', ');
            }

            return null; // Aprobado
        },

        /**
         * Show the Flex payment form container (non-destructive — does not destroy the SDK).
         * Uses CSS visibility so an already-mounted SDK instance stays alive.
         */
        showPaymentForm: function () {
            var container = this.getPaymeContainer();
            if (!container) return;
            container.style.display = '';
            container.style.visibility = '';

            // Also show the popup button if in popup+junto mode
            var popupBtn = document.getElementById('payme-classic-popup-btn');
            if (popupBtn) popupBtn.style.display = '';
        },

        /**
         * Hide the Flex payment form container without destroying it.
         * Keeps the SDK instance intact so it can be revealed again without re-mounting.
         */
        hidePaymentForm: function () {
            var container = this.getPaymeContainer();
            if (container) {
                container.style.display = 'none';
            }

            // Also hide the popup button if in popup+junto mode
            var popupBtn = document.getElementById('payme-classic-popup-btn');
            if (popupBtn) popupBtn.style.display = 'none';
        },

        clearError: function (container) {
            if (!container) return;
            var existingWarning = container.querySelector('.payme-billing-warning');
            if (existingWarning) existingWarning.remove();

            // Also clear generic errors
            var existingError = container.querySelector('.woocommerce-error, .payme-error-box');
            if (existingError) existingError.remove();
        },

        refreshBillingState: function () {
            if (document.querySelector('.wc-block-checkout')) return;

            if (this._refreshTimer) clearTimeout(this._refreshTimer);
            var self = this;
            this._refreshTimer = setTimeout(function () {
                self._refreshTimer = null;
                self._doRefreshBillingState();
            }, 150);
        },

        _doRefreshBillingState: function () {
            this._fieldCache = {};
            this._fieldCacheTime = Date.now();

            var error = this.validateBilling();
            var banner = document.getElementById('payme-billing-banner');

            if (error) {
                if (banner) {
                    var pEl = banner.querySelector('p');
                    if (pEl) pEl.innerText = error;
                    banner.style.display = '';
                }
                // Hide the SDK form if it's already mounted
                this.hidePaymentForm();
            } else {
                if (banner) banner.style.display = 'none';
                // Only show the container — do NOT auto-mount the SDK.
                // The SDK mounts exclusively via checkout_place_order_payme (after WC validation).
                this.showPaymentForm();
            }
        },

        showBillingWarning: function (container, message) {
            this.isInitialized = false;

            // Update the PHP-rendered banner if it exists
            var phpBanner = document.getElementById('payme-billing-banner');
            if (phpBanner) {
                phpBanner.style.display = '';
                var ul = phpBanner.querySelector('ul');
                if (ul) {
                    ul.innerHTML = '<li><strong>Aviso de Facturación:</strong> ' + message + '</li>';
                }
                return;
            }

            // Otherwise show inline in the container via element injector
            if (container) {
                this.clearError(container);
                var wrapper = document.createElement('div');
                wrapper.className = 'payme-billing-warning';
                wrapper.style.padding = '15px';
                wrapper.style.backgroundColor = '#f8d7da';
                wrapper.style.color = '#721c24';
                wrapper.style.border = '1px solid #f5c6cb';
                wrapper.style.borderRadius = '4px';
                wrapper.style.marginBottom = '15px';
                wrapper.innerHTML = '<strong>Aviso de Facturación:</strong><br>' + message;
                container.prepend(wrapper);
            }
        },

        /**
         * Show/hide the PHP-rendered billing banner based on field completion.
         */
        checkBillingBanner: function (container) {
            if (document.querySelector('.wc-block-checkout')) return;

            var billingError = this.validateBilling();
            var banner = document.getElementById('payme-billing-banner');
            if (banner) {
                banner.style.display = billingError ? '' : 'none';
            }
        },

        collectOrderData: function () {
            if (document.querySelector('.wc-block-checkout')) {
                return this.collectBlocksOrderData();
            } else {
                return this.collectClassicOrderData();
            }
        },

        collectBlocksOrderData: function () {
            var orderData = {
                total: 0,
                currency: payme_params.currency || 'PEN',
                billing: {
                    first_name: '',
                    last_name: '',
                    email: '',
                    phone: '',
                    address_1: '',
                    address_2: '',
                    city: '',
                    state: '',
                    country: ''
                }
            };

            if (this.selectedMethod) {
                orderData.selected_method = this.selectedMethod;
            }

            try {
                if (window.wc && window.wc.wcBlocksData) {
                    var checkoutData = window.wc.wcBlocksData.checkout_data || {};
                    var cartData = window.wc.wcBlocksData.cart_data || {};

                    if (cartData.totals && cartData.totals.total_price) {
                        orderData.total = parseFloat(cartData.totals.total_price) / 100;
                    }

                    if (cartData.currency) {
                        orderData.currency = cartData.currency.code || 'PEN';
                    }

                    if (checkoutData.billingAddress) {
                        var billing = checkoutData.billingAddress;
                        orderData.billing = {
                            first_name: billing.first_name || '',
                            last_name: billing.last_name || '',
                            email: billing.email || '',
                            phone: billing.phone || '',
                            address_1: billing.address_1 || '',
                            address_2: billing.address_2 || '',
                            city: billing.city || '',
                            state: billing.state || '',
                            country: billing.country || '',
                            postcode: billing.postcode || ''
                        };
                    }
                }

                // If no total found from store, try known selectors
                if (orderData.total === 0) {
                    orderData.total = this.getCartTotalFromDOM();
                }

                if (!orderData.billing.first_name) {
                    orderData.billing = this.getBillingFromDOM();
                }

            } catch (error) {
                orderData.total = this.getCartTotalFromDOM();
                orderData.billing = this.getBillingFromDOM();
            }

            return orderData;
        },

        collectClassicOrderData: function () {
            var orderData = {
                total: this.getCartTotalFromDOM(),
                currency: this.getCurrency(),
                billing: this.getBillingFromDOM()
            };

            if (this.selectedMethod) {
                orderData.selected_method = this.selectedMethod;
            }

            return orderData;
        },

        getBillingFromDOM: function () {
            return {
                first_name: this.findBillingFieldValue('first_name'),
                last_name: this.findBillingFieldValue('last_name'),
                email: this.findBillingFieldValue('email'),
                phone: this.findBillingFieldValue('phone'),
                address_1: this.findBillingFieldValue('address_1') || this.findBillingFieldValue('address'),
                address_2: this.findBillingFieldValue('address_2'),
                city: this.findBillingFieldValue('city'),
                state: this.findBillingFieldValue('state'),
                country: this.findBillingFieldValue('country'),
                postcode: this.findBillingFieldValue('postcode') || this.findBillingFieldValue('zip')
            };
        },

        getCartTotalFromDOM: function () {
            // Try specific, known selectors only — no wildcard DOM scanning
            var selectors = [
                '.order-total .woocommerce-Price-amount bdi',
                '.order-total .woocommerce-Price-amount',
                '.wc-block-components-totals-footer-item .wc-block-formatted-money-amount',
                '.wc-block-components-totals-item__value .wc-block-formatted-money-amount',
                '.total .woocommerce-Price-amount bdi',
                '.total .woocommerce-Price-amount',
                '[data-testid="total-value"]',
                '.order_total .amount',
                '#order_review .order-total .amount'
            ];

            for (var i = 0; i < selectors.length; i++) {
                var element = document.querySelector(selectors[i]);
                if (element) {
                    var totalText = element.textContent || '';
                    var matches = totalText.match(/([\d,]+\.?\d*)/g);
                    if (matches && matches.length > 0) {
                        var numbers = matches.map(function (m) { return parseFloat(m.replace(/,/g, '')); });
                        var total = Math.max.apply(null, numbers);
                        if (total > 0) {
                            return total;
                        }
                    }
                }
            }

            // Try WooCommerce JS objects
            if (typeof wc_checkout_params !== 'undefined' && wc_checkout_params.cart_total) {
                var cartTotal = parseFloat(wc_checkout_params.cart_total);
                if (cartTotal > 0) return cartTotal;
            }

            return 0;
        },

        getCurrency: function () {
            return payme_params.currency || 'PEN';
        },

        loadPaymeFlex: function (container, paymentData) {
            if (container) {
                container.innerHTML = '';
                container.classList.add('loading');
            }
            var self = this;

            // Popup mode preloads the SDK assets and server session, but does
            // not initialize Flex. The modal is opened only by a user click.
            if (payme_params.display_mode === 'popup') {
                this.loadFlexSDK(function () {
                    self.popupPaymentData = paymentData;
                    if (container) container.classList.remove('loading');

                    if (typeof window._paymeBlocksOnReady === 'function') {
                        window._paymeBlocksOnReady();
                    }

                    var $btn = $('#place_order');
                    if ($btn.length) {
                        self.styleNativePlaceOrder && self.styleNativePlaceOrder();
                    }
                    self.setPopupPreloadState(false);
                }, function () {
                    self.popupPaymentData = null;
                    self.isInitialized = false;
                    self.setPopupPreloadState(true);
                });
            } else {
                this.mountFlexSdk(container, paymentData);
            }
        },

        /**
         * Mount Flex directly in the existing DOM node, following Pay-me's
         * documented integration. Avoiding an extra iframe lets the official
         * responsive CSS use the real mobile viewport and container width.
         */
        mountFlexSdk: function (container, paymentData) {
            var self = this;

            if (!container || !paymentData || !paymentData.nonce || paymentData._paymeFlexConsumed) {
                this.invalidateFlexSession();
                if (container) this.showError(container, payme_params.messages.error);
                console.error('[Payme] Se bloqueó una inicialización de Flex sin un nonce válido.');
                return;
            }
            if (typeof FlexPaymentForms !== 'function') {
                this.invalidateFlexSession();
                this.showError(container, payme_params.messages.error);
                console.error('[Payme] La librería oficial Flex no está disponible.');
                return;
            }

            paymentData._paymeFlexConsumed = true;
            var flexSessionId = this._paymentDataRequestSeq;
            var isPopupTarget = container.id === 'payme-modal-flex-form';

            if (this._activeFlexCleanup) this._activeFlexCleanup();
            container.innerHTML = '';
            container.style.width = isPopupTarget ? PAYME_POPUP_NATURAL_WIDTH + 'px' : '100%';
            container.style.maxWidth = isPopupTarget ? 'none' : '100%';
            container.style.minWidth = '0';
            container.style.boxSizing = 'border-box';
            container.style.overflow = 'visible';
            container.style.opacity = isPopupTarget ? '0' : '1';

            var flexSettings = {
                show_close_button: true,
                show_border: true,
                show_operation_number: true
            };
            if (payme_params.hide_animation === 'yes') {
                flexSettings.display_result_screen = false;
            }

            var flexConfig = {
                nonce: paymentData.nonce,
                payload: paymentData.payload,
                settings: flexSettings,
                display_settings: paymentData.display_settings
            };
            if (paymentData.i18n) flexConfig.i18n = paymentData.i18n;

            var observer = null;
            var readyNotified = false;
            var applyTheme = function () {
                if (!isPopupTarget || !window.getComputedStyle) return;

                self.syncThemePalette();
                var overlay = document.getElementById('payme-modal-overlay');
                if (!overlay) return;

                var overlayStyles = window.getComputedStyle(overlay);
                var accent = overlayStyles.getPropertyValue('--payme-theme-accent').trim();
                var foreground = overlayStyles.getPropertyValue('--payme-theme-on-accent').trim();
                var fontFamily = overlayStyles.getPropertyValue('--payme-theme-font').trim();

                if (fontFamily) container.style.fontFamily = fontFamily;
                if (!accent) return;

                container.querySelectorAll('button').forEach(function (button) {
                    var label = [
                        button.getAttribute('aria-label') || '',
                        button.getAttribute('title') || '',
                        button.textContent || ''
                    ].join(' ').trim();
                    var isPrimaryAction = button.getAttribute('type') === 'submit'
                        || /pagar|pay now|pay|continuar|continue|finalizar/i.test(label);

                    if (!isPrimaryAction) return;
                    button.style.setProperty('background-color', accent, 'important');
                    button.style.setProperty('border-color', accent, 'important');
                    if (foreground) button.style.setProperty('color', foreground, 'important');
                    if (fontFamily) button.style.setProperty('font-family', fontFamily, 'important');
                });
            };
            var closeHandler = function (event) {
                var button = event.target && event.target.closest
                    ? event.target.closest('button, [role="button"]')
                    : null;
                if (!button) return;
                var closeLabel = [
                    button.getAttribute('aria-label') || '',
                    button.getAttribute('title') || '',
                    button.textContent || ''
                ].join(' ').trim();
                if (/cerrar|close|^[x×✕]$/i.test(closeLabel)) {
                    setTimeout(function () { self.closePaymentModal(true); }, 0);
                }
            };
            var notifyReady = function () {
                if (flexSessionId !== self._paymentDataRequestSeq) return;
                if (!container.firstElementChild) return;
                applyTheme();
                if (readyNotified) return;
                readyNotified = true;
                container.classList.remove('loading', 'payme-loading');
                container.classList.add('visible');
                container.style.opacity = '1';
                if (typeof window._paymeBlocksOnReady === 'function') {
                    window._paymeBlocksOnReady();
                }
            };
            var cleanup = function () {
                container.removeEventListener('click', closeHandler, true);
                if (observer) observer.disconnect();
                if (self._activeFlexCleanup === cleanup) self._activeFlexCleanup = null;
            };

            this._activeFlexCleanup = cleanup;
            container.addEventListener('click', closeHandler, true);
            if (window.MutationObserver) {
                observer = new MutationObserver(function (mutations) {
                    var hasSdkChange = mutations.some(function (mutation) {
                        return mutation.target !== container;
                    });
                    if (!hasSdkChange) return;
                    notifyReady();
                });
                observer.observe(container, {
                    childList: true,
                    subtree: true
                });
            }

            try {
                this.paymentForm = new FlexPaymentForms(flexConfig);
                this.paymentForm.init(
                    container,
                    function (response) {
                        if (flexSessionId !== self._paymentDataRequestSeq) return;
                        cleanup();
                        self.responseCallback(response);
                    },
                    function (tracking) {
                        if (flexSessionId === self._paymentDataRequestSeq) {
                            self.trackingCallback(tracking);
                        }
                    },
                    function (error) {
                        if (flexSessionId !== self._paymentDataRequestSeq) return;
                        cleanup();
                        self.onErrorCallback(error);
                    }
                );
                window.requestAnimationFrame(notifyReady);
                [50, 150, 300, 600].forEach(function (delay) {
                    setTimeout(function () {
                        notifyReady();
                    }, delay);
                });
            } catch (error) {
                cleanup();
                this.onErrorCallback({ message: error && error.message ? error.message : String(error) });
            }
        },

        // Retained temporarily for backwards reference only. New integrations
        // use the direct DOM mount above, as required by the official guide.
        _mountIframeSdkLegacy: function (container, paymentData) {
            var self = this;

            // The banking nonce is single-use. Refuse to initialize Flex twice
            // with the same payment-data object; the next user attempt will go
            // through getPaymentData() and request a new nonce from the server.
            if (!paymentData || !paymentData.nonce || paymentData._paymeFlexConsumed) {
                this.invalidateFlexSession();
                if (container) {
                    this.showError(container, payme_params.messages.error);
                }
                console.error('[Payme] Se bloqueó una inicialización de Flex sin un nonce válido.');
                return;
            }
            paymentData._paymeFlexConsumed = true;
            var flexSessionId = this._paymentDataRequestSeq;

            var iframe = document.createElement('iframe');
            iframe.style.cssText = 'display:block;width:100%;max-width:100%;border:none;background:transparent;overflow:hidden;min-height:380px;transition:height 0.3s ease;';
            iframe.scrolling = 'no';
            container.appendChild(iframe);

            // Opciones oficiales de Pay-me Flex. Se aplican tanto al formulario
            // embebido como al que se monta dentro del modal.
            var flexSettings = {
                show_close_button: true,
                show_operation_number: true
            };
            if (payme_params.hide_animation === 'yes') {
                flexSettings.display_result_screen = false;
            }

            var flexConfig = {
                nonce: paymentData.nonce,
                payload: paymentData.payload,
                settings: flexSettings,
                display_settings: paymentData.display_settings
            };
            if (paymentData.i18n) {
                flexConfig.i18n = paymentData.i18n;
            }

            var safeConfig = JSON.stringify(flexConfig).replace(/</g, '\\u003c').replace(/>/g, '\\u003e');
            var iframeId = 'payme_if_' + Math.random().toString(36).substr(2, 9);

            var srcdocHtml = `<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="${payme_params.flex_css_url}">
    <script src="${payme_params.flex_js_url}"></script>
    <style>
        html, body { width: 100%; max-width: 100%; margin: 0; padding: 0; background: transparent; overflow-x: hidden; font-family: sans-serif; }
        #flex-root { width: 100%; max-width: 100%; min-width: 0; }
        ::-webkit-scrollbar { display: none; }
    </style>
</head>
<body>
    <div id="flex-root"></div>
    <script>
        window.onload = function() {
            if (typeof FlexPaymentForms !== 'function') {
                window.parent.postMessage({ id: '${iframeId}', type: 'FLEX_ERROR', payload: { message: 'SDK bloqueado o no cargado en el Iframe.' } }, '*');
                return;
            }
            try {
                var config = ${safeConfig};
                var form = new FlexPaymentForms(config);
                form.init(
                    document.getElementById('flex-root'),
                    function(r) { window.parent.postMessage({ id: '${iframeId}', type: 'FLEX_SUCCESS', payload: r }, '*'); },
                    function(r) { window.parent.postMessage({ id: '${iframeId}', type: 'FLEX_TRACKING', payload: r }, '*'); },
                    function(r) { window.parent.postMessage({ id: '${iframeId}', type: 'FLEX_ERROR', payload: r }, '*'); }
                );

                // El botón de cierre pertenece al SDK. Solo notificamos al padre
                // para retirar el overlay exterior cuando el usuario lo pulse.
                document.addEventListener('click', function(event) {
                    var target = event.target;
                    var button = target && target.closest
                        ? target.closest('button, [role="button"]')
                        : null;
                    if (!button) return;

                    var closeLabel = [
                        button.getAttribute('aria-label') || '',
                        button.getAttribute('title') || '',
                        button.textContent || ''
                    ].join(' ').trim();

                    if (/cerrar|close|^[x×✕]$/i.test(closeLabel)) {
                        window.parent.postMessage({ id: '${iframeId}', type: 'FLEX_CLOSE' }, '*');
                    }
                }, true);

                var ro = new ResizeObserver(function(entries) {
                    var h = document.body.scrollHeight;
                    window.parent.postMessage({ id: '${iframeId}', type: 'FLEX_RESIZE', height: h }, '*');
                });
                ro.observe(document.body);

                window.parent.postMessage({ id: '${iframeId}', type: 'FLEX_READY' }, '*');
            } catch (e) {
                window.parent.postMessage({ id: '${iframeId}', type: 'FLEX_ERROR', payload: { message: e.toString() } }, '*');
            }
        };
    </script>
</body>
</html>`;

            var msgListener = function (event) {
                if (!event.data || event.data.id !== iframeId) return;
                if (flexSessionId !== self._paymentDataRequestSeq) {
                    cleanupMessageListener();
                    return;
                }

                switch (event.data.type) {
                    case 'FLEX_SUCCESS':
                        cleanupMessageListener();
                        self.responseCallback(event.data.payload);
                        break;
                    case 'FLEX_TRACKING':
                        self.trackingCallback(event.data.payload);
                        break;
                    case 'FLEX_ERROR':
                        cleanupMessageListener();
                        self.onErrorCallback(event.data.payload);
                        break;
                    case 'FLEX_CLOSE':
                        cleanupMessageListener();
                        self.closePaymentModal(true);
                        break;
                    case 'FLEX_RESIZE':
                        var newHeight = event.data.height + 5;
                        iframe.style.height = Math.max(380, newHeight) + 'px';
                        break;
                    case 'FLEX_READY':
                        container.classList.remove('loading', 'payme-loading');
                        container.classList.add('visible');

                        payme.checkBillingBanner(container);

                        if (typeof window._paymeBlocksOnReady === 'function') {
                            window._paymeBlocksOnReady();
                        }
                        break;
                }
            };

            var cleanupMessageListener = function () {
                window.removeEventListener('message', msgListener);
                if (self._activeFlexCleanup === cleanupMessageListener) {
                    self._activeFlexCleanup = null;
                }
            };

            if (this._activeFlexCleanup) this._activeFlexCleanup();
            this._activeFlexCleanup = cleanupMessageListener;
            window.addEventListener('message', msgListener);
            iframe.srcdoc = srcdocHtml;
        },

        openPaymentPopup: function () {
            if (!this.popupPaymentData) {
                this.setPopupPreloadState(true);
                this.queuePopupPreload(0);
                return false;
            }

            var self = this;
            var data = this.popupPaymentData;

            // Remove any existing modal
            var existing = document.getElementById('payme-modal-overlay');
            if (existing) return;

            // Consume the session before mounting. It must never remain cached
            // while Flex is active because reopening it would reuse the nonce.
            this.popupPaymentData = null;

            // Create fullscreen modal overlay
            var overlay = document.createElement('div');
            overlay.id = 'payme-modal-overlay';
            overlay.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;height:100dvh;background:rgba(0,0,0,0.6);z-index:2147483646;display:flex;flex-direction:column;align-items:flex-start;justify-content:flex-start;padding:15px 20px;box-sizing:border-box;overflow:auto;overscroll-behavior:contain;animation:payme-fade-in 0.2s ease;';

            var modal = document.createElement('div');
            modal.className = 'payme-checkout-modal';
            modal.style.cssText = 'background:transparent;width:' + PAYME_POPUP_NATURAL_WIDTH + 'px;height:auto;max-width:none;max-height:none;overflow:visible;position:relative;box-sizing:border-box;flex:0 0 auto;margin:auto;animation:payme-slide-up 0.25s ease;';

            // Body — Flex form goes here
            var body = document.createElement('div');
            body.className = 'payme-checkout-modal-body';
            body.style.cssText = 'padding:0;';

            var flexTarget = document.createElement('div');
            flexTarget.id = 'payme-modal-flex-form';
            flexTarget.style.cssText = 'width:' + PAYME_POPUP_NATURAL_WIDTH + 'px;max-width:none;min-height:300px;background:transparent;margin:0;padding:0;overflow:visible;';
            body.appendChild(flexTarget);

            modal.appendChild(body);
            overlay.appendChild(modal);

            // Add animation keyframes
            var style = document.createElement('style');
            style.textContent = '@keyframes payme-fade-in{from{opacity:0}to{opacity:1}}'
                + '@keyframes payme-slide-up{from{transform:translateY(30px);opacity:0}to{transform:translateY(0);opacity:1}}';
            overlay.appendChild(style);

            // Click outside to close
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) {
                    self.closePaymentModal(true);
                }
            });

            document.body.appendChild(overlay);
            document.body.classList.add('payme-modal-open');
            this.syncThemePalette();
            this._bodyOverflowBeforeModal = document.body.style.overflow;
            document.body.style.overflow = 'hidden';

            // Mount the official Flex component in its natural-width wrapper.
            this.mountFlexSdk(flexTarget, data);
            return true;
        },

        closePaymentModal: function (prepareNextAttempt) {
            var overlay = document.getElementById('payme-modal-overlay');
            var wasModalOpen = !!overlay || document.body.classList.contains('payme-modal-open');
            if (overlay) {
                overlay.remove();
            }
            if (wasModalOpen) {
                document.body.classList.remove('payme-modal-open');
                document.body.style.overflow = this._bodyOverflowBeforeModal || '';
                this._bodyOverflowBeforeModal = null;
            }

            // CRITICAL FIX: The Alignet session token is strictly ONE-TIME USE.
            // If the user closes the modal, we must clear the cached payment data 
            // so that clicking "Place Order" again fetches a fresh token.
            this.invalidateFlexSession();

            if (typeof window._paymeBlocksOnPopupClosed === 'function') {
                window._paymeBlocksOnPopupClosed();
            }
            if (prepareNextAttempt) {
                this.setPopupPreloadState(true);
                this.queuePopupPreload(150);
            }

            if (this._modalFlexInstance) {
                try { this._modalFlexInstance.terminate && this._modalFlexInstance.terminate(); } catch (e) { }
                this._modalFlexInstance = null;
            }
        },

        responseCallback: function (response) {
            // Frontend Debounce Lock: Previene ejecución doble si el usuario hace clic frenéticamente 
            // en el botón de retorno mientras la cuenta regresiva automática de Alignet corre.
            if (this._isProcessingResponseCallback) {
                console.warn('[Payme] Se evitó procesar dos veces la respuesta del SDK.');
                return;
            }
            this._isProcessingResponseCallback = true;

            // Ensure modal is closed if it exists
            this.closePaymentModal(false);
            // The Flex SDK only calls the success callback when payment is approved.
            // We trust the SDK and send the result to the server for order creation.

            // Terminate Flex SDK
            try {
                if (this.paymentForm && this.paymentForm.terminate) {
                    this.paymentForm.terminate();
                }
            } catch (e) {
                console.warn('[Payme] No se pudo finalizar la instancia de Flex:', e);
            }

            // Show processing overlay immediately
            this.showProcessingOverlay();

            $.ajax({
                url: payme_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'payme_process_payment_result',
                    nonce: payme_params.nonce,
                    payment_result: JSON.stringify(response)
                },
                success: function (ajaxResponse) {
                    if (ajaxResponse.success && ajaxResponse.data.redirect) {
                        window.location.replace(ajaxResponse.data.redirect);
                    } else {
                        // Payment denied or error — redirect to order-received page if available
                        if (ajaxResponse.data && ajaxResponse.data.redirect) {
                            window.location.replace(ajaxResponse.data.redirect);
                        } else {
                            payme.removeProcessingOverlay();
                            window.location.replace(payme_params.checkout_url || window.location.href);
                        }
                    }
                },
                error: function () {
                    payme.removeProcessingOverlay();
                    window.location.replace(payme_params.checkout_url || window.location.href);
                }
            });
        },

        getErrorMessage: function (response) {
            if (!response) return null;
            if (response.message) return response.message;
            if (response.error && response.error.message) return response.error.message;
            if (response.meta && response.meta.status && response.meta.status.message) {
                return response.meta.status.message;
            }
            return null;
        },

        responseCodes: {
            '00': 'Pago Exitoso',
            '01': 'Denegado por el emisor',
            '03': 'Datos de comercio Inválidos',
            '04': 'Denegado por el emisor',
            '05': 'Denegado por el emisor',
            '08': 'Denegado por el emisor',
            '10': 'Aprobación Parcial Denegada',
            '12': 'Transacción no válida',
            '13': 'Monto invalido',
            '14': 'Número de cuenta no válido',
            '15': 'Emisor no disponible',
            '30': 'Error durante el proceso emisor',
            '41': 'Denegado por el emisor',
            '43': 'Denegado por el emisor',
            '51': 'Fondos insuficientes',
            '54': 'Tarjeta expirada',
            '55': 'PIN incorrecto',
            '57': 'La transacción no es permitida',
            '58': 'La transacción no es permitida',
            '61': 'Excede el límite del monto',
            '62': 'Denegado por el emisor',
            '63': 'Violación de seguridad',
            '65': 'Error durante el proceso emisor',
            '70': 'Denegado por el emisor',
            '71': 'Error de clave PIN',
            '75': 'Excedió el numero de intentos',
            '76': 'Error durante el proceso emisor',
            '77': 'Error durante el proceso emisor',
            '78': 'Error durante el proceso emisor',
            '79': 'Denegado por la marca',
            '81': 'Denegado por el emisor',
            '82': 'Denegado por la marca',
            '83': 'Denegado por el emisor',
            '84': 'Denegado por el emisor',
            '85': 'Denegado por el emisor',
            '86': 'Error durante el proceso emisor',
            '87': 'Denegado por el emisor',
            '88': 'Error de ingreso de datos',
            '89': 'Denegado por el emisor',
            '91': 'Error durante el proceso emisor',
            '92': 'Error durante el proceso emisor',
            '94': 'Numero pedido duplicado',
            '96': 'Error durante el proceso emisor'
        },

        getResponseCodeMessage: function (code) {
            if (!code && code !== 0) return 'El pago no pudo ser procesado. Inténtalo nuevamente.';
            var strCode = String(code).padStart(2, '0');
            var msg = this.responseCodes[strCode];
            if (msg) return msg + ' (Código: ' + strCode + ')';
            return 'El pago no pudo ser procesado. Código: ' + strCode;
        },

        trackingCallback: function (trackData) {
            // Tracking data from Payme Flex — no action needed
        },

        onErrorCallback: function (error) {
            // Ensure modal is closed if it exists on error
            this.closePaymentModal(false);
            this.isInitializing = false;

            var container = document.getElementById('payme-flex-container');
            if (container) {
                this.showError(container, payme_params.messages.error);
            }
            // Notify Blocks component on error too
            if (typeof window._paymeBlocksOnReady === 'function') {
                window._paymeBlocksOnReady();
            }
            if (payme_params.display_mode === 'popup') {
                this.setPopupPreloadState(true);
                this.queuePopupPreload(250);
            }
        },

        showError: function (container, message) {
            container.innerHTML = '<div class="payme-error" style="padding: 15px; background: #fee; border: 1px solid #fcc; border-radius: 5px; color: #c33; margin: 10px 0;">⚠️ ' + message + '</div>';
            this.isInitialized = false;
        },

        showLoading: function () {
            var container = this.getPaymeContainer();
            if (container) {
                this.showLoadingInContainer(container);
            }
        },

        showLoadingInContainer: function (container) {
            container.innerHTML = '<div class="payme-loading" style="padding: 20px; text-align: center; background: #f9f9f9; border-radius: 8px; margin: 10px 0; border: 1px solid #e0e0e0;"><div style="display: inline-block; width: 20px; height: 20px; border: 3px solid #f3f3f3; border-top: 3px solid #0073aa; border-radius: 50%; animation: payme-spin 1s linear infinite; margin-right: 10px;"></div><span style="color: #666; font-size: 14px;">' + payme_params.messages.loading + '</span></div><style>@keyframes payme-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }</style>';
        },

        hidePaymentForm: function () {
            var container = document.getElementById('payme-flex-container') || document.getElementById('payme-payment-form');
            if (container) {
                container.innerHTML = '';
            }

            this.isInitialized = false;
            this.paymentForm = null;

            if (this.blocksInterval) {
                clearInterval(this.blocksInterval);
                this.blocksInterval = null;
            }
        },

        showProcessingOverlay: function () {
            if (document.getElementById('payme-processing-overlay')) return;
            var overlay = document.createElement('div');
            overlay.id = 'payme-processing-overlay';
            overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(255,255,255,0.92);z-index:999999;display:flex;align-items:center;justify-content:center;flex-direction:column;';
            overlay.innerHTML = '<div style="width:40px;height:40px;border:3px solid #e5e7eb;border-top:3px solid #3b82f6;border-radius:50%;animation:payme-spin 0.8s linear infinite;margin-bottom:16px;"></div>'
                + '<p style="font-size:16px;color:#1e293b;font-weight:600;margin:0;">Procesando tu pago...</p>'
                + '<p style="font-size:13px;color:#64748b;margin:6px 0 0;">Serás redirigido en un momento</p>';
            document.body.appendChild(overlay);
        },

        removeProcessingOverlay: function () {
            var overlay = document.getElementById('payme-processing-overlay');
            if (overlay) overlay.remove();
        }
    };

    // Initialize
    payme.init();

    // Exposed globally — required by payme-blocks.js for Blocks checkout integration
    window.payme = payme;
});
