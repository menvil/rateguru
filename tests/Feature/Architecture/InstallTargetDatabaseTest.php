<?php

use Illuminate\Support\Facades\File;

/**
 * Prepare Host: infrastructure/scripts/install-target-database — the smallest
 * primitive that closes the clean-host gap the clean-host bootstrap deliberately left open, and
 * the one whose safety rules matter most, because it is the only part of
 * preparation that can touch a live database.
 *
 * Every test runs the real, shipped script as a subprocess against a stub
 * `psql` that records every statement it is handed and answers catalog queries
 * from a fixture state file. What is being pinned is not PostgreSQL's
 * behaviour but this script's: what it will and, above all, will not execute.
 */
function itdbScript(): string
{
    return base_path('infrastructure/scripts/install-target-database');
}

function itdbScratchDir(): string
{
    $dir = sys_get_temp_dir().'/target-db-'.uniqid('', true).'-'.getmypid();

    foreach (['/bin', '/state', '/target/shared'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue();
    }

    return $dir;
}

function itdbCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * A stub `psql` that is deliberately dumb: it logs the full argv and any stdin,
 * and answers the four catalog questions from toggle files. That is enough to
 * prove exactly which statements this installer does and does not issue.
 */
function itdbWriteStubs(string $scratch): void
{
    file_put_contents($scratch.'/bin/psql', <<<'STUB'
        #!/bin/bash
        args="$*"
        printf 'psql %s\n' "${args}" >> "${STUB_STATE}/psql.log"

        payload="${args}"

        # Exactly one statement arrives on stdin — the one carrying the role
        # password, which is the whole point of sending it that way. Every other
        # call uses --command, and reading stdin for those would block forever
        # on the inherited descriptor.
        if [[ "${args}" != *"--command="* ]]; then
            stdin="$(cat || true)"
            if [[ -n "${stdin}" ]]; then
                printf 'stdin %s\n' "${stdin}" >> "${STUB_STATE}/psql.log"
                payload="${stdin}"
            fi
        fi

        # Application-credential connectivity probe.
        if [[ "${args}" == *"--username="* ]]; then
            [[ -e "${STUB_STATE}/app-can-connect" ]] && exit 0
            exit 2
        fi

        # Order matters: the rolcanlogin probe also mentions pg_roles, so it has
        # to be matched before the generic existence query.
        if [[ -e "${STUB_STATE}/psql-unreachable" ]]; then
            echo "psql: error: connection to server failed" >&2
            exit 2
        fi

        case "${payload}" in
            *"rolsuper"*)
                [[ -e "${STUB_STATE}/role-elevated" ]] && echo SUPERUSER
                ;;
            *"SELECT rolcanlogin"*)
                if [[ -e "${STUB_STATE}/role-cannot-login" ]]; then
                    echo f
                elif [[ -e "${STUB_STATE}/role-exists" ]]; then
                    echo t
                fi
                ;;
            *"FROM pg_roles WHERE rolname"*)
                [[ -e "${STUB_STATE}/role-exists" ]] && echo 1
                ;;
            *"pg_get_userbyid"*)
                [[ -e "${STUB_STATE}/database-exists" ]] && cat "${STUB_STATE}/database-owner"
                ;;
            *"FROM pg_database WHERE datname"*)
                [[ -e "${STUB_STATE}/database-exists" ]] && echo 1
                ;;
            *"CREATE ROLE"*)
                touch "${STUB_STATE}/role-exists"
                ;;
            *"CREATE DATABASE"*)
                touch "${STUB_STATE}/database-exists"
                printf 'rateguru_staging_app' > "${STUB_STATE}/database-owner"
                ;;
        esac
        exit 0
        STUB);
    chmod($scratch.'/bin/psql', 0o755);

    // runuser -u postgres -- psql ... : drop the first two arguments and exec.
    file_put_contents($scratch.'/bin/runuser', <<<'STUB'
        #!/bin/bash
        shift 2
        [[ "$1" == "--" ]] && shift
        exec "$@"
        STUB);
    chmod($scratch.'/bin/runuser', 0o755);
}

function itdbWriteCommon(string $scratch): string
{
    $registry = base_path('infrastructure/config/deployment-targets.json');
    $path = $scratch.'/common';

    file_put_contents($path, <<<COMMON
        #!/usr/bin/env bash
        REGISTRY="{$registry}"
        log() { printf '[log] %s\\n' "\$*"; }
        fail() { printf 'ERROR: %s\\n' "\$*" >&2; exit 1; }
        rateguru_test_overrides_allowed() { [[ "\${RATEGURU_ALLOW_TEST_OVERRIDES:-false}" == true ]]; }
        require_flag_value() {
            local flag="\$1" remaining="\$2" value="\${3-}"
            [[ "\${remaining}" -ge 2 ]] || fail "\${flag} requires a value"
            [[ -n "\${value}" ]] || fail "\${flag} requires a non-empty value"
            case "\${value}" in -*) fail "\${flag} requires a value, not another option: \${value}" ;; esac
        }
        _prop() { jq -r --arg id "\$1" ".targets[\\\$id].\$2 // empty" "\${REGISTRY}"; }
        target_lifecycle() { _prop "\$1" 'lifecycle'; }
        target_database_name() { _prop "\$1" 'database.name'; }
        target_database_role() { _prop "\$1" 'database.application_role'; }
        target_root() { printf '%s\\n' "\${STUB_TARGET_ROOT}"; }
        require_active_target() {
            local lifecycle
            [[ "\$1" =~ ^[a-z0-9][a-z0-9-]{1,31}\$ ]] || fail "invalid target ID: \$1"
            lifecycle="\$(target_lifecycle "\$1")"
            [[ -n "\${lifecycle}" ]] || fail "unknown target: \$1"
            [[ "\${lifecycle}" == active ]] || fail "target \$1 has lifecycle=\${lifecycle}, not active"
        }
        COMMON);

    return $path;
}

/** @param  array<string, string>  $overrides */
function itdbWriteEnv(string $scratch, array $overrides = []): void
{
    $values = array_merge([
        'APP_KEY' => 'base64:irrelevant',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '5432',
        'DB_DATABASE' => 'rateguru_staging',
        'DB_USERNAME' => 'rateguru_staging_app',
        'DB_PASSWORD' => "s3cr3t'w1th\\quotes",
    ], $overrides);

    $lines = [];

    foreach ($values as $key => $value) {
        $lines[] = $key.'='.$value;
    }

    file_put_contents($scratch.'/target/shared/.env', implode("\n", $lines)."\n");
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, mixed>  $options
 * @return array{0: int, 1: string}
 */
function itdbRun(string $scratch, array $arguments, array $options = []): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(
        array_merge(['bash', itdbScript()], $arguments),
        $descriptors,
        $pipes,
        null,
        [
            'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => getenv('HOME') ?: '/tmp',
            'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
            'RATEGURU_COMMON_FILE' => itdbWriteCommon($scratch),
            'RATEGURU_TARGETDB_EUID' => $options['euid'] ?? '0',
            'RATEGURU_TARGETDB_PSQL_BIN' => $scratch.'/bin/psql',
            'RATEGURU_TARGETDB_RUNUSER_BIN' => $scratch.'/bin/runuser',
            'STUB_STATE' => $scratch.'/state',
            'STUB_TARGET_ROOT' => $scratch.'/target',
        ],
    );

    expect($process)->not->toBeFalse();

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

function itdbLog(string $scratch): string
{
    return is_file($scratch.'/state/psql.log')
        ? (string) file_get_contents($scratch.'/state/psql.log')
        : '';
}

/** An already-provisioned, already-populated database. */
function itdbExistingDatabase(string $scratch): void
{
    touch($scratch.'/state/role-exists');
    touch($scratch.'/state/database-exists');
    touch($scratch.'/state/app-can-connect');
    file_put_contents($scratch.'/state/database-owner', 'rateguru_staging_app');
}

// =============================================================================
// Clean host
// =============================================================================

it('creates the role and the database on a clean host, then verifies connectivity', function () {
    $scratch = itdbScratchDir();

    try {
        itdbWriteStubs($scratch);
        itdbWriteEnv($scratch);

        // A clean host: nothing exists, but the credentials will work once the
        // role does.
        touch($scratch.'/state/app-can-connect');

        [$exit, $output] = itdbRun($scratch, ['--apply', '--target', 'staging-main']);

        expect($exit)->toBe(0);

        $log = itdbLog($scratch);

        expect($log)->toContain('CREATE ROLE rateguru_staging_app WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION');
        expect($log)->toContain('CREATE DATABASE rateguru_staging OWNER rateguru_staging_app TEMPLATE template0');
        expect($log)->toContain('GRANT CONNECT ON DATABASE rateguru_staging TO rateguru_staging_app');

        expect($output)->toContain('No schema was created and no migration was run');
    } finally {
        itdbCleanup($scratch);
    }
});

it('never puts the role password in an argument vector', function () {
    $scratch = itdbScratchDir();

    try {
        itdbWriteStubs($scratch);
        itdbWriteEnv($scratch);
        touch($scratch.'/state/app-can-connect');

        [$exit, $output] = itdbRun($scratch, ['--apply', '--target', 'staging-main']);

        expect($exit)->toBe(0);

        // The CREATE ROLE statement travels on stdin; the connectivity probe
        // uses PGPASSWORD. Neither reaches argv, and neither reaches the
        // operator-visible output.
        foreach (explode("\n", itdbLog($scratch)) as $line) {
            if (str_starts_with($line, 'psql ')) {
                expect($line)->not->toContain('s3cr3t');
            }
        }

        expect(itdbLog($scratch))->toContain('stdin ');
        expect($output)->not->toContain('s3cr3t');
    } finally {
        itdbCleanup($scratch);
    }
});

it('escapes the password correctly for a SQL literal', function () {
    $scratch = itdbScratchDir();

    try {
        itdbWriteStubs($scratch);
        itdbWriteEnv($scratch);
        touch($scratch.'/state/app-can-connect');

        itdbRun($scratch, ['--apply', '--target', 'staging-main']);

        // Backslashes doubled first, then single quotes — reversing the order
        // would re-escape the escapes. The fixture password contains both.
        expect(itdbLog($scratch))->toContain("PASSWORD E's3cr3t''w1th\\\\quotes'");
    } finally {
        itdbCleanup($scratch);
    }
});

// =============================================================================
// Already prepared host — nothing destructive, ever
// =============================================================================

it('skips an existing role and database without recreating either', function () {
    $scratch = itdbScratchDir();

    try {
        itdbWriteStubs($scratch);
        itdbWriteEnv($scratch);
        itdbExistingDatabase($scratch);

        [$exit, $output] = itdbRun($scratch, ['--apply', '--target', 'staging-main']);

        expect($exit)->toBe(0);
        expect($output)->toContain('SKIP role rateguru_staging_app: already exists (never re-created, never re-passworded)');
        expect($output)->toContain('SKIP database rateguru_staging: already exists (never dropped, never recreated, contents never touched)');

        expect(itdbLog($scratch))->not->toContain('CREATE ROLE');
        expect(itdbLog($scratch))->not->toContain('CREATE DATABASE');
    } finally {
        itdbCleanup($scratch);
    }
});

it('refuses an over-privileged pre-existing role rather than calling the target prepared', function () {
    $scratch = itdbScratchDir();

    try {
        itdbWriteStubs($scratch);
        itdbWriteEnv($scratch);
        itdbExistingDatabase($scratch);
        touch($scratch.'/state/role-elevated');

        // "It can log in" is not "it is safe to hand the application": a role
        // created by someone else may hold SUPERUSER, and this installer never
        // alters an existing role, so the only honest answer is to fail.
        [$exit, $output] = itdbRun($scratch, ['--verify', '--target', 'staging-main']);

        expect($exit)->toBe(1);
        expect($output)->toContain('holds SUPERUSER');
        expect($output)->toContain('never alters an existing role');

        [$exit, $output] = itdbRun($scratch, ['--check', '--target', 'staging-main']);
        expect($exit)->toBe(1);
        expect($output)->toContain('holds SUPERUSER');
    } finally {
        itdbCleanup($scratch);
    }
});

it('treats an unreadable catalog as fatal, never as an absent object', function () {
    $scratch = itdbScratchDir();

    try {
        itdbWriteStubs($scratch);
        itdbWriteEnv($scratch);
        touch($scratch.'/state/psql-unreachable');

        // A database server that is down and a role that does not exist both
        // produce empty output. Conflating them would let --check report a
        // clean bill of health on a broken host, and let --apply converge
        // against state it never actually read.
        [$exit, $output] = itdbRun($scratch, ['--apply', '--target', 'staging-main']);

        expect($exit)->toBe(1);
        expect($output)->toContain('refusing to treat an unreadable catalog as an absent object');
        expect(itdbLog($scratch))
            ->not->toContain('CREATE ROLE')
            ->not->toContain('CREATE DATABASE');
    } finally {
        itdbCleanup($scratch);
    }
});

it('reports readiness through the exit status of --check', function () {
    $scratch = itdbScratchDir();

    try {
        itdbWriteStubs($scratch);
        itdbWriteEnv($scratch);

        // prepare-host's read-only walk reads this status; a --check that
        // always succeeded would let it call a target prepared on a host with
        // no database at all.
        [$exit, $output] = itdbRun($scratch, ['--check', '--target', 'staging-main']);
        expect($exit)->toBe(1);
        expect($output)->toContain('NEEDS_APPLY');

        itdbExistingDatabase($scratch);

        [$exit, $output] = itdbRun($scratch, ['--check', '--target', 'staging-main']);
        expect($exit)->toBe(0);
        expect($output)->toContain('already satisfied');

        // And read-only means read-only: neither run issued a statement that
        // changes anything. (The privilege probe legitimately names CREATEDB
        // and CREATEROLE while reading pg_roles, so the guard names the
        // statements rather than the substring.)
        expect(itdbLog($scratch))
            ->not->toContain('CREATE ROLE')
            ->not->toContain('CREATE DATABASE')
            ->not->toContain('GRANT ');
    } finally {
        itdbCleanup($scratch);
    }
});

it('never issues a destructive or schema-changing statement in any mode', function () {
    $scratch = itdbScratchDir();

    try {
        itdbWriteStubs($scratch);
        itdbWriteEnv($scratch);
        itdbExistingDatabase($scratch);

        foreach (['--check', '--apply', '--verify'] as $mode) {
            itdbRun($scratch, [$mode, '--target', 'staging-main']);
        }

        $log = itdbLog($scratch);

        foreach ([
            'DROP DATABASE', 'DROP ROLE', 'DROP SCHEMA', 'DROP TABLE',
            'TRUNCATE', 'DELETE FROM', 'UPDATE ', 'INSERT INTO',
            'ALTER ROLE', 'ALTER DATABASE', 'REVOKE', 'REASSIGN',
        ] as $forbidden) {
            expect($log)->not->toContain($forbidden);
        }
    } finally {
        itdbCleanup($scratch);
    }
});

it('never rotates the password of a role that already exists', function () {
    $scratch = itdbScratchDir();

    try {
        itdbWriteStubs($scratch);
        itdbWriteEnv($scratch);
        itdbExistingDatabase($scratch);

        itdbRun($scratch, ['--apply', '--target', 'staging-main']);

        // Silently resetting a password would break every running PHP-FPM
        // worker, the queue worker and the scheduler at once.
        expect(itdbLog($scratch))->not->toContain('PASSWORD');
    } finally {
        itdbCleanup($scratch);
    }
});

it('never runs a migration and contains no migration machinery at all', function () {
    $source = File::get(itdbScript());

    expect($source)->not->toMatch('/artisan\s+migrate/');
    expect($source)->not->toContain('pg_restore');
    expect($source)->not->toContain('pg_dump');

    // The PostgreSQL CLIs that create or destroy a database outright are never
    // invoked. (`no createdb, no createrole` appears in a log line describing
    // the role's own privileges, so bare-word occurrences are not enough.)
    expect($source)->not->toMatch('/(^|[\s"\/])(createdb|dropdb)\s/m');
});

it('refuses a role that cannot log in before creating a database for it', function () {
    $scratch = itdbScratchDir();

    try {
        itdbWriteStubs($scratch);
        itdbWriteEnv($scratch);

        // The role exists but cannot log in, and the database does not exist
        // yet. Discovering that only in the final verification would leave a
        // database and a CONNECT grant behind for an account the run refuses.
        touch($scratch.'/state/role-exists');
        file_put_contents($scratch.'/state/role-cannot-login', '');

        [$exit, $output] = itdbRun($scratch, ['--apply', '--target', 'staging-main']);

        expect($exit)->toBe(1);
        expect($output)->toContain('exists but cannot log in');
        expect($output)->toContain('Nothing was created');

        expect(itdbLog($scratch))
            ->not->toContain('CREATE DATABASE')
            ->not->toContain('GRANT ');
    } finally {
        itdbCleanup($scratch);
    }
});

it('fails closed when existing credentials cannot connect, and changes nothing', function () {
    $scratch = itdbScratchDir();

    try {
        itdbWriteStubs($scratch);
        itdbWriteEnv($scratch);
        touch($scratch.'/state/role-exists');
        touch($scratch.'/state/database-exists');
        file_put_contents($scratch.'/state/database-owner', 'rateguru_staging_app');
        // Deliberately no app-can-connect toggle.

        [$exit, $output] = itdbRun($scratch, ['--apply', '--target', 'staging-main']);

        expect($exit)->toBe(1);
        expect($output)->toContain('cannot connect to rateguru_staging as rateguru_staging_app');
        expect($output)->toContain('were left completely untouched');
        expect($output)->toContain('rotation is a separate operation');

        expect(itdbLog($scratch))
            ->not->toContain('CREATE ROLE')
            ->not->toContain('CREATE DATABASE');
    } finally {
        itdbCleanup($scratch);
    }
});

it('fails closed when the database is owned by someone else', function () {
    $scratch = itdbScratchDir();

    try {
        itdbWriteStubs($scratch);
        itdbWriteEnv($scratch);
        itdbExistingDatabase($scratch);
        file_put_contents($scratch.'/state/database-owner', 'someone_else');

        [$exit, $output] = itdbRun($scratch, ['--verify', '--target', 'staging-main']);

        expect($exit)->toBe(1);
        expect($output)->toContain('is owned by someone_else, not rateguru_staging_app');
        expect($output)->toContain('ownership is never reassigned here');
    } finally {
        itdbCleanup($scratch);
    }
});

// =============================================================================
// The .env is read, never executed
// =============================================================================

it('reads the target .env without ever sourcing or eval-ing it', function () {
    $source = File::get(itdbScript());

    // Only real invocations count: the header prose legitimately promises the
    // file is "never eval'd".
    expect($source)->not->toMatch('/(^|[;&|(]\s*)eval\s/m');
    // The allowlist is load-bearing, not decorative: the reads are driven from
    // the array, so a seventh key cannot be introduced at a call site.
    expect($source)->toContain('ENV_KEYS=(DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD)');
    expect($source)->toContain('for key in "${ENV_KEYS[@]}"; do');
    expect($source)->toContain('read_env_keys "${SHARED_ENV}"');

    // The only `source` in the file is the installed common library.
    preg_match_all('/^\s*source\s+"?([^"\n]+)"?/m', $source, $matches);
    expect($matches[1])->toBe(['${COMMON_FILE}']);
});

it('cannot be made to execute code planted in the target .env', function () {
    $scratch = itdbScratchDir();

    try {
        itdbWriteStubs($scratch);
        itdbWriteEnv($scratch);
        itdbExistingDatabase($scratch);

        file_put_contents(
            $scratch.'/target/shared/.env',
            "DB_CONNECTION=pgsql\nDB_DATABASE=rateguru_staging\nDB_USERNAME=rateguru_staging_app\n"
                ."DB_PASSWORD=pw\nEVIL=\$(touch ".$scratch."/pwned)\n`touch ".$scratch."/pwned2`\n",
        );

        itdbRun($scratch, ['--verify', '--target', 'staging-main']);

        expect(file_exists($scratch.'/pwned'))->toBeFalse('the .env must never be executed as shell');
        expect(file_exists($scratch.'/pwned2'))->toBeFalse();
    } finally {
        itdbCleanup($scratch);
    }
});

it('fails closed when the registry and the .env disagree about the database identity', function () {
    $scratch = itdbScratchDir();

    try {
        itdbWriteStubs($scratch);
        itdbExistingDatabase($scratch);
        itdbWriteEnv($scratch, ['DB_DATABASE' => 'someone_elses_database']);

        [$exit, $output] = itdbRun($scratch, ['--verify', '--target', 'staging-main']);

        expect($exit)->toBe(1);
        expect($output)->toContain('drift: the registry declares database rateguru_staging');
        expect($output)->toContain('resolve the mismatch manually; nothing was changed');
    } finally {
        itdbCleanup($scratch);
    }
});

it('refuses a non-PostgreSQL connection rather than guessing', function () {
    $scratch = itdbScratchDir();

    try {
        itdbWriteStubs($scratch);
        itdbWriteEnv($scratch, ['DB_CONNECTION' => 'mariadb']);

        [$exit, $output] = itdbRun($scratch, ['--verify', '--target', 'staging-main']);

        expect($exit)->toBe(1);
        expect($output)->toContain('this installer manages PostgreSQL targets only');
    } finally {
        itdbCleanup($scratch);
    }
});

// =============================================================================
// CLI contract
// =============================================================================

it('requires --target, a mode and root', function () {
    $scratch = itdbScratchDir();

    try {
        itdbWriteStubs($scratch);
        itdbWriteEnv($scratch);

        [$exit, $output] = itdbRun($scratch, ['--apply']);
        expect($exit)->toBe(1);
        expect($output)->toContain('--target is required');

        [$exit, $output] = itdbRun($scratch, ['--target', 'staging-main']);
        expect($exit)->toBe(1);
        expect($output)->toContain('one of --check, --apply or --verify is required');

        [$exit, $output] = itdbRun($scratch, ['--apply', '--target', 'staging-main'], ['euid' => '1000']);
        expect($exit)->toBe(1);
        expect($output)->toContain('must run as root');
    } finally {
        itdbCleanup($scratch);
    }
});

it('rejects a lifecycle=planned target before touching PostgreSQL', function () {
    $scratch = itdbScratchDir();

    try {
        itdbWriteStubs($scratch);
        itdbWriteEnv($scratch);

        [$exit, $output] = itdbRun($scratch, ['--apply', '--target', 'tits-guru']);

        expect($exit)->toBe(1);
        expect($output)->toContain('lifecycle=planned, not active');
        expect(itdbLog($scratch))->toBe('');
    } finally {
        itdbCleanup($scratch);
    }
});
