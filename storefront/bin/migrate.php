#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Create or update the storefront's MySQL tables. Safe to run again: each
 * migration is applied once.
 *
 *     php bin/migrate.php
 */

require dirname(__DIR__).'/src/bootstrap.php';

use Storefront\Support\Migrator;

$applied = Migrator::run(dirname(__DIR__).'/database/migrations');

echo $applied === [] ? "Nothing to migrate.\n" : 'Applied: '.implode(', ', $applied)."\n";
