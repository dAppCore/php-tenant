<?php

// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

use Tests\TestCase;

/*
| Tests\TestCase, not Orchestra\Testbench\TestCase.
|
| The raw Testbench case registers no providers, so Core\Tenant\Boot never
| booted, none of this package's migrations ran, and every test that touched a
| workspace failed on a missing table. The package's own case is the one that
| registers Boot and loads the migrations.
*/
uses(TestCase::class)->in('Feature', 'Unit');
