<?php

namespace BNA\Tests\Unit;

use PHPUnit\Framework\TestCase;

class WebhookSignatureTest extends TestCase {

    private $secret = 'test_webhook_secret_key_12345';

    public function test_valid_signature_is_accepted() {
        $raw_body = '{"event":"transaction.approved","deliveryId":"123","configId":"456","data":{"id":"test123","status":"APPROVED","amount":100.00}}';
        
        $data_extracted = \BNA_Webhooks::test_extract_data_from_json($raw_body);
        $this->assertNotFalse($data_extracted, 'Data extraction should succeed');
        
        $timestamp = gmdate('Y-m-d\TH:i:s.000\Z');
        
        $data_hash = hash('sha256', $data_extracted);
        $signing_string = $data_hash . ':' . $timestamp;
        $valid_signature = hash_hmac('sha256', $signing_string, $this->secret);
        
        $result = \BNA_Webhooks::test_verify_signature(
            $raw_body,
            $valid_signature,
            $timestamp,
            $this->secret
        );
        
        $this->assertTrue($result, 'Valid signature should be accepted');
    }

    public function test_invalid_signature_is_rejected() {
        $raw_body = '{"event":"transaction.approved","data":{"id":"test123","status":"APPROVED"}}';
        $timestamp = gmdate('Y-m-d\TH:i:s.000\Z');
        $invalid_signature = 'invalid_signature_12345';
        
        $result = \BNA_Webhooks::test_verify_signature(
            $raw_body,
            $invalid_signature,
            $timestamp,
            $this->secret
        );
        
        $this->assertFalse($result);
    }

    public function test_old_timestamp_is_rejected() {
        $raw_body = '{"event":"transaction.approved","data":{"id":"test123","status":"APPROVED"}}';
        $old_timestamp = '2020-01-01T10:30:00.000Z';
        
        $data_extracted = \BNA_Webhooks::test_extract_data_from_json($raw_body);
        $data_hash = hash('sha256', $data_extracted);
        $signing_string = $data_hash . ':' . $old_timestamp;
        $signature = hash_hmac('sha256', $signing_string, $this->secret);
        
        $result = \BNA_Webhooks::test_verify_signature(
            $raw_body,
            $signature,
            $old_timestamp,
            $this->secret
        );
        
        $this->assertFalse($result);
    }

    public function test_missing_signature_is_rejected() {
        $raw_body = '{"event":"transaction.approved","data":{"id":"test123"}}';
        $timestamp = gmdate('Y-m-d\TH:i:s.000\Z');
        
        $result = \BNA_Webhooks::test_verify_signature(
            $raw_body,
            '',
            $timestamp,
            $this->secret
        );
        
        $this->assertFalse($result);
    }

    public function test_extract_data_from_simple_json() {
        $raw_body = '{"event":"customer.created","deliveryId":"123","data":{"id":"abc","email":"test@test.com"}}';
        
        $result = \BNA_Webhooks::test_extract_data_from_json($raw_body);
        
        $this->assertNotFalse($result);
        $this->assertStringContainsString('"id":"abc"', $result);
        $this->assertStringContainsString('"email":"test@test.com"', $result);
    }

    public function test_extract_data_from_nested_json() {
        $raw_body = '{"event":"transaction.approved","data":{"id":"tx123","customerInfo":{"email":"test@test.com","address":{"city":"Toronto"}}}}';
        
        $result = \BNA_Webhooks::test_extract_data_from_json($raw_body);
        
        $this->assertNotFalse($result);
        $this->assertStringContainsString('"id":"tx123"', $result);
    }

    public function test_extract_data_returns_false_for_invalid_json() {
        $raw_body = '{"event":"test","invalid_structure"}';
        
        $result = \BNA_Webhooks::test_extract_data_from_json($raw_body);
        
        $this->assertFalse($result);
    }
}
