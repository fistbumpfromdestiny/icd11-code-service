<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create extension if not exists pg_trgm');

        // Built at import: own title at weight A, ancestor titles at weight C.
        // Raw statement, since Blueprint has no tsvector type.
        DB::statement('alter table icd_entities add column search_vector tsvector');
        DB::statement('create index icd_entities_search_vector_gin on icd_entities using gin (search_vector)');
        DB::statement('create index icd_entities_title_trgm on icd_entities using gin (title gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists icd_entities_title_trgm');
        DB::statement('drop index if exists icd_entities_search_vector_gin');
        DB::statement('alter table icd_entities drop column if exists search_vector');
        DB::statement('drop extension if exists pg_trgm');
    }
};
