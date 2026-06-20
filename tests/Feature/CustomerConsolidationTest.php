<?php

use App\Models\Customer;
use App\Models\CustomerAudit;
use App\Models\User;
use App\Services\CustomerConsolidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->service = app(CustomerConsolidationService::class);
});

it('el chat solo rellena campos vacíos y nunca pisa', function (): void {
    $customer = Customer::factory()->create(['first_name' => null, 'last_name' => null]);

    // Campo vacío → el chat lo rellena.
    $this->service->apply($customer, ['first_name' => 'Ana'], 'chat');
    expect($customer->refresh()->first_name)->toBe('Ana');

    // Campo ya cargado → el chat NO lo pisa.
    $this->service->apply($customer, ['first_name' => 'Otro'], 'chat');
    expect($customer->refresh()->first_name)->toBe('Ana');
});

it('el checkout pisa un valor de origen chat', function (): void {
    $customer = Customer::factory()->create(['first_name' => null]);

    $this->service->apply($customer, ['first_name' => 'Ana'], 'chat');
    $this->service->apply($customer, ['first_name' => 'María'], 'checkout');

    expect($customer->refresh()->first_name)->toBe('María');
});

it('el checkout NO pisa un valor curado por admin: marca divergencia', function (): void {
    $admin = User::factory()->create();
    $customer = Customer::factory()->create(['first_name' => null]);

    $this->service->apply($customer, ['first_name' => 'Ana'], 'admin', $admin->id);
    $divergences = $this->service->apply($customer, ['first_name' => 'María'], 'checkout');

    expect($customer->refresh()->first_name)->toBe('Ana')
        ->and($divergences)->toHaveCount(1)
        ->and($divergences[0]['field'])->toBe('first_name')
        ->and($divergences[0]['incoming'])->toBe('María');
});

it('la edición admin se aplica y queda auditada', function (): void {
    $admin = User::factory()->create();
    $customer = Customer::factory()->create(['first_name' => 'Ana', 'last_name' => null]);

    $this->service->apply($customer, ['first_name' => 'Ana María', 'last_name' => 'López'], 'admin', $admin->id);

    expect($customer->refresh()->first_name)->toBe('Ana María')
        ->and($customer->name)->toBe('Ana María López'); // syncName

    $audit = CustomerAudit::where('customer_id', $customer->id)->where('field', 'first_name')->firstOrFail();
    expect($audit->source)->toBe('admin')
        ->and($audit->user_id)->toBe($admin->id)
        ->and($audit->new_value)->toBe('Ana María');
});

it('no escribe un DNI que ya pertenece a otro customer: no rompe y consolida el resto', function (): void {
    // Otro customer ya es dueño del DNI (la situación que reventaba el checkout).
    Customer::factory()->create(['dni' => '30123727', 'email' => 'otro@bar.com']);

    $placeholder = Customer::factory()->create(['dni' => null, 'first_name' => null]);

    $divergences = $this->service->apply($placeholder, [
        'dni' => '30123727',
        'first_name' => 'Jose Andrés',
    ], 'checkout');

    $placeholder->refresh();

    // El DNI en conflicto NO se escribió (habría violado el índice único)...
    expect($placeholder->dni)->toBeNull()
        // ...pero el resto de los campos sí se consolidaron.
        ->and($placeholder->first_name)->toBe('Jose Andrés')
        ->and($divergences)->toBe([]);
});

it('tampoco escribe un email que ya pertenece a otro customer', function (): void {
    Customer::factory()->create(['email' => 'dup@bar.com']);

    $placeholder = Customer::factory()->create(['email' => null]);

    $this->service->apply($placeholder, ['email' => 'dup@bar.com'], 'checkout');

    expect($placeholder->refresh()->email)->toBeNull();
});

it('normaliza email a minúsculas y registra provenance por campo', function (): void {
    $customer = Customer::factory()->create(['email' => null]);

    $this->service->apply($customer, ['email' => '  Foo@Bar.COM '], 'checkout');

    $customer->refresh();
    expect($customer->email)->toBe('foo@bar.com')
        ->and($customer->metadata['field_sources']['email']['source'])->toBe('checkout');
});
