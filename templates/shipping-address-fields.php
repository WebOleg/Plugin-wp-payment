<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<div id="bna-shipping-address-section" class="bna-shipping-section" style="display: none;">
    <h3><?php _e('Shipping Address', 'bna-smart-payment'); ?></h3>
    
    <div class="bna-shipping-same-wrapper">
        <label class="bna-checkbox-label">
            <input 
                type="checkbox" 
                id="bna_shipping_same_as_billing" 
                name="bna_shipping_same_as_billing" 
                value="1" 
                checked="checked"
            />
            <span><?php _e('Same as billing address', 'bna-smart-payment'); ?></span>
        </label>
    </div>

    <div id="bna-shipping-fields" class="bna-shipping-fields" style="display: none;">
        
        <p class="form-row form-row-wide">
            <label for="bna_shipping_country">
                <?php _e('Country', 'bna-smart-payment'); ?> 
                <abbr class="required" title="required">*</abbr>
            </label>
            <input 
                type="text" 
                class="input-text" 
                name="bna_shipping_country" 
                id="bna_shipping_country" 
                value="Canada"
                readonly
            />
        </p>

        <p class="form-row form-row-wide">
            <label for="bna_shipping_address_1">
                <?php _e('Street address', 'bna-smart-payment'); ?> 
                <abbr class="required" title="required">*</abbr>
            </label>
            <input 
                type="text" 
                class="input-text" 
                name="bna_shipping_address_1" 
                id="bna_shipping_address_1"
            />
        </p>

        <p class="form-row form-row-wide">
            <label for="bna_shipping_address_2">
                <?php _e('Apartment, suite, unit, etc.', 'bna-smart-payment'); ?> 
                <span class="optional"><?php _e('(optional)', 'bna-smart-payment'); ?></span>
            </label>
            <input 
                type="text" 
                class="input-text" 
                name="bna_shipping_address_2" 
                id="bna_shipping_address_2"
            />
        </p>

        <p class="form-row form-row-first">
            <label for="bna_shipping_city">
                <?php _e('City', 'bna-smart-payment'); ?> 
                <abbr class="required" title="required">*</abbr>
            </label>
            <input 
                type="text" 
                class="input-text" 
                name="bna_shipping_city" 
                id="bna_shipping_city"
            />
        </p>

        <p class="form-row form-row-last">
            <label for="bna_shipping_province">
                <?php _e('Province', 'bna-smart-payment'); ?> 
                <abbr class="required" title="required">*</abbr>
            </label>
            <input 
                type="text" 
                class="input-text" 
                name="bna_shipping_province" 
                id="bna_shipping_province"
                placeholder="<?php esc_attr_e('Province', 'bna-smart-payment'); ?>"
            />
        </p>

        <p class="form-row form-row-wide">
            <label for="bna_shipping_postcode">
                <?php _e('Postal code', 'bna-smart-payment'); ?> 
                <abbr class="required" title="required">*</abbr>
            </label>
            <input 
                type="text" 
                class="input-text" 
                name="bna_shipping_postcode" 
                id="bna_shipping_postcode"
                maxlength="7"
            />
        </p>
    </div>
</div>
