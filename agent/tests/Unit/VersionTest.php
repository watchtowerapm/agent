<?php

namespace Tests\Unit;

use Tests\TestCase;

use function file_get_contents;
use function json_decode;

class VersionTest extends TestCase
{
    public function test_version_is_up_to_date(): void
    {
        $composer = json_decode(file_get_contents(__DIR__.'/../../../composer.json'), true);

        $this->assertSame($composer['version'], self::packageVersion());
    }
}
