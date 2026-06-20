<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Referencia opaca del proveedor POR PÓLIZA, acotada a la captura diferida de
     * documentos.
     *
     * Aísla del dominio (`polizas`) el token que la captura de documentos necesita
     * cuando un PDF no estuvo listo dentro de la ventana de `emit()`: el documento se
     * genera async del lado de la compañía, así que la captura se reintenta fuera del
     * request (job `CapturePendingPolicyDocuments`). El dominio guarda el token opaco
     * (su valor es el `presale_id` de Visred, pero el dominio NO lo interpreta — lo
     * persiste y se lo devuelve al puerto) + los `kind` pendientes.
     *
     * Espeja la convención `*_provider_refs` (ver `quote_alternative_provider_refs`,
     * que aísla el `quotation_result_id` que `emitir/` consume): mismo patrón de token
     * opaco que entra y sale del adapter sin que el dominio lo entienda. Ver docs/v2/10
     * §3 y docs/v2/08 §6 (captura diferida de documentos).
     *
     * Efímera por diseño: el `presale_id` caduca, así que la fila se borra cuando no
     * quedan documentos pendientes (o cuando el job agota reintentos). No es una
     * referencia durable de cartera — esa es `polizas.numero`.
     */
    public function up(): void
    {
        Schema::create('poliza_provider_refs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('poliza_id')->constrained()->cascadeOnDelete();
            // Token opaco del proveedor que habilita re-pedir los documentos (presale_id).
            $table->string('document_token');
            // Producto del catálogo del proveedor (auto/hogar/...). Lo necesita el re-pedido.
            $table->string('product_id')->default('auto');
            // `kind` de dominio (PolicyDocumentKind) todavía sin capturar. Se vacía a
            // medida que el job los descarga; fila vacía → se borra.
            $table->json('pending_document_kinds');
            // Observabilidad del reintento (cuándo se intentó por última vez).
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamps();

            // Una sola referencia de captura diferida por póliza.
            $table->unique('poliza_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('poliza_provider_refs');
    }
};
