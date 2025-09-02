<?php
/**
 * Shipping Address Fields Template
 * Displays shipping address collection form during checkout
 */

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
            <span class="checkmark"></span>
            <?php _e('Same as billing address', 'bna-smart-payment'); ?>
        </label>
    </div>

    <div id="bna-shipping-fields" class="bna-shipping-fields" style="display: none;">
        
        <div class="bna-form-row bna-form-row-wide">
            <label for="bna_shipping_country">
                <?php _e('Country / Region', 'bna-smart-payment'); ?> 
                <abbr class="required" title="required">*</abbr>
            </label>
            <select 
                name="bna_shipping_country" 
                id="bna_shipping_country" 
                class="bna-country-select"
                data-placeholder="<?php esc_attr_e('Select a country / region…', 'bna-smart-payment'); ?>"
            >
                <option value=""><?php _e('Select a country / region…', 'bna-smart-payment'); ?></option>
                <?php
                $countries = WC()->countries->get_shipping_countries();
                foreach ($countries as $code => $name) {
                    $selected = ($code === 'CA') ? 'selected' : '';
                    echo '<option value="' . esc_attr($code) . '" ' . $selected . '>' . esc_html($name) . '</option>';
                }
                ?>
            </select>
        </div>

        <div class="bna-form-row bna-form-row-wide">
            <label for="bna_shipping_address_1">
                <?php _e('Street address', 'bna-smart-payment'); ?> 
                <abbr class="required" title="required">*</abbr>
            </label>
            <input 
                type="text" 
                class="bna-input-text" 
                name="bna_shipping_address_1" 
                id="bna_shipping_address_1" 
                placeholder="<?php esc_attr_e('House number and street name', 'bna-smart-payment'); ?>"
                value=""
            />
        </div>

        <div class="bna-form-row bna-form-row-wide">
            <label for="bna_shipping_address_2">
                <?php _e('Apartment, suite, unit, etc.', 'bna-smart-payment'); ?> 
                <span class="optional"><?php _e('(optional)', 'bna-smart-payment'); ?></span>
            </label>
            <input 
                type="text" 
                class="bna-input-text" 
                name="bna_shipping_address_2" 
                id="bna_shipping_address_2" 
                placeholder="<?php esc_attr_e('Apartment, suite, unit, etc. (optional)', 'bna-smart-payment'); ?>"
                value=""
            />
        </div>

        <div class="bna-form-row bna-form-row-wide">
            <div class="bna-form-row bna-form-row-first">
                <label for="bna_shipping_city">
                    <?php _e('Town / City', 'bna-smart-payment'); ?> 
                    <abbr class="required" title="required">*</abbr>
                </label>
                <input 
                    type="text" 
                    class="bna-input-text" 
                    name="bna_shipping_city" 
                    id="bna_shipping_city" 
                    placeholder="<?php esc_attr_e('Town / City', 'bna-smart-payment'); ?>"
                    value=""
                />
            </div>
            
            <div class="bna-form-row bna-form-row-last">
                <label for="bna_shipping_state">
                    <?php _e('State / Province', 'bna-smart-payment'); ?> 
                    <abbr class="required" title="required">*</abbr>
                </label>
                <select 
                    name="bna_shipping_state" 
                    id="bna_shipping_state" 
                    class="bna-state-select"
                    data-placeholder="<?php esc_attr_e('Select an option…', 'bna-smart-payment'); ?>"
                >
                    <option value=""><?php _e('Select an option…', 'bna-smart-payment'); ?></option>
                    <?php
                    $states = WC()->countries->get_states('CA');
                    if (!empty($states)) {
                        foreach ($states as $code => $name) {
                            echo '<option value="' . esc_attr($code) . '">' . esc_html($name) . '</option>';
                        }
                    }
                    ?>
                </select>
            </div>
        </div>

        <div class="bna-form-row bna-form-row-wide">
            <label for="bna_shipping_postcode">
                <?php _e('Postal code / ZIP', 'bna-smart-payment'); ?> 
                <abbr class="required" title="required">*</abbr>
            </label>
            <input 
                type="text" 
                class="bna-input-text" 
                name="bna_shipping_postcode" 
                id="bna_shipping_postcode" 
                placeholder="<?php esc_attr_e('Postal code / ZIP', 'bna-smart-payment'); ?>"
                value=""
                maxlength="10"
            />
        </div>
    </div>
</div>
