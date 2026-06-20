<?php

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerAudit;
use App\Models\MobileAccount;
use App\Models\Vehicle;
use App\Services\CustomerConsolidationService;
use App\Services\CustomerIdentificationService;
use App\Services\CustomerMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->service = app(CustomerMergeService::class);
});

it('fusiona el perdedor en el survivor: repunta hijos, lo elimina y completa vacíos', function (): void {
    $survivor = Customer::factory()->create([
        'dni' => null, 'email' => null, 'first_name' => 'Andrés', 'last_name' => null,
    ]);
    $loser = Customer::factory()->create([
        'dni' => '30123727', 'email' => 'a@b.com', 'last_name' => 'Romero',
    ]);

    $conv = Conversation::factory()->create(['customer_id' => $loser->id]);
    $vehicle = Vehicle::factory()->create(['customer_id' => $loser->id]);

    $result = $this->service->merge($survivor, $loser);

    // Survivor toma las claves únicas que tenía vacías + el resto de los vacíos.
    expect($result->id)->toBe($survivor->id)
        ->and($result->dni)->toBe('30123727')
        ->and($result->email)->toBe('a@b.com')
        ->and($result->last_name)->toBe('Romero')
        ->and($result->first_name)->toBe('Andrés');

    // Hijos re-apuntados al survivor.
    expect($conv->refresh()->customer_id)->toBe($survivor->id)
        ->and($vehicle->refresh()->customer_id)->toBe($survivor->id);

    // Perdedor hard-deleted (NO soft): libera el slot único de dni/email.
    expect(Customer::withTrashed()->find($loser->id))->toBeNull();
});

it('en conflicto gana el survivor y se audita el valor descartado', function (): void {
    $survivor = Customer::factory()->create(['first_name' => 'Andrés']);
    $loser = Customer::factory()->create(['first_name' => 'Jose']);

    $this->service->merge($survivor, $loser);

    expect($survivor->refresh()->first_name)->toBe('Andrés');

    $audit = CustomerAudit::where('customer_id', $survivor->id)
        ->where('field', 'first_name')
        ->where('source', 'merge')
        ->firstOrFail();

    expect($audit->old_value)->toBe('Jose')      // descartado (perdedor)
        ->and($audit->new_value)->toBe('Andrés'); // conservado (survivor)
});

it('reconcile fusiona la fila dueña del DNI/email declarado y no rompe', function (): void {
    $owner = Customer::factory()->create(['dni' => '30123727', 'email' => 'dup@b.com']);
    $survivor = Customer::factory()->create(['dni' => null, 'email' => null]);

    $result = $this->service->reconcile($survivor, [
        'dni' => '30123727', 'email' => 'DUP@b.com', 'phone' => null,
    ]);

    expect($result->id)->toBe($survivor->id)
        ->and($result->dni)->toBe('30123727')
        ->and($result->email)->toBe('dup@b.com')
        ->and(Customer::withTrashed()->find($owner->id))->toBeNull();
});

it('reconcile es no-op si ninguna otra fila posee esos identificadores', function (): void {
    $survivor = Customer::factory()->create(['dni' => '30123727']);
    $before = Customer::count();

    $result = $this->service->reconcile($survivor, [
        'dni' => '30123727', 'email' => null, 'phone' => null,
    ]);

    expect($result->id)->toBe($survivor->id)
        ->and(Customer::count())->toBe($before);
});

it('escenario #4: el checkout reconcilia la fila por-email y la app la resuelve', function (): void {
    // Fila creada por WhatsApp (solo teléfono) que sostiene la conversación/póliza.
    $whatsappRow = Customer::factory()->create([
        'phone' => '+5493516280778', 'dni' => null, 'email' => null,
    ]);
    // Fila creada por otra puerta (email+dni), con el mobile_account de la app.
    $emailRow = Customer::factory()->create([
        'email' => 'andresrom@gmail.com', 'dni' => '30123727', 'phone' => null,
    ]);
    $conv = Conversation::factory()->create(['customer_id' => $whatsappRow->id]);
    $mobile = MobileAccount::factory()->create([
        'email' => 'andresrom@gmail.com', 'customer_id' => $emailRow->id,
    ]);

    // Checkout: la persona declara dni+email → reconcilia por claves fuertes.
    $survivor = $this->service->reconcile($whatsappRow, [
        'dni' => '30123727', 'email' => 'andresrom@gmail.com',
    ]);

    // El survivor es la fila de la conversación, ahora con el email → la app lo resuelve.
    expect($survivor->id)->toBe($whatsappRow->id)
        ->and($survivor->email)->toBe('andresrom@gmail.com')
        ->and($survivor->dni)->toBe('30123727')
        ->and(Customer::withTrashed()->find($emailRow->id))->toBeNull()
        ->and($conv->refresh()->customer_id)->toBe($whatsappRow->id)
        ->and($mobile->fresh()->resolveCustomer()?->id)->toBe($whatsappRow->id);
});

it('reconcile NO fusiona dos personas distintas que comparten teléfono (número reasignado)', function (): void {
    $other = Customer::factory()->create([
        'dni' => '30111111', 'email' => 'a@a.com', 'phone' => '+5493510000000',
    ]);
    $survivor = Customer::factory()->create([
        'dni' => null, 'email' => null, 'phone' => '+5493510000000',
    ]);
    $before = Customer::count();

    // Declara su propio dni/email (que nadie más posee) + el teléfono compartido.
    $result = $this->service->reconcile($survivor, [
        'dni' => '30999999', 'email' => 'b@b.com', 'phone' => '+5493510000000',
    ]);

    // El teléfono se ignora: no fusiona la fila de la otra persona.
    expect($result->id)->toBe($survivor->id)
        ->and(Customer::count())->toBe($before)
        ->and(Customer::find($other->id))->not->toBeNull();
});

it('chat identify: enriquece la fila linkeada con un identificador que nadie posee (no crea otra)', function (): void {
    $service = app(CustomerIdentificationService::class);
    $linked = Customer::factory()->create([
        'phone' => '+5493511111111', 'email' => null, 'dni' => null,
    ]);
    $before = Customer::count();

    $result = $service->resolveForConversation('email', 'nuevo@x.com', $linked);

    expect($result->id)->toBe($linked->id)
        ->and($result->email)->toBe('nuevo@x.com')
        ->and(Customer::count())->toBe($before);
});

it('chat identify: fusiona cuando el identificador pertenece a otra fila', function (): void {
    $service = app(CustomerIdentificationService::class);
    $linked = Customer::factory()->create([
        'phone' => '+5493511111111', 'dni' => null, 'email' => null,
    ]);
    $other = Customer::factory()->create([
        'email' => 'ya@existe.com', 'dni' => '30222222',
    ]);
    $conv = Conversation::factory()->create(['customer_id' => $linked->id]);

    $result = $service->resolveForConversation('email', 'ya@existe.com', $linked);

    expect($result->id)->toBe($linked->id)              // survivor = fila linkeada
        ->and($result->email)->toBe('ya@existe.com')    // absorbió el email
        ->and($result->dni)->toBe('30222222')           // y el dni
        ->and(Customer::withTrashed()->find($other->id))->toBeNull()
        ->and($conv->refresh()->customer_id)->toBe($linked->id);
});

it('survivorship por campo: un campo curado por admin no se pisa con un dato de chat', function (): void {
    $consolidation = app(CustomerConsolidationService::class);

    $survivor = Customer::factory()->create(['dni' => null, 'email' => null]);
    $consolidation->apply($survivor, ['dni' => '30111111'], 'admin');

    $loser = Customer::factory()->create(['dni' => null, 'email' => null]);
    $consolidation->apply($loser, ['dni' => '30999999', 'email' => 'fill@x.com'], 'chat');

    $this->service->merge($survivor, $loser);
    $survivor->refresh();

    expect($survivor->dni)->toBe('30111111')         // admin protegido
        ->and($survivor->email)->toBe('fill@x.com'); // hueco rellenado por el perdedor
});

it('survivorship por campo: a igual confianza gana el dato más reciente', function (): void {
    $consolidation = app(CustomerConsolidationService::class);

    Carbon::setTestNow('2026-06-01 10:00:00');
    $survivor = Customer::factory()->create(['phone' => null]);
    $consolidation->apply($survivor, ['phone' => '+5493510000001'], 'chat');

    Carbon::setTestNow('2026-06-10 10:00:00');
    $loser = Customer::factory()->create(['phone' => null]);
    $consolidation->apply($loser, ['phone' => '+5493510000002'], 'chat');

    $this->service->merge($survivor, $loser);

    expect($survivor->refresh()->phone)->toBe('+5493510000002'); // el más nuevo gana

    Carbon::setTestNow();
});
