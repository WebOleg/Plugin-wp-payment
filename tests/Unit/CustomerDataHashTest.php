<?php

namespace BNA\Tests\Unit;

use PHPUnit\Framework\TestCase;

class CustomerDataHashTest extends TestCase {

    private $api;

    protected function setUp(): void {
        $this->api = new \BNA_API();
    }

    public function test_same_data_produces_same_hash() {
        $data1 = array(
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john@example.com',
            'type' => 'Personal'
        );
        
        $data2 = array(
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john@example.com',
            'type' => 'Personal'
        );
        
        $hash1 = $this->api->test_generate_customer_hash($data1);
        $hash2 = $this->api->test_generate_customer_hash($data2);
        
        $this->assertEquals($hash1, $hash2);
    }

    public function test_different_email_produces_different_hash() {
        $data1 = array(
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john@example.com'
        );
        
        $data2 = array(
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'jane@example.com'
        );
        
        $hash1 = $this->api->test_generate_customer_hash($data1);
        $hash2 = $this->api->test_generate_customer_hash($data2);
        
        $this->assertNotEquals($hash1, $hash2);
    }

    public function test_different_shipping_produces_different_hash() {
        $data1 = array(
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john@example.com',
            'shippingAddress' => array(
                'city' => 'Toronto',
                'country' => 'Canada'
            )
        );
        
        $data2 = array(
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john@example.com',
            'shippingAddress' => array(
                'city' => 'Vancouver',
                'country' => 'Canada'
            )
        );
        
        $hash1 = $this->api->test_generate_customer_hash($data1);
        $hash2 = $this->api->test_generate_customer_hash($data2);
        
        $this->assertNotEquals($hash1, $hash2);
    }

    public function test_null_shipping_vs_missing_shipping() {
        $data1 = array(
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john@example.com',
            'shippingAddress' => null
        );
        
        $data2 = array(
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john@example.com'
        );
        
        $hash1 = $this->api->test_generate_customer_hash($data1);
        $hash2 = $this->api->test_generate_customer_hash($data2);
        
        $this->assertEquals($hash1, $hash2);
    }

    public function test_whitespace_is_trimmed() {
        $data1 = array(
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john@example.com'
        );
        
        $data2 = array(
            'firstName' => '  John  ',
            'lastName' => '  Doe  ',
            'email' => '  john@example.com  '
        );
        
        $hash1 = $this->api->test_generate_customer_hash($data1);
        $hash2 = $this->api->test_generate_customer_hash($data2);
        
        $this->assertEquals($hash1, $hash2);
    }
}
