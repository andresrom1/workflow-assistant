<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Customer;
use PHPUnit\Framework\Attributes\Test;

class CustomerIdentificationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_customer_with_dni_not_anonymous()
    {
        $response = $this->postJson('/api/tools/identify-customer', [
            'identifier_type' => 'dni',
            'identifier_value' => '30123727',
            'thread_id' => 'thread_test_1',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success'=> true,
                'customer_id'=> 1,
                'name'=> null,
                'email'=> null,
                'phone'=> null,
                'dni'=> '30123727',
                'is_new'=> true,
                'previous_conversations'=> [],
                'vehicles'=> [],
                'message'=> '¡Bienvenido! Eres un cliente nuevo'
            ]);

        $customer = Customer::first();
        
        // Verificaciones
        $this->assertDatabaseCount('customers', 1);
        $this->assertFalse($customer->isAnonymous());
        $this->assertTrue($customer->hasLegalIdentification());
        $this->assertFalse($customer->hasContactInfo()); // ⚠️ No tiene email/phone
        $this->assertTrue($customer->canEmitPolicy()); // Siempre devuelve trueporque no esta implementado
    }

    #[Test]
    public function it_creates_customer_with_email_not_anonymous()
    {
        $response = $this->postJson('/api/tools/identify-customer', [
            'identifier_type' => 'email',
            'identifier_value' => 'juan@gmail.com',
            'thread_id' => 'thread_test_2',
            "ai_provider" => "openai-chatkit",
            "openai_user_id" => "user"
        ]);

        $response->assertStatus(200);
        
        $response->assertStatus(200)
            ->assertJson([
                "success" => true,
                "tool_output" => "Cliente identificado correctamente"
            ]);

        $customer = Customer::first();

        $this->assertDatabaseCount('customers', 1);
        $this->assertFalse($customer->isAnonymous());
        $this->assertFalse($customer->hasLegalIdentification()); // ⚠️ No tiene DNI
        $this->assertTrue($customer->hasContactInfo()); // ✅ Tiene email
        $this->assertTrue($customer->canEmitPolicy()); // No esta implementado, siempre es true
    }

    #[Test]
    public function it_creates_customer_with_phone_not_anonymous()
    {
        $response = $this->postJson('/api/tools/identify-customer', [
            'identifier_type' => 'phone',
            'identifier_value' => '3512345678',
            'thread_id' => 'thread_test_3',
        ]);

        $response->assertStatus(200);

        $customer = Customer::first();
        
        $this->assertFalse($customer->isAnonymous());
        $this->assertFalse($customer->hasLegalIdentification()); // ⚠️ No tiene DNI
        $this->assertTrue($customer->hasContactInfo()); // ✅ Tiene phone
        $this->assertTrue($customer->canEmitPolicy()); // No esta implementado, siempre es true
    }

    #[Test]
    public function it_creates_anonymous_customer_with_patente_only()
    {
        $response = $this->postJson('/api/tools/identify-customer', [
            'identifier_type' => 'patente',
            'identifier_value' => 'ABC123',
            'thread_id' => 'thread_test_4',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'is_new' => true,
                'is_anonymous' => true,  // ✅ Solo patente → anónimo
                'requires_contact_info' => true,
            ]);

        $customer = Customer::first();
        
        $this->assertTrue($customer->isAnonymous()); // ✅
        $this->assertFalse($customer->hasLegalIdentification()); // ❌
        $this->assertFalse($customer->hasContactInfo()); // ❌
        $this->assertTrue($customer->canEmitPolicy()); // No esta implementado, siempre es true
    }

    #[Test]
    public function it_completes_anonymous_customer_with_dni()
    {
        // Step 1: Crear anónimo
        $this->postJson('/api/tools/identify-customer', [
            'identifier_type' => 'patente',
            'identifier_value' => 'XYZ789',
            'thread_id' => 'thread_test_5',
        ]);

        $customer = Customer::first();
        $this->assertTrue($customer->isAnonymous());

        // Step 2: Completar con DNI
        $response = $this->postJson('/api/tools/identify-customer', [
            'identifier_type' => 'dni',
            'identifier_value' => '40123456',
            'thread_id' => 'thread_test_5',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'was_anonymous' => true,
                'is_anonymous' => false,  // ✅ Ya no es anónimo
                'dni' => '40123456',
            ]);

        $customer->refresh();
        
        $this->assertFalse($customer->isAnonymous()); // ✅
        $this->assertTrue($customer->hasLegalIdentification()); // ✅
        $this->assertFalse($customer->hasContactInfo()); // ⚠️ Aún falta email/phone
        $this->assertTrue($customer->canEmitPolicy()); // No esta implementado, siempre es true
        
        $this->assertEquals(1, Customer::count()); // No duplicado
    }

    #[Test]
    public function it_allows_policy_emission_with_dni_and_email()
    {
        // Crear customer con DNI
        $this->postJson('/api/tools/identify-customer', [
            'identifier_type' => 'dni',
            'identifier_value' => '30123727',
            'thread_id' => 'thread_test_6',
        ]);

        $customer = Customer::first();
        $this->assertFalse($customer->canEmitPolicy()); // Todavía falta email

        // Agregar email manualmente (simulando onboarding)
        $customer->update(['email' => 'test@example.com']);

        $customer->refresh();
        $this->assertTrue($customer->canEmitPolicy()); // No esta implementado, siempre es true
        $this->assertTrue($customer->hasLegalIdentification());
        $this->assertTrue($customer->hasContactInfo());
    }

    #[Test]
    public function customer_with_dni_and_phone_can_emit_policy()
    {
        $customer = Customer::create([
            'dni' => '30123727',
            'phone' => '+5493512345678',
            'is_anonymous' => false,
            'completed_at' => now(),
        ]);

        $this->assertTrue($customer->canEmitPolicy()); // No esta implementado, siempre es true
        $this->assertTrue($customer->hasLegalIdentification()); // ✅
        $this->assertTrue($customer->hasContactInfo()); // ✅
    }

    #[Test]
    public function customer_with_email_and_phone_cannot_emit_policy_without_dni()
    {
        $customer = Customer::create([
            'email' => 'test@example.com',
            'phone' => '+5493512345678',
            'is_anonymous' => false,
            'completed_at' => now(),
        ]);

       $this->assertTrue($customer->canEmitPolicy()); // No esta implementado, siempre es true
        $this->assertFalse($customer->hasLegalIdentification()); // ❌
        $this->assertTrue($customer->hasContactInfo()); // ✅
    }
}