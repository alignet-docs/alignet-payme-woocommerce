(function ($) {
    'use strict';

    $(function () {
        var $card = $('#payme-payload-configuration');
        if (!$card.length) return;

        var $field = $('input.payme-payload-configuration-data').first();
        if (!$field.length) return;
        var fields = $card.find('[data-payload-field]').map(function () {
            return $(this).data('payload-field');
        }).get();
        var configuration = {};

        try {
            configuration = JSON.parse($field.val() || '{}');
            if (!configuration || Array.isArray(configuration) || typeof configuration !== 'object') {
                configuration = {};
            }
        } catch (error) {
            configuration = {};
        }

        function getEntry(field) {
            var entry = configuration[field] || {};
            return {
                mode: entry.mode === 'static' ? 'static' : 'dynamic',
                value: typeof entry.value === 'string' ? entry.value : ''
            };
        }

        function setEntry(field, mode, value) {
            if (mode === 'static') {
                configuration[field] = { mode: 'static', value: value || '' };
            } else {
                delete configuration[field];
            }
            $field.val(JSON.stringify(configuration));
        }

        function render() {
            fields.forEach(function (field) {
                var entry = getEntry(field);
                var $row = $card.find('[data-payload-field="' + field + '"]');
                $row.find('[data-mode]').each(function () {
                    var active = $(this).data('mode') === entry.mode;
                    $(this).toggleClass('active', active).attr('aria-pressed', active ? 'true' : 'false');
                });
                $row.toggleClass('is-static', entry.mode === 'static');
                $row.find('.payme-payload-static-value').val(entry.value).prop('required', entry.mode === 'static');
                $row.find('.payme-payload-field-error').text('');
            });
        }

        function focusField(field) {
            var $input = $card.find('[data-payload-field="' + field + '"] .payme-payload-static-value');
            $input.trigger('focus');
        }

        function validateConfiguration() {
            var invalid = null;
            fields.some(function (field) {
                var entry = configuration[field];
                if (entry && entry.mode === 'static' && !String(entry.value || '').trim()) {
                    invalid = { field: field };
                    return true;
                }
                return false;
            });

            $card.find('.payme-payload-field-error').text('');
            if (!invalid) return true;

            focusField(invalid.field);
            $card.find('[data-payload-field="' + invalid.field + '"] .payme-payload-field-error')
                .text('El valor estático es obligatorio.');
            return false;
        }

        $card.on('click', '[data-mode]', function () {
            var $row = $(this).closest('[data-payload-field]');
            var field = $row.data('payload-field');
            var mode = $(this).data('mode');
            var value = $row.find('.payme-payload-static-value').val();
            setEntry(field, mode, value);
            render();
            if (mode === 'static') $row.find('.payme-payload-static-value').trigger('focus');
        });

        $card.on('input', '.payme-payload-static-value', function () {
            var $row = $(this).closest('[data-payload-field]');
            setEntry($row.data('payload-field'), 'static', $(this).val());
            $row.find('.payme-payload-field-error').text('');
        });

        $card.closest('form').on('submit.paymePayload', function (event) {
            if (!validateConfiguration()) {
                event.preventDefault();
                event.stopImmediatePropagation();
                return false;
            }
        });

        render();
    });
})(jQuery);
