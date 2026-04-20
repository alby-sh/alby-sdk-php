<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pest bootstrap
|--------------------------------------------------------------------------
*/

uses()->beforeEach(function (): void {
    \Alby\Report\Alby::reset();
})->in(__DIR__);
