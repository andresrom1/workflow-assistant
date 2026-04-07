<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_prompts', function (Blueprint $table) {
            $table->id();
            $table->string('agent_key')->index();
            $table->longText('content');
            $table->unsignedInteger('version');
            $table->boolean('is_active')->default(false)->index();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['agent_key', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_prompts');
    }
};
