<?php

use Illuminate\Support\Facades\Schema;

it('removes the deals table when its migration is rolled back', function () {
    $migration = require database_path('migrations/2026_10_20_212632_create_deals_table.php');

    $migration->down();

    expect(Schema::hasTable('deals'))->toBeFalse();
});
