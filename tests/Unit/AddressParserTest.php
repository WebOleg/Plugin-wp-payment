<?php

namespace BNA\Tests\Unit;

use PHPUnit\Framework\TestCase;

class AddressParserTest extends TestCase {

    private $api;

    protected function setUp(): void {
        $this->api = new \BNA_API();
    }

    public function test_extract_number_from_start() {
        $address = '123 Main Street';
        $result = $this->api->test_extract_street_number($address);
        
        $this->assertEquals('123', $result);
    }

    public function test_extract_number_from_end() {
        $address = 'Main Street 123';
        $result = $this->api->test_extract_street_number($address);
        
        $this->assertEquals('123', $result);
    }

    public function test_extract_number_with_letter() {
        $address = '123A Queen Street';
        $result = $this->api->test_extract_street_number($address);
        
        $this->assertEquals('123A', $result);
    }

    public function test_fallback_when_no_number() {
        $address = 'Main Street';
        $result = $this->api->test_extract_street_number($address);
        
        $this->assertEquals('1', $result);
    }

    public function test_clean_street_name_removes_number_from_start() {
        $address = '123 Main Street';
        $number = '123';
        $result = $this->api->test_clean_street_name($address, $number);
        
        $this->assertEquals('Main Street', $result);
    }

    public function test_clean_street_name_removes_number_from_end() {
        $address = 'Main Street 456';
        $number = '456';
        $result = $this->api->test_clean_street_name($address, $number);
        
        $this->assertEquals('Main Street', $result);
    }

    public function test_clean_street_name_fallback() {
        $address = '123';
        $number = '123';
        $result = $this->api->test_clean_street_name($address, $number);
        
        $this->assertEquals('Main Street', $result);
    }

    public function test_complex_address_parsing() {
        $address = '456B King Street West';
        $number = $this->api->test_extract_street_number($address);
        $street = $this->api->test_clean_street_name($address, $number);
        
        $this->assertEquals('456B', $number);
        $this->assertEquals('King Street West', $street);
    }
}
