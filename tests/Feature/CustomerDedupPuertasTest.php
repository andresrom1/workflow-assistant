<?php

use App\Jobs\ProcessConversationInbox;
use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\User;
use App\Services\PolicyChainResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

/**
 * Toda puerta por la que entra un cliente identifica con CustomerIdentificationService antes de
 * crear. Hasta el 2026-07-24 el orquestador hacía esa búsqueda por teléfono en cada turno
 * (`tryAutoIdentifyByPhone`); el refactor a BSUID la eliminó y nadie la reemplazó, así que cada
 * conversación nueva del mismo usuario acuñaba un Customer duplicado. Ver ROADMAP.
 */
beforeEach(function () {
    Bus::fake([ProcessConversationInbox::class]);
    $this->waId = '5493516280778';
    $this->bsuid = 'AR.2891873447684597';
});

function ingesta(string $waId, string $messageId, ?string $bsuid = null, string $contactName = 'Andrés'): void
{
    ProcessWhatsAppMessage::dispatchSync(
        waId: $waId,
        messageBody: 'Hola',
        messageId: $messageId,
        phoneNumberId: '123456789',
        contactName: $contactName,
        extUserId: $bsuid,
    );
}

it('dos ingestas con el mismo teléfono dejan UN solo cliente', function () {
    ingesta($this->waId, 'wamid.uno');

    // Segunda conversación del mismo número (BSUID distinto, p. ej. tras un cambio de cuenta).
    ingesta($this->waId, 'wamid.dos', 'AR.otro-bsuid');

    expect(Customer::where('phone', '+'.$this->waId)->count())->toBe(1)
        ->and(Customer::count())->toBe(1);
});

it('tras el Reset del admin, el mismo BSUID vuelve al mismo cliente', function () {
    ingesta($this->waId, 'wamid.uno', $this->bsuid);

    $customerId = Conversation::where('ext_user_id', $this->bsuid)->value('customer_id');

    // El Reset archiva la conversación: el siguiente mensaje no la encuentra y abre una nueva.
    Conversation::where('ext_user_id', $this->bsuid)->update([
        'status' => 'archived',
        'external_conversation_id' => 'archived_1',
    ]);

    ingesta($this->waId, 'wamid.dos', $this->bsuid);

    $conversaciones = Conversation::where('ext_user_id', $this->bsuid)->get();

    expect($conversaciones)->toHaveCount(2)
        ->and($conversaciones->pluck('customer_id')->unique()->all())->toBe([$customerId])
        ->and(Customer::count())->toBe(1);
});

it('el BSUID gana sobre el teléfono cuando apuntan a clientes distintos', function () {
    $porBsuid = Customer::factory()->create(['phone' => null, 'dni' => null]);
    Conversation::factory()->create(['ext_user_id' => $this->bsuid, 'customer_id' => $porBsuid->id]);
    Customer::factory()->create(['phone' => '+'.$this->waId, 'dni' => null]);

    ingesta($this->waId, 'wamid.uno', $this->bsuid);

    expect(Conversation::where('ext_user_id', $this->bsuid)->latest('id')->value('customer_id'))
        ->toBe($porBsuid->id);
});

it('un usuario nuevo de verdad sigue creando su cliente', function () {
    ingesta('5491199999999', 'wamid.nuevo', 'AR.nuevo');

    expect(Customer::where('phone', '+5491199999999')->count())->toBe(1);
});

it('el alta manual del admin reusa el cliente que ya existía por WhatsApp', function () {
    ingesta($this->waId, 'wamid.uno', $this->bsuid);
    $existente = Customer::firstOrFail();

    $this->actingAs(User::factory()->create())
        ->post(route('customers.store'), [
            'name' => 'Andrés Romero',
            'dni' => '30123727',
            'phone' => '+'.$this->waId,
        ])
        ->assertRedirect(route('customers.show', $existente));

    expect(Customer::count())->toBe(1)
        ->and($existente->fresh()->dni)->toBe('30123727');
});

it('la ingesta de pólizas encuentra al mismo cliente aunque venga el CUIL en vez del DNI', function () {
    $chain = app(PolicyChainResolver::class);

    $porDni = $chain->resolveCustomer('30123727', ['first_name' => 'Andrés', 'last_name' => 'Romero']);
    $porCuil = $chain->resolveCustomer('20301237271', ['first_name' => 'Andrés', 'last_name' => 'Romero']);

    expect($porCuil->id)->toBe($porDni->id)
        ->and(Customer::count())->toBe(1);
});

it('el CUIT de una persona jurídica NO se reduce al DNI de una física', function () {
    $chain = app(PolicyChainResolver::class);

    $fisica = $chain->resolveCustomer('30123727', ['first_name' => 'Andrés', 'last_name' => 'Romero']);
    $juridica = $chain->resolveCustomer('30123727123', ['razon_social' => 'Mango SRL'], 'cuit', 'juridica');

    expect($juridica->id)->not->toBe($fisica->id)
        ->and(Customer::count())->toBe(2);
});

it('dos pólizas del mismo documento siguen cayendo en un solo cliente', function () {
    $chain = app(PolicyChainResolver::class);

    $uno = $chain->resolveCustomer('27123456', ['first_name' => 'Ana', 'last_name' => 'Pérez']);
    $dos = $chain->resolveCustomer('27123456', ['first_name' => 'Ana', 'last_name' => 'Pérez']);

    expect($dos->id)->toBe($uno->id)
        ->and(Customer::count())->toBe(1);
});
