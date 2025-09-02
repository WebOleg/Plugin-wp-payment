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
            
            <div class="bna-custom-select" id="bna_country_selector">
                <div class="bna-select-selected" data-value="">
                    <span class="bna-select-text">Select Country...</span>
                    <span class="bna-select-arrow">▼</span>
                </div>
                <ul class="bna-select-options" style="display: none;">
                    <li data-value="">Select Country...</li>
                </ul>
            </div>
            
            <input type="hidden" name="bna_shipping_country" id="bna_shipping_country" value="" required />
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
                placeholder="<?php esc_attr_e('House number and street name', 'bna-smart-payment'); ?>"
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
                placeholder="<?php esc_attr_e('Apartment, suite, etc.', 'bna-smart-payment'); ?>"
            />
        </p>

        <p class="form-row form-row-wide">
            <label for="bna_shipping_city">
                <?php _e('City', 'bna-smart-payment'); ?> 
                <abbr class="required" title="required">*</abbr>
            </label>
            <input 
                type="text" 
                class="input-text" 
                name="bna_shipping_city" 
                id="bna_shipping_city"
                placeholder="<?php esc_attr_e('City', 'bna-smart-payment'); ?>"
            />
        </p>

        <p class="form-row form-row-wide">
            <label for="bna_shipping_state">
                <?php _e('Province', 'bna-smart-payment'); ?> 
                <abbr class="required" title="required">*</abbr>
            </label>
            
            <div class="bna-custom-select" id="bna_province_selector">
                <div class="bna-select-selected" data-value="">
                    <span class="bna-select-text">Select Province...</span>
                    <span class="bna-select-arrow">▼</span>
                </div>
                <ul class="bna-select-options" style="display: none;">
                    <li data-value="">Select Province...</li>
                </ul>
            </div>
            
            <input type="hidden" name="bna_shipping_state" id="bna_shipping_state" value="" required />
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
                maxlength="10"
                placeholder="<?php esc_attr_e('A1A 1A1', 'bna-smart-payment'); ?>"
            />
        </p>
    </div>
</div>
