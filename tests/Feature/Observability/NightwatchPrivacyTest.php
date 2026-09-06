<?php

use App\Models\User;
use App\Support\Observability\NightwatchPrivacy;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Records\CacheEvent;
use Laravel\Nightwatch\Records\Command;
use Laravel\Nightwatch\Records\Exception as NightwatchException;
use Laravel\Nightwatch\Records\OutgoingRequest;
use Laravel\Nightwatch\Records\Request as NightwatchRequest;
use Symfony\Component\HttpFoundation\FileBag;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * the Nightwatch evaluation: the redaction callbacks, exercised the way the agent path
 * exercises them.
 *
 * Each of these hands a real Nightwatch record to the exact method
 * App\Providers\ObservabilityServiceProvider registers as that record's
 * callback, and asserts what survives. No reflection, no assertions about the
 * package's own source, and no reimplementation of the rule under test.
 */
function nightwatchRequestRecord(
    string $url,
    string $routePath = '',
    string $ip = '203.0.113.9',
): NightwatchRequest {
    return new NightwatchRequest(
        method: 'GET',
        url: $url,
        routeName: '',
        routeMethods: ['GET'],
        routeDomain: '',
        routePath: $routePath,
        routeAction: '',
        ip: $ip,
        duration: 1000,
        statusCode: 200,
        requestSize: 0,
        responseSize: 0,
        headers: new HeaderBag,
        payload: new InputBag,
        files: new FileBag,
    );
}

function nightwatchPrivacy(): NightwatchPrivacy
{
    return app(NightwatchPrivacy::class);
}

// --- the authenticated user ------------------------------------------------

it('sends the internal user ID and nothing else about the user', function () {
    // Deliberately end to end rather than a direct call: this proves the
    // callback is actually registered on the Core the application booted, not
    // merely that it would return the right thing if someone called it.
    // Nightwatch's default resolver would return id, name (User::$name) and
    // username (User::$email); ours returns the ID alone.
    $user = User::factory()->create([
        'name' => 'Sentinel Person',
        'email' => 'nightwatch-secret@example.invalid',
    ]);

    $this->actingAs($user);

    $details = app(Core::class)->executionState->user->details();

    expect($details)->toBe(['id' => $user->id]);
    expect(array_keys($details))->toBe(['id']);

    $encoded = json_encode($details);
    expect($encoded)
        ->not->toContain('Sentinel Person')
        ->not->toContain('nightwatch-secret@example.invalid');
});

it('returns no optional user fields at all', function () {
    expect(nightwatchPrivacy()->userDetails(User::factory()->make()))->toBe([]);
});

// --- incoming requests ------------------------------------------------------

it('removes the request IP entirely, rather than hashing or truncating it', function () {
    $record = nightwatchRequestRecord('https://rateguru.test/', '/', '203.0.113.9');

    nightwatchPrivacy()->redactRequest($record);

    // Not a hash and not a /24: a reversible-by-lookup stand-in is not
    // anonymity, and the Nightwatch evaluation has no question a client address answers.
    expect($record->ip)->toBe('');
});

it('keeps query parameter names and drops every query value', function () {
    // ?search= is free text a user typed — routinely another user's name,
    // because the feed search box is also the people search.
    $record = nightwatchRequestRecord(
        'https://rateguru.test/?search=NW_PRIVATE_SENTINEL_123&sort=top&tag=cats',
        '/',
    );

    nightwatchPrivacy()->redactRequest($record);

    expect($record->url)->toBe('https://rateguru.test/?search&sort&tag');
    expect($record->url)
        ->not->toContain('NW_PRIVATE_SENTINEL_123')
        ->not->toContain('cats')
        ->not->toContain('top');
});

it('drops a query parameter whose own name is not RateGuru-shaped', function () {
    // A parameter name is still attacker-controlled text; scanners and
    // third-party redirects are not our filter vocabulary.
    $record = nightwatchRequestRecord(
        'https://rateguru.test/?sort=top&'.urlencode('victim@example.invalid').'=1',
        '/',
    );

    nightwatchPrivacy()->redactRequest($record);

    expect($record->url)->toBe('https://rateguru.test/?sort');
    expect($record->url)->not->toContain('victim@example.invalid');
});

it('replaces the concrete path with the route pattern when the route names a secret', function (string $url, string $routePath, string $expected) {
    $record = nightwatchRequestRecord($url, $routePath);

    nightwatchPrivacy()->redactRequest($record);

    expect($record->url)->toBe($expected);
})->with([
    // A live password-reset token in the path and the account email in the
    // query — Laravel's own ResetPassword notification builds exactly this URL.
    'password reset' => [
        'https://rateguru.test/reset-password/9f1c8a7b6d5e4f3a?email=someone%40example.invalid',
        '/reset-password/{token}',
        'https://rateguru.test/reset-password/{token}',
    ],
    // The user ID, a hash of the email, and a live HMAC signature.
    'email verification' => [
        'https://rateguru.test/verify-email/42/6b3f?expires=1900000000&signature=deadbeef',
        '/verify-email/{id}/{hash}',
        'https://rateguru.test/verify-email/{id}/{hash}',
    ],
]);

it('keeps a concrete path that names nothing secret', function () {
    // A post ID is the diagnosis, not a leak — it must survive.
    $record = nightwatchRequestRecord('https://rateguru.test/posts/1234', '/posts/{post}');

    nightwatchPrivacy()->redactRequest($record);

    expect($record->url)->toBe('https://rateguru.test/posts/1234');
});

it('does not send a username back in the path after suppressing it on the user', function () {
    // /u/{username} is a public page, so this is not a secret — but the handle
    // identifies a person, RateGuru tombstones it on account deletion, and
    // Nightwatch::user() is already withholding it. Sending it in the URL
    // would make that suppression theatre.
    $record = nightwatchRequestRecord('https://rateguru.test/u/sentinel-handle', '/u/{username}');

    nightwatchPrivacy()->redactRequest($record);

    expect($record->url)->toBe('https://rateguru.test/u/{username}');
    expect($record->url)->not->toContain('sentinel-handle');
});

it('forwards only the query parameter names RateGuru itself defines', function (string $query, string $expected) {
    $record = nightwatchRequestRecord('https://rateguru.test/'.$query, '/');

    nightwatchPrivacy()->redactRequest($record);

    expect($record->url)->toBe('https://rateguru.test/'.$expected);
})->with([
    // The feed filters, which are the whole point of keeping names at all.
    'feed filters' => ['?search=x&sort=top&tag=y&feed=following', '?feed&search&sort&tag'],
    // An array filter is one name, not one per index.
    'array filter' => ['?category[0]=1&category[1]=2', '?category'],
    // A name is attacker-controlled text: an allowlist, not a shape check, is
    // what stops `?<whatever-a-bot-put-here>` being forwarded.
    'bot-supplied name' => ['?utm_source=phish&sentinel_handle=1', ''],
    // Framework markers the route pattern already implies.
    'signed URL markers' => ['?expires=1&signature=deadbeef', ''],
]);

it('keeps the port and survives a URL it cannot parse', function () {
    $record = nightwatchRequestRecord('http://rateguru.test:8080/u/someone?tab=posts', '/u/{username}');
    nightwatchPrivacy()->redactRequest($record);
    expect($record->url)->toBe('http://rateguru.test:8080/u/{username}?tab');

    $broken = nightwatchRequestRecord('http:///', '');
    nightwatchPrivacy()->redactRequest($broken);
    expect($broken->url)->toBeString();
});

// --- outgoing requests ------------------------------------------------------

it('strips the whole query string from an outgoing request URL', function () {
    // Unlike an incoming URL, nothing here is RateGuru's vocabulary: these are
    // user-pasted import URLs and the redirect hops they resolve to, so the
    // parameter names are third-party strings too.
    $record = new OutgoingRequest(
        method: 'GET',
        url: 'https://cdn.example.invalid/a/b.jpg?X-Amz-Signature=deadbeef&X-Amz-Credential=AKIAsecret',
        duration: 1000,
        requestSize: 0,
        responseSize: 100,
        statusCode: 200,
    );

    nightwatchPrivacy()->redactOutgoingRequest($record);

    // Scheme, host and path are what an import failure is diagnosed from.
    expect($record->url)->toBe('https://cdn.example.invalid/a/b.jpg');
});

it('strips a fragment from an outgoing request URL as well', function () {
    // UrlImportValidator drops the fragment from what RateGuru fetches, but a
    // redirect Location is not bound by that, and #access_token=... is a real
    // shape on the public internet.
    $record = new OutgoingRequest('GET', 'https://example.invalid/cb#access_token=deadbeef&scope=all', 1, 0, 0, 200);

    nightwatchPrivacy()->redactOutgoingRequest($record);

    expect($record->url)->toBe('https://example.invalid/cb');
});

it('leaves an outgoing URL with no query string alone', function () {
    $record = new OutgoingRequest('GET', 'https://example.invalid/page', 1, 0, 0, 200);

    nightwatchPrivacy()->redactOutgoingRequest($record);

    expect($record->url)->toBe('https://example.invalid/page');
});

// --- cache -----------------------------------------------------------------

it('rejects the login throttle cache key, which is a plaintext email and IP', function () {
    // Illuminate\Cache\RateLimiter does not hash the keys it is handed — only
    // the ThrottleRequests middleware does, before calling it — and
    // AuthenticateUserAction::throttleKey() is "<email>|<ip>". This single
    // fact is why the cache policy is an allowlist and not a blocklist.
    $record = new CacheEvent(
        store: 'database',
        key: 'someone@example.invalid|203.0.113.9',
        type: 'hit',
        duration: 100,
        ttl: 60,
    );

    expect(nightwatchPrivacy()->rejectCacheEvent($record))->toBeTrue();
});

it('allows exactly the cache key shapes RateGuru is known to produce', function (string $key) {
    $record = new CacheEvent('database', $key, 'hit', 100, 60);

    expect(nightwatchPrivacy()->rejectCacheEvent($record))->toBeFalse();
})->with([
    'sidebar-nav-categories',
    'sidebar-nav-top-tags',
    'media-audit:full',
    'media-purge:1234',
    'media-variant-write:1234:post_feed_640',
    'rate-limit:comment:user:99',
    'rate-limit:comment:user:99:timer',
    'rate-limit:vote:user:99:target:post:1234',
]);

it('rejects any cache key it does not recognise', function (string $key) {
    $record = new CacheEvent('database', $key, 'hit', 100, 60);

    expect(nightwatchPrivacy()->rejectCacheEvent($record))->toBeTrue();
})->with([
    // A future feed cache key would inline the raw search term.
    'search term' => 'post-list:feed:search="NW_PRIVATE_SENTINEL_123"',
    'session id' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0',
    'near miss on a known prefix' => 'media-purge:someone@example.invalid',
    'unknown namespace' => 'something-new:42',
]);

// --- commands ---------------------------------------------------------------

it('removes personal data from an Artisan invocation, in either option form', function (string $command) {
    // rateguru:admin:create is the one RateGuru command that takes PII as
    // arguments, and it is exactly the one an operator runs by hand on a live
    // server. Symfony Console accepts `--email=x` and `--email x` alike, so
    // both have to be covered — and Nightwatch builds this string by joining
    // the raw argv tokens with spaces, so a quoted "Ada Lovelace" arrives
    // looking like two tokens and must still be consumed whole.
    $record = new Command(
        class: 'App\\Console\\Commands\\CreateAdminUserCommand',
        name: 'rateguru:admin:create',
        command: $command,
        exitCode: 0,
        duration: 1000,
    );

    nightwatchPrivacy()->redactCommand($record);

    // The invocation stays visible; the values do not.
    expect($record->command)->toContain('rateguru:admin:create');

    foreach (['someone@example.invalid', 'sentinel-handle', 'Ada', 'Lovelace'] as $leaked) {
        expect($record->command)->not->toContain($leaked);
    }

    expect(substr_count($record->command, '[redacted]'))->toBe(3);
})->with([
    'equals form' => 'artisan rateguru:admin:create --email=someone@example.invalid --username=sentinel-handle --name=Ada',
    'space form' => 'artisan rateguru:admin:create --email someone@example.invalid --username sentinel-handle --name Ada',
    'mixed forms' => 'artisan rateguru:admin:create --email someone@example.invalid --username=sentinel-handle --name Ada',
    'multi-word value' => 'artisan rateguru:admin:create --email someone@example.invalid --username sentinel-handle --name Ada Lovelace',
]);

it('does not mistake a longer option name for one it redacts', function () {
    $record = new Command('C', 'x:y', 'artisan x:y --emails=3 --namespace=app', 0, 5);

    nightwatchPrivacy()->redactCommand($record);

    expect($record->command)->toBe('artisan x:y --emails=3 --namespace=app');
});

it('leaves a command with no personal arguments untouched', function () {
    $record = new Command('C', 'rateguru:observability:health', 'artisan rateguru:observability:health', 0, 5);

    nightwatchPrivacy()->redactCommand($record);

    expect($record->command)->toBe('artisan rateguru:observability:health');
});

// --- exceptions -------------------------------------------------------------

it('removes the offending value from a unique-constraint violation message', function (string $message, string $leaked) {
    $record = new NightwatchException(
        class: 'Illuminate\\Database\\UniqueConstraintViolationException',
        message: $message,
        code: '23505',
        file: 'x.php',
        line: 1,
        handled: false,
    );

    nightwatchPrivacy()->redactException($record);

    // The column name is the diagnosis and stays; the value does not.
    expect($record->message)->not->toContain($leaked);
    expect($record->message)->toContain('[redacted]');
})->with([
    'postgres' => [
        'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "users_email_unique" DETAIL: Key (email)=(someone@example.invalid) already exists.',
        'someone@example.invalid',
    ],
    'mysql' => [
        "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'someone@example.invalid' for key 'users.users_email_unique'",
        'someone@example.invalid',
    ],
]);

it('leaves an ordinary exception message alone', function () {
    $record = new NightwatchException('RuntimeException', 'A full media audit is already running.', 0, 'x.php', 1, false);

    nightwatchPrivacy()->redactException($record);

    expect($record->message)->toBe('A full media audit is already running.');
});

// --- wiring -----------------------------------------------------------------

it('registers every redaction callback in the one provider that owns them', function () {
    // The user callback is proven end to end above. The rest are pure record
    // mutators with nothing observable to reach for from outside the package,
    // so this guards the wiring itself: a callback that exists but is never
    // registered protects nothing.
    $provider = phpSourceWithoutComments('app/Providers/ObservabilityServiceProvider.php');

    foreach ([
        'Nightwatch::user(',
        'Nightwatch::redactRequests(',
        'Nightwatch::redactOutgoingRequests(',
        'Nightwatch::redactCommands(',
        'Nightwatch::redactExceptions(',
        'Nightwatch::rejectCacheEvents(',
    ] as $registration) {
        expect($provider)->toContain($registration);
    }

    // Every rule lives in one class, and the provider only wires it up.
    expect($provider)->toContain('NightwatchPrivacy');
});

it('builds no vendor-agnostic observability abstraction', function () {
    // Two concrete products are being compared and one is expected to be
    // removed; permanent architecture is not built around a trial.
    // Walked, not globbed: PHP's glob() has no `**`, so a pattern like
    // app/**/*APMManager*.php silently matches nothing and the guard would
    // pass no matter what was added.
    $found = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        foreach ([
            'ObservabilityProviderInterface',
            'APMManager',
            'TelemetryAdapter',
            'MonitoringBus',
            'VendorAgnosticTracer',
        ] as $forbidden) {
            if (str_contains($file->getFilename(), $forbidden)) {
                $found[] = $file->getFilename();
            }
        }
    }

    expect($found)->toBe([]);

    // The privacy class talks to Nightwatch's records directly, and knows
    // nothing about Sentry.
    $privacy = phpSourceWithoutComments('app/Support/Observability/NightwatchPrivacy.php');
    expect($privacy)->not->toContain('Sentry');
});
