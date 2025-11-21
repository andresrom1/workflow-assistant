<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('dni')->unique()->nullable();
            $table->string('email')->unique()->nullable();
            $table->string('phone')->nullable()->nullable();
            $table->string('name')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_anonymous')->default(false);
            $table->timestamp('completed_at')->nullable(); // Ver si es necesario
            $table->timestamps();
            $table->softDeletes(); // ← Soft deletes
            
            $table->index('dni');
            $table->index('email');
            $table->index('phone');
            $table->index('deleted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
