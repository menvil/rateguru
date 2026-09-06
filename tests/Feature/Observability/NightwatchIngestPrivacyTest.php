<?php

use App\Models\User;
use Illuminate\Support\Facades\Context;
use Laravel\Nightwatch\Events\IngestingEvents;
use Laravel\Nightwatch\Facades\Nightwatch;
use Tests\TestCase;

/**
 * the Nightwatch evaluation: what Nightwatch would actually put on the wire.
 *
 * The other Nightwatch tests assert RateGuru's configuration and call its
 * redaction callbacks directly. These go one level further and read the
 * serialized records themselves, through the package's own public
 * `IngestingEvents` event — dispatched with the exact payloads immediately
 * before they are written to the agent socket. The recorder returns `false`,
 * which halts that write: no socket is opened, no agent is needed, and no
 * account quota is consumed.
 *
 * This is what makes the SQL claim a proof rather than a reading of the
 * package's source: the sentinel values below are looked for in the bytes
 * Nightwatch was about to send.
 */
beforeAll(function (): void {
    // Nightwatch decides in `register()` whether to install its hooks at all,
    // so config() from inside a test is far too late, and an environment
    // variable would be re-applied from .env on the next application refresh
    // (see the comment on TestCase::$bootConfiguration).
    TestCase::$bootConfiguration = [
        'nightwatch.enabled' => true,
        // Never transmitted anywhere: the recorder halts ingestion, and the
        // package only ever sends a hash of this to its own agent.
        'nightwatch.token' => 'phase-6b-test-token',
        'nightwatch.deployment' => 'v1.2.3-20260101-000000-abc1234',
        'nightwatch.sampling.requests' => 1.0,
        'nightwatch.sampling.commands' => 1.0,
        'nightwatch.sampling.exceptions' => 1.0,
        'deployment.target' => 'staging-main',
        'deployment.release' => 'v1.2.3-20260101-000000-abc1234',
        'deployment.commit' => 'abc1234',
    ];
});

afterAll(function (): void {
    TestCase::$bootConfiguration = [];
});

const NW_SQL_SENTINEL = 'nightwatch-secret@example.invalid';
const NW_TEXT_SENTINEL = 'NW_PRIVATE_SENTINEL_123';

it('sends parameterized SQL and never a binding value', function () {
    $recorder = captureNightwatchIngest();

    // Two sentinels, two shapes: an address that looks like PII, and an opaque
    // token that could not appear by coincidence.
    User::query()->where('email', NW_SQL_SENTINEL)->first();
    User::query()->where('name', NW_TEXT_SENTINEL)->get();

    Nightwatch::digest();

    $queries = $recorder->ofType('query');

    expect($queries)->not->toBeEmpty('Nightwatch produced no query records to inspect');

    // The hard acceptance criterion: neither sentinel appears anywhere in the
    // captured payload — not in the SQL, not in a group hash preview, nowhere.
    expect($recorder->encoded())
        ->not->toContain(NW_SQL_SENTINEL)
        ->not->toContain(NW_TEXT_SENTINEL);

    // And the SQL that is sent is still useful: it is the statement, with
    // placeholders where the values were.
    $statements = array_column($queries, 'sql');
    $selects = array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'from "users"') || str_contains($sql, 'from `users`') || str_contains($sql, 'from "users"')));

    expect($selects)->not->toBeEmpty('no users query was captured');
    expect(implode("\n", $selects))->toContain('?');
});

it('carries the canonical deployment identity on every record', function () {
    $recorder = captureNightwatchIngest();

    User::query()->count();

    Nightwatch::digest();

    expect($recorder->records)->not->toBeEmpty();

    foreach ($recorder->records as $record) {
        // Nightwatch's native `deploy` field, populated from the artifact's
        // release.json — the same string Sentry reports as its release.
        expect($record['deploy'] ?? null)->toBe('v1.2.3-20260101-000000-abc1234');
    }
});

it('publishes the deployment facts on Laravel Context, which Nightwatch reads natively', function () {
    // Nightwatch serializes Context::all() onto its execution records, so
    // Context is the carrier — RateGuru does not invent a second one.
    expect(Context::all())->toMatchArray([
        'app' => 'RateGuru',
        'deployment_target' => 'staging-main',
        'release' => 'v1.2.3-20260101-000000-abc1234',
        'commit' => 'abc1234',
    ]);

    expect(Context::get('environment'))->toBe('testing');
});

it('puts no user identity beyond the ID on a record', function () {
    $user = User::factory()->create([
        'name' => 'Sentinel Person',
        'email' => NW_SQL_SENTINEL,
    ]);

    $this->actingAs($user);

    $recorder = captureNightwatchIngest();

    User::query()->count();

    Nightwatch::digest();

    expect($recorder->records)->not->toBeEmpty();
    expect($recorder->encoded())
        ->not->toContain('Sentinel Person')
        ->not->toContain(NW_SQL_SENTINEL);

    // The ID is present, and is all that is present. Read from the wire form,
    // because the user field is deferred behind a LazyValue until encoding.
    $withUser = array_values(array_filter(
        $recorder->wire(),
        static fn (array $record): bool => ($record['user'] ?? '') !== '',
    ));

    expect($withUser)->not->toBeEmpty();
    expect($withUser[0]['user'])->toBe((string) $user->id);
});

it('never opens a socket to reach the agent during the test suite', function () {
    // The recorder halts ingestion before Ingest::transmit() is reached. If
    // that ever stopped being true, a test run would try to connect to
    // 127.0.0.1:2407 and stall on the connect timeout instead of failing
    // loudly — so the halt is asserted directly.
    $recorder = captureNightwatchIngest();

    User::query()->count();

    expect($recorder(new IngestingEvents([])))->toBeFalse();

    Nightwatch::digest();
});
