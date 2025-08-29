<?php
/**
 * BNA Checkout Payload Schema V4
 * Only send non-empty address fields
 */

if (!defined('ABSPATH')) exit;

class BNA_Checkout_Payload {

    private $order;
    private $customer_id;

    public function __construct($order, $customer_id = null) {
        $this->order = $order;
        $this->customer_id = $customer_id;
    }

    /**
     * Get complete checkout payload for BNA API
     */
    public function get_payload() {
        $payload = [
            'iframeId' => get_option('bna_smart_payment_iframe_id'),
            'subtotal' => (float) $this->order->get_total(),
            'items' => $this->get_items()
        ];

        // Add customer ID if available
        if (!empty($this->customer_id)) {
            $payload['customerId'] = $this->customer_id;
        }

        // Add customer info based on toggles
        $customer_info = $this->get_customer_info();
        if (!empty($customer_info)) {
            $payload['customerInfo'] = $customer_info;
        }

        return $payload;
    }

    /**
     * Get customer info based on admin toggle settings
     */
    private function get_customer_info() {
        $customer_info = [
            'type' => 'Personal',
            'email' => $this->order->get_billing_email(),
            'firstName' => $this->order->get_billing_first_name() ?: 'Customer',
            'lastName' => $this->order->get_billing_last_name() ?: 'Customer'
        ];

        // Add phone if toggle enabled
        if ($this->is_phone_enabled() && !empty($this->order->get_billing_phone())) {
            $customer_info['phoneCode'] = '+1';
            $customer_info['phoneNumber'] = $this->format_phone_number($this->order->get_billing_phone());
        }

        // Add birthdate if toggle enabled
        if ($this->is_birthdate_enabled()) {
            $birthdate = $this->get_customer_birthdate();
            if ($birthdate) {
                $customer_info['birthDate'] = $birthdate;
            }
        }

        // Add billing address if toggle enabled AND address is valid
        if ($this->is_billing_address_enabled() && $this->has_valid_billing_address()) {
            $customer_info['address'] = $this->get_clean_billing_address();
        }

        return $customer_info;
    }

    /**
     * Get order items formatted for BNA API
     */
    private function get_items() {
        $items = [];

        foreach ($this->order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            
            $items[] = [
                'sku' => $product && $product->get_sku() ? $product->get_sku() : 'ITEM-' . $item_id,
                'description' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'price' => (float) $this->order->get_item_total($item),
                'amount' => (float) $this->order->get_line_total($item)
            ];
        }

        // Add taxes if exists
        if ($this->order->get_total_tax() > 0) {
            $items[] = [
                'sku' => 'TAX',
                'description' => 'Tax',
                'quantity' => 1,
                'price' => (float) $this->order->get_total_tax(),
                'amount' => (float) $this->order->get_total_tax()
            ];
        }

        return $items;
    }

    /**
     * Check if order has valid billing address
     */
    private function has_valid_billing_address() {
        $street = $this->order->get_billing_address_1();
        $city = $this->order->get_billing_city();
        
        // Need at least street and valid city
        if (empty($street) || empty($city)) {
            return false;
        }

        // City must pass BNA validation pattern
        return $this->is_valid_city_name($city);
    }

    /**
     * Get clean billing address with only non-empty fields
     */
    private function get_clean_billing_address() {
        $street = $this->order->get_billing_address_1();
        $city = $this->clean_city_name($this->order->get_billing_city());
        
        // Start with required fields
        $address = [
            'streetName' => $street,
            'city' => $city,
            'province' => $this->order->get_billing_state() ?: 'Unknown',
            'country' => $this->order->get_billing_country() ?: 'CA',
            'postalCode' => $this->order->get_billing_postcode() ?: 'A1A1A1'
        ];

        // Add optional fields only if they have values
        $streetNumber = $this->extract_street_number($street);
        if (!empty($streetNumber)) {
            $address['streetNumber'] = $streetNumber;
        }

        $apartment = $this->order->get_billing_address_2();
        if (!empty($apartment)) {
            $address['apartment'] = $apartment;
        }

        return $address;
    }

    /**
     * Validate city name against BNA pattern
     */
    private function is_valid_city_name($city) {
        return preg_match('/^[\da-zA-ZÀ-ÖØ-öø-ÿ\s-]+$/u', $city);
    }

    /**
     * Clean city name to match BNA validation
     */
    private function clean_city_name($city) {
        if (empty($city)) {
            return 'Unknown';
        }

        // Remove invalid characters (keep only: digits, latin letters, accented chars, spaces, hyphens)
        $clean_city = preg_replace('/[^\da-zA-ZÀ-ÖØ-öø-ÿ\s-]/u', '', $city);
        
        if (empty(trim($clean_city))) {
            return 'Unknown';
        }
        
        return trim($clean_city);
    }

    /**
     * Extract street number from address
     */
    private function extract_street_number($street) {
        if (empty($street)) {
            return '';
        }

        // Look for numbers at the beginning
        if (preg_match('/^(\d+)/', $street, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Get customer birthdate from order
     */
    private function get_customer_birthdate() {
        // Try order meta first
        $birthdate = $this->order->get_meta('_billing_birthdate');
        if (!empty($birthdate)) {
            return $birthdate;
        }

        // Try POST data
        if (isset($_POST['billing_birthdate']) && !empty($_POST['billing_birthdate'])) {
            return sanitize_text_field($_POST['billing_birthdate']);
        }

        // Default fallback
        return date('Y-m-d', strtotime('-25 years'));
    }

    private function is_phone_enabled() {
        return get_option('bna_smart_payment_enable_phone') === 'yes';
    }

    private function is_birthdate_enabled() {
        return get_option('bna_smart_payment_enable_birthdate') === 'yes';
    }

    private function is_billing_address_enabled() {
        return get_option('bna_smart_payment_enable_billing_address') === 'yes';
    }

    private function format_phone_number($phone) {
        if (empty($phone)) {
            return '1234567890';
        }
        
        $phone = preg_replace('/\D/', '', $phone);
        
        if (strlen($phone) < 10) {
            $phone = str_pad($phone, 10, '0', STR_PAD_RIGHT);
        }
        
        return substr($phone, 0, 10);
    }
}
