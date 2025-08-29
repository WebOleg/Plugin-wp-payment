<?php
/**
 * BNA Checkout Payload Schema V5 - Fixed
 * Fixes: street number optional, customerId conflict, city validation
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

        // EITHER use existing customer ID OR send customer info, not both
        if (!empty($this->customer_id)) {
            // Use existing customer
            $payload['customerId'] = $this->customer_id;
        } else {
            // Send customer info to create new customer
            $customer_info = $this->get_customer_info();
            if (!empty($customer_info)) {
                $payload['customerInfo'] = $customer_info;
            }
        }

        return $payload;
    }

    /**
     * Get customer info for new customer creation
     */
    public function get_customer_info() {
        $customer_info = [
            'type' => 'Personal',
            'email' => $this->order->get_billing_email(),
            'firstName' => $this->order->get_billing_first_name() ?: 'Customer',
            'lastName' => $this->order->get_billing_last_name() ?: 'Customer'
        ];

        // Add phone if enabled
        if ($this->is_phone_enabled() && !empty($this->order->get_billing_phone())) {
            $customer_info['phoneCode'] = '+1';
            $customer_info['phoneNumber'] = $this->format_phone_number($this->order->get_billing_phone());
        }

        // Add birthdate if enabled
        if ($this->is_birthdate_enabled()) {
            $birthdate = $this->get_customer_birthdate();
            if ($birthdate) {
                $customer_info['birthDate'] = $birthdate;
            }
        }

        // Add address if enabled and valid
        if ($this->is_billing_address_enabled() && $this->has_valid_billing_address()) {
            $customer_info['address'] = $this->get_clean_billing_address();
        }

        return $customer_info;
    }

    /**
     * Check if address is valid for BNA API
     */
    private function has_valid_billing_address() {
        $street = trim($this->order->get_billing_address_1());
        $city = trim($this->order->get_billing_city());

        // Minimum requirements: street and valid city
        if (empty($street) || empty($city)) {
            return false;
        }

        // City must pass BNA validation pattern
        return $this->is_valid_city_name($city);
    }

    /**
     * Get clean address - only include fields that have values
     */
    private function get_clean_billing_address() {
        $street = trim($this->order->get_billing_address_1());
        $city = $this->clean_city_name($this->order->get_billing_city());

        $address = [
            'streetName' => $street,
            'city' => $city,
            'province' => $this->order->get_billing_state() ?: 'Unknown',
            'country' => $this->order->get_billing_country() ?: 'CA',
            'postalCode' => $this->order->get_billing_postcode() ?: 'A1A1A1'
        ];

        // Only add street number if we can extract it
        $street_number = $this->extract_street_number($street);
        if (!empty($street_number)) {
            $address['streetNumber'] = $street_number;
        }
        // If no street number - don't include the field at all

        // Only add apartment if it exists
        $apartment = trim($this->order->get_billing_address_2());
        if (!empty($apartment)) {
            $address['apartment'] = $apartment;
        }

        return $address;
    }

    /**
     * Validate city name against BNA pattern: /^[\da-zA-ZÀ-ÖØ-öø-ÿ\s-]+$/u
     */
    private function is_valid_city_name($city) {
        if (empty($city)) return false;
        return preg_match('/^[\da-zA-ZÀ-ÖØ-öø-ÿ\s-]+$/u', $city);
    }

    /**
     * Clean city name - remove invalid characters
     */
    private function clean_city_name($city) {
        if (empty($city)) {
            return 'Unknown';
        }

        // Remove all characters that don't match BNA pattern
        $clean_city = preg_replace('/[^\da-zA-ZÀ-ÖØ-öø-ÿ\s-]/u', '', $city);
        $clean_city = trim($clean_city);

        if (empty($clean_city)) {
            return 'Unknown';
        }

        return $clean_city;
    }

    /**
     * Try to extract street number from address
     */
    private function extract_street_number($street) {
        if (empty($street)) {
            return '';
        }

        // Look for numbers at the beginning of address
        if (preg_match('/^(\d+)/', trim($street), $matches)) {
            return $matches[1];
        }

        // Look for numbers anywhere in the string as fallback
        if (preg_match('/(\d+)/', $street, $matches)) {
            return $matches[1];
        }

        // If no numbers found, generate a fallback for BNA API
        // You can uncomment this if you want to always send an address:
        // return '123';

        return '';
    }

    /**
     * Get order items
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

        // Add tax if exists
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
     * Get customer birthdate
     */
    private function get_customer_birthdate() {
        // From order meta
        $birthdate = $this->order->get_meta('_billing_birthdate');
        if (!empty($birthdate)) {
            return $birthdate;
        }

        // From POST data
        if (isset($_POST['billing_birthdate']) && !empty($_POST['billing_birthdate'])) {
            return sanitize_text_field($_POST['billing_birthdate']);
        }

        // Fallback
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