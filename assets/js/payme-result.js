(function () {
    'use strict';

    function setFeedback(button, message) {
        var feedback = button.parentNode.querySelector('.payme-result__copy-feedback');
        if (!feedback) return;
        feedback.textContent = message;
        window.setTimeout(function () {
            feedback.textContent = '';
        }, 2200);
    }

    function fallbackCopy(value) {
        var input = document.createElement('textarea');
        input.value = value;
        input.setAttribute('readonly', 'readonly');
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.appendChild(input);
        input.select();
        var copied = document.execCommand('copy');
        document.body.removeChild(input);
        return copied;
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-payme-copy]');
        if (!button) return;

        var value = button.getAttribute('data-payme-copy') || '';
        var messages = window.payme_result_i18n || {};
        var onSuccess = function () {
            button.classList.add('payme-result__copy-button--copied');
            setFeedback(button, messages.copied || 'Copiado');
            window.setTimeout(function () {
                button.classList.remove('payme-result__copy-button--copied');
            }, 2200);
        };
        var onError = function () {
            setFeedback(button, messages.copy_error || 'No se pudo copiar');
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(value).then(onSuccess, onError);
        } else {
            fallbackCopy(value) ? onSuccess() : onError();
        }
    });
}());
