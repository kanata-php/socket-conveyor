<?php

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    protected string $dbLocation = __DIR__ . '/Assets/temp-database.sqlite';
}
