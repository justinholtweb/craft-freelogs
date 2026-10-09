<?php

namespace justinholtweb\freelog\tests;

use justinholtweb\freelog\helpers\Redactor;
use justinholtweb\freelog\services\Digest;
use PHPUnit\Framework\TestCase;

/**
 * What the error digest takes out of a log line before it leaves the server — and, as much,
 * what it leaves alone, because a digest that masks every other word is no use either.
 */
class RedactorTest extends TestCase
{
    private const M = Redactor::MASK;

    public function testKnownSecretsGoByValue(): void
    {
        $redactor = new Redactor(['s3cr3t-security-key', 'abc']);

        self::assertSame('key was ' . self::M . ' here', $redactor->redact('key was s3cr3t-security-key here'));
        // Too short to replace by value without hitting ordinary words.
        self::assertSame('abc is fine', $redactor->redact('abc is fine'));
    }

    public function testCredentialShapedValues(): void
    {
        $r = new Redactor();

        self::assertSame('Authorization: Bearer ' . self::M, $r->redact('Authorization: Bearer abc123def456ghi789'));
        self::assertSame('POST /login password=' . self::M . '&remember=1', $r->redact('POST /login password=hunter2&remember=1'));
        self::assertSame('{"loginName":"x","password":"' . self::M . '"}', $r->redact('{"loginName":"x","password":"hunter2 with spaces"}'));
        self::assertSame("'apiKey' => '" . self::M . "'", $r->redact("'apiKey' => 'sk_live_abcdef'"));
        self::assertSame('[password] => ' . self::M, $r->redact('[password] => hunter2'));
        self::assertSame('secret = ' . self::M, $r->redact('secret = hunter2'));
        self::assertSame('GET /cb?code=1&access_token=' . self::M, $r->redact('GET /cb?code=1&access_token=ya29.a0AfH6SM'));
        self::assertSame('mysql://' . self::M . '@db:3306/craft', $r->redact('mysql://root:p4ss@db:3306/craft'));
        self::assertSame('jwt ' . self::M . ' end', $r->redact('jwt eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.sig-nature end'));
        self::assertSame('token: ' . self::M, $r->redact('token: 9f8e7d6c5b4a3f2e'));
    }

    public function testTheWordAfterTokenIsNotEaten(): void
    {
        $r = new Redactor();

        // The erpy gotcha: a pattern for "token: <value>" took the next word of plain prose.
        self::assertSame('Invalid CSRF token: missing from the request', $r->redact('Invalid CSRF token: missing from the request'));
        self::assertSame('The CSRF token could not be verified.', $r->redact('The CSRF token could not be verified.'));
        self::assertSame('CSRF token expired', $r->redact('CSRF token expired'));
        self::assertSame('Basic authentication failed', $r->redact('Basic authentication failed'));
        self::assertSame('password: incorrect for user', $r->redact('password: incorrect for user'));
        self::assertSame('craft\records\Token::find() failed', $r->redact('craft\records\Token::find() failed'));
    }

    public function testPersonalData(): void
    {
        $r = new Redactor();

        self::assertSame('No user ' . self::M . '@example.com', $r->redact('No user jo.smith+news@example.com'));
        self::assertSame('from 203.0.113.x via proxy', $r->redact('from 203.0.113.42 via proxy'));
        self::assertSame('version 1.2.3.4.5 kept', $r->redact('version 1.2.3.4.5 kept'));
        self::assertSame('card ' . self::M . ' declined', $r->redact('card 4242 4242 4242 4242 declined'));
        // Not a Luhn number: an order or entry id stays readable.
        self::assertSame('order 1234567890123 failed', $r->redact('order 1234567890123 failed'));
    }

    public function testLengthCapAndInvalidUtf8(): void
    {
        $r = new Redactor();

        $out = $r->redact(str_repeat('a', 600), 100);
        self::assertSame(100, mb_strlen($out));
        self::assertStringEndsWith('…', $out);
        self::assertTrue(mb_check_encoding($r->redact("bad \xC3 byte"), 'UTF-8'));
    }

    public function testSplitKeepsTheFirstLineAndDropsMonologContext(): void
    {
        [$first, $trace] = Digest::split("Boom in Foo.php:12 {\"exception\":{\"class\":\"X\"}} []\n#0 a()\n\n#1 b()");
        self::assertSame('Boom in Foo.php:12', $first);
        self::assertSame("#0 a()\n#1 b()", $trace);

        [$first] = Digest::split('Value was [1, 2] here');
        self::assertSame('Value was [1, 2] here', $first, 'Brackets in the message itself stay.');

        [, $trace] = Digest::split("x\n" . implode("\n", array_map(fn($i) => "#$i f()", range(0, 40))));
        self::assertSame(Digest::MAX_TRACE_LINES, substr_count($trace, "\n") + 1);
    }

    public function testFingerprintGroupsRepeats(): void
    {
        self::assertSame(
            Digest::fingerprint('Undefined array key 17 in "site/_entry" at line 42'),
            Digest::fingerprint('Undefined array key 3 in "site/_news" at line 9'),
        );
        self::assertNotSame(
            Digest::fingerprint('Undefined array key 17'),
            Digest::fingerprint('Calling unknown method foo()'),
        );
    }
}
