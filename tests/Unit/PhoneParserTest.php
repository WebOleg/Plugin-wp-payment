<?php

namespace BNA\Tests\Unit;

use PHPUnit\Framework\TestCase;

class PhoneParserTest extends TestCase {

    private $api;

    protected function setUp(): void {
        $this->api = new \BNA_API();
    }

    public function test_ukrainian_mobile_detected_by_prefix() {
        $digits = '0931234567';
        $result = $this->api->test_determine_phone_country($digits, 'CA');
        
        $this->assertEquals('+380', $result);
    }

    public function test_ukrainian_mobile_093_prefix() {
        $digits = '0931234567';
        $result = $this->api->test_determine_phone_country($digits, 'US');
        
        $this->assertEquals('+380', $result);
    }

    public function test_ukrainian_mobile_067_prefix() {
        $digits = '0671234567';
        $result = $this->api->test_determine_phone_country($digits, 'CA');
        
        $this->assertEquals('+380', $result);
    }

    public function test_canadian_number_from_billing_country() {
        $digits = '4161234567';
        $result = $this->api->test_determine_phone_country($digits, 'CA');
        
        $this->assertEquals('+1', $result);
    }

    public function test_us_number_from_billing_country() {
        $digits = '2125551234';
        $result = $this->api->test_determine_phone_country($digits, 'US');
        
        $this->assertEquals('+1', $result);
    }

    public function test_ukrainian_format_removes_leading_zero() {
        $digits = '0931234567';
        $result = $this->api->test_format_phone($digits, '+380');
        
        $this->assertEquals('931234567', $result);
    }

    public function test_canadian_format_10_digits() {
        $digits = '4161234567';
        $result = $this->api->test_format_phone($digits, '+1');
        
        $this->assertEquals('4161234567', $result);
    }

    public function test_canadian_format_removes_leading_1() {
        $digits = '14161234567';
        $result = $this->api->test_format_phone($digits, '+1');
        
        $this->assertEquals('4161234567', $result);
    }

    public function test_ukrainian_9_digits_unchanged() {
        $digits = '931234567';
        $result = $this->api->test_format_phone($digits, '+380');
        
        $this->assertEquals('931234567', $result);
    }
}
