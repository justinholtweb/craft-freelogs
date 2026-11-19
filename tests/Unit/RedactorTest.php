<?php

declare(strict_types=1);

use JustinHoltWeb\Freelog\Helpers\Redactor;
use JustinHoltWeb\Freelog\Services\Digest;

/*
 * What the error digest takes out of a log line before it leaves the server — and, as much,
 * what it leaves alone, because a digest that masks every other word is no use either.
 */

const M = Redactor::MASK;

test('known secrets go by value', function () {
    $redactor = new Redactor(['s3cr3t-security-key', 'abc']);

    $this->assertSame('key was '.M.' here', $redactor->redact('key was s3cr3t-security-key here'));
    // Too short to replace by value without hitting ordinary words.
    $this->assertSame('abc is fine', $redactor->redact('abc is fine'));
});

test('credential shaped values', function () {
    $r = new Redactor;

    $this->assertSame('Authorization: Bearer '.M, $r->redact('Authorization: Bearer abc123def456ghi789'));
    $this->assertSame('POST /login password='.M.'&remember=1', $r->redact('POST /login password=hunter2&remember=1'));
    $this->assertSame('{"loginName":"x","password":"'.M.'"}', $r->redact('{"loginName":"x","password":"hunter2 with spaces"}'));
    $this->assertSame("'apiKey' => '".M."'", $r->redact("'apiKey' => 'sk_live_abcdef'"));
    $this->assertSame('[password] => '.M, $r->redact('[password] => hunter2'));
    $this->assertSame('secret = '.M, $r->redact('secret = hunter2'));
    $this->assertSame('GET /cb?code=1&access_token='.M, $r->redact('GET /cb?code=1&access_token=ya29.a0AfH6SM'));
    $this->assertSame('mysql://'.M.'@db:3306/craft', $r->redact('mysql://root:p4ss@db:3306/craft'));
    $this->assertSame('jwt '.M.' end', $r->redact('jwt eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.sig-nature end'));
    $this->assertSame('token: '.M, $r->redact('token: 9f8e7d6c5b4a3f2e'));
});

test('the word after token is not eaten', function () {
    $r = new Redactor;

    // The erpy gotcha: a pattern for "token: <value>" took the next word of plain prose.
    $this->assertSame('Invalid CSRF token: missing from the request', $r->redact('Invalid CSRF token: missing from the request'));
    $this->assertSame('The CSRF token could not be verified.', $r->redact('The CSRF token could not be verified.'));
    $this->assertSame('CSRF token expired', $r->redact('CSRF token expired'));
    $this->assertSame('Basic authentication failed', $r->redact('Basic authentication failed'));
    $this->assertSame('password: incorrect for user', $r->redact('password: incorrect for user'));
    $this->assertSame('craft\records\Token::find() failed', $r->redact('craft\records\Token::find() failed'));
});

test('personal data', function () {
    $r = new Redactor;

    $this->assertSame('No user '.M.'@example.com', $r->redact('No user jo.smith+news@example.com'));
    $this->assertSame('from 203.0.113.x via proxy', $r->redact('from 203.0.113.42 via proxy'));
    $this->assertSame('version 1.2.3.4.5 kept', $r->redact('version 1.2.3.4.5 kept'));
    $this->assertSame('card '.M.' declined', $r->redact('card 4242 4242 4242 4242 declined'));
    // Not a Luhn number: an order or entry id stays readable.
    $this->assertSame('order 1234567890123 failed', $r->redact('order 1234567890123 failed'));
});

test('length cap and invalid UTF-8', function () {
    $r = new Redactor;

    $out = $r->redact(str_repeat('a', 600), 100);
    $this->assertSame(100, mb_strlen($out));
    $this->assertStringEndsWith('…', $out);
    $this->assertTrue(mb_check_encoding($r->redact("bad \xC3 byte"), 'UTF-8'));
});

test('split keeps the first line and drops monolog context', function () {
    [$first, $trace] = Digest::split("Boom in Foo.php:12 {\"exception\":{\"class\":\"X\"}} []\n#0 a()\n\n#1 b()");
    $this->assertSame('Boom in Foo.php:12', $first);
    $this->assertSame("#0 a()\n#1 b()", $trace);

    [$first] = Digest::split('Value was [1, 2] here');
    $this->assertSame('Value was [1, 2] here', $first, 'Brackets in the message itself stay.');

    [, $trace] = Digest::split("x\n".implode("\n", array_map(fn ($i) => "#$i f()", range(0, 40))));
    $this->assertSame(Digest::MAX_TRACE_LINES, substr_count($trace, "\n") + 1);
});

test('fingerprint groups repeats', function () {
    $this->assertSame(
        Digest::fingerprint('Undefined array key 17 in "site/_entry" at line 42'),
        Digest::fingerprint('Undefined array key 3 in "site/_news" at line 9'),
    );
    $this->assertNotSame(
        Digest::fingerprint('Undefined array key 17'),
        Digest::fingerprint('Calling unknown method foo()'),
    );
});

test('the install’s own secrets are Laravel’s: APP_KEY, database passwords, secret-named env vars', function () {
    $secrets = Redactor::installSecrets(
        [
            'app.key' => 'base64:Zm9vYmFyYmF6cXV4MTIzNDU2Nzg5MGFiY2RlZmdoaWo=',
            'app.previous_keys' => ['base64:b2xka2V5b2xka2V5b2xka2V5b2xka2V5b2xka2V5MTI='],
            'securityKey' => 'craft5-security-key-value',
            'database.connections' => [
                'mysql' => ['driver' => 'mysql', 'password' => 'db-pass-123'],
                'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ],
        [
            'STRIPE_SECRET' => 'sk_live_51abcdef',
            'MAIL_PASSWORD' => 'smtp-pass-456',
            'AUTH_METHOD' => 'password',   // a setting, not a secret
            'APP_NAME' => 'Craft',
            'PWD' => '/var/www/html',      // the shell's working directory
            'CRAFT_SECURITY_KEY' => 'from-the-old-env-file',
        ],
    );

    expect($secrets)->toContain('base64:Zm9vYmFyYmF6cXV4MTIzNDU2Nzg5MGFiY2RlZmdoaWo=')
        ->toContain('Zm9vYmFyYmF6cXV4MTIzNDU2Nzg5MGFiY2RlZmdoaWo=')
        ->toContain('b2xka2V5b2xka2V5b2xka2V5b2xka2V5b2xka2V5MTI=')
        ->toContain('craft5-security-key-value')
        ->toContain('db-pass-123')
        ->toContain('sk_live_51abcdef')
        ->toContain('smtp-pass-456')
        ->toContain('from-the-old-env-file')
        ->not->toContain('password')
        ->not->toContain('Craft')
        ->not->toContain('/var/www/html');

    $r = new Redactor($secrets);
    $line = 'Decrypt failed with key Zm9vYmFyYmF6cXV4MTIzNDU2Nzg5MGFiY2RlZmdoaWo= for jo@example.com from 10.0.0.12';

    expect($r->redact($line))->toBe('Decrypt failed with key '.M.' for '.M.'@example.com from 10.0.0.x');
});

test('a Laravel log line with JSON context is redacted', function () {
    $r = new Redactor;
    $line = 'Login failed {"loginName":"jo@example.com","password":"hunter2","ip":"203.0.113.42"}';

    expect($r->redact($line))->toBe('Login failed {"loginName":"'.M.'@example.com","password":"'.M.'","ip":"203.0.113.x"}');
});
