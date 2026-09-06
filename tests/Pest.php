<?php

use App\Enums\MediaResizeMode;
use App\Enums\MediaVariantName;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Services\Media\MediaVariantSpecification;
use App\Services\Media\NormalizedImage;
use App\Support\Import\Dns\HostResolver;
use App\Support\Import\ImportFetchPolicy;
use App\Support\Import\ImportHttpTransport;
use App\Support\Import\ImportTransportResponse;
use App\Support\Import\ResolvedImportTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Nightwatch\Events\IngestingEvents as NightwatchIngestingEvents;
use Sentry\ClientBuilder as SentryClientBuilder;
use Sentry\Event as SentryEvent;
use Sentry\EventType as SentryEventType;
use Sentry\Laravel\Integration as SentryLaravelIntegration;
use Sentry\State\HubInterface as SentryHubInterface;
use Sentry\Transport\Result as SentryTransportResult;
use Sentry\Transport\ResultStatus as SentryResultStatus;
use Sentry\Transport\TransportInterface as SentryTransportInterface;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * A HostResolver test double that never touches real DNS — shared by every
 * Import test file that needs UrlImportValidator to resolve a hostname to a
 * specific IP without depending on that hostname actually existing on the
 * public internet. See the block comment above the image-marker helpers for
 * why a bare function/class needed by more than one test file has to live
 * here rather than in any single one of them (Pest's --parallel runner
 * assigns whole files to separate workers).
 */
final class FakeHostResolver implements HostResolver
{
    /**
     * @param  array<string, list<string>>  $hostToIps
     */
    public function __construct(private readonly array $hostToIps) {}

    public function resolve(string $host): array
    {
        return $this->hostToIps[$host] ?? [];
    }
}

/**
 * Binds a FakeHostResolver so UrlImportValidator (and everything built on
 * it — SafeImportHttpClient, the import adapters/actions/Livewire
 * components) never performs a real DNS lookup in tests. Defaults cover the
 * handful of hostnames the wider Import test suite fetches through
 * Http::fake(); pass $extraHostToIps to add or override entries for a
 * specific test.
 *
 * @param  array<string, list<string>>  $extraHostToIps
 */
function bindFakeHostResolver(array $extraHostToIps = []): void
{
    $defaults = [
        'example.com' => ['93.184.216.34'],
        'cdn.example.com' => ['93.184.216.35'],
        'www.instagram.com' => ['157.240.2.174'],
    ];

    app()->instance(HostResolver::class, new FakeHostResolver($extraHostToIps + $defaults));
}

/**
 * An ImportHttpTransport test double that returns a scripted sequence of
 * responses (one per call to get()) and records every ResolvedImportTarget
 * it was actually invoked with — shared by every SafeImportHttpClient-level
 * test that needs precise, per-hop control over what the "network" returns
 * without going through Http::fake() (which bypasses PinnedImportHttpTransport
 * entirely and can't simulate hop-by-hop transport behavior). A scripted
 * entry may be an ImportTransportResponse, or a Closure(ResolvedImportTarget,
 * ImportFetchPolicy): ImportTransportResponse for a hop that needs to throw
 * (e.g. simulating a connect timeout on one specific hop).
 */
final class ScriptedImportHttpTransport implements ImportHttpTransport
{
    /** @var list<ResolvedImportTarget> */
    public array $calls = [];

    /**
     * @param  list<ImportTransportResponse|Closure>  $responses
     */
    public function __construct(private array $responses) {}

    public function get(ResolvedImportTarget $target, ImportFetchPolicy $policy): ImportTransportResponse
    {
        $this->calls[] = $target;

        $next = array_shift($this->responses);

        if ($next === null) {
            throw new RuntimeException('ScriptedImportHttpTransport: no more scripted responses.');
        }

        if ($next instanceof Closure) {
            return $next($target, $policy);
        }

        return $next;
    }
}

/**
 * The single committed source of truth for which infrastructure CLIs must
 * stay executable — also read (independently, at runtime) by both
 * deploy-staging.yml and release.yml, so the allowlist enforced by
 * InfrastructureScriptExecutableModesTest, DeployStagingWorkflowTest and
 * ProductionReleaseWorkflowTest can never drift from what the artifact-build
 * workflows actually verify.
 *
 * @return list<string>
 */
function requiredCliManifestNames(): array
{
    $manifestPath = base_path('infrastructure/config/required-clis.txt');
    $contents = file_get_contents($manifestPath);

    if ($contents === false) {
        throw new RuntimeException("could not read the required-CLI manifest: {$manifestPath}");
    }

    $lines = preg_split('/\R/', $contents);

    return array_values(array_filter(array_map('trim', $lines), fn (string $line): bool => $line !== ''));
}

/*
|--------------------------------------------------------------------------
| Scope guards: what a change added, and what it deliberately did not
|--------------------------------------------------------------------------
|
| A scope guard answers two questions about one body of work: does the end
| state look the way it should, and did this branch stay inside its own
| boundary. The second half needs a diff, so these helpers resolve the
| revision a branch is measured against and read files as of it.
|
| They live here, once, because every guard needs the identical answer.
| Copies of them drifted apart across three separate guard files before this.
*/

/**
 * Every operational file a rejected architecture could sneak back into.
 *
 * @return list<string>
 */
function operationalFiles(): array
{
    $configFiles = [];

    $tree = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('infrastructure/config'), FilesystemIterator::SKIP_DOTS),
    );

    foreach ($tree as $entry) {
        if ($entry->isFile()) {
            $configFiles[] = $entry->getPathname();
        }
    }

    return array_values(array_filter(array_merge(
        glob(base_path('.github/workflows/*.yml')) ?: [],
        glob(base_path('.github/actions/*/action.yml')) ?: [],
        glob(base_path('infrastructure/scripts/*')) ?: [],
        $configFiles,
    ), 'is_file'));
}

/**
 * The revision this branch is measured against: the pull request's own base
 * commit in CI, `origin/develop` locally, or null when neither is available.
 */
function branchBaseRevision(): ?string
{
    $baseSha = getenv('BASE_SHA');

    if (is_string($baseSha) && $baseSha !== '' && gitSucceeds(['cat-file', '-e', $baseSha.'^{commit}'])) {
        return $baseSha;
    }

    return gitSucceeds(['rev-parse', '--verify', 'origin/develop']) ? 'origin/develop' : null;
}

/**
 * @param  list<string>  $arguments
 */
function gitSucceeds(array $arguments): bool
{
    // Every argument is escaped individually: BASE_SHA is an environment
    // value and this runs through a shell.
    $command = 'cd '.escapeshellarg(base_path()).' && git '
        .implode(' ', array_map('escapeshellarg', $arguments))
        .' >/dev/null 2>&1; echo $?';

    return trim((string) shell_exec($command)) === '0';
}

/** @return list<string> */
function branchChangedFiles(): array
{
    $baseline = trim((string) shell_exec(
        'cd '.escapeshellarg(base_path()).' && git diff --name-only '
            .escapeshellarg((string) branchBaseRevision()).' HEAD 2>/dev/null'
    ));

    return $baseline === '' ? [] : explode("\n", $baseline);
}

/**
 * The changed files whose CODE actually changed — a file this branch touched
 * only in comments is not in the list.
 *
 * The "these files stay untouched" guards exist to protect behaviour: a script
 * accepted on real hardware must not be quietly edited by a later slice. What
 * they are not for is freezing prose. A repository-wide comment cleanup
 * legitimately rewrites a line in `backup` or `common` without changing a
 * single thing either one does, and a guard that fires on that is measuring
 * bytes where it means to measure behaviour — which trains people to widen it,
 * and then it stops catching the real edit too.
 *
 * @return list<string>
 */
function branchChangedCodeFiles(): array
{
    return array_values(array_filter(
        branchChangedFiles(),
        static function (string $path): bool {
            $diff = branchFileDiff($path);
            $changed = array_merge($diff['added'], $diff['removed']);

            return $changed !== [] && sourceCodeLines($changed) !== [];
        },
    ));
}

/**
 * The lines that are not blank and not a whole-line comment.
 *
 * Deliberately only `#` and `//`. A leading `*` is a comment continuation in
 * PHP but a `case` arm in shell, and every file these guards protect is a
 * shell script or a `#`-commented config — so treating `*` as prose here would
 * hide exactly the kind of change they exist to catch.
 *
 * @param  list<string>  $lines
 * @return list<string>
 */
function sourceCodeLines(array $lines): array
{
    return array_values(array_filter(
        array_map('trim', $lines),
        static fn (string $line): bool => $line !== ''
            && ! str_starts_with($line, '#')
            && ! str_starts_with($line, '//'),
    ));
}

/**
 * The lines this branch adds to, and removes from, one file.
 *
 * `-U0` so the hunks carry no context: every `+` really is an addition. This is
 * what lets a scope guard say "exactly this much changed, and nothing else"
 * about a file it deliberately touches, instead of the blunter "this file did
 * not change at all".
 *
 * @return array{added: list<string>, removed: list<string>}
 */
function branchFileDiff(string $path): array
{
    $diff = (string) shell_exec(
        'cd '.escapeshellarg(base_path()).' && git diff -U0 '
            .escapeshellarg((string) branchBaseRevision()).' HEAD -- '.escapeshellarg($path).' 2>/dev/null'
    );

    $added = [];
    $removed = [];

    foreach (explode("\n", $diff) as $line) {
        if (str_starts_with($line, '+++') || str_starts_with($line, '---')) {
            continue;
        }

        if (str_starts_with($line, '+')) {
            $added[] = mb_substr($line, 1);
        } elseif (str_starts_with($line, '-')) {
            $removed[] = mb_substr($line, 1);
        }
    }

    return ['added' => $added, 'removed' => $removed];
}

/**
 * One file as this branch has it committed.
 *
 * These guards describe the branch, not the working tree — that is what a diff
 * against the base measures, and what CI reviews. Reading the file from HEAD
 * too keeps both halves of an assertion talking about the same thing, instead
 * of comparing a committed diff against uncommitted edits.
 */
function committedFile(string $path): string
{
    return (string) shell_exec(
        'cd '.escapeshellarg(base_path()).' && git show HEAD:'.escapeshellarg($path).' 2>/dev/null'
    );
}

/**
 * One file as the base revision has it, so a diff-bounded guard can say where a
 * REMOVED line used to live, not only where an added one landed.
 */
function baseRevisionFile(string $path): string
{
    return (string) shell_exec(
        'cd '.escapeshellarg(base_path()).' && git show '
            .escapeshellarg((string) branchBaseRevision().':'.$path).' 2>/dev/null'
    );
}

/**
 * The body of one shell function, so an ordering assertion is about what
 * actually runs rather than about where things happen to be declared. Every one
 * of these scripts defines its helpers above its pipeline, so a whole-file
 * position comparison would routinely say the opposite of the truth.
 */
function shellFunctionBody(string $source, string $name): string
{
    $start = mb_strpos($source, "\n{$name}() {\n");

    expect($start)->not->toBeFalse("{$name} is not defined");

    $end = mb_strpos($source, "\n}\n", $start);

    expect($end)->not->toBeFalse("{$name} has no closing brace");

    return mb_substr($source, $start, $end - $start);
}

/**
 * One source file with its comment and blank lines removed, so a
 * forbidden-construct scan reasons about code rather than about prose.
 *
 * Every guard that greps a script, a wrapper, an action or a workflow for
 * something it must never contain needs this, and for one specific reason: the
 * files most likely to be scanned for `eval` / `bash -c` / a legacy flag are
 * exactly the files that legitimately DOCUMENT not using it. A naive whole-file
 * grep turns "no eval, no bash -c, no string-built command" in a header comment
 * into a violation — the real incident
 * install-target-perimeter's own verify_wrapper_static_contract was hardened
 * against, and one that has since been rediscovered in four separate test
 * files.
 *
 * `#` is the comment marker in every format this is used on: shell scripts,
 * YAML actions and YAML workflows alike.
 */
function executableSourceLines(string $source): string
{
    return implode("\n", array_filter(
        preg_split('/\R/', $source),
        static fn (string $line): bool => $line !== '' && ! str_starts_with(ltrim($line), '#'),
    ));
}

/**
 * The sourced libraries under infrastructure/scripts: never executed
 * directly, so they must stay non-executable. Kept here beside
 * requiredCliManifestNames() so the CLI allowlist and the library exemption
 * have one definition between every test that reasons about either.
 *
 * @return list<string>
 */
function sourcedLibraryNames(): array
{
    return ['common', 'restore-common'];
}

/**
 * A correctly normalized release-tree fixture: every manifested CLI present
 * and executable, every sourced library present, readable and non-executable,
 * the manifest itself copied verbatim from the real committed one. Shared by
 * InfrastructureScriptExecutableModesTest (testing verify-required-clis and
 * deploy directly) and DeployStagingWorkflowTest/ProductionReleaseWorkflowTest
 * (testing that each workflow correctly delegates to it).
 */
function releaseCliFixture(array $cliNames): string
{
    $root = sys_get_temp_dir().'/release-cli-exec-check-'.uniqid('', true);

    mkdir($root.'/infrastructure/scripts', 0o755, true);
    mkdir($root.'/infrastructure/config', 0o755, true);
    copy(base_path('infrastructure/config/required-clis.txt'), $root.'/infrastructure/config/required-clis.txt');

    foreach ($cliNames as $name) {
        file_put_contents($root.'/infrastructure/scripts/'.$name, "#!/usr/bin/env bash\n");
        chmod($root.'/infrastructure/scripts/'.$name, 0o755);
    }

    foreach (sourcedLibraryNames() as $library) {
        file_put_contents($root.'/infrastructure/scripts/'.$library, "#!/usr/bin/env bash\n");
        chmod($root.'/infrastructure/scripts/'.$library, 0o644);
    }

    return $root;
}

/**
 * An in-memory Sentry transport: every event the SDK decides to send lands in
 * $events instead of on the network. Shared by every Sentry test file (see the
 * block comment above the image-marker helpers for why a helper used by more
 * than one test file has to live here rather than in any single one of them).
 */
final class RecordingSentryTransport implements SentryTransportInterface
{
    /** @var list<SentryEvent> */
    public array $events = [];

    public function send(SentryEvent $event): SentryTransportResult
    {
        $this->events[] = $event;

        return new SentryTransportResult(SentryResultStatus::success(), $event);
    }

    public function close(?int $timeout = null): SentryTransportResult
    {
        return new SentryTransportResult(SentryResultStatus::success());
    }

    /** @return list<SentryEvent> */
    public function errorEvents(): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (SentryEvent $event): bool => $event->getType() === SentryEventType::event(),
        ));
    }
}

/**
 * Rebuilds the Sentry client the application is already using, from the
 * application's own config/sentry.php, with only the transport replaced — so
 * tests exercise our real options (release, environment, sampling, PII, SQL
 * bindings) and our real scope, and never open a socket to sentry.io.
 *
 * The hub instance itself is kept and only re-bound to the new client, so the
 * scope App\Providers\ObservabilityServiceProvider configured at boot (the
 * deployment_target/commit tags) survives.
 *
 * @param  array<string, mixed>  $config  config overrides applied before the client is built
 */
function fakeSentryTransport(array $config = []): RecordingSentryTransport
{
    config(array_merge(['sentry.dsn' => 'https://recorder@sentry.invalid/1'], $config));

    $transport = new RecordingSentryTransport;

    /** @var SentryClientBuilder $builder */
    $builder = app(SentryClientBuilder::class);
    $builder->setTransport($transport);

    // The PHP SDK's default integrations install process-global error and
    // exception handlers. The Laravel service provider strips exactly those in
    // production; here we build without them entirely and add back only the
    // Laravel integration that shapes events, so a test client can never take
    // over PHPUnit's own handlers.
    $builder->getOptions()->setDefaultIntegrations(false);
    $builder->getOptions()->setIntegrations([new SentryLaravelIntegration]);

    app(SentryHubInterface::class)->bindClient($builder->getClient());

    return $transport;
}

/**
 * Captures the records Nightwatch is about to transmit, and stops it.
 *
 * `IngestingEvents` is the package's own public event, dispatched through
 * `Event::until()` with the exact serialized payloads immediately before they
 * are written to the agent socket — and a listener returning `false` halts
 * that write. So this both reads what would really be sent (not a
 * reconstruction of it) and guarantees a test never opens a socket, needs an
 * agent, or consumes account quota.
 *
 * Shared by every Nightwatch ingest test (see the block comment above the
 * image-marker helpers for why a helper used by more than one test file has to
 * live here rather than in any single one of them).
 */
final class RecordingNightwatchIngest
{
    /** @var list<array<string, mixed>> */
    public array $records = [];

    public function __invoke(NightwatchIngestingEvents $event): bool
    {
        foreach ($event->records as $record) {
            $this->records[] = $record;
        }

        return false;
    }

    /**
     * The records exactly as they would go on the wire.
     *
     * Nightwatch defers some fields (the user ID, for one) behind a
     * JsonSerializable LazyValue that only resolves during encoding, so the
     * raw array is not yet what would be sent. Round-tripping through
     * json_encode reproduces `Payload::json`'s own step and is therefore the
     * only faithful thing to assert against.
     *
     * @return list<array<string, mixed>>
     */
    public function wire(): array
    {
        return json_decode($this->encoded(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string, mixed>> */
    public function ofType(string $type): array
    {
        return array_values(array_filter(
            $this->wire(),
            static fn (array $record): bool => ($record['t'] ?? null) === $type,
        ));
    }

    /** The whole capture as one string, for "this value appears nowhere" assertions. */
    public function encoded(): string
    {
        return (string) json_encode(
            $this->records,
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}

/**
 * Registers the recorder above and returns it. Nightwatch flushes its buffer
 * on `Nightwatch::digest()`, which is the public way to make a test's events
 * arrive at a deterministic point.
 */
function captureNightwatchIngest(): RecordingNightwatchIngest
{
    $recorder = new RecordingNightwatchIngest;

    Event::listen(NightwatchIngestingEvents::class, $recorder);

    return $recorder;
}

/**
 * The executable source of a PHP file with every comment removed — shared by
 * the observability guards that assert a file never shells out to Git and
 * never special-cases a deployment target. Those files document both rules at
 * length, and prose about a rule must never be mistaken for a breach of it.
 * (See the block comment above the image-marker helpers for why a helper used
 * by more than one test file has to live here.)
 */
function phpSourceWithoutComments(string $relativePath): string
{
    return collect(token_get_all(File::get(base_path($relativePath))))
        ->reject(fn (array|string $token): bool => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
        ->map(fn (array|string $token): string => is_array($token) ? $token[1] : $token)
        ->implode('');
}

/**
 * A minimal NormalizedImage fixture shared by every Media test that needs
 * one to hand to MediaStorage::storeNormalized() without running a real
 * image through GdImageIngestor first.
 */
function normalizedFixture(string $bytes = 'normalized-jpeg-bytes'): NormalizedImage
{
    return new NormalizedImage(
        bytes: $bytes,
        mimeType: 'image/jpeg',
        extension: 'jpg',
        byteSize: strlen($bytes),
        width: 800,
        height: 600,
    );
}

/**
 * Create two configurable Rating Groups used by feed filter tests.
 */
function seedFeedFilterGroups(): void
{
    $type = RatingGroup::factory()->create(['key' => 'type', 'sort_order' => 10]);
    RatingOption::factory()->create(['rating_group_id' => $type->id, 'key' => 'type_a', 'sort_order' => 10]);
    RatingOption::factory()->create(['rating_group_id' => $type->id, 'key' => 'type_b', 'sort_order' => 20]);

    $attribute = RatingGroup::factory()->create(['key' => 'attribute', 'sort_order' => 20]);
    RatingOption::factory()->create(['rating_group_id' => $attribute->id, 'key' => 'attribute_a', 'sort_order' => 10]);
    RatingOption::factory()->create(['rating_group_id' => $attribute->id, 'key' => 'attribute_b', 'sort_order' => 20]);
    RatingOption::factory()->create(['rating_group_id' => $attribute->id, 'key' => 'attribute_c', 'sort_order' => 30]);
    RatingOption::factory()->create(['rating_group_id' => $attribute->id, 'key' => 'attribute_d', 'sort_order' => 40]);
    RatingOption::factory()->create(['rating_group_id' => $attribute->id, 'key' => 'attribute_other', 'sort_order' => 50]);
}

/**
 * The following image-marker helpers are shared across multiple Media test
 * files (GdImageIngestorTest, GdImageVariantProcessorTest, and everything
 * that generates fixture bytes for MediaVariantGenerator/Writer/Job/Command
 * tests). They live here — not as bare functions inside any one of those
 * files — because Pest's parallel runner (`--parallel`, used in CI) assigns
 * whole test files to separate worker processes; a bare function declared in
 * file A is not visible to file B when they land in different workers, which
 * only surfaces as an "undefined function" failure under --parallel, not
 * under a normal sequential run. Everything in this file, by contrast, is
 * part of Pest's own bootstrap and is loaded by every worker.
 */

/**
 * Distinct marker colors in each corner: TL=red, TR=green, BL=blue,
 * BR=white — lets orientation tests assert on physical pixel positions, not
 * just reported dimensions.
 */
function makeMarkerImage(int $width, int $height): GdImage
{
    $im = imagecreatetruecolor($width, $height);
    imagefill($im, 0, 0, imagecolorallocate($im, 0, 0, 0));
    imagesetpixel($im, 0, 0, imagecolorallocate($im, 255, 0, 0));
    imagesetpixel($im, $width - 1, 0, imagecolorallocate($im, 0, 255, 0));
    imagesetpixel($im, 0, $height - 1, imagecolorallocate($im, 0, 0, 255));
    imagesetpixel($im, $width - 1, $height - 1, imagecolorallocate($im, 255, 255, 255));

    return $im;
}

function markerCornerColor(GdImage $im, int $x, int $y): string
{
    $rgb = imagecolorsforindex($im, imagecolorat($im, $x, $y));

    return match (true) {
        $rgb['red'] > 200 && $rgb['green'] < 50 && $rgb['blue'] < 50 => 'RED',
        $rgb['green'] > 200 && $rgb['red'] < 50 && $rgb['blue'] < 50 => 'GREEN',
        $rgb['blue'] > 200 && $rgb['red'] < 50 && $rgb['green'] < 50 => 'BLUE',
        $rgb['red'] > 200 && $rgb['green'] > 200 && $rgb['blue'] > 200 => 'WHITE',
        default => 'OTHER',
    };
}

/** @return array{tl: string, tr: string, bl: string, br: string, width: int, height: int} */
function markerCorners(string $bytes): array
{
    $im = imagecreatefromstring($bytes);
    $w = imagesx($im);
    $h = imagesy($im);

    return [
        'tl' => markerCornerColor($im, 0, 0),
        'tr' => markerCornerColor($im, $w - 1, 0),
        'bl' => markerCornerColor($im, 0, $h - 1),
        'br' => markerCornerColor($im, $w - 1, $h - 1),
        'width' => $w,
        'height' => $h,
    ];
}

function jpegMarkerBytes(int $width = 20, int $height = 10, int $quality = 90): string
{
    $im = makeMarkerImage($width, $height);
    ob_start();
    imagejpeg($im, null, $quality);

    return ob_get_clean();
}

/** @param 'png'|'webp' $format */
function markerBytesWithAlpha(string $format, int $width = 20, int $height = 10): string
{
    $im = imagecreatetruecolor($width, $height);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagesetpixel($im, 0, 0, imagecolorallocatealpha($im, 255, 0, 0, 0));
    imagesetpixel($im, $width - 1, 0, imagecolorallocatealpha($im, 0, 255, 0, 0));
    imagesetpixel($im, 0, $height - 1, imagecolorallocatealpha($im, 0, 0, 255, 0));
    ob_start();

    match ($format) {
        'png' => imagepng($im),
        'webp' => imagewebp($im),
    };

    return ob_get_clean();
}

/**
 * Solid corner BLOCKS (not single pixels, unlike makeMarkerImage() above) —
 * a single marker pixel gets diluted below markerCornerColor()'s detection
 * threshold once imagecopyresampled()'s interpolation and JPEG's lossy
 * compression both apply, which never happens to makeMarkerImage()'s own
 * pixel-preserving flip/rotate use in GdImageIngestorTest. Block size scales
 * with the image so it survives even an aggressive downscale (e.g.
 * 4000x500 -> 640x80, ~0.16x, exercised in GdImageVariantProcessorTest).
 */
function containResizeMarkerBytes(int $width, int $height): string
{
    return solidCornerBlockJpeg($width, $height, 0, 0, $width, $height);
}

/**
 * Places the four marker color blocks at the region that will become a
 * CoverSquare crop's four corners, rather than the source image's own
 * absolute corners — lets a crop+resize be verified with the same
 * markerCorners() reader above.
 */
function coverSquareCropMarkerBytes(int $width, int $height): string
{
    $cropSize = min($width, $height);
    $cropX = intdiv($width - $cropSize, 2);
    $cropY = intdiv($height - $cropSize, 2);

    return solidCornerBlockJpeg($width, $height, $cropX, $cropY, $cropSize, $cropSize);
}

/**
 * Generalizes coverSquareCropMarkerBytes() to an arbitrary (non-square)
 * target aspect ratio — places the four marker color blocks at the region
 * that will become a Cover crop's four corners for the given targetWidth /
 * targetHeight ratio, mirroring GdImageVariantProcessor::planCover()'s own
 * math so a test failure here means the two have diverged.
 */
function coverCropMarkerBytes(int $width, int $height, int $targetWidth, int $targetHeight): string
{
    $targetRatio = $targetWidth / $targetHeight;
    $srcRatio = $width / $height;

    if ($srcRatio > $targetRatio) {
        $cropHeight = $height;
        $cropWidth = (int) round($height * $targetRatio);
    } else {
        $cropWidth = $width;
        $cropHeight = (int) round($width / $targetRatio);
    }

    $cropX = intdiv($width - $cropWidth, 2);
    $cropY = intdiv($height - $cropHeight, 2);

    return solidCornerBlockJpeg($width, $height, $cropX, $cropY, $cropWidth, $cropHeight);
}

function solidCornerBlockJpeg(int $width, int $height, int $regionX, int $regionY, int $regionW, int $regionH): string
{
    $blockW = max(4, (int) round($regionW * 0.15));
    $blockH = max(4, (int) round($regionH * 0.15));

    $im = imagecreatetruecolor($width, $height);
    imagefill($im, 0, 0, imagecolorallocate($im, 0, 0, 0));

    $red = imagecolorallocate($im, 255, 0, 0);
    $green = imagecolorallocate($im, 0, 255, 0);
    $blue = imagecolorallocate($im, 0, 0, 255);
    $white = imagecolorallocate($im, 255, 255, 255);

    imagefilledrectangle($im, $regionX, $regionY, $regionX + $blockW - 1, $regionY + $blockH - 1, $red);
    imagefilledrectangle($im, $regionX + $regionW - $blockW, $regionY, $regionX + $regionW - 1, $regionY + $blockH - 1, $green);
    imagefilledrectangle($im, $regionX, $regionY + $regionH - $blockH, $regionX + $blockW - 1, $regionY + $regionH - 1, $blue);
    imagefilledrectangle($im, $regionX + $regionW - $blockW, $regionY + $regionH - $blockH, $regionX + $regionW - 1, $regionY + $regionH - 1, $white);

    ob_start();
    imagejpeg($im, null, 90);

    return ob_get_clean();
}

function variantSpec(
    MediaVariantName $name = MediaVariantName::PostFeed640,
    int $maxWidth = 640,
    int $maxHeight = 1280,
    MediaResizeMode $mode = MediaResizeMode::Contain,
    int $quality = 82,
    ?string $outputMimeType = null,
): MediaVariantSpecification {
    return new MediaVariantSpecification($name, $maxWidth, $maxHeight, $mode, $quality, $outputMimeType);
}

/**
 * A soft-deleted MediaAsset, with a master file and two variant files all
 * physically present, whose deleted_at is 19 days in the past — past the
 * 7-day default purge grace period and safely purgeable. Shared by
 * MediaLifecycleServicePurgeTest and MediaPurgeCommandTest (see the
 * block comment above image-marker helpers for why a shared bare function
 * used by more than one test file must live here, not in either file).
 * Requires the caller to have already called Storage::fake('public').
 *
 * Leaves Carbon test time frozen at 2026-01-20 12:00:00 when it returns —
 * callers must reset it themselves (e.g. `afterEach(fn () =>
 * Carbon::setTestNow())`) rather than assuming "now" is real wall-clock
 * time for the rest of the test.
 */
function createPurgeableAsset(): MediaAsset
{
    Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));
    $asset = MediaAsset::factory()->postImage()->create(['path' => 'posts/master.jpg']);
    Storage::disk('public')->put($asset->path, 'master-bytes');

    foreach ([MediaVariantName::PostFeed640, MediaVariantName::PostDetail1920] as $name) {
        $variant = MediaVariant::factory()->named($name)->create([
            'media_asset_id' => $asset->id,
            'disk' => 'public',
            'path' => "posts/master/{$name->value}.jpg",
        ]);
        Storage::disk('public')->put($variant->path, "{$name->value}-bytes");
    }

    $asset->delete();
    Carbon::setTestNow(Carbon::parse('2026-01-20 12:00:00')); // 19 days later, past the 7-day default grace

    return $asset->fresh();
}

/**
 * Builds a failed_jobs row in Laravel's real payload shape — the "command"
 * field is genuine serialize() output (never unserialize()'d by anything
 * under test), so these fixtures exercise FailedMediaJobReader's actual
 * regex-based extraction the same way a real queue worker failure would
 * populate the table. Shared by FailedMediaJobReaderTest and
 * MediaAuditServiceTest (see the block comment above image-marker helpers
 * for why a shared bare function used by more than one test file must live
 * here, not in either file).
 */
function insertFailedJobRow(string $jobClass, ?string $command, string $exception = "RuntimeException: boom\n#0 somewhere", ?string $failedAt = null): string
{
    $uuid = (string) Str::uuid();

    $payload = [
        'uuid' => $uuid,
        'displayName' => $jobClass,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'maxTries' => 3,
        'data' => [
            'commandName' => $jobClass,
            'command' => $command,
        ],
    ];

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'sync',
        'queue' => 'default',
        'payload' => json_encode($payload),
        'exception' => $exception,
        'failed_at' => $failedAt ?? now(),
    ]);

    return $uuid;
}

/**
 * Shared by MediaAspectRatioBrowserTest and ResponsiveMediaBrowserTest — see
 * the block comment above for why cross-file browser-test helpers live here
 * rather than as a bare function in either file.
 *
 * Several contexts (drawer, fullscreen, non-first feed cards) render
 * loading="lazy". Reading naturalWidth/naturalHeight before the browser has
 * actually decoded the image would yield 0/0 → a NaN ratio that fails the
 * assertion regardless of fit, nondeterministically depending on load
 * timing (worse under CI's --parallel, where workers compete for CPU).
 * `complete` can turn true slightly before pixel decoding has actually
 * finished, so each poll also awaits image.decode() itself (Pest's
 * $page->script() awaits a returned Promise, same as Playwright's
 * page.evaluate) rather than trusting the synchronous flags alone.
 */
function waitForImageLoaded(mixed $page, string $selector, float $timeoutSeconds = 5.0): void
{
    $deadline = microtime(true) + $timeoutSeconds;

    while (microtime(true) < $deadline) {
        $loaded = $page->script(<<<JS
            (async () => {
                const img = document.querySelector('{$selector}');
                if (!img || !img.complete || img.naturalWidth === 0) {
                    return false;
                }
                try {
                    await img.decode();
                } catch (e) {
                    return false;
                }
                return true;
            })()
        JS);

        if ($loaded) {
            return;
        }

        usleep(100_000);
    }

    throw new RuntimeException("Image [{$selector}] did not finish loading within {$timeoutSeconds}s.");
}

/**
 * Compares the rendered <img> box ratio against the image's own natural
 * ratio. object-fit: cover would force the box toward the *container's*
 * ratio instead, so a mismatch here is exactly what would catch a
 * regression back to cropping.
 *
 * @return array{naturalWidth: int, naturalHeight: int, width: float, height: float, ratioDiff: float}
 */
function imageFitGeometry(mixed $page, string $selector): array
{
    waitForImageLoaded($page, $selector);

    $geometry = $page->script(<<<JS
        (() => {
            const img = document.querySelector('{$selector}');
            const rect = img.getBoundingClientRect();
            return {
                naturalWidth: img.naturalWidth,
                naturalHeight: img.naturalHeight,
                width: rect.width,
                height: rect.height,
            };
        })()
    JS);

    $naturalRatio = $geometry['naturalWidth'] / $geometry['naturalHeight'];
    $renderedRatio = $geometry['width'] / $geometry['height'];

    $geometry['ratioDiff'] = abs($naturalRatio - $renderedRatio);

    return $geometry;
}

/*
|--------------------------------------------------------------------------
| Restore Target Data — Restore Target Data harness
|--------------------------------------------------------------------------
|
| Shared by FetchBackupTest, VerifyBackupTest, RestoreDatabaseTest,
| RestoreStorageTest, RestoreTargetTest and RestoreServerPrimitivesScopeTest — five files that
| all need the same scratch target tree, the same parity registry, the same
| backup fixtures and the same self-contained stub host tooling. A helper used
| by more than one test file has to live here rather than in any single one of
| them (see the block comment above the image-marker helpers).
|
| Every test below executes the REAL shipped scripts under
| infrastructure/scripts — never a reimplementation of their logic — with each
| host dependency supplied through the gated RATEGURU_* test-override
| contract, exactly the way BackupTest/RestoreTest already do.
*/

/** The immutable release identity the fixture target is "serving". */
const FIXTURE_RELEASE = 'v1.4.0-20260101-000000-a81d7f2';

const FIXTURE_SOURCE_SHA = 'a81d7f2c3b4a5968778899aabbccddeeff001122';

/** A different, equally valid release/commit pair, for code-mismatch tests. */
const FIXTURE_OTHER_RELEASE = 'v1.5.0-20260202-000000-b92e8a3';

const FIXTURE_OTHER_SOURCE_SHA = 'b92e8a3d4c5a6a79889900bbccddeeff11223344';

function restoreScratchDir(): string
{
    $dir = sys_get_temp_dir().'/rateguru-restore-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/bin', '/pg'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

function removeScratchDir(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

function infraScript(string $name): string
{
    return base_path('infrastructure/scripts/'.$name);
}

/**
 * A copy of a shipped script with two — and only two — mechanical rewrites,
 * so the real pipeline runs end to end without being root:
 *
 *   * every root-only `install -o root -g root` uses this process's own
 *     uid/gid instead;
 *   * `require_root` becomes a no-op, but ONLY when RGTEST_BYPASS_ROOT=true is
 *     explicitly set in the environment.
 *
 * Every line of restore logic under test is byte-identical to what ships. The
 * production root gate itself is proven separately, against the unpatched
 * script, by the "requires root" test in each file.
 */
function patchedInfraScript(string $scratch, string $name): string
{
    $path = $scratch.'/patched-'.$name;

    if (file_exists($path)) {
        return $path;
    }

    $source = File::get(infraScript($name));
    $source = str_replace('-o root', '-o '.getmyuid(), $source);
    $source = str_replace('-g root', '-g '.getmygid(), $source);

    // Anchored on the LAST library the script sources, so this works for the
    // restore primitives (common + restore-common) and for a script that
    // sources common alone — require_root only exists once the library
    // defining it has been loaded.
    $source = preg_replace(
        '/^(source "\$\{[A-Z_]*COMMON_FILE\}"\n)(?![\s\S]*^source "\$\{[A-Z_]*COMMON_FILE\}"\n)/m',
        "$1\nif [[ \"\${RGTEST_BYPASS_ROOT:-false}\" == true ]]; then require_root() { :; }; fi\n",
        $source,
        1,
    );

    file_put_contents($path, $source);
    chmod($path, 0o755);

    return $path;
}

function writeExecutable(string $path, string $body): string
{
    file_put_contents($path, $body);
    chmod($path, 0o755);

    return $path;
}

/**
 * A host-global deployment.conf pointing PHP_BIN at the scratch php stub.
 * common validates and sources this file; it is never the installed default
 * path, so no root ownership is demanded of it.
 */
function deploymentConfFixture(string $scratch): string
{
    $path = $scratch.'/deployment.conf';

    file_put_contents($path, implode("\n", [
        "RELEASE_ID_REGEX='^v[0-9]+\\.[0-9]+\\.[0-9]+-[0-9]{8}-[0-9]{6}-[0-9a-f]{7,40}\$'",
        'PHP_BIN='.$scratch.'/bin/php',
        'PHP_FPM_SERVICE=php-noop-fpm',
        '',
    ]));

    return $path;
}

/**
 * A registry declaring one fully valid, lifecycle=active `parity-target`
 * whose paths all live inside the scratch tree, plus the patched `targets`
 * validator that accepts it (the shipped validator only allows staging-main
 * to be active).
 *
 * @return array{0: string, 1: string} [registryPath, targetsCliPath]
 */
function parityRegistryFixture(string $scratch, array $options = []): array
{
    $account = trim((string) shell_exec('id -un'));
    $group = trim((string) shell_exec('id -gn'));

    $patched = File::get(base_path('infrastructure/scripts/targets'));
    $patched = str_replace('ACTIVE_ALLOWLIST="staging-main"', 'ACTIVE_ALLOWLIST="parity-target"', $patched);
    $patched = str_replace('elif [[ "${application_root}" != /home/www/rateguru/* ]]; then', 'elif false; then', $patched);
    $patched = str_replace('elif [[ "${incoming}" != /home/* ]]; then', 'elif false; then', $patched);
    $patched = str_replace('if [[ "${code_group}" == "${runtime_group}" ]]; then', 'if false; then', $patched);
    $patched = str_replace('if [[ "${code_group}" == "${runtime_user}" ]]; then', 'if false; then', $patched);

    $targetsPath = writeExecutable($scratch.'/parity-targets', $patched);

    $registry = [
        'schema_version' => 1,
        'targets' => [
            'parity-target' => [
                'id' => 'parity-target',
                'lifecycle' => $options['lifecycle'] ?? 'active',
                'environment_class' => 'staging',
                'application_root' => $scratch.'/target',
                'runtime_user' => $account,
                'runtime_group' => $group,
                'deploy_user' => 'parity-deploy',
                'code_group' => $group,
                'incoming_artifacts' => $scratch.'/incoming',
                'release_retention' => 5,
                'database' => [
                    'name' => $options['database'] ?? 'parity_db',
                    'application_role' => $options['role'] ?? 'parity_app',
                ],
                'health' => ['url' => 'http://127.0.0.1/', 'host_header' => 'parity.internal'],
                'public_hostnames' => ['parity.example'],
                'backup' => [
                    'namespace' => $options['namespace'] ?? 'parity',
                    'local_retention_days' => 14,
                    'offsite_retention_days' => 30,
                    'minimum_retained_backups' => 2,
                ],
                'php_fpm' => ['pool' => 'parity-pool', 'socket' => '/run/php/parity.sock'],
                'supervisor' => ['program' => 'parity-queue', 'queue' => 'parity'],
                'scheduler' => ['name' => 'parity-scheduler'],
                'nginx' => ['site_name' => 'parity-site', 'internal_hostname' => 'parity.internal'],
                'environment_template' => 'infrastructure/templates/environment/staging.env.example',
            ],
            'planned-target' => [
                'id' => 'planned-target',
                'lifecycle' => 'planned',
                'environment_class' => 'production',
                'application_root' => $scratch.'/planned',
                // Distinct identities from parity-target: the registry
                // validator rejects two targets sharing a runtime user,
                // runtime group or code group. Nothing ever uses these — a
                // planned target is rejected before any identity is read.
                'runtime_user' => 'planned-runtime',
                'runtime_group' => 'planned-runtime',
                'deploy_user' => 'planned-deploy',
                'code_group' => 'planned-code',
                'incoming_artifacts' => $scratch.'/planned-incoming',
                'release_retention' => 5,
                'database' => ['name' => 'planned_db', 'application_role' => 'planned_app'],
                'health' => ['url' => 'http://127.0.0.1/', 'host_header' => 'planned.internal'],
                'public_hostnames' => ['planned.example'],
                'backup' => [
                    'namespace' => 'planned',
                    'local_retention_days' => 14,
                    'offsite_retention_days' => 30,
                    'minimum_retained_backups' => 2,
                ],
                'php_fpm' => ['pool' => 'planned-pool', 'socket' => '/run/php/planned.sock'],
                'supervisor' => ['program' => 'planned-queue', 'queue' => 'planned'],
                'scheduler' => ['name' => 'planned-scheduler'],
                'nginx' => ['site_name' => 'planned-site', 'internal_hostname' => 'planned.internal'],
                'environment_template' => 'infrastructure/templates/environment/tits-guru.env.example',
            ],
        ],
    ];

    $registryPath = $scratch.'/registry.json';
    file_put_contents($registryPath, json_encode($registry, JSON_PRETTY_PRINT));

    exec(escapeshellarg($targetsPath).' validate --file '.escapeshellarg($registryPath).' 2>&1', $out, $exit);
    expect($exit)->toBe(0, "parity registry fixture failed validation:\n".implode("\n", $out));

    return [$registryPath, $targetsPath];
}

/**
 * The scratch target tree a live restore acts on: an immutable release under
 * releases/, a current symlink, shared/.env, the shared storage layout deploy
 * itself creates, and the lock/deployment directories.
 */
function targetTreeFixture(string $scratch, array $options = []): string
{
    $root = $scratch.'/target';
    $release = $options['release'] ?? FIXTURE_RELEASE;
    $sourceSha = $options['source_sha'] ?? FIXTURE_SOURCE_SHA;

    mkdir($root.'/releases/'.$release, 0o755, true);
    mkdir($root.'/shared/storage/app/public', 0o755, true);
    mkdir($root.'/shared/storage/framework', 0o755, true);
    mkdir($root.'/locks', 0o755, true);
    mkdir($root.'/deployments', 0o755, true);
    mkdir($root.'/incoming', 0o755, true);

    file_put_contents(
        $root.'/releases/'.$release.'/release.json',
        json_encode(['project' => 'rateguru', 'release' => $release, 'source_sha' => $sourceSha], JSON_PRETTY_PRINT),
    );
    file_put_contents($root.'/releases/'.$release.'/artisan', "<?php\n");

    if (($options['current'] ?? true) === true) {
        symlink($root.'/releases/'.$release, $root.'/current');
    }

    file_put_contents($root.'/shared/.env', implode("\n", [
        'APP_ENV=staging',
        'DB_CONNECTION=pgsql',
        'DB_HOST=127.0.0.1',
        'DB_PORT=5432',
        'DB_DATABASE='.($options['database'] ?? 'parity_db'),
        'DB_USERNAME='.($options['role'] ?? 'parity_app'),
        'DB_PASSWORD=s3cr3t-not-logged',
        '',
    ]));

    file_put_contents($root.'/shared/storage/app/live-marker.txt', "live\n");
    file_put_contents($root.'/shared/storage/app/public/live-public.txt', "live public\n");

    return $root;
}

/**
 * A real, on-disk backup directory in exactly the shape
 * infrastructure/scripts/backup produces: a genuine gzip storage archive, the
 * six checksummed files, and a genuine SHA256SUMS computed with real
 * sha256sum, so every checksum check downstream is a real check.
 */
function buildBackupFixture(string $namespaceRoot, string $timestamp, array $options = []): string
{
    $dir = $namespaceRoot.'/'.$timestamp;
    mkdir($dir, 0o755, true);

    file_put_contents($dir.'/database.dump', $options['dump'] ?? "FAKE-PG-CUSTOM-DUMP\n");

    $stage = $dir.'.src';
    mkdir($stage.'/app/public', 0o755, true);
    file_put_contents($stage.'/app/restored-marker.txt', "restored\n");
    file_put_contents($stage.'/app/public/restored-public.txt', "restored public\n");

    if (isset($options['archive_builder'])) {
        $options['archive_builder']($stage);
    }

    exec('tar -C '.escapeshellarg($stage).' -czf '.escapeshellarg($dir.'/storage-app.tar.gz').' '.($options['archive_member'] ?? 'app').' 2>&1');
    exec('rm -rf '.escapeshellarg($stage));

    if (isset($options['storage_archive_bytes'])) {
        file_put_contents($dir.'/storage-app.tar.gz', $options['storage_archive_bytes']);
    }

    file_put_contents($dir.'/environment.env', "APP_ENV=staging\nDB_PASSWORD=from-backup-never-applied\n");
    file_put_contents($dir.'/server-configuration.tar.gz', "fake server configuration snapshot\n");

    $releaseJson = array_key_exists('release_json', $options)
        ? $options['release_json']
        : ['project' => 'rateguru', 'release' => FIXTURE_RELEASE, 'source_sha' => FIXTURE_SOURCE_SHA];

    file_put_contents(
        $dir.'/release.json',
        is_string($releaseJson) ? $releaseJson : json_encode($releaseJson, JSON_PRETTY_PRINT),
    );

    $manifest = array_key_exists('manifest', $options)
        ? $options['manifest']
        : backupManifestFixture();

    if ($manifest !== null) {
        file_put_contents($dir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    $files = ['database.dump', 'storage-app.tar.gz', 'environment.env', 'release.json', 'server-configuration.tar.gz'];

    if ($manifest !== null) {
        $files[] = 'manifest.json';
    }

    $lines = [];
    foreach ($files as $file) {
        $lines[] = hash_file('sha256', $dir.'/'.$file).'  '.$file;
    }

    foreach ($options['extra_sha_lines'] ?? [] as $extra) {
        $lines[] = $extra;
    }

    file_put_contents($dir.'/SHA256SUMS', implode("\n", $lines)."\n");

    if (! empty($options['corrupt_after_checksum'])) {
        file_put_contents($dir.'/database.dump', "TAMPERED\n");
    }

    return $dir;
}

/** @return array<string, mixed> */
function backupManifestFixture(array $overrides = []): array
{
    return array_merge([
        'manifest_schema_version' => 2,
        'project' => 'rateguru',
        'selector' => 'target',
        'target' => 'parity-target',
        'environment' => 'staging',
        'backup_namespace' => 'parity',
        'created_at' => '2026-01-01T00:00:00Z',
        'hostname' => 'test-host',
        'database' => 'parity_db',
        'release' => FIXTURE_RELEASE,
        'postgres_version' => 'pg_dump (PostgreSQL) 18.4',
        'php_version' => '8.5.0',
    ], $overrides);
}

/**
 * A file-backed fake PostgreSQL: one file per database under $scratch/pg/db,
 * holding "<owner> <allowconn>". The psql/createdb/dropdb/pg_restore stubs
 * below read and mutate it, so a rename swap, a connection barrier and a drop
 * are all genuinely observable across separate script invocations — which is
 * what makes the activation/compensation tests real rather than rigged.
 */
function installFakePostgres(string $scratch, array $options = []): void
{
    $catalog = $scratch.'/pg/db';
    @mkdir($catalog, 0o755, true);

    foreach ($options['databases'] ?? ['parity_db' => 'parity_app'] as $name => $owner) {
        file_put_contents($catalog.'/'.$name, $owner." t\n");
    }

    writeExecutable($scratch.'/bin/runuser', "#!/usr/bin/env bash\nshift 2; shift\nexec \"\$@\"\n");

    writeExecutable($scratch.'/bin/psql', <<<'BASH'
#!/usr/bin/env bash
set -uo pipefail
catalog="${RGTEST_PG_CATALOG}"
printf '%s\n' "psql $*" >> "${RGTEST_PSQL_LOG}"

cmd=""
for arg in "$@"; do
    case "${arg}" in
        --command=*) cmd="${arg#--command=}" ;;
    esac
done

db_owner() { [[ -f "${catalog}/$1" ]] && awk '{print $1}' "${catalog}/$1"; }
db_allow() { [[ -f "${catalog}/$1" ]] && awk '{print $2}' "${catalog}/$1"; }

if [[ "${cmd}" =~ SELECT\ 1\ FROM\ pg_database\ WHERE\ datname\ =\ \'([a-z0-9_]+)\' ]]; then
    [[ -f "${catalog}/${BASH_REMATCH[1]}" ]] && printf '1\n'
    exit 0
fi

if [[ "${cmd}" =~ pg_get_userbyid\(datdba\)\ FROM\ pg_database\ WHERE\ datname\ =\ \'([a-z0-9_]+)\' ]]; then
    db_owner "${BASH_REMATCH[1]}"
    exit 0
fi

if [[ "${cmd}" =~ SELECT\ datallowconn\ FROM\ pg_database\ WHERE\ datname\ =\ \'([a-z0-9_]+)\' ]]; then
    db_allow "${BASH_REMATCH[1]}"
    exit 0
fi

if [[ "${cmd}" =~ SELECT\ 1\ FROM\ pg_roles\ WHERE\ rolname\ =\ \'([a-z0-9_]+)\' ]]; then
    grep -qx "${BASH_REMATCH[1]}" "${RGTEST_PG_ROLES}" && printf '1\n'
    exit 0
fi

if [[ "${cmd}" == *"rolcanlogin"* ]]; then
    printf '%s\n' "${RGTEST_ROLE_CANLOGIN:-t}"
    exit 0
fi

if [[ "${cmd}" == *"rolsuper"* ]]; then
    printf '%s\n' "${RGTEST_ROLE_ELEVATED:-}"
    exit 0
fi

if [[ "${cmd}" =~ ALTER\ DATABASE\ \"([a-z0-9_]+)\"\ WITH\ ALLOW_CONNECTIONS\ (true|false) ]]; then
    name="${BASH_REMATCH[1]}"
    value="${BASH_REMATCH[2]}"
    [[ -f "${catalog}/${name}" ]] || { printf 'ERROR: no such database %s\n' "${name}" >&2; exit 1; }
    flag=t
    [[ "${value}" == false ]] && flag=f
    printf '%s %s\n' "$(db_owner "${name}")" "${flag}" > "${catalog}/${name}"
    exit 0
fi

if [[ "${cmd}" =~ ALTER\ DATABASE\ \"([a-z0-9_]+)\"\ RENAME\ TO\ \"([a-z0-9_]+)\" ]]; then
    from="${BASH_REMATCH[1]}"
    to="${BASH_REMATCH[2]}"
    if [[ -n "${RGTEST_RENAME_FAIL:-}" ]] && [[ "${RGTEST_RENAME_FAIL}" == "${from}->${to}" ]]; then
        printf 'ERROR: injected rename failure\n' >&2
        exit 1
    fi
    if [[ -n "${RGTEST_RENAME_FAIL_TO_PREFIX:-}" ]] && [[ "${to}" == "${RGTEST_RENAME_FAIL_TO_PREFIX}"* ]]; then
        printf 'ERROR: injected rename failure\n' >&2
        exit 1
    fi
    [[ -f "${catalog}/${from}" ]] || { printf 'ERROR: no such database %s\n' "${from}" >&2; exit 1; }
    [[ ! -f "${catalog}/${to}" ]] || { printf 'ERROR: database %s already exists\n' "${to}" >&2; exit 1; }
    mv "${catalog}/${from}" "${catalog}/${to}"
    exit 0
fi

if [[ "${cmd}" == *"pg_terminate_backend"* ]]; then
    exit 0
fi

if [[ "${cmd}" == *"information_schema.tables"* ]]; then
    printf '%s\n' "${RGTEST_TABLE_COUNT:-42}"
    exit 0
fi

if [[ "${cmd}" == *"public.migrations"* ]]; then
    printf '%s\n' "${RGTEST_MIGRATION_COUNT:-17}"
    exit 0
fi

if [[ "${cmd}" == "SELECT 1;" ]]; then
    printf '1\n'
    exit 0
fi

printf 'ERROR: unhandled SQL in fake psql: %s\n' "${cmd}" >&2
exit 1
BASH);

    writeExecutable($scratch.'/bin/createdb', <<<'BASH'
#!/usr/bin/env bash
set -uo pipefail
printf '%s\n' "createdb $*" >> "${RGTEST_CREATEDB_LOG}"
[[ "${RGTEST_CREATEDB_EXIT:-0}" == 0 ]] || exit "${RGTEST_CREATEDB_EXIT}"
owner=""
name=""
for arg in "$@"; do
    case "${arg}" in
        --owner=*) owner="${arg#--owner=}" ;;
        --template=*) ;;
        -*) ;;
        *) name="${arg}" ;;
    esac
done
[[ -n "${name}" ]] || exit 1
[[ ! -f "${RGTEST_PG_CATALOG}/${name}" ]] || exit 1
printf '%s t\n' "${owner}" > "${RGTEST_PG_CATALOG}/${name}"
BASH);

    writeExecutable($scratch.'/bin/dropdb', <<<'BASH'
#!/usr/bin/env bash
set -uo pipefail
printf '%s\n' "dropdb $*" >> "${RGTEST_DROPDB_LOG}"
for arg in "$@"; do
    case "${arg}" in
        -*) ;;
        *) rm -f "${RGTEST_PG_CATALOG}/${arg}" ;;
    esac
done
BASH);

    writeExecutable($scratch.'/bin/pg_restore', <<<'BASH'
#!/usr/bin/env bash
set -uo pipefail
printf '%s\n' "pg_restore $*" >> "${RGTEST_PG_RESTORE_LOG}"
if [[ -n "${PGPASSWORD:-}" ]]; then
    printf 'pgpassword-present\n' >> "${RGTEST_PG_RESTORE_LOG}"
fi
cat >/dev/null
exit "${RGTEST_PG_RESTORE_EXIT:-0}"
BASH);

    file_put_contents($scratch.'/pg/roles', implode("\n", $options['roles'] ?? ['parity_app'])."\n");

    foreach (['psql', 'createdb', 'dropdb', 'pg_restore'] as $log) {
        touch($scratch.'/'.$log.'.log');
    }
}

/** @return array<string, string> */
function fakePostgresEnv(string $scratch): array
{
    return [
        'RGTEST_PG_CATALOG' => $scratch.'/pg/db',
        'RGTEST_PG_ROLES' => $scratch.'/pg/roles',
        'RGTEST_PSQL_LOG' => $scratch.'/psql.log',
        'RGTEST_CREATEDB_LOG' => $scratch.'/createdb.log',
        'RGTEST_DROPDB_LOG' => $scratch.'/dropdb.log',
        'RGTEST_PG_RESTORE_LOG' => $scratch.'/pg_restore.log',
        'RATEGURU_CREATEDB_BIN' => $scratch.'/bin/createdb',
        'RATEGURU_DROPDB_BIN' => $scratch.'/bin/dropdb',
        'RATEGURU_PG_RESTORE_BIN' => $scratch.'/bin/pg_restore',
        'RATEGURU_PSQL_BIN' => $scratch.'/bin/psql',
        'RATEGURU_RESTORE_RUNUSER_BIN' => $scratch.'/bin/runuser',
    ];
}

/** The baseline environment every Restore Target Data script invocation needs. */
function infraScriptEnv(string $scratch, string $registryPath, string $targetsPath, array $overrides = []): array
{
    return array_merge([
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_COMMON_FILE' => infraScript('common'),
        // The patched copy: restore-common's own workspace/history creation
        // uses `install -o root -g root`, which needs real root. Only that is
        // rewritten; every line of logic under test is byte-identical.
        'RATEGURU_RESTORE_COMMON_FILE' => patchedInfraScript($scratch, 'restore-common'),
        'RATEGURU_DEPLOYMENT_CONF_FILE' => deploymentConfFixture($scratch),
        'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
        'RATEGURU_TARGETS_CLI' => $targetsPath,
        'RATEGURU_BACKUP_BASE' => $scratch.'/backups',
        'RATEGURU_RUN_ROOT' => $scratch.'/run',
        'RATEGURU_RESTORE_HISTORY_ROOT' => $scratch.'/restores',
        'RATEGURU_RESTORE_CRON_D_ROOT' => $scratch.'/cron.d',
        'RATEGURU_RESTORE_WEB_GROUP' => trim((string) shell_exec('id -gn')),
        'RGTEST_BYPASS_ROOT' => 'true',
    ], $overrides);
}

/**
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function runInfraScript(string $scriptPath, array $arguments, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(array_merge(['bash', $scriptPath], $arguments), $descriptors, $pipes, null, $env);

    expect($process)->not->toBeFalse('could not start the script under test');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

/**
 * Sources a script (so its functions exist without main() running) and
 * executes an arbitrary body against them — the technique BackupTest and
 * RestoreTest already use for coverage that must bypass require_root.
 *
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function runInfraHarness(string $scratch, string $scriptPath, string $body, array $env): array
{
    $harness = $scratch.'/harness-'.uniqid('', true).'.sh';
    file_put_contents($harness, "set -Eeuo pipefail\nsource ".escapeshellarg($scriptPath)."\n".$body."\n");

    return runInfraScript($harness, [], $env);
}

/** Extracts every operation ID a script printed, newest last. */
function operationIdsIn(string $output): array
{
    preg_match_all('/\b(\d{8}-\d{6}-[0-9a-f]{6})\b/', $output, $matches);

    return array_values(array_unique($matches[1]));
}

/**
 * A restore operation workspace with a state document in a chosen phase, and
 * a real staged backup inside it — the exact shape restore-target hands the
 * restore-database/restore-storage primitives.
 *
 * @param  array<string, string>  $state
 */
function restoreWorkspaceFixture(string $scratch, string $operationId, array $state = [], array $backupOptions = []): string
{
    $workspace = $scratch.'/run/restores/parity-target/'.$operationId;
    mkdir($workspace.'/selected-backup', 0o700, true);
    chmod($workspace, 0o700);

    $backup = buildBackupFixture($scratch.'/source-'.$operationId, '20260115-120000', $backupOptions);

    foreach (scandir($backup) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        copy($backup.'/'.$entry, $workspace.'/selected-backup/'.$entry);
    }

    file_put_contents($workspace.'/state.json', json_encode(array_merge([
        'operation_id' => $operationId,
        'target' => 'parity-target',
        'environment' => 'staging',
        'backup_namespace' => 'parity',
        'source' => 'local',
        'backup' => '20260115-120000',
        'status' => 'running',
        'phase' => 'backup-verified',
    ], $state), JSON_PRETTY_PRINT));
    chmod($workspace.'/state.json', 0o600);

    return $workspace;
}

/** @return array<string, mixed> */
function restoreOperationState(string $workspace): array
{
    return json_decode(File::get($workspace.'/state.json'), true);
}

/** The database names restore-database derives for a given operation. */
function stagedDatabaseName(string $operationId): string
{
    return 'rateguru_rst_parity_'.str_replace('-', '_', $operationId);
}

function preRestoreDatabaseName(string $operationId): string
{
    return 'rateguru_pre_parity_'.str_replace('-', '_', $operationId);
}

/** Every database the fake catalog currently holds. */
function fakePostgresDatabases(string $scratch): array
{
    $entries = array_values(array_diff(scandir($scratch.'/pg/db'), ['.', '..']));
    sort($entries);

    return $entries;
}

/** Advances an operation's recorded phase, the way restore-target does. */
function setRestoreOperationPhase(string $workspace, string $phase): void
{
    $state = restoreOperationState($workspace);
    $state['phase'] = $phase;

    file_put_contents($workspace.'/state.json', json_encode($state, JSON_PRETTY_PRINT));
}

/**
 * Builds a tar.gz from an explicit entry spec, so an archive containing a
 * symlink, hardlink, device node, FIFO, absolute path or `..` component can
 * be constructed exactly — none of which a filesystem-based `tar -c` can
 * reliably produce on every platform this suite runs on.
 *
 * @param  list<array{name: string, type: string, link?: string}>  $entries
 */
function buildArchiveFixture(string $path, array $entries): void
{
    $python = <<<'PY'
import io, json, sys, tarfile

spec = json.loads(sys.argv[2])

with tarfile.open(sys.argv[1], "w:gz") as tf:
    for entry in spec:
        info = tarfile.TarInfo(entry["name"])
        kind = entry["type"]

        if kind == "dir":
            info.type = tarfile.DIRTYPE
            info.mode = 0o755
        elif kind == "file":
            info.type = tarfile.REGTYPE
            info.mode = 0o644
            info.size = 1
        elif kind == "symlink":
            info.type = tarfile.SYMTYPE
            info.linkname = entry.get("link", "/etc/passwd")
        elif kind == "hardlink":
            info.type = tarfile.LNKTYPE
            info.linkname = entry.get("link", "app/regular.txt")
        elif kind == "fifo":
            info.type = tarfile.FIFOTYPE
        elif kind == "chardev":
            info.type = tarfile.CHRTYPE
            info.devmajor, info.devminor = 1, 3
        elif kind == "blockdev":
            info.type = tarfile.BLKTYPE
            info.devmajor, info.devminor = 8, 0
        else:
            raise SystemExit("unknown entry type: " + kind)

        if info.type == tarfile.REGTYPE:
            tf.addfile(info, io.BytesIO(b"x"))
        else:
            tf.addfile(info)
PY;

    $script = sys_get_temp_dir().'/rateguru-archive-'.uniqid('', true).'.py';
    file_put_contents($script, $python);

    exec(
        'python3 '.escapeshellarg($script).' '.escapeshellarg($path).' '.escapeshellarg(json_encode($entries)).' 2>&1',
        $output,
        $exit,
    );

    unlink($script);

    expect($exit)->toBe(0, "could not build the archive fixture:\n".implode("\n", $output));
}

/**
 * The target-runtime stubs a full restore-target run needs: this target's own
 * Supervisor program, its Laravel maintenance mode, the existing `backup` and
 * `restore-test` implementations it reuses for the emergency backup, the
 * health check, and pgrep for the scheduler barrier.
 *
 * Each records what it was asked to do, so a test can assert BOTH the runtime
 * state that resulted and the fact that nothing global was ever touched.
 */
function installTargetRuntimeStubs(string $scratch): void
{
    writeExecutable($scratch.'/bin/supervisorctl', <<<'BASH'
#!/usr/bin/env bash
set -uo pipefail
printf '%s\n' "supervisorctl $*" >> "${RGTEST_SUPERVISOR_LOG}"

action="${1:-}"
group="${2:-}"

# Supervisor's own status exit codes, from supervisor 4.2.1
# (supervisorctl.py LSBStatusExitStatuses, states.py STOPPED_STATES):
#
#   0  every matched process is in a running-ish state
#   3  at least one matched process is STOPPED, EXITED, FATAL or UNKNOWN
#   4  upcheck() failed, or a name matched nothing
#
# Modelling this faithfully is the point: the stub used to exit 0 for every
# status, which is why a real staging restore — where a correctly STOPPED queue
# reports rc 3 — was not caught here first.
supervisor_status_rc() {
    local rc=0 state

    for state in "$@"; do
        case "${state}" in
            STOPPED|EXITED|FATAL|UNKNOWN) rc=3 ;;
        esac
    done

    printf '%s\n' "${rc}"
}

case "${action}" in
    status)
        # An observation failure that is NOT a process state: supervisord
        # unreachable, or the group unknown. do_status overrides the exit
        # status to 4 for both.
        if [[ -n "${RGTEST_SUPERVISOR_STATUS_FAILURE:-}" ]]; then
            printf '%s\n' "${RGTEST_SUPERVISOR_STATUS_FAILURE}" >&2
            exit "${RGTEST_SUPERVISOR_STATUS_FAILURE_RC:-4}"
        fi

        # Arbitrary stdout, for the malformed / wrong-group cases.
        if [[ -n "${RGTEST_SUPERVISOR_STATUS_STDOUT:-}" ]]; then
            printf '%s\n' "${RGTEST_SUPERVISOR_STATUS_STDOUT}"
            exit "${RGTEST_SUPERVISOR_STATUS_RC:-0}"
        fi

        state="$(cat "${RGTEST_SUPERVISOR_STATE}")"
        printf '%-40s %s   pid 4242, uptime 0:10:00\n' "${group%:*}:${group%:*}_00" "${state}"

        # A second process in the same group, so a MIXED group (one RUNNING,
        # one FATAL) can be exercised the way a real crash-looping worker
        # presents. Empty means a single-process group.
        second="$(cat "${RGTEST_SUPERVISOR_SECOND_STATE}" 2>/dev/null || true)"
        if [[ -n "${second}" ]]; then
            printf '%-40s %s   pid 4243, uptime 0:00:01\n' "${group%:*}:${group%:*}_01" "${second}"
        fi

        exit "$(supervisor_status_rc "${state}" ${second:+"${second}"})"
        ;;
    stop)
        # supervisorctl stop takes the whole group down, second process included.
        # RGTEST_SUPERVISOR_STOP_STATE models a stop that TOOK EFFECT but landed
        # somewhere other than STOPPED — the state a confirmation timeout sees.
        printf '%s\n' "${RGTEST_SUPERVISOR_STOP_STATE:-STOPPED}" > "${RGTEST_SUPERVISOR_STATE}"
        [[ -z "$(cat "${RGTEST_SUPERVISOR_SECOND_STATE}" 2>/dev/null || true)" ]] \
            || printf '%s\n' "${RGTEST_SUPERVISOR_STOP_STATE:-STOPPED}" > "${RGTEST_SUPERVISOR_SECOND_STATE}"
        ;;
    start)
        # RGTEST_SUPERVISOR_START_STATE models a start that TOOK EFFECT but has
        # not reached RUNNING — STARTING, or a worker crash-looping in BACKOFF.
        printf '%s\n' "${RGTEST_SUPERVISOR_START_STATE:-RUNNING}" > "${RGTEST_SUPERVISOR_STATE}"
        [[ -z "$(cat "${RGTEST_SUPERVISOR_SECOND_STATE}" 2>/dev/null || true)" ]] \
            || printf '%s\n' "${RGTEST_SUPERVISOR_START_STATE:-RUNNING}" > "${RGTEST_SUPERVISOR_SECOND_STATE}"
        ;;
    *)
        exit 1
        ;;
esac
BASH);

    writeExecutable($scratch.'/bin/php', <<<'BASH'
#!/usr/bin/env bash
set -uo pipefail
printf '%s\n' "php $*" >> "${RGTEST_PHP_LOG}"

case "${2:-}" in
    down)
        [[ "${RGTEST_ARTISAN_DOWN_EXIT:-0}" == 0 ]] || exit "${RGTEST_ARTISAN_DOWN_EXIT}"
        printf '{"time":0}\n' > "${RGTEST_MAINTENANCE_FLAG}"
        ;;
    up)
        [[ "${RGTEST_ARTISAN_UP_EXIT:-0}" == 0 ]] || exit "${RGTEST_ARTISAN_UP_EXIT}"
        # RGTEST_ARTISAN_UP_INEFFECTIVE models `artisan up` reporting success
        # while the target stays down.
        [[ -n "${RGTEST_ARTISAN_UP_INEFFECTIVE:-}" ]] || rm -f "${RGTEST_MAINTENANCE_FLAG}"
        ;;
    schedule:interrupt)
        exit "${RGTEST_SCHEDULE_INTERRUPT_EXIT:-0}"
        ;;
    *)
        ;;
esac
BASH);

    writeExecutable($scratch.'/bin/backup-stub', <<<'BASH'
#!/usr/bin/env bash
set -uo pipefail
printf '%s\n' "backup $*" >> "${RGTEST_BACKUP_LOG}"
[[ "${RGTEST_BACKUP_EXIT:-0}" == 0 ]] || exit "${RGTEST_BACKUP_EXIT}"

mkdir -p "${RGTEST_BACKUP_NAMESPACE_ROOT}"

# "none" is the explicit "this backup run produced nothing" case: an empty
# environment value cannot express it, since a shell default would take over.
ids="${RGTEST_EMERGENCY_BACKUP_IDS:-20260116-090000}"

if [[ "${ids}" != none ]]; then
    for stamp in ${ids}; do
        cp -a "${RGTEST_BACKUP_TEMPLATE}" "${RGTEST_BACKUP_NAMESPACE_ROOT}/${stamp}"
    done
fi
BASH);

    writeExecutable($scratch.'/bin/restore-test-stub', <<<'BASH'
#!/usr/bin/env bash
set -uo pipefail
printf '%s\n' "restore-test $*" >> "${RGTEST_RESTORE_TEST_LOG}"
exit "${RGTEST_RESTORE_TEST_EXIT:-0}"
BASH);

    writeExecutable($scratch.'/bin/health-check-stub', <<<'BASH'
#!/usr/bin/env bash
set -uo pipefail
printf '%s\n' "health-check $*" >> "${RGTEST_HEALTH_CHECK_LOG}"
exit "${RGTEST_HEALTH_CHECK_EXIT:-0}"
BASH);

    // Nothing is running by default: pgrep exits 1 when no process matches.
    writeExecutable($scratch.'/bin/pgrep', <<<'BASH'
#!/usr/bin/env bash
set -uo pipefail
printf '%s\n' "pgrep $*" >> "${RGTEST_PGREP_LOG}"
exit "${RGTEST_PGREP_EXIT:-1}"
BASH);

    file_put_contents($scratch.'/supervisor-state', "RUNNING\n");
    file_put_contents($scratch.'/supervisor-second-state', '');

    foreach (['supervisor', 'php', 'backup', 'restore-test', 'health-check', 'pgrep'] as $log) {
        touch($scratch.'/'.$log.'.log');
    }
}

/** @return array<string, string> */
function targetRuntimeEnv(string $scratch): array
{
    return [
        'RGTEST_SUPERVISOR_LOG' => $scratch.'/supervisor.log',
        'RGTEST_SUPERVISOR_STATE' => $scratch.'/supervisor-state',
        'RGTEST_SUPERVISOR_SECOND_STATE' => $scratch.'/supervisor-second-state',
        'RGTEST_PHP_LOG' => $scratch.'/php.log',
        'RGTEST_MAINTENANCE_FLAG' => $scratch.'/target/shared/storage/framework/down',
        'RGTEST_BACKUP_LOG' => $scratch.'/backup.log',
        'RGTEST_BACKUP_TEMPLATE' => $scratch.'/emergency-template',
        'RGTEST_BACKUP_NAMESPACE_ROOT' => $scratch.'/backups/parity',
        'RGTEST_RESTORE_TEST_LOG' => $scratch.'/restore-test.log',
        'RGTEST_HEALTH_CHECK_LOG' => $scratch.'/health-check.log',
        'RGTEST_PGREP_LOG' => $scratch.'/pgrep.log',
        'RATEGURU_RESTORE_PGREP_BIN' => $scratch.'/bin/pgrep',
        'RATEGURU_RESTORE_SUPERVISORCTL_BIN' => $scratch.'/bin/supervisorctl',
        'RATEGURU_RESTORE_BACKUP_BIN' => $scratch.'/bin/backup-stub',
        'RATEGURU_RESTORE_RESTORE_TEST_BIN' => $scratch.'/bin/restore-test-stub',
        'RATEGURU_RESTORE_HEALTH_CHECK_BIN' => $scratch.'/bin/health-check-stub',
        'RATEGURU_RESTORE_QUEUE_WAIT_ATTEMPTS' => '3',
        'RATEGURU_RESTORE_QUEUE_RETRY_DELAY' => '0',
        'RATEGURU_RESTORE_SCHEDULER_WAIT_ATTEMPTS' => '3',
        'RATEGURU_RESTORE_SCHEDULER_RETRY_DELAY' => '0',
    ];
}
