<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_provider_refs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->string('external_quote_id')->index();
            $table->json('raw_response')->nullable();
            $table->timestamps();
        });

        Schema::table('quote_alternatives', function (Blueprint $table) {
            $table->dropIndex('quote_alternatives_external_quote_id_index');
            $table->dropIndex('quote_alternatives_external_code_index');
            $table->dropColumn(['external_quote_id', 'external_code']);
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn('raw_response');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->text('raw_response')->nullable();
        });

        Schema::table('quote_alternatives', function (Blueprint $table) {
            $table->string('external_code')->nullable()->index();
            $table->string('external_quote_id')->nullable()->index();
        });

        Schema::dropIfExists('quote_provider_refs');
    }
};
