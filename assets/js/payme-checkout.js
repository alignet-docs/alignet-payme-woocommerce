jQuery(document).ready(function($) {
    'use strict';
    
    if (typeof payme_params === 'undefined') {
        return;
    }
    
    var payme = {
        paymentForm: null,
        isInitialized: false,
        
        init: function() {
            this.bindEvents();
            this.detectCheckoutType();
            this.hideClassicPlaceOrder();
        },
        
        detectCheckoutType: function() {
            if (document.querySelector('.wc-block-checkout')) {
                // Blocks checkout — don't initialize classic support
                return;
            } else {
                this.initClassicSupport();
            }
        },

        /**
         * Hide "Place Order" button and terms/conditions in classic checkout when Payme is selected
         * (Flex SDK handles the payment flow, so the native button and terms must be hidden)
         */
        hideClassicPlaceOrder: function() {
            // Only for classic checkout
            if (document.querySelector('.wc-block-checkout')) return;

            var self = this;
            var toggle = function() {
                var selected = $('input[name="payment_method"]:checked').val();
                var btn = $('#place_order');
                var terms = $('.woocommerce-terms-and-conditions-wrapper');
                if (selected === 'payme') {
                    btn.hide();
                    terms.hide();
                } else {
                    btn.show();
                    terms.show();
                }
            };

            $('body').on('change', 'input[name="payment_method"]', toggle);
            $(document.body).on('updated_checkout', toggle);
            // Initial check
            setTimeout(toggle, 500);
        },
        
        initBlocksSupport: function() {
            this.monitorBlocksPaymentSelection();
        },
        
        initClassicSupport: function() {
            $('body').on('change', 'input[name="payment_method"]', this.handlePaymentMethodChange.bind(this));

            // Re-validate billing when user fills in required fields
            var billingFields = '#billing_first_name, #billing_last_name, #billing_email, #billing_address_1, #billing_city, #billing_phone, input[name="billing_first_name"], input[name="billing_last_name"], input[name="billing_email"], input[name="billing_address_1"], input[name="billing_city"], input[name="billing_phone"]';
            $('body').on('change blur', billingFields, function() {
                // If Payme is selected but not initialized (blocked by billing validation), retry
                var selected = $('input[name="payment_method"]:checked').val();
                if (selected === 'payme' && !payme.isInitialized) {
                    payme.initializePaymeFlex();
                }
            });
            
            $(document.body).on('updated_checkout', function() {
                // Reset isInitialized because WooCommerce re-renders payment HTML
                payme.isInitialized = false;

                const selectedMethod = $('input[name="payment_method"]:checked').val();
                if (selectedMethod === 'payme') {
                    // Don't auto-initialize for popup+junto — wait for button click
                    if (payme_params.display_mode === 'popup' && payme_params.payment_type !== 'separado') {
                        return;
                    }
                    setTimeout(() => {
                        payme.initializePaymeFlex();
                    }, 500);
                }
            });

            // Initial check: if Payme is already selected on page load
            setTimeout(function() {
                var selected = $('input[name="payment_method"]:checked').val();
                if (selected === 'payme' && !payme.isInitialized) {
                    if (payme_params.display_mode === 'popup' && payme_params.payment_type !== 'separado') {
                        return;
                    }
                    payme.initializePaymeFlex();
                }
            }, 800);

            // Junto + Modal: bind the "Pagar con Payme" button
            $('body').on('click', '#payme-classic-popup-btn', function(e) {
                e.preventDefault();
                if (!payme.isInitialized) {
                    payme.initializePaymeFlex();
                }
            });
        },
        
        monitorBlocksPaymentSelection: function() {
            var observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.type === 'childList') {
                        var paymeInput = document.querySelector('input[value="payme"]:checked');
                        if (paymeInput && !payme.isInitialized) {
                            setTimeout(function() {
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
            
            var checkInterval = setInterval(function() {
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
        
        bindEvents: function() {
            $('form.checkout').on('checkout_place_order_payme', this.handleCheckoutSubmit);
        },
        
        handlePaymentMethodChange: function() {
            var selectedMethod = $('input[name="payment_method"]:checked').val();
            
            if (selectedMethod === 'payme') {
                this.isInitialized = false;
                this.paymentForm = null;
                
                // Don't auto-initialize for popup+junto — wait for button click
                if (payme_params.display_mode === 'popup' && payme_params.payment_type !== 'separado') {
                    return;
                }
                
                setTimeout(() => {
                    this.initializePaymeFlex();
                }, 50);
            } else {
                this.hidePaymentForm();
            }
        },
        
        initializePaymeFlex: function() {
            if (this.isInitialized) {
                return;
            }
            
            // Validate billing fields before initializing (Classic checkout only)
            if (!document.querySelector('.wc-block-checkout')) {
                var billingError = this.validateBilling();
                if (billingError) {
                    var container = this.getPaymeContainer();
                    if (container) {
                        this.showBillingWarning(container, billingError);
                    }
                    return;
                }
            }

            this.isInitialized = true;
            
            var container = this.getPaymeContainer();
            if (!container) {
                return;
            }
            
            // Check if we're in separate methods mode
            var separateMethods = document.querySelector('.payme-methods-list');
            if (separateMethods) {
                this.handleSeparateMethods(container);
                return;
            }
            
            this.getPaymentData(container);
        },
        
        handleSeparateMethods: function(container) {
            var self = this;
            var methodOptions = document.querySelectorAll('input[name="payme_selected_method"]');
            
            methodOptions.forEach(function(option) {
                option.addEventListener('change', function() {
                    if (option.checked) {
                        // Hide all payment forms
                        document.querySelectorAll('.payme-embedded-form').forEach(function(form) {
                            if (form.id.startsWith('payme-payment-form-')) {
                                form.style.display = 'none';
                                form.innerHTML = '';
                            }
                        });
                        
                        // Update visual selection
                        document.querySelectorAll('.payme-method-card').forEach(function(card) {
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
        
        getPaymeContainer: function() {
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
        
        getPaymentData: function(container) {
            // Validate billing fields before requesting payment data
            var billingError = this.validateBilling();
            if (billingError) {
                this.showBillingWarning(container, billingError);
                return;
            }

            var orderData = this.collectOrderData();
            
            if (this.selectedMethod) {
                orderData.selected_method = this.selectedMethod;
            }
            
            $.ajax({
                url: payme_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'payme_get_payment_data',
                    nonce: payme_params.nonce,
                    order_data: JSON.stringify(orderData)
                },
                success: function(response) {
                    if (response.success) {
                        payme.loadPaymeFlex(container, response.data);
                    } else {
                        payme.showError(container, response.data.message || payme_params.messages.error);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[Payme] AJAX error:', status, error);
                    payme.showError(container, payme_params.messages.error);
                }
            });
        },

        validateBilling: function() {
            // For Blocks checkout, validation is handled in payme-blocks.js
            if (document.querySelector('.wc-block-checkout')) return null;

            // Try multiple selectors for each field (different WooCommerce versions use different IDs/names)
            var fields = [
                { sels: ['#billing_first_name', 'input[name="billing_first_name"]', 'input[name="billing[first_name]"]'], label: 'Nombre' },
                { sels: ['#billing_last_name', 'input[name="billing_last_name"]', 'input[name="billing[last_name]"]'], label: 'Apellido' },
                { sels: ['#billing_email', 'input[name="billing_email"]', 'input[name="billing[email]"]', 'input[type="email"]'], label: 'Correo electrónico' },
                { sels: ['#billing_address_1', 'input[name="billing_address_1"]', 'input[name="billing[address_1]"]'], label: 'Dirección' },
                { sels: ['#billing_city', 'input[name="billing_city"]', 'input[name="billing[city]"]'], label: 'Ciudad' },
                { sels: ['#billing_phone', 'input[name="billing_phone"]', 'input[name="billing[phone]"]', 'input[type="tel"]'], label: 'Teléfono' }
            ];
            var missing = [];
            for (var i = 0; i < fields.length; i++) {
                var found = false;
                for (var j = 0; j < fields[i].sels.length; j++) {
                    var el = document.querySelector(fields[i].sels[j]);
                    if (el && el.value && el.value.trim()) {
                        found = true;
                        break;
                    }
                }
                if (!found) {
                    missing.push(fields[i].label);
                }
            }
            if (missing.length > 0) {
                return 'Completa los siguientes campos para continuar: ' + missing.join(', ');
            }
            return null;
        },

        showBillingWarning: function(container, message) {
            // Update the PHP-rendered banner if it exists, otherwise show inline
            var phpBanner = document.getElementById('payme-billing-banner');
            if (phpBanner) {
                phpBanner.style.display = '';
            }
            // Don't inject into the container — leave it empty so user sees the banner above
            this.isInitialized = false;
        },

        /**
         * Show/hide the PHP-rendered billing banner based on field completion.
         */
        checkBillingBanner: function(container) {
            if (document.querySelector('.wc-block-checkout')) return;

            var billingError = this.validateBilling();
            var banner = document.getElementById('payme-billing-banner');
            if (banner) {
                banner.style.display = billingError ? '' : 'none';
            }
        },
        
        collectOrderData: function() {
            if (document.querySelector('.wc-block-checkout')) {
                return this.collectBlocksOrderData();
            } else {
                return this.collectClassicOrderData();
            }
        },
        
        collectBlocksOrderData: function() {
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
                    country: 'PE'
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
                            country: billing.country || 'PE',
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
        
        collectClassicOrderData: function() {
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
        
        getBillingFromDOM: function() {
            return {
                first_name: $('#billing_first_name').val() || $('input[name*="first_name"]').val() || $('input[id*="first_name"]').val() || '',
                last_name: $('#billing_last_name').val() || $('input[name*="last_name"]').val() || $('input[id*="last_name"]').val() || '',
                email: $('#billing_email').val() || $('input[name*="email"]').val() || $('input[id*="email"]').val() || '',
                phone: $('#billing_phone').val() || $('input[name*="phone"]').val() || $('input[id*="phone"]').val() || '',
                address_1: $('#billing_address_1').val() || $('input[name*="address_1"]').val() || $('input[id*="address_1"]').val() || '',
                address_2: $('#billing_address_2').val() || $('input[name*="address_2"]').val() || $('input[id*="address_2"]').val() || '',
                city: $('#billing_city').val() || $('input[name*="city"]').val() || $('input[id*="city"]').val() || '',
                state: $('#billing_state').val() || $('input[name*="state"]').val() || $('input[id*="state"]').val() || '',
                country: $('#billing_country').val() || $('input[name*="country"]').val() || $('input[id*="country"]').val() || 'PE',
                postcode: $('#billing_postcode').val() || $('input[name*="postcode"]').val() || $('input[id*="postcode"]').val() || ''
            };
        },
        
        getCartTotalFromDOM: function() {
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
                        var numbers = matches.map(function(m) { return parseFloat(m.replace(/,/g, '')); });
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
        
        getCurrency: function() {
            return payme_params.currency || 'PEN';
        },
        
        loadPaymeFlex: function(container, paymentData) {
            container.innerHTML = '';
            container.classList.add('loading');
            
            if (payme_params.display_mode === 'popup') {
                // Popup mode: store payment data and open modal immediately
                this.popupPaymentData = paymentData;
                container.classList.remove('loading');

                // Notify Blocks to stop showing spinner
                if (typeof window._paymeBlocksOnReady === 'function') {
                    window._paymeBlocksOnReady();
                }

                // Open modal right away
                this.openPaymentPopup();
            } else {
                var flexForm = document.createElement('div');
                flexForm.id = 'payme-flex-form';
                flexForm.className = 'payme-flex-form';
                container.appendChild(flexForm);
                
                try {
                    var flexSettings = { show_close_button: false };
                    if (payme_params.hide_animation === 'yes') {
                        flexSettings.display_result_screen = false;
                    }

                    var paymentForm = new FlexPaymentForms({
                        nonce: paymentData.nonce,
                        payload: paymentData.payload,
                        settings: flexSettings,
                        display_settings: paymentData.display_settings
                    });
                    
                    paymentForm.init(
                        flexForm,
                        this.responseCallback.bind(this),
                        this.trackingCallback.bind(this),
                        this.onErrorCallback.bind(this)
                    );
                    
                    this.paymentForm = paymentForm;
                    
                    setTimeout(function() {
                        container.classList.remove('loading');
                        container.classList.add('visible');
                        // Hide operation number text generated by Flex
                        payme.hideOperationNumber(container);
                        // Retry after Flex fully renders
                        setTimeout(function() { payme.hideOperationNumber(container); }, 1500);
                        setTimeout(function() { payme.hideOperationNumber(container); }, 3000);
                        // Watch for late-rendered operation number text
                        payme.observeOperationNumber(container);
                        // Show billing incomplete banner if needed
                        payme.checkBillingBanner(container);
                        // Notify Blocks component that Flex is ready
                        if (typeof window._paymeBlocksOnReady === 'function') {
                            window._paymeBlocksOnReady();
                        }
                    }, 500);
                    
                } catch (error) {
                    container.classList.remove('loading');
                    this.showError(container, payme_params.messages.error);
                }
            }
        },

        openPaymentPopup: function() {
            if (!this.popupPaymentData) {
                window.location.replace(payme_params.checkout_url || window.location.href);
                return;
            }

            var self = this;
            var data = this.popupPaymentData;
            var hideAnim = payme_params.hide_animation === 'yes';

            // Build Flex settings
            var flexSettings = { show_close_button: false };
            if (hideAnim) flexSettings.display_result_screen = false;

            // Remove any existing modal
            var existing = document.getElementById('payme-modal-overlay');
            if (existing) existing.remove();

            // Create fullscreen modal overlay
            var overlay = document.createElement('div');
            overlay.id = 'payme-modal-overlay';
            overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:999999;display:flex;align-items:center;justify-content:center;animation:payme-fade-in 0.2s ease;';

            var modal = document.createElement('div');
            modal.style.cssText = 'background:#fff;border-radius:12px;width:95%;max-width:560px;max-height:90vh;overflow-y:auto;position:relative;box-shadow:0 20px 60px rgba(0,0,0,0.3);animation:payme-slide-up 0.25s ease;';

            // Header with close button only (no title)
            var header = document.createElement('div');
            header.style.cssText = 'display:flex;align-items:center;justify-content:flex-end;padding:8px 12px;';

            var closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.style.cssText = 'background:none;border:none;cursor:pointer;padding:4px;color:#94a3b8;font-size:20px;line-height:1;';
            closeBtn.innerHTML = '✕';
            closeBtn.addEventListener('click', function() {
                self.closePaymentModal();
            });
            header.appendChild(closeBtn);

            // Body — Flex form goes here
            var body = document.createElement('div');
            body.style.cssText = 'padding:16px 20px 20px;';

            var flexTarget = document.createElement('div');
            flexTarget.id = 'payme-modal-flex-form';
            flexTarget.className = 'payme-loading';
            flexTarget.style.cssText = 'min-height:300px;';
            body.appendChild(flexTarget);

            modal.appendChild(header);
            modal.appendChild(body);
            overlay.appendChild(modal);

            // Add animation keyframes
            var style = document.createElement('style');
            style.textContent = '@keyframes payme-fade-in{from{opacity:0}to{opacity:1}}'
                + '@keyframes payme-slide-up{from{transform:translateY(30px);opacity:0}to{transform:translateY(0);opacity:1}}';
            overlay.appendChild(style);

            document.body.appendChild(overlay);
            document.body.style.overflow = 'hidden';

            // Initialize Flex SDK inside the modal
            try {
                var pf = new FlexPaymentForms({
                    nonce: data.nonce,
                    payload: data.payload,
                    settings: flexSettings,
                    display_settings: data.display_settings
                });

                self._modalFlexInstance = pf;

                pf.init(
                    flexTarget,
                    function(resp) {
                        // Success
                        try { pf.terminate && pf.terminate(); } catch (e) {}
                        self.closePaymentModal();
                        self.responseCallback(resp);
                    },
                    function(trackData) {
                        // Tracking — no action
                    },
                    function(err) {
                        // Error
                        self.closePaymentModal();
                        self.onErrorCallback(err);
                    }
                );

                // Remove loading state after Flex renders
                setTimeout(function() {
                    flexTarget.classList.remove('payme-loading');
                    self.hideOperationNumber(flexTarget);
                    setTimeout(function() { self.hideOperationNumber(flexTarget); }, 1500);
                    self.observeOperationNumber(flexTarget);
                }, 500);

            } catch (err) {
                self.closePaymentModal();
                window.location.replace(payme_params.checkout_url || window.location.href);
            }
        },

        closePaymentModal: function() {
            var overlay = document.getElementById('payme-modal-overlay');
            if (overlay) {
                overlay.remove();
            }
            document.body.style.overflow = '';
            if (this._modalFlexInstance) {
                try { this._modalFlexInstance.terminate && this._modalFlexInstance.terminate(); } catch (e) {}
                this._modalFlexInstance = null;
            }
        },

        responseCallback: function(response) {
            // The Flex SDK only calls the success callback when payment is approved.
            // We trust the SDK and send the result to the server for order creation.

            // Terminate Flex SDK
            try {
                if (this.paymentForm && this.paymentForm.terminate) {
                    this.paymentForm.terminate();
                }
            } catch (e) {
                console.warn('Payme terminate:', e);
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
                success: function(ajaxResponse) {
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
                error: function() {
                    payme.removeProcessingOverlay();
                    window.location.replace(payme_params.checkout_url || window.location.href);
                }
            });
        },
        
        getErrorMessage: function(response) {
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

        getResponseCodeMessage: function(code) {
            if (!code && code !== 0) return 'El pago no pudo ser procesado. Inténtalo nuevamente.';
            var strCode = String(code).padStart(2, '0');
            var msg = this.responseCodes[strCode];
            if (msg) return msg + ' (Código: ' + strCode + ')';
            return 'El pago no pudo ser procesado. Código: ' + strCode;
        },
        
        trackingCallback: function(trackData) {
            // Tracking data from Payme Flex — no action needed
        },
        
        onErrorCallback: function(error) {
            var container = document.getElementById('payme-flex-container');
            if (container) {
                this.showError(container, payme_params.messages.error);
            }
            // Notify Blocks component on error too
            if (typeof window._paymeBlocksOnReady === 'function') {
                window._paymeBlocksOnReady();
            }
        },
        
        showError: function(container, message) {
            container.innerHTML = '<div class="payme-error" style="padding: 15px; background: #fee; border: 1px solid #fcc; border-radius: 5px; color: #c33; margin: 10px 0;">⚠️ ' + message + '</div>';
            this.isInitialized = false;
        },
        
        showLoading: function() {
            var container = this.getPaymeContainer();
            if (container) {
                this.showLoadingInContainer(container);
            }
        },
        
        showLoadingInContainer: function(container) {
            container.innerHTML = '<div class="payme-loading" style="padding: 20px; text-align: center; background: #f9f9f9; border-radius: 8px; margin: 10px 0; border: 1px solid #e0e0e0;"><div style="display: inline-block; width: 20px; height: 20px; border: 3px solid #f3f3f3; border-top: 3px solid #0073aa; border-radius: 50%; animation: payme-spin 1s linear infinite; margin-right: 10px;"></div><span style="color: #666; font-size: 14px;">' + payme_params.messages.loading + '</span></div><style>@keyframes payme-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }</style>';
        },
        
        hideOperationNumber: function(container) {
            // Hide "Operacion Nro.XXXXXXXXX" text rendered by Flex SDK
            var allNodes = container.querySelectorAll('*');
            for (var i = 0; i < allNodes.length; i++) {
                var node = allNodes[i];
                var text = node.textContent || '';
                if (/Operacion\s+Nro/i.test(text) && node.children.length === 0) {
                    node.style.display = 'none';
                }
            }
            // Also check direct text nodes of the flex form
            var flexForm = container.querySelector('#payme-flex-form');
            if (flexForm) {
                var childNodes = flexForm.childNodes;
                for (var j = 0; j < childNodes.length; j++) {
                    if (childNodes[j].nodeType === 3 && /Operacion\s+Nro/i.test(childNodes[j].textContent)) {
                        childNodes[j].textContent = '';
                    }
                }
            }
        },
        
        observeOperationNumber: function(container) {
            // MutationObserver to catch late-rendered operation number text
            if (this._opNumberObserver) {
                this._opNumberObserver.disconnect();
            }
            var self = this;
            this._opNumberObserver = new MutationObserver(function() {
                self.hideOperationNumber(container);
            });
            this._opNumberObserver.observe(container, { childList: true, subtree: true, characterData: true });
            // Stop observing after 10 seconds
            setTimeout(function() {
                if (self._opNumberObserver) {
                    self._opNumberObserver.disconnect();
                    self._opNumberObserver = null;
                }
            }, 10000);
        },
        
        hidePaymentForm: function() {
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

        showProcessingOverlay: function() {
            if (document.getElementById('payme-processing-overlay')) return;
            var overlay = document.createElement('div');
            overlay.id = 'payme-processing-overlay';
            overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(255,255,255,0.92);z-index:999999;display:flex;align-items:center;justify-content:center;flex-direction:column;';
            overlay.innerHTML = '<div style="width:40px;height:40px;border:3px solid #e5e7eb;border-top:3px solid #3b82f6;border-radius:50%;animation:payme-spin 0.8s linear infinite;margin-bottom:16px;"></div>'
                + '<p style="font-size:16px;color:#1e293b;font-weight:600;margin:0;">Procesando tu pago...</p>'
                + '<p style="font-size:13px;color:#64748b;margin:6px 0 0;">Serás redirigido en un momento</p>';
            document.body.appendChild(overlay);
        },

        removeProcessingOverlay: function() {
            var overlay = document.getElementById('payme-processing-overlay');
            if (overlay) overlay.remove();
        },
        
        handleCheckoutSubmit: function(e) {
            var selectedMethod = $('input[name="payment_method"]:checked').val();
            if (selectedMethod === 'payme') {
                return false;
            }
            return true;
        }
    };
    
    // Initialize
    payme.init();
    
    // Exposed globally — required by payme-blocks.js for Blocks checkout integration
    window.payme = payme;
});
