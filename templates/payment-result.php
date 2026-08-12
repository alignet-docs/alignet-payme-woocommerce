<?php
/**
 * Pay-me unified result template.
 *
 * Available variables: $data and $order.
 */

if (!defined('ABSPATH')) {
    exit;
}

$classes = array('payme-result', 'payme-result--' . sanitize_html_class($data['state']));
foreach ($data['custom_classes'] as $custom_class) {
    $classes[] = $custom_class;
}
$has_continue_action = !empty($data['continue_url']);
?>
<section class="<?php echo esc_attr(implode(' ', $classes)); ?>"
    <?php if (!empty($data['primary_color'])) : ?>style="--payme-primary-color: <?php echo esc_attr($data['primary_color']); ?>;"<?php endif; ?>
    aria-labelledby="payme-result-title">
    <div class="payme-result__container">
        <?php if (!empty($data['logo'])) : ?>
            <img class="payme-result__logo" src="<?php echo esc_url($data['logo']); ?>" alt="<?php esc_attr_e('Logo del comercio', 'payme-gateway'); ?>">
        <?php endif; ?>

        <header class="payme-result__status">
            <span class="payme-result__status-icon" aria-hidden="true">
                <?php if ($data['icon'] === 'check') : ?>
                    <svg viewBox="0 0 24 24" role="img"><path d="m5 12.5 4.2 4.2L19 7"/></svg>
                <?php elseif ($data['icon'] === 'clock') : ?>
                    <svg viewBox="0 0 24 24" role="img"><circle cx="12" cy="12" r="8"/><path d="M12 7v5l3 2"/></svg>
                <?php elseif ($data['icon'] === 'reverse') : ?>
                    <svg viewBox="0 0 24 24" role="img"><path d="M8 8H4V4"/><path d="M4.5 8A8 8 0 1 1 4 15"/></svg>
                <?php else : ?>
                    <svg viewBox="0 0 24 24" role="img"><path d="m7 7 10 10M17 7 7 17"/></svg>
                <?php endif; ?>
            </span>
            <span class="payme-result__badge"><?php echo esc_html($data['state_label']); ?></span>
            <h2 id="payme-result-title" class="payme-result__title"><?php echo esc_html($data['title']); ?></h2>
            <p class="payme-result__description"><?php echo esc_html($data['description']); ?></p>
        </header>

        <div class="payme-result__amount" aria-label="<?php esc_attr_e('Monto de la operación', 'payme-gateway'); ?>">
            <span class="payme-result__amount-label"><?php esc_html_e('Monto', 'payme-gateway'); ?></span>
            <strong class="payme-result__amount-value"><?php echo wp_kses_post($data['amount_html']); ?></strong>
            <?php if (!empty($data['payment_method'])) : ?>
                <span class="payme-result__payment-method"><?php echo esc_html($data['payment_method']); ?></span>
            <?php endif; ?>
        </div>

        <dl class="payme-result__details">
            <?php if (!empty($data['merchant_operation_number'])) : ?>
                <div class="payme-result__detail-row payme-result__operation">
                    <dt><?php esc_html_e('N.º de operación', 'payme-gateway'); ?></dt>
                    <dd><?php echo esc_html($data['merchant_operation_number']); ?></dd>
                </div>
            <?php endif; ?>

            <?php if (!empty($data['transaction_id'])) : ?>
                <div class="payme-result__detail-row payme-result__transaction">
                    <dt><?php esc_html_e('ID de transacción', 'payme-gateway'); ?></dt>
                    <dd>
                        <span class="payme-result__transaction-value"><?php echo esc_html($data['transaction_id']); ?></span>
                        <button type="button" class="payme-result__copy-button"
                            data-payme-copy="<?php echo esc_attr($data['transaction_id']); ?>"
                            aria-label="<?php esc_attr_e('Copiar ID de transacción', 'payme-gateway'); ?>"
                            title="<?php esc_attr_e('Copiar', 'payme-gateway'); ?>">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="8" y="8" width="10" height="11" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h2"/></svg>
                        </button>
                        <span class="payme-result__copy-feedback" role="status" aria-live="polite"></span>
                    </dd>
                </div>
            <?php endif; ?>

            <?php if (!empty($data['authorization_code'])) : ?>
                <div class="payme-result__detail-row payme-result__authorization">
                    <dt><?php esc_html_e('Código de autorización', 'payme-gateway'); ?></dt>
                    <dd><?php echo esc_html($data['authorization_code']); ?></dd>
                </div>
            <?php endif; ?>

            <?php if (!empty($data['expiration_date'])) : ?>
                <div class="payme-result__detail-row payme-result__expiration">
                    <dt><?php esc_html_e('Válido hasta', 'payme-gateway'); ?></dt>
                    <dd><?php echo esc_html($data['expiration_date']); ?></dd>
                </div>
            <?php endif; ?>

            <?php if (!empty($data['qr_id'])) : ?>
                <div class="payme-result__detail-row payme-result__qr-id">
                    <dt><?php esc_html_e('Código QR', 'payme-gateway'); ?></dt>
                    <dd><?php echo esc_html($data['qr_id']); ?></dd>
                </div>
            <?php endif; ?>

            <?php if (!empty($data['cip'])) : ?>
                <div class="payme-result__detail-row payme-result__cip">
                    <dt><?php esc_html_e('Código CIP', 'payme-gateway'); ?></dt>
                    <dd><?php echo esc_html($data['cip']); ?></dd>
                </div>
            <?php endif; ?>
        </dl>

        <?php if (!empty($data['qr_image'])) : ?>
            <div class="payme-result__qr">
                <img src="<?php echo esc_attr($data['qr_image']); ?>" alt="<?php esc_attr_e('Código QR para completar el pago', 'payme-gateway'); ?>">
            </div>
        <?php endif; ?>

        <div class="payme-result__actions">
            <?php if ($has_continue_action) : ?>
                <a class="button alt payme-result__primary-button" href="<?php echo esc_url($data['continue_url']); ?>">
                    <?php esc_html_e('Continuar pago', 'payme-gateway'); ?>
                </a>
            <?php elseif (!empty($data['cip_url'])) : ?>
                <a class="button alt payme-result__primary-button" href="<?php echo esc_url($data['cip_url']); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e('Ver instrucciones de pago', 'payme-gateway'); ?>
                </a>
            <?php endif; ?>

            <a class="button <?php echo ($has_continue_action || !empty($data['cip_url'])) ? 'payme-result__secondary-button' : 'alt payme-result__primary-button'; ?>"
                href="<?php echo esc_url($data['return_url']); ?>">
                <?php echo esc_html($data['return_button_text']); ?>
            </a>
        </div>
    </div>
</section>
