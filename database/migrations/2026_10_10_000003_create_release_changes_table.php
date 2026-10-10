<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('release_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->foreignId('previous_release_id')->constrained('releases')->cascadeOnDelete();
            $table->string('change_type');
            $table->text('linearization_uri');
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();

            $table->index(['release_id', 'change_type']);
            $table->index('previous_release_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_changes');
    }
};
