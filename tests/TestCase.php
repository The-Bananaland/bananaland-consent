<?php

namespace Bananaland\Consent\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();
        Functions\when('wp_get_environment_type')->justReturn('production');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }
}
