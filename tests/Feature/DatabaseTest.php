<?php

use Illuminate\Support\Facades\DB;

it('runs on the PostgreSQL test database', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('icd_testing');
});

it('has the pg_trgm extension available', function () {
    $available = DB::selectOne(
        'select 1 from pg_available_extensions where name = ?',
        ['pg_trgm'],
    );

    expect($available)->not->toBeNull();
});
