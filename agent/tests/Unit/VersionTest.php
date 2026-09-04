<?php

namespace Tests\Unit;

use Tests\TestCase;

use function file_get_contents;
use function json_decode;

class VersionTest extends TestCase
{
    public function test_version_is_up_to_date(): void
    {
        $json = file_get_contents(__DIR__.'/../../../composer.json');
        $this->assertNotFalse($json);

        $composer = json_decode($json, true);
        $this->assertIsArray($composer);
        $this->assertArrayHasKey('version', $composer);
        $this->assertSame($composer['version'], self::packageVersion());
    }
}
