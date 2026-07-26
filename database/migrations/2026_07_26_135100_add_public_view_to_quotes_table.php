<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            // Credencial de la vista pública de cotizaciones (/cotizaciones/{token}).
            // 16 chars: es un link que viaja por WhatsApp, no una URL para tipear.
            $table->string('public_token', 16)->nullable()->unique()->after('checkout_token');

            // Qué presentó el agente por WhatsApp. Hasta ahora la única traza de esto era
            // agent_execution_logs.tool_calls, que es un log de auditoría sin retención.
            // Sin FK, igual que checkout_alternative_id: QuoteRepository::saveResults() borra y
            // recrea las alternativas en cada reintento del job, y una FK restrictiva rompería
            // esa idempotencia.
            $table->unsignedBigInteger('recommended_alternative_id')->nullable()->after('checkout_alternative_id');

            // Ordenado, recomendada primero — mismo orden que los botones de Meta.
            $table->json('presented_alternative_ids')->nullable()->after('recommended_alternative_id');

            // Mapa {"<alternative_id>": "razón"}. Lo escribe el LLM y se muestra literal en una
            // página pública: ver la deuda documentada en resources/prompts/agents/CheckoutAgent.md.
            $table->json('presentation_reasons')->nullable()->after('presented_alternative_ids');

            $table->timestamp('presented_at')->nullable()->after('presentation_reasons');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropUnique(['public_token']);
            $table->dropColumn([
                'public_token',
                'recommended_alternative_id',
                'presented_alternative_ids',
                'presentation_reasons',
                'presented_at',
            ]);
        });
    }
};
