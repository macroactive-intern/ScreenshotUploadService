<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screenshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('original_name');
            $table->string('path');
            $table->unsignedBigInteger('size_bytes');
            $table->enum('mime_type', ['image/png', 'image/jpeg']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screenshots');
    }
};
