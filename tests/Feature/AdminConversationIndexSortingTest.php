<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminConversationIndexSortingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    #[Test]
    public function it_sorts_conversations_by_updated_at_descending_by_default(): void
    {
        $older = Conversation::factory()->create(['updated_at' => now()->subDays(2)]);
        $newer = Conversation::factory()->create(['updated_at' => now()->subDay()]);

        $this->actingAs($this->admin)
            ->get(route('admin.conversations.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Conversations/Index')
                ->where('conversations.data.0.id', $newer->id)
                ->where('conversations.data.1.id', $older->id)
            );
    }

    #[Test]
    public function it_sorts_conversations_by_customer_name(): void
    {
        $customerA = Customer::factory()->create(['name' => 'Ana García']);
        $customerZ = Customer::factory()->create(['name' => 'Zoe Martínez']);

        $conversationZ = Conversation::factory()->create(['customer_id' => $customerZ->id]);
        $conversationA = Conversation::factory()->create(['customer_id' => $customerA->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.conversations.index', ['sort' => 'customer_name', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('conversations.data.0.id', $conversationA->id)
                ->where('conversations.data.1.id', $conversationZ->id)
            );
    }

    #[Test]
    public function it_sorts_conversations_by_messages_count(): void
    {
        $conversationFew = Conversation::factory()->create();
        $conversationMany = Conversation::factory()->create();

        $conversationFew->messages()->create([
            'direction' => 'inbound',
            'content' => 'Hola',
            'type' => 'text',
        ]);

        $conversationMany->messages()->createMany([
            ['direction' => 'inbound', 'content' => 'Hola', 'type' => 'text'],
            ['direction' => 'inbound', 'content' => '¿Cómo están?', 'type' => 'text'],
            ['direction' => 'inbound', 'content' => 'Gracias', 'type' => 'text'],
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.conversations.index', ['sort' => 'messages_count', 'direction' => 'desc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('conversations.data.0.id', $conversationMany->id)
                ->where('conversations.data.1.id', $conversationFew->id)
            );
    }

    #[Test]
    public function it_ignores_invalid_sort_parameters(): void
    {
        Conversation::factory()->count(3)->create();

        $this->actingAs($this->admin)
            ->get(route('admin.conversations.index', ['sort' => 'injected', 'direction' => 'desc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Conversations/Index')
                ->has('conversations.data', 3)
            );
    }
}
