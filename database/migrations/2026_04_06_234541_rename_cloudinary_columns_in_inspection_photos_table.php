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
        Schema::table('inspection_photos', function (Blueprint $table) {
            $table->renameColumn('cloudinary_public_id', 'storage_path');
            $table->renameColumn('cloudinary_url', 'storage_url');
        });
    }

    public function down(): void
    {
        Schema::table('inspection_photos', function (Blueprint $table) {
            $table->renameColumn('storage_path', 'cloudinary_public_id');
            $table->renameColumn('storage_url', 'cloudinary_url');
        });
    }
};
