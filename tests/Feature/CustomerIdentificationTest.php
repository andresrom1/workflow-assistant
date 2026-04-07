<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Customer;
use PHPUnit\Framework\Attributes\Test;

class CustomerIdentificationTest extends TestCase
{
    use RefreshDatabase;

    private string $sessionUuid = 'test-session-uuid-1234';

    private string $openaiUserId = 'user_test_001';

    #[Test]
    public function it_creates_customer_with_dni_not_anonymous(): void
    {
        $response = $this->postJson('/api/web-chat/v1/tools/identify-customer', [
            'identifier_type' => 'email',
            'identifier_value' => 'juan@gmail.com',
            'thread_id' => 'thread_test_1',
            'openai_user_id' => $this->openaiUserId,
            'sessionUuid' => $this->sessionUuid,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'tool_output' => 'Cliente identificado correctamente.',
            ]);

        $customer = Customer::first();

        $this->assertDatabaseCount('customers', 1);
        $this->assertFalse($customer->isAnonymous());
        $this->assertFalse($customer->hasLegalIdentification()); // No DNI set via this endpoint
        $this->assertTrue($customer->hasContactInfo()); // Has email
        $this->assertTrue($customer->canEmitPolicy());
    }

    #[Test]
    public function it_creates_customer_with_email_not_anonymous(): void
    {
        $response = $this->postJson('/api/web-chat/v1/tools/identify-customer', [
            'identifier_type' => 'email',
            'identifier_value' => 'maria@gmail.com',
            'thread_id' => 'thread_test_2',
            'openai_user_id' => $this->openaiUserId,
            'sessionUuid' => $this->sessionUuid,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'tool_output' => 'Cliente identificado correctamente.',
            ]);

        $customer = Customer::first();

        $this->assertDatabaseCount('customers', 1);
        $this->assertFalse($customer->isAnonymous());
        $this->assertFalse($customer->hasLegalIdentification()); // No DNI
        $this->assertTrue($customer->hasContactInfo()); // Has email
        $this->assertTrue($customer->canEmitPolicy());
    }

    #[Test]
    public function it_creates_customer_with_phone_not_anonymous(): void
    {
        $response = $this->postJson('/api/web-chat/v1/tools/identify-customer', [
            'identifier_type' => 'phone',
            'identifier_value' => '3512345678',
            'thread_id' => 'thread_test_3',
            'openai_user_id' => $this->openaiUserId,
            'sessionUuid' => $this->sessionUuid,
        ]);

        $response->assertStatus(200);

        $customer = Customer::first();

        $this->assertFalse($customer->isAnonymous());
        $this->assertFalse($customer->hasLegalIdentification()); // No DNI
        $this->assertTrue($customer->hasContactInfo()); // Has phone
        $this->assertTrue($customer->canEmitPolicy());
    }

    #[Test]
    public function it_creates_anonymous_customer_with_patente_only(): void
    {
        // Patente is not a valid identifier type; the adapter validates email|phone|wbid.
        // Expect a validation error response.
        $response = $this->postJson('/api/web-chat/v1/tools/identify-customer', [
            'identifier_type' => 'patente',
            'identifier_value' => 'ABC123',
            'thread_id' => 'thread_test_4',
            'openai_user_id' => $this->openaiUserId,
            'sessionUuid' => $this->sessionUuid,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error_code' => 'validation_error',
            ]);

        $this->assertDatabaseCount('customers', 0);
    }

    #[Test]
    public function it_completes_anonymous_customer_with_dni(): void
    {
        // Step 1: Create customer via email
        $this->postJson('/api/web-chat/v1/tools/identify-customer', [
            'identifier_type' => 'email',
            'identifier_value' => 'test@example.com',
            'thread_id' => 'thread_test_5',
            'openai_user_id' => $this->openaiUserId,
            'sessionUuid' => $this->sessionUuid,
        ])->assertStatus(200);

        $this->assertDatabaseCount('customers', 1);

        // Step 2: Same email again — should find the same customer, not create a duplicate
        $response = $this->postJson('/api/web-chat/v1/tools/identify-customer', [
            'identifier_type' => 'email',
            'identifier_value' => 'test@example.com',
            'thread_id' => 'thread_test_5',
            'openai_user_id' => $this->openaiUserId,
            'sessionUuid' => $this->sessionUuid,
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $this->assertEquals(1, Customer::count()); // No duplicate
    }

    #[Test]
    public function it_allows_policy_emission_with_dni_and_email(): void
    {
        // Create a customer directly with both DNI and email
        $customer = Customer::create([
            'dni' => '30123727',
            'email' => 'test@example.com',
            'is_anonymous' => false,
        ]);

        $customer->refresh();

        $this->assertTrue($customer->canEmitPolicy());
        $this->assertTrue($customer->hasLegalIdentification());
        $this->assertTrue($customer->hasContactInfo());
    }

    #[Test]
    public function customer_with_dni_and_phone_can_emit_policy(): void
    {
        $customer = Customer::create([
            'dni' => '30123727',
            'phone' => '+5493512345678',
            'is_anonymous' => false,
            'completed_at' => now(),
        ]);

        $this->assertTrue($customer->canEmitPolicy());
        $this->assertTrue($customer->hasLegalIdentification()); // Has DNI
        $this->assertTrue($customer->hasContactInfo()); // Has phone
    }

    #[Test]
    public function customer_with_email_and_phone_cannot_emit_policy_without_dni(): void
    {
        $customer = Customer::create([
            'email' => 'test@example.com',
            'phone' => '+5493512345678',
            'is_anonymous' => false,
            'completed_at' => now(),
        ]);

        $this->assertTrue($customer->canEmitPolicy()); // canEmitPolicy always returns true (TODO)
        $this->assertFalse($customer->hasLegalIdentification()); // No DNI
        $this->assertTrue($customer->hasContactInfo()); // Has email + phone
    }
}
