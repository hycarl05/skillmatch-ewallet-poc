<?php

namespace Tests\Unit;

use App\Payments\Gateways\BillplzGateway;
use PHPUnit\Framework\TestCase;

class BillplzSignatureTest extends TestCase
{
    public function test_signature_sorts_fields_case_insensitively_and_ignores_x_signature(): void
    {
        $data = ['paid' => 'true', 'id' => 'W_79pJDk', 'Amount' => '200', 'x_signature' => 'ignored'];

        $expected = hash_hmac('sha256', 'Amount200|idW_79pJDk|paidtrue', 'secret');

        $this->assertSame($expected, BillplzGateway::signature($data, 'secret'));
    }

    public function test_changing_any_field_changes_the_signature(): void
    {
        $data = ['id' => 'abc', 'paid' => 'true', 'paid_amount' => '45000'];
        $tampered = ['paid_amount' => '100'] + $data;

        $this->assertNotSame(BillplzGateway::signature($data, 'k'), BillplzGateway::signature($tampered, 'k'));
    }
}
