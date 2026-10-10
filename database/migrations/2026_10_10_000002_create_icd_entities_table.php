<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('icd_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            // Identity across releases; codes get reassigned.
            $table->text('linearization_uri');
            // Residual categories (.Y, .Z) have no foundation URI.
            $table->text('foundation_uri')->nullable();
            $table->string('code')->nullable();
            $table->string('block_id')->nullable();
            $table->text('title');
            $table->string('class_kind');
            $table->string('chapter', 2);
            $table->smallInteger('depth');
            $table->foreignId('parent_id')->nullable()->constrained('icd_entities');
            // Compared across releases, since parent_id is per release.
            $table->text('parent_uri')->nullable();
            $table->boolean('is_leaf');
            $table->boolean('is_residual');
            $table->boolean('is_primary_tabulation')->nullable();
            $table->text('coding_note')->nullable();

            $table->unique(['release_id', 'linearization_uri']);
            $table->index(['release_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('icd_entities');
    }
};
