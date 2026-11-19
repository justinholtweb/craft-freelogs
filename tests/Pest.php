<?php

declare(strict_types=1);

/*
 |--------------------------------------------------------------------------
 | Freelog's unit suite
 |--------------------------------------------------------------------------
 |
 | The log reader, the parser, the redactor and the digest's position logic are plain PHP over
 | files in a temporary directory, so they run without booting Craft or a database. Mail, the
 | marker table, routes and permissions are verified live in the plugin-testing-craft6 harness.
 |
 */

uses()->group('unit')->in('Unit');

/** A real log excerpt from `tests/fixtures`. */
function logFixture(string $name): string
{
    return (string) file_get_contents(__DIR__.'/fixtures/'.$name);
}
