const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { createElement } = window.wp.element;
const { __ } = window.wp.i18n;
const { decodeEntities } = window.wp.htmlEntities;
const { getSetting } = window.wc.wcSettings;

// Get settings with fallback
const settings = getSetting('payme_data', {
    title: 'Payme Gateway',
    description: 'Paga con tarjeta, Yape, QR y más métodos',
    supports: ['products'],
    icon: ''
});

const defaultLabel = __('Payme Gateway', 'payme-gateway');
const label = decodeEntities(settings.title) || defaultLabel;

const methodIcons = {
    'CARD': 'tarjeta.png',
    'YAPE': 'yape.png',
    'QR': 'qr.png',
    'BANK_TRANSFER': 'transbank.png',
    'CUOTEALO': 'cuotealo.png',
    'PAGOEFECTIVO': 'pagoefectivo.png'
};

const methodNames = {
    'CARD': 'Tarjeta de Crédito/Débito',
    'YAPE': 'Yape',
    'QR': 'Código QR',
    'BANK_TRANSFER': 'Transferencia Bancaria',
    'CUOTEALO': 'Cuotéalo BCP',
    'PAGOEFECTIVO': 'PagoEfectivo'
};

const pluginUrl = settings.plugin_url || '';

/**
 * Content component for Payme payment method
 */
const Content = () => {
    const { useEffect, useState, useCallback } = window.wp.element;
    const [isInitialized, setIsInitialized] = useState(false);
    const [isLoading, setIsLoading] = useState(false);
    const [selectedMethod, setSelectedMethod] = useState(null);
    const [billingError, setBillingError] = useState(null);

    const paymentType = settings.settings?.payment_type || 'junto';
    const paymentMethods = settings.settings?.payment_methods || ['CARD'];
    const displayMode = settings.settings?.display_mode || 'embedded';

    const validateBilling = useCallback(() => {
        const fields = [
            { id: 'email', label: 'Correo electrónico' },
            { id: 'billing-first_name', label: 'Nombre' },
            { id: 'billing-last_name', label: 'Apellido' },
            { id: 'billing-address_1', label: 'Dirección' },
            { id: 'billing-city', label: 'Ciudad' },
            { id: 'billing-phone', label: 'Teléfono' },
        ];
        const missing = [];
        for (const f of fields) {
            const input = document.getElementById(f.id);
            if (!input || !input.value.trim()) {
                missing.push(f.label);
            }
        }
        if (missing.length > 0) {
            return 'Completa los siguientes campos para continuar: ' + missing.join(', ');
        }
        return null;
    }, []);

    const onFlexReady = useCallback(() => {
        setIsLoading(false);
        const placeOrderBtn = document.querySelector('.wc-block-components-checkout-place-order-button');
        if (placeOrderBtn) placeOrderBtn.style.display = 'none';
        const terms = document.querySelector('.wc-block-checkout__terms');
        if (terms) terms.style.display = 'none';
    }, []);

    useEffect(() => {
        window._paymeBlocksOnReady = onFlexReady;

        // Hide Place Order button and terms immediately when Payme is selected
        const placeOrderBtn = document.querySelector('.wc-block-components-checkout-place-order-button');
        if (placeOrderBtn) placeOrderBtn.style.display = 'none';
        const terms = document.querySelector('.wc-block-checkout__terms');
        if (terms) terms.style.display = 'none';

        return () => {
            delete window._paymeBlocksOnReady;
            const placeOrderBtn = document.querySelector('.wc-block-components-checkout-place-order-button');
            if (placeOrderBtn) placeOrderBtn.style.display = '';
            const terms = document.querySelector('.wc-block-checkout__terms');
            if (terms) terms.style.display = '';
        };
    }, [onFlexReady]);

    useEffect(() => {
        if (paymentType === 'separado') return;
        if (isInitialized) return;
        // In popup mode, don't auto-initialize — wait for the "Pagar" button click
        if (displayMode === 'popup') return;
        setIsLoading(true);
        const initializePayme = () => {
            setTimeout(() => {
                const error = validateBilling();
                if (error) {
                    setBillingError(error);
                    setIsLoading(false);
                    return;
                }
                setBillingError(null);
                const container = document.getElementById('payme-flex-container');
                if (container && typeof window.payme !== 'undefined') {
                    window.payme.isInitialized = false;
                    window.payme.initializePaymeFlex();
                    setIsInitialized(true);
                } else {
                    setTimeout(initializePayme, 1000);
                }
            }, 100);
        };
        initializePayme();
    }, [paymentType, isInitialized, displayMode]);

    const handleMethodSelect = useCallback((method) => {
        setSelectedMethod(method);

        // Clear ALL flex containers so old form disappears
        document.querySelectorAll('.payme-flex-accordion-body').forEach(el => {
            el.innerHTML = '';
        });

        const error = validateBilling();
        if (error) {
            setBillingError(error);
            setIsLoading(false);
            const placeOrderBtn = document.querySelector('.wc-block-components-checkout-place-order-button');
            if (placeOrderBtn) placeOrderBtn.style.display = '';
            return;
        }
        setBillingError(null);
        setIsLoading(true);

        const placeOrderBtn = document.querySelector('.wc-block-components-checkout-place-order-button');
        if (placeOrderBtn) placeOrderBtn.style.display = 'none';

        setTimeout(() => {
            const container = document.getElementById('payme-flex-' + method);
            if (typeof window.payme !== 'undefined' && container) {
                window.payme.selectedMethod = method;
                window.payme.isInitialized = false;

                if (displayMode === 'popup') {
                    // For popup mode: get payment data via AJAX, then show button
                    window.payme.getPaymentData(container);
                    // The _paymeBlocksOnReady callback will set popupReady=true
                } else {
                    window.payme.getPaymentData(container);
                }
            }
        }, 150);
    }, [validateBilling, displayMode]);

    // ── SEPARADO MODE: Accordion layout ──
    if (paymentType === 'separado') {
        return createElement('div', {
            className: 'payme-accordion-wrapper'
        }, [
            createElement('p', {
                key: 'title',
                style: { fontWeight: '600', marginBottom: '10px', fontSize: '14px' }
            }, 'Selecciona el medio de Pago'),

            ...paymentMethods.map((method) => {
                const methodName = methodNames[method] || method;
                const methodIconFile = methodIcons[method] || '';
                const isSelected = selectedMethod === method;
                const isOpen = isSelected;

                return createElement('div', {
                    key: method,
                    className: 'payme-accordion-item' + (isSelected ? ' payme-accordion-active' : ''),
                    style: {
                        border: isSelected ? '2px solid #007cba' : '1px solid #e0e0e0',
                        borderRadius: '8px',
                        marginBottom: '6px',
                        overflow: 'hidden',
                        transition: 'border-color 0.2s ease',
                        background: '#fff'
                    }
                }, [
                    // Accordion header (clickable row)
                    createElement('div', {
                        key: 'header',
                        className: 'payme-accordion-header',
                        style: {
                            display: 'flex',
                            alignItems: 'center',
                            padding: '12px 16px',
                            cursor: 'pointer',
                            background: isSelected ? '#f0f8ff' : '#fff',
                            transition: 'background 0.2s ease'
                        },
                        onClick: () => {
                            if (!isSelected || billingError) {
                                handleMethodSelect(method);
                            }
                        }
                    }, [
                        // Radio circle
                        createElement('div', {
                            key: 'radio',
                            style: {
                                width: '18px',
                                height: '18px',
                                borderRadius: '50%',
                                border: isSelected ? '2px solid #007cba' : '2px solid #ccc',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                marginRight: '12px',
                                flexShrink: 0
                            }
                        }, isSelected ? createElement('div', {
                            key: 'dot',
                            style: {
                                width: '10px',
                                height: '10px',
                                borderRadius: '50%',
                                background: '#007cba'
                            }
                        }) : null),
                        // Icon
                        methodIconFile && pluginUrl ? createElement('img', {
                            key: 'icon',
                            src: pluginUrl + 'assets/images/methods/' + methodIconFile,
                            alt: methodName,
                            style: { width: '24px', height: '24px', marginRight: '10px', objectFit: 'contain', flexShrink: 0 }
                        }) : null,
                        // Name
                        createElement('span', {
                            key: 'name',
                            style: { fontWeight: isSelected ? '600' : '400', fontSize: '14px' }
                        }, methodName)
                    ]),

                    // Accordion body: billing error, spinner, or flex container
                    // In popup mode, hide body once modal is open (not loading anymore)
                    isOpen && (billingError || isLoading || displayMode !== 'popup') ? createElement('div', {
                        key: 'body',
                        style: {
                            borderTop: '1px solid #e8e8e8',
                            padding: '0'
                        }
                    }, [
                        // Billing error
                        billingError ? createElement('div', {
                            key: 'billing-error',
                            className: 'payme-billing-warning',
                            style: {
                                padding: '16px 20px',
                                background: 'linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%)',
                                border: '1px solid #f59e0b',
                                borderLeft: '4px solid #f59e0b',
                                borderRadius: '8px',
                                margin: '12px 16px',
                                boxShadow: '0 1px 3px rgba(245,158,11,0.15)'
                            }
                        }, [
                            createElement('div', { key: 'header', style: { display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '8px' } }, [
                                createElement('span', { key: 'icon', style: { fontSize: '24px' } }, '📋'),
                                createElement('strong', { key: 'title', style: { fontSize: '15px', color: '#1e293b' } }, 'Completa tus datos para continuar')
                            ]),
                            createElement('p', { key: 'msg', style: { margin: 0, color: '#92400e', fontSize: '13px', lineHeight: '1.6' } }, ['⚠️ ', billingError])
                        ]) : null,

                        // Spinner (while loading)
                        isLoading && !billingError ? createElement('div', {
                            key: 'spinner',
                            className: 'payme-loading',
                            style: {
                                padding: '30px 16px',
                                textAlign: 'center',
                                color: '#666',
                                fontSize: '14px'
                            }
                        }, 'Cargando pasarela de pago...') : null,

                        // Flex container for this method (embedded mode)
                        createElement('div', {
                            key: 'flex',
                            id: 'payme-flex-' + method,
                            className: 'payme-flex-accordion-body payme-embedded-form',
                            style: {
                                display: !billingError && displayMode !== 'popup' ? 'block' : 'none',
                                minHeight: !billingError && isLoading && displayMode !== 'popup' ? '200px' : '0',
                                padding: '12px 16px'
                            }
                        })
                    ]) : null
                ]);
            })
        ]);
    }

    // ── JUNTO MODE (default) ──
    return createElement('div', {
        className: 'wc-block-components-payment-method-content wc-block-components-payment-method-content--payme'
    }, [
        createElement('p', { key: 'description' },
            decodeEntities(settings.description || 'Paga con tarjeta, Yape, QR y más métodos')),

        // Show "Pagar con Payme" button for modal+junto mode
        displayMode === 'popup' && !isLoading ? createElement('button', {
            key: 'popup-btn',
            type: 'button',
            className: 'payme-popup-pay-btn',
            style: {
                display: 'block', width: '100%', padding: '14px 24px', marginTop: '12px',
                background: 'linear-gradient(135deg, #3b82f6 0%, #2563eb 100%)',
                color: '#fff', border: 'none', borderRadius: '8px', fontSize: '16px',
                fontWeight: '600', cursor: 'pointer', textAlign: 'center',
                boxShadow: '0 2px 8px rgba(59,130,246,0.3)',
                transition: 'all 0.2s ease'
            },
            onClick: function() {
                var error = validateBilling();
                if (error) {
                    setBillingError(error);
                    return;
                }
                setBillingError(null);
                setIsLoading(true);
                var container = document.getElementById('payme-flex-container');
                if (container && typeof window.payme !== 'undefined') {
                    window.payme.isInitialized = false;
                    window.payme.initializePaymeFlex();
                    setIsInitialized(true);
                }
            }
        }, '💳 Pagar con Payme') : null,

        billingError ? createElement('div', {
            key: 'billing-error',
            className: 'payme-billing-warning',
            style: {
                padding: '16px 20px', marginTop: '12px',
                background: 'linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%)',
                border: '1px solid #f59e0b', borderLeft: '4px solid #f59e0b',
                borderRadius: '8px', boxShadow: '0 1px 3px rgba(245,158,11,0.15)'
            }
        }, [
            createElement('div', { key: 'header', style: { display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '8px' } }, [
                createElement('span', { key: 'icon', style: { fontSize: '24px' } }, '📋'),
                createElement('strong', { key: 'title', style: { fontSize: '15px', color: '#1e293b' } }, 'Completa tus datos para continuar')
            ]),
            createElement('p', { key: 'msg', style: { margin: 0, color: '#92400e', fontSize: '13px', lineHeight: '1.6' } }, ['⚠️ ', billingError])
        ]) : null,

        // Loading indicator (only for embedded mode, not popup)
        isLoading && displayMode !== 'popup' ? createElement('div', {
            key: 'loading',
            className: 'payme-loading',
            style: { padding: '40px 20px', textAlign: 'center', color: '#666', fontSize: '14px' }
        }, 'Cargando pasarela de pago...') : null,

        createElement('div', {
            key: 'payme-form',
            id: 'payme-flex-container',
            className: 'payme-embedded-form',
            style: isLoading || displayMode === 'popup' ? { display: 'none' } : {}
        })
    ]);
};

/**
 * Label component for Payme payment method
 */
const Label = () => {
    return createElement('span', {
        style: { width: '100%', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }
    }, [
        createElement('span', { key: 'label' }, label),
        settings.icon ? createElement('img', {
            key: 'icon',
            src: settings.icon,
            style: { maxHeight: '24px', maxWidth: '80px' },
            alt: label
        }) : null
    ]);
};

/**
 * Payme payment method configuration
 */
const PaymePaymentMethod = {
    name: 'payme',
    label: createElement(Label),
    content: createElement(Content),
    edit: createElement(Content),
    canMakePayment: () => true,
    ariaLabel: label,
    supports: {
        features: settings.supports || ['products']
    }
};

registerPaymentMethod(PaymePaymentMethod);
