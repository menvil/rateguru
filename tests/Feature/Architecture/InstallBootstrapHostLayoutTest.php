<?php

use Illuminate\Support\Facades\File;

/**
 * Phase 5 slice 5.3: infrastructure/scripts/install-bootstrap-host-layout —
 * users, groups and filesystem bootstrap for a clean RateGuru host.
 *
 * Every test executes the real, shipped script as a subprocess — never a
 * reimplementation — against a fully simulated host: fixture passwd/group
 * files, a fixture filesystem root the script maps every canonical path
 * onto (RATEGURU_HOSTLAYOUT_FS_ROOT), a layered stat stub that reads real
 * types/modes but fixture ownership (non-root tests cannot create files
 * owned by root or the contract accounts), and logging
 * install/chown/chmod/groupadd/useradd/usermod stubs that perform the real
 * filesystem work inside the scratch directory while recording every
 * invocation. All of it is injected through RATEGURU_HOSTLAYOUT_*
 * overrides the script only honors alongside
 * RATEGURU_ALLOW_TEST_OVERRIDES=true. Nothing here needs root and nothing
 * touches the CI runner's real accounts or directories.
 *
 * The profiles that matter mirror the real situations the installer must
 * serve: a clean Phase-5.2-compliant host (everything to create), the
 * current staging host with its one known drift (the target root not being
 * root-owned — remediated by chowning exactly that directory entry), and
 * an already compliant host (second --apply performs zero mutation).
 * tits-guru stays lifecycle=planned and must cause zero mutation of any
 * kind.
 */

// =============================================================================
// Harness
// =============================================================================

function hostLayoutScript(): string
{
    return base_path('infrastructure/scripts/install-bootstrap-host-layout');
}

function hostLayoutSource(): string
{
    return File::get(hostLayoutScript());
}

function hostLayoutScratchDir(): string
{
    $dir = sys_get_temp_dir().'/bootstrap-host-layout-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/bin', '/fs', '/log'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

function hostLayoutCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function hostLayoutRun(array $arguments, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(
        array_merge(['bash', hostLayoutScript()], $arguments),
        $descriptors,
        $pipes,
        null,
        $env,
    );

    expect($process)->not->toBeFalse('could not start install-bootstrap-host-layout subprocess');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

function hostLayoutWriteStub(string $path, string $content): void
{
    file_put_contents($path, $content);
    chmod($path, 0o755);
}

// =============================================================================
// Identity fixtures (fixture /etc/passwd and /etc/group)
// =============================================================================

/**
 * A clean Phase-5.2-compliant host: only the package-created prerequisite
 * accounts exist. No RateGuru identity has been created yet.
 */
function hostLayoutCleanPasswd(): string
{
    return implode("\n", [
        'root:x:0:0:root:/root:/bin/bash',
        'www-data:x:33:33::/var/www:/usr/sbin/nologin',
        'postgres:x:110:118::/var/lib/postgresql:/bin/bash',
    ])."\n";
}

function hostLayoutCleanGroup(): string
{
    return implode("\n", [
        'root:x:0:',
        'www-data:x:33:',
        'postgres:x:118:',
    ])."\n";
}

/**
 * The current staging host's identities: every slice 5.3 account, group and
 * membership already exists.
 */
function hostLayoutCompliantPasswd(): string
{
    return hostLayoutCleanPasswd().implode("\n", [
        'rateguru-staging:x:5001:5001::/home/www/rateguru/staging:/usr/sbin/nologin',
        'deploy-rateguru-staging:x:5002:5002::/home/deploy-rateguru-staging:/bin/bash',
    ])."\n";
}

function hostLayoutCompliantGroup(): string
{
    return hostLayoutCleanGroup().implode("\n", [
        'rateguru-staging:x:5001:',
        'deploy-rateguru-staging:x:5002:',
        'rateguru-staging-code:x:5010:rateguru-staging,deploy-rateguru-staging,www-data',
    ])."\n";
}

// =============================================================================
// Filesystem fixtures
// =============================================================================

/**
 * The slice 5.3 directory contract: logical path => [owner, group, mode].
 *
 * @return array<string, array{0: string, 1: string, 2: int}>
 */
function hostLayoutContractDirs(): array
{
    $deploy = 'deploy-rateguru-staging';
    $code = 'rateguru-staging-code';
    $runtime = 'rateguru-staging';

    return [
        '/home/www/rateguru' => ['root', 'root', 0o755],
        '/home/www/rateguru/config' => ['root', 'root', 0o755],
        '/home/www/rateguru/bin' => ['root', 'root', 0o755],
        '/home/www/rateguru/backups' => ['root', 'root', 0o700],
        '/home/www/rateguru/run' => ['root', 'root', 0o700],
        '/var/log/rateguru' => ['root', 'root', 0o750],
        '/home/www/rateguru/staging' => ['root', 'root', 0o755],
        '/home/www/rateguru/staging/releases' => [$deploy, $code, 0o2750],
        '/home/www/rateguru/staging/shared' => [$runtime, $runtime, 0o2770],
        '/home/www/rateguru/staging/shared/storage' => [$runtime, $runtime, 0o2770],
        '/home/www/rateguru/staging/locks' => [$deploy, $code, 0o2750],
        '/home/www/rateguru/staging/deployments' => [$deploy, $code, 0o2750],
        "/home/{$deploy}" => [$deploy, $deploy, 0o750],
        "/home/{$deploy}/.ssh" => [$deploy, $deploy, 0o700],
        "/home/{$deploy}/incoming" => [$deploy, $deploy, 0o750],
    ];
}

/**
 * Writes one fixture ownership-table row per physical path. The layered
 * stat stub serves owner/group from this table (real files in the scratch
 * are all owned by the test user) while type and mode stay real.
 *
 * @param  array<string, array{0: string, 1: string}>  $rows  physical => [owner, group]
 */
function hostLayoutWriteOwnerTable(string $scratch, array $rows): void
{
    $lines = '';

    foreach ($rows as $physical => [$owner, $group]) {
        $lines .= "{$physical}|{$owner}|{$group}\n";
    }

    file_put_contents($scratch.'/fs/owner-table.txt', $lines);
}

/**
 * @return array<string, array{0: string, 1: string}> physical => [owner, group]
 */
function hostLayoutOwnerTableRows(string $scratch): array
{
    $rows = [];

    foreach (explode("\n", (string) file_get_contents($scratch.'/fs/owner-table.txt')) as $line) {
        if ($line === '') {
            continue;
        }

        [$path, $owner, $group] = explode('|', $line);
        $rows[$path] = [$owner, $group];
    }

    return $rows;
}

/**
 * Builds the compliant on-disk tree (real directories with real modes) and
 * the ownership rows declaring the contract owners, plus the existing-data
 * sentinels every apply must leave byte-identical: a nested immutable
 * release, uploaded storage content, shared/.env, authorized_keys, a
 * backup archive, and the current/previous deployment symlinks.
 *
 * @return array<string, array{0: string, 1: string}> the ownership rows
 */
function hostLayoutBuildCompliantFilesystem(string $scratch): array
{
    $fs = $scratch.'/fs';
    $rows = [];

    foreach (hostLayoutContractDirs() as $logical => [$owner, $group, $mode]) {
        $physical = $fs.$logical;
        @mkdir($physical, 0o755, true);
        chmod($physical, $mode);
        $rows[$physical] = [$owner, $group];
    }

    @mkdir($fs.'/var/log', 0o755, true);

    $deploy = 'deploy-rateguru-staging';
    $staging = $fs.'/home/www/rateguru/staging';

    // Nested existing data — never re-owned, re-moded or rewritten through
    // a parent reconciliation.
    @mkdir($staging.'/releases/20240101120000/app', 0o755, true);
    chmod($staging.'/releases/20240101120000', 0o750);
    file_put_contents($staging.'/releases/20240101120000/app/config.php', "<?php // RELEASE-CODE-SENTINEL\n");
    chmod($staging.'/releases/20240101120000/app/config.php', 0o640);
    $rows[$staging.'/releases/20240101120000'] = [$deploy, 'rateguru-staging-code'];
    $rows[$staging.'/releases/20240101120000/app/config.php'] = [$deploy, 'rateguru-staging-code'];

    @mkdir($staging.'/shared/storage/app/public', 0o770, true);
    file_put_contents($staging.'/shared/storage/app/public/upload.jpg', 'JPEG-UPLOAD-SENTINEL');
    chmod($staging.'/shared/storage/app/public/upload.jpg', 0o640);
    $rows[$staging.'/shared/storage/app/public/upload.jpg'] = ['rateguru-staging', 'rateguru-staging'];

    file_put_contents($staging.'/shared/.env', "APP_KEY=base64:ENV-SECRET-SENTINEL-hunter2\n");
    chmod($staging.'/shared/.env', 0o640);
    $rows[$staging.'/shared/.env'] = ['rateguru-staging', 'rateguru-staging'];

    file_put_contents($fs."/home/{$deploy}/.ssh/authorized_keys", "ssh-ed25519 AAAA-DEPLOY-KEY-SENTINEL deploy\n");
    chmod($fs."/home/{$deploy}/.ssh/authorized_keys", 0o600);
    $rows[$fs."/home/{$deploy}/.ssh/authorized_keys"] = [$deploy, $deploy];

    file_put_contents($fs.'/home/www/rateguru/backups/db-20240101.tar.gz', 'BACKUP-HISTORY-SENTINEL');
    $rows[$fs.'/home/www/rateguru/backups/db-20240101.tar.gz'] = ['root', 'root'];

    symlink('releases/20240101120000', $staging.'/current');
    symlink('releases/20240101120000', $staging.'/previous');

    return $rows;
}

/**
 * The minimal clean-host filesystem: /home and /var/log exist (as on any
 * Ubuntu host), nothing RateGuru does.
 */
function hostLayoutBuildCleanFilesystem(string $scratch): void
{
    @mkdir($scratch.'/fs/home', 0o755, true);
    @mkdir($scratch.'/fs/var/log', 0o755, true);
}

// =============================================================================
// Stubs
// =============================================================================

function hostLayoutWriteStubs(string $scratch): void
{
    // stat: layered — type and mode from the real scratch filesystem, owner
    // and group from the fixture ownership table when a row exists.
    hostLayoutWriteStub($scratch.'/bin/stat', <<<'STUB'
        #!/bin/bash
        path="${!#}"
        if [[ -L "${path}" ]]; then
            ftype="symbolic link"
        elif [[ -d "${path}" ]]; then
            ftype="directory"
        elif [[ -f "${path}" ]]; then
            ftype="regular file"
        elif [[ -e "${path}" ]]; then
            ftype="other"
        else
            exit 1
        fi
        mode="$(PATH="${STUB_REAL_PATH}" stat -c '%a' -- "${path}" 2>/dev/null)" \
            || mode="$(PATH="${STUB_REAL_PATH}" stat -f '%Mp%Lp' "${path}" 2>/dev/null)" || exit 1
        mode="$(printf '%o' $(( 8#${mode} )))"
        row="$(PATH="${STUB_REAL_PATH}" awk -F'|' -v p="${path}" '$1 == p { print $2 "|" $3; found = 1; exit } END { exit !found }' "${STUB_OWNER_TABLE}" 2>/dev/null)" || row=""
        if [[ -z "${row}" ]]; then
            row="$(PATH="${STUB_REAL_PATH}" stat -c '%U|%G' -- "${path}" 2>/dev/null)" \
                || row="$(PATH="${STUB_REAL_PATH}" stat -f '%Su|%Sg' "${path}" 2>/dev/null)" || exit 1
        fi
        printf '%s|%s|%s\n' "${ftype}" "${row}" "${mode}"
        STUB);

    // chown: records the invocation and upserts the ownership row for the
    // exact path given — nested paths are untouched, exactly like a real
    // non-recursive chown.
    hostLayoutWriteStub($scratch.'/bin/chown', <<<'STUB'
        #!/bin/bash
        printf 'chown %s\n' "$*" >> "${STUB_LOG}/chown.log"
        owner_group=""
        path=""
        for arg in "$@"; do
            case "${arg}" in
                -*) ;;
                *)
                    if [[ -z "${owner_group}" ]]; then
                        owner_group="${arg}"
                    else
                        path="${arg}"
                    fi
                    ;;
            esac
        done
        owner="${owner_group%%:*}"
        group="${owner_group##*:}"
        tmp="${STUB_OWNER_TABLE}.tmp"
        PATH="${STUB_REAL_PATH}" awk -F'|' -v p="${path}" '$1 != p' "${STUB_OWNER_TABLE}" > "${tmp}" 2>/dev/null || : > "${tmp}"
        printf '%s|%s|%s\n' "${path}" "${owner}" "${group}" >> "${tmp}"
        PATH="${STUB_REAL_PATH}" mv "${tmp}" "${STUB_OWNER_TABLE}"
        exit 0
        STUB);

    // chmod/install: log and delegate to the real tools (mode changes work
    // for the test user's own scratch files).
    hostLayoutWriteStub($scratch.'/bin/chmod', <<<'STUB'
        #!/bin/bash
        printf 'chmod %s\n' "$*" >> "${STUB_LOG}/chmod.log"
        PATH="${STUB_REAL_PATH}" chmod "$@"
        STUB);

    hostLayoutWriteStub($scratch.'/bin/install', <<<'STUB'
        #!/bin/bash
        printf 'install %s\n' "$*" >> "${STUB_LOG}/install.log"
        PATH="${STUB_REAL_PATH}" install "$@"
        STUB);

    // groupadd/useradd/usermod: log and mutate the fixture group/passwd
    // files the way the real shadow tools mutate /etc — never deleting,
    // never renumbering.
    hostLayoutWriteStub($scratch.'/bin/groupadd', <<<'STUB'
        #!/bin/bash
        printf 'groupadd %s\n' "$*" >> "${STUB_LOG}/identity.log"
        name="${!#}"
        if PATH="${STUB_REAL_PATH}" grep -q "^${name}:" "${STUB_GROUP_FILE}"; then
            exit 9
        fi
        max="$(PATH="${STUB_REAL_PATH}" awk -F: 'BEGIN { m = 4999 } $3 > m && $3 < 60000 { m = $3 } END { print m }' "${STUB_GROUP_FILE}")"
        printf '%s:x:%s:\n' "${name}" "$((max + 1))" >> "${STUB_GROUP_FILE}"
        exit 0
        STUB);

    hostLayoutWriteStub($scratch.'/bin/useradd', <<<'STUB'
        #!/bin/bash
        printf 'useradd %s\n' "$*" >> "${STUB_LOG}/identity.log"
        login="${!#}"
        gid_name=""
        home=""
        shell=""
        prev=""
        for arg in "$@"; do
            case "${prev}" in
                --gid) gid_name="${arg}" ;;
                --home-dir) home="${arg}" ;;
                --shell) shell="${arg}" ;;
            esac
            prev="${arg}"
        done
        if PATH="${STUB_REAL_PATH}" grep -q "^${login}:" "${STUB_PASSWD_FILE}"; then
            exit 9
        fi
        gid="$(PATH="${STUB_REAL_PATH}" awk -F: -v g="${gid_name}" '$1 == g { print $3; exit }' "${STUB_GROUP_FILE}")"
        if [[ -z "${gid}" ]]; then
            exit 6
        fi
        max="$(PATH="${STUB_REAL_PATH}" awk -F: 'BEGIN { m = 4999 } $3 > m && $3 < 60000 { m = $3 } END { print m }' "${STUB_PASSWD_FILE}")"
        printf '%s:x:%s:%s::%s:%s\n' "${login}" "$((max + 1))" "${gid}" "${home}" "${shell}" >> "${STUB_PASSWD_FILE}"
        exit 0
        STUB);

    hostLayoutWriteStub($scratch.'/bin/usermod', <<<'STUB'
        #!/bin/bash
        printf 'usermod %s\n' "$*" >> "${STUB_LOG}/identity.log"
        login="${!#}"
        groups=""
        prev=""
        for arg in "$@"; do
            if [[ "${prev}" == "--groups" || "${prev}" == "-G" ]]; then
                groups="${arg}"
            fi
            prev="${arg}"
        done
        tmp="${STUB_GROUP_FILE}.tmp"
        PATH="${STUB_REAL_PATH}" awk -F: -v OFS=: -v g="${groups}" -v u="${login}" '
            $1 == g {
                if ($4 == "") { $4 = u }
                else if (index("," $4 ",", "," u ",") == 0) { $4 = $4 "," u }
            }
            { print }
        ' "${STUB_GROUP_FILE}" > "${tmp}"
        PATH="${STUB_REAL_PATH}" mv "${tmp}" "${STUB_GROUP_FILE}"
        exit 0
        STUB);
}

// =============================================================================
// Fixture composition
// =============================================================================

/**
 * Build a fully simulated host and return the environment to run the
 * script against it. The default profile is the compliant current staging
 * host; every option knocks one aspect back toward a clean or broken one.
 *
 * Options:
 *   euid:        string (default '0')
 *   profile:     'compliant' | 'clean' — identities and filesystem together
 *   passwd:      fixture /etc/passwd content override
 *   group:       fixture /etc/group content override
 *   registry:    JSON string override (default: the committed registry)
 *   targetsCli:  'real' (default) | 'stub-pass' — an always-passing stub,
 *                for scenarios exercising the installer's own containment
 *                checks on registries the real validator would reject
 *   ownerRows:   array<string physical, array{0:string,1:string}> merged
 *                over the profile's rows (set a row to fake drift)
 *
 * @param  array<string, mixed>  $options
 * @return array<string, string>
 */
function hostLayoutFixture(string $scratch, array $options = []): array
{
    $fs = $scratch.'/fs';
    $profile = $options['profile'] ?? 'compliant';

    if ($profile === 'compliant') {
        $rows = hostLayoutBuildCompliantFilesystem($scratch);
        $passwd = hostLayoutCompliantPasswd();
        $group = hostLayoutCompliantGroup();
    } else {
        hostLayoutBuildCleanFilesystem($scratch);
        $rows = [];
        $passwd = hostLayoutCleanPasswd();
        $group = hostLayoutCleanGroup();
    }

    foreach ($options['ownerRows'] ?? [] as $physical => $ownerGroup) {
        $rows[$physical] = $ownerGroup;
    }

    hostLayoutWriteOwnerTable($scratch, $rows);

    file_put_contents($fs.'/etc-passwd', $options['passwd'] ?? $passwd);
    file_put_contents($fs.'/etc-group', $options['group'] ?? $group);

    file_put_contents(
        $fs.'/deployment-targets.json',
        $options['registry'] ?? File::get(base_path('infrastructure/config/deployment-targets.json')),
    );

    hostLayoutWriteStubs($scratch);

    $env = [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_HOSTLAYOUT_EUID' => $options['euid'] ?? '0',
        'RATEGURU_HOSTLAYOUT_FS_ROOT' => $fs,
        'RATEGURU_HOSTLAYOUT_PASSWD_FILE' => $fs.'/etc-passwd',
        'RATEGURU_HOSTLAYOUT_GROUP_FILE' => $fs.'/etc-group',
        'RATEGURU_HOSTLAYOUT_SOURCE_REGISTRY' => $fs.'/deployment-targets.json',
        'RATEGURU_HOSTLAYOUT_STAT_BIN' => $scratch.'/bin/stat',
        'RATEGURU_HOSTLAYOUT_INSTALL_BIN' => $scratch.'/bin/install',
        'RATEGURU_HOSTLAYOUT_CHOWN_BIN' => $scratch.'/bin/chown',
        'RATEGURU_HOSTLAYOUT_CHMOD_BIN' => $scratch.'/bin/chmod',
        'RATEGURU_HOSTLAYOUT_GROUPADD_BIN' => $scratch.'/bin/groupadd',
        'RATEGURU_HOSTLAYOUT_USERADD_BIN' => $scratch.'/bin/useradd',
        'RATEGURU_HOSTLAYOUT_USERMOD_BIN' => $scratch.'/bin/usermod',
        'STUB_LOG' => $scratch.'/log',
        'STUB_REAL_PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'STUB_OWNER_TABLE' => $fs.'/owner-table.txt',
        'STUB_PASSWD_FILE' => $fs.'/etc-passwd',
        'STUB_GROUP_FILE' => $fs.'/etc-group',
    ];

    if (($options['targetsCli'] ?? 'real') === 'stub-pass') {
        hostLayoutWriteStub($scratch.'/bin/targets', "#!/bin/bash\nexit 0\n");
        $env['RATEGURU_TARGETS_CLI'] = $scratch.'/bin/targets';
    }

    return $env;
}

/**
 * A committed-registry variant with one active-target field replaced.
 *
 * @param  array<string, mixed>  $overrides  dot-free key => value for staging-main
 */
function hostLayoutRegistryWith(array $overrides): string
{
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true, 512, JSON_THROW_ON_ERROR);

    foreach ($overrides as $key => $value) {
        $registry['targets']['staging-main'][$key] = $value;
    }

    return json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
}

/**
 * A committed-registry variant with one field replaced on a NAMED target.
 *
 * @param  array<string, mixed>  $overrides  dot-free key => value
 */
function hostLayoutRegistryWithOn(string $targetId, array $overrides): string
{
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true, 512, JSON_THROW_ON_ERROR);

    foreach ($overrides as $key => $value) {
        $registry['targets'][$targetId][$key] = $value;
    }

    return json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
}

/**
 * Content + structure snapshot for mutation-free proofs.
 *
 * @return array<string, string>
 */
function hostLayoutTreeSnapshot(string $dir): array
{
    $snapshot = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $entry) {
        $path = $entry->getPathname();

        if (is_link($path)) {
            $snapshot[$path] = 'link:'.readlink($path);
        } elseif ($entry->isFile()) {
            $snapshot[$path] = md5_file($path).':'.substr(sprintf('%o', fileperms($path)), -4);
        } else {
            $snapshot[$path] = 'dir:'.substr(sprintf('%o', fileperms($path)), -4);
        }
    }

    ksort($snapshot);

    return $snapshot;
}

function hostLayoutLog(string $scratch, string $name): string
{
    $path = $scratch.'/log/'.$name;

    return is_file($path) ? (string) file_get_contents($path) : '';
}

function hostLayoutMode(string $path): int
{
    clearstatcache(true, $path);

    return fileperms($path) & 0o7777;
}

// =============================================================================
// CLI contract
// =============================================================================

it('prints usage on --help and rejects unknown, missing or duplicated modes', function () {
    $env = ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'];

    [$exit, $output] = hostLayoutRun(['--help'], $env);
    expect($exit)->toBe(0);
    expect($output)->toContain('--check')->toContain('--apply')->toContain('--verify')->toContain('root');

    [$exit, $output] = hostLayoutRun([], $env);
    expect($exit)->toBe(1);
    expect($output)->toContain('one of --check, --apply or --verify is required');

    [$exit, $output] = hostLayoutRun(['--check', '--verify'], $env);
    expect($exit)->toBe(1);
    expect($output)->toContain('mode given more than once');

    [$exit, $output] = hostLayoutRun(['--frobnicate'], $env);
    expect($exit)->toBe(1);
    expect($output)->toContain('unknown argument: --frobnicate');
});

it('requires root for every mode and mutates nothing without it', function () {
    $scratch = hostLayoutScratchDir();

    try {
        $env = hostLayoutFixture($scratch, ['profile' => 'clean', 'euid' => '1000']);
        $before = hostLayoutTreeSnapshot($scratch);

        foreach (['--check', '--apply', '--verify'] as $mode) {
            [$exit, $output] = hostLayoutRun([$mode], $env);

            expect($exit)->toBe(1);
            expect($output)->toContain(substr($mode, 2).' must run as root');
        }

        expect(hostLayoutTreeSnapshot($scratch))->toBe($before, 'a non-root invocation mutated the fixture');
    } finally {
        hostLayoutCleanup($scratch);
    }
});

// =============================================================================
// --check
// =============================================================================

it('recognizes the compliant staging host as satisfied and reports the planned target as deliberately skipped', function () {
    $scratch = hostLayoutScratchDir();

    try {
        $env = hostLayoutFixture($scratch);
        [$exit, $output] = hostLayoutRun(['--check'], $env);

        expect($exit)->toBe(0, "a compliant host must satisfy --check:\n{$output}");
        expect($output)->toContain('SLICE 5.3 CONTRACT: SATISFIED');
        expect($output)->toContain('PASS     registry:source — valid (1 active target(s): staging-main)');
        expect($output)->toContain('PASS     target:staging-main — lifecycle=active');
        expect($output)->toContain('PASS     target:tits-guru — lifecycle=planned — not provisioned by this slice');
        expect($output)->toContain('PASS     prerequisite:user:www-data');
        expect($output)->toContain('PASS     prerequisite:user:postgres');
        expect($output)->toContain('PASS     group:rateguru-staging — exists');
        expect($output)->toContain('PASS     group:rateguru-staging-code — exists');
        expect($output)->toContain('PASS     group:deploy-rateguru-staging — exists');
        expect($output)->toContain('PASS     user:rateguru-staging — exists (primary group rateguru-staging');
        expect($output)->toContain('PASS     user:deploy-rateguru-staging — exists (primary group deploy-rateguru-staging');
        expect($output)->toContain('PASS     membership:rateguru-staging:rateguru-staging-code');
        expect($output)->toContain('PASS     path:/home/www/rateguru — directory, root:root, mode 755');
        expect($output)->toContain('PASS     path:/home/www/rateguru/staging/releases — directory, deploy-rateguru-staging:rateguru-staging-code, mode 2750');
        expect($output)->toContain('PASS     path:/home/www/rateguru/staging/shared — directory, rateguru-staging:rateguru-staging, mode 2770');
        expect($output)->toContain('PASS     path:/home/www/rateguru/staging/current — symbolic link');
        expect($output)->toContain("MISSING: 0\n");
        expect($output)->toContain("CONFLICT: 0\n");

        // No tits-guru identity or path is ever demanded.
        expect($output)->not->toContain('rateguru-tits-guru');
        expect($output)->not->toContain('/home/www/rateguru/production');
    } finally {
        hostLayoutCleanup($scratch);
    }
});

it('reports the full work list on a clean Phase-5.2-compliant host and exits non-zero', function () {
    $scratch = hostLayoutScratchDir();

    try {
        $env = hostLayoutFixture($scratch, ['profile' => 'clean']);
        [$exit, $output] = hostLayoutRun(['--check'], $env);

        expect($exit)->toBe(1, "a clean host cannot satisfy the slice 5.3 contract:\n{$output}");
        expect($output)->toContain('SLICE 5.3 CONTRACT: NOT SATISFIED');

        // Prerequisites are present (5.2 completed); identities and the
        // filesystem are the work list.
        expect($output)->toContain('PASS     prerequisite:user:www-data');
        expect($output)->toContain('MISSING  group:rateguru-staging — missing');
        expect($output)->toContain('MISSING  group:rateguru-staging-code — missing');
        expect($output)->toContain('MISSING  group:deploy-rateguru-staging — missing');
        expect($output)->toContain('MISSING  user:rateguru-staging — missing');
        expect($output)->toContain('MISSING  user:deploy-rateguru-staging — missing');
        expect($output)->toContain('MISSING  membership:rateguru-staging:rateguru-staging-code — user or group absent');
        expect($output)->toContain('MISSING  path:/home/www/rateguru — absent');
        expect($output)->toContain('MISSING  path:/home/www/rateguru/staging — absent');
        expect($output)->toContain('MISSING  path:/home/www/rateguru/staging/shared/storage — absent');
        expect($output)->toContain('MISSING  path:/home/deploy-rateguru-staging/.ssh — absent');
        expect($output)->toContain('MISSING  path:/home/deploy-rateguru-staging/incoming — absent');
        expect($output)->toContain('MISSING  path:/var/log/rateguru — absent');

        // Intended actions annotate every unsatisfied item.
        expect($output)->toContain('-> apply: groupadd rateguru-staging-code');
        expect($output)->toContain('-> apply: useradd with primary group rateguru-staging, shell /usr/sbin/nologin (no password login; never renumbered later)');
        expect($output)->toContain('-> apply: useradd with primary group deploy-rateguru-staging, shell /bin/bash (no password login; never renumbered later)');
        expect($output)->toContain('-> apply: create the accounts, then usermod --append --groups rateguru-staging-code rateguru-staging');
        expect($output)->toContain('-> apply: install -d mode 2750, chown deploy-rateguru-staging:rateguru-staging-code — this directory entry only');

        // current/previous stay deployment-owned: absent is PASS, and no
        // action ever proposes fabricating them.
        expect($output)->toContain('PASS     path:/home/www/rateguru/staging/current — absent');
        expect($output)->toContain('PASS     path:/home/www/rateguru/staging/previous — absent');

        // The planned target contributes nothing to the work list.
        expect($output)->toContain('PASS     target:tits-guru — lifecycle=planned');
        expect($output)->not->toContain('rateguru-tits-guru');
        expect($output)->not->toContain('/home/www/rateguru/production');
    } finally {
        hostLayoutCleanup($scratch);
    }
});

it('reports each missing identity aspect independently', function () {
    // Missing code group only.
    $scratch = hostLayoutScratchDir();

    try {
        $group = str_replace("rateguru-staging-code:x:5010:rateguru-staging,deploy-rateguru-staging,www-data\n", '', hostLayoutCompliantGroup());
        $env = hostLayoutFixture($scratch, ['group' => $group]);
        [$exit, $output] = hostLayoutRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  group:rateguru-staging-code — missing');
        expect($output)->toContain('PASS     group:rateguru-staging — exists');
        expect($output)->toContain('MISSING  membership:rateguru-staging:rateguru-staging-code — user or group absent');
    } finally {
        hostLayoutCleanup($scratch);
    }

    // Missing membership only.
    $scratch = hostLayoutScratchDir();

    try {
        $group = str_replace(
            'rateguru-staging-code:x:5010:rateguru-staging,deploy-rateguru-staging,www-data',
            'rateguru-staging-code:x:5010:deploy-rateguru-staging,www-data',
            hostLayoutCompliantGroup(),
        );
        $env = hostLayoutFixture($scratch, ['group' => $group]);
        [$exit, $output] = hostLayoutRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  membership:rateguru-staging:rateguru-staging-code — rateguru-staging is not a member');
    } finally {
        hostLayoutCleanup($scratch);
    }

    // Missing runtime user only (its home/dirs exist).
    $scratch = hostLayoutScratchDir();

    try {
        $passwd = str_replace("rateguru-staging:x:5001:5001::/home/www/rateguru/staging:/usr/sbin/nologin\n", '', hostLayoutCompliantPasswd());
        $env = hostLayoutFixture($scratch, ['passwd' => $passwd]);
        [$exit, $output] = hostLayoutRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  user:rateguru-staging — missing');
        expect($output)->toContain('PASS     user:deploy-rateguru-staging — exists');
    } finally {
        hostLayoutCleanup($scratch);
    }

    // Missing deploy user only.
    $scratch = hostLayoutScratchDir();

    try {
        $passwd = str_replace("deploy-rateguru-staging:x:5002:5002::/home/deploy-rateguru-staging:/bin/bash\n", '', hostLayoutCompliantPasswd());
        $env = hostLayoutFixture($scratch, ['passwd' => $passwd]);
        [$exit, $output] = hostLayoutRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  user:deploy-rateguru-staging — missing');
    } finally {
        hostLayoutCleanup($scratch);
    }
});

it('fails when a package-created prerequisite account is absent, naming the incomplete 5.2 runtime', function () {
    $scratch = hostLayoutScratchDir();

    try {
        $passwd = str_replace("www-data:x:33:33::/var/www:/usr/sbin/nologin\n", '', hostLayoutCompliantPasswd());
        $group = str_replace("www-data:x:33:\n", '', hostLayoutCompliantGroup());
        $env = hostLayoutFixture($scratch, ['passwd' => $passwd, 'group' => $group]);

        [$exit, $output] = hostLayoutRun(['--check'], $env);
        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  prerequisite:user:www-data — missing — the Phase 5.2 runtime prerequisite is incomplete');
        expect($output)->toContain('MISSING  prerequisite:group:www-data — missing');
        expect($output)->toContain('-> apply: run install-bootstrap-runtime --apply (slice 5.2) first — package-owned accounts are never created here');

        // --apply fails closed before any mutation.
        $before = hostLayoutTreeSnapshot($scratch);
        [$exit, $output] = hostLayoutRun(['--apply'], $env);
        expect($exit)->toBe(1);
        expect($output)->toContain("ERROR: package-created account 'www-data' is missing — the Phase 5.2 runtime prerequisite is incomplete");
        expect(hostLayoutTreeSnapshot($scratch))->toBe($before);
        expect(hostLayoutLog($scratch, 'identity.log'))->toBe('');
    } finally {
        hostLayoutCleanup($scratch);
    }
});

it('flags every structural filesystem drift as CONFLICT with an entry-only remediation', function () {
    $scratch = hostLayoutScratchDir();

    try {
        $fs = $scratch.'/fs';
        $env = hostLayoutFixture($scratch, ['ownerRows' => [
            // The real staging drift: the target root is not root-owned.
            $fs.'/home/www/rateguru/staging' => ['deploy-rateguru-staging', 'rateguru-staging-code'],
            // Releases with a wrong group.
            $fs.'/home/www/rateguru/staging/releases' => ['deploy-rateguru-staging', 'rateguru-staging'],
        ]]);

        // Wrong modes on shared, incoming and .ssh; group-writable home.
        chmod($fs.'/home/www/rateguru/staging/shared', 0o770);
        chmod($fs.'/home/deploy-rateguru-staging/incoming', 0o755);
        chmod($fs.'/home/deploy-rateguru-staging/.ssh', 0o750);
        chmod($fs.'/home/deploy-rateguru-staging', 0o775);

        [$exit, $output] = hostLayoutRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT path:/home/www/rateguru/staging — owned by deploy-rateguru-staging:rateguru-staging-code, required root:root');
        expect($output)->toContain('-> apply: chown root:root on this directory entry only (never recursive; contents keep their ownership)');
        expect($output)->toContain('CONFLICT path:/home/www/rateguru/staging/releases — owned by deploy-rateguru-staging:rateguru-staging, required deploy-rateguru-staging:rateguru-staging-code');
        expect($output)->toContain('CONFLICT path:/home/www/rateguru/staging/shared — mode 770, required 2770');
        expect($output)->toContain('CONFLICT path:/home/deploy-rateguru-staging/incoming — mode 755, required 0750');
        expect($output)->toContain('CONFLICT path:/home/deploy-rateguru-staging/.ssh — mode 750, required 0700');
        expect($output)->toContain('CONFLICT path:/home/deploy-rateguru-staging — mode 775 is group- or other-writable');
    } finally {
        hostLayoutCleanup($scratch);
    }
});

it('keeps --check and --verify strictly read-only', function () {
    $scratch = hostLayoutScratchDir();

    try {
        $env = hostLayoutFixture($scratch, ['ownerRows' => [
            $scratch.'/fs/home/www/rateguru/staging' => ['deploy-rateguru-staging', 'rateguru-staging-code'],
        ]]);
        $before = hostLayoutTreeSnapshot($scratch);

        [$checkExit] = hostLayoutRun(['--check'], $env);
        [$verifyExit] = hostLayoutRun(['--verify'], $env);

        expect($checkExit)->toBe(1);
        expect($verifyExit)->toBe(1);
        expect(hostLayoutTreeSnapshot($scratch))->toBe($before, '--check/--verify mutated the fixture');

        foreach (['identity.log', 'install.log', 'chown.log', 'chmod.log'] as $log) {
            expect(hostLayoutLog($scratch, $log))->toBe('', "--check/--verify invoked a mutation tool ({$log})");
        }
    } finally {
        hostLayoutCleanup($scratch);
    }
});

// =============================================================================
// --apply
// =============================================================================

it('bootstraps a clean host: groups, users, membership and the full tree in dependency order, ending satisfied', function () {
    $scratch = hostLayoutScratchDir();

    try {
        $fs = $scratch.'/fs';
        $env = hostLayoutFixture($scratch, ['profile' => 'clean']);
        [$exit, $output] = hostLayoutRun(['--apply'], $env);

        expect($exit)->toBe(0, "clean-host apply must converge and verify:\n{$output}");
        expect($output)->toContain('APPLY    validating the entire plan before any mutation');
        expect($output)->toContain('SLICE 5.3 CONTRACT: SATISFIED');

        // Identity dependency order: all groups strictly before users,
        // users before the membership append.
        $identityLog = hostLayoutLog($scratch, 'identity.log');
        $order = [
            'groupadd --system rateguru-staging',
            'groupadd --system rateguru-staging-code',
            'groupadd deploy-rateguru-staging',
            'useradd --system --gid rateguru-staging --no-create-home --home-dir /home/www/rateguru/staging --shell /usr/sbin/nologin rateguru-staging',
            'useradd --gid deploy-rateguru-staging --no-create-home --home-dir /home/deploy-rateguru-staging --shell /bin/bash deploy-rateguru-staging',
            'usermod --append --groups rateguru-staging-code rateguru-staging',
        ];
        $previous = -1;

        foreach ($order as $needle) {
            $position = strpos($identityLog, $needle);
            expect($position)->not->toBeFalse("missing identity mutation: {$needle}\n{$identityLog}");
            expect($position)->toBeGreaterThan($previous, "identity mutation out of order: {$needle}");
            $previous = $position;
        }

        // The fixture account database now carries the full contract,
        // including the canonical shells and the canonical deploy home.
        $group = file_get_contents($fs.'/etc-group');
        expect($group)->toContain('rateguru-staging-code');
        $passwd = file_get_contents($fs.'/etc-passwd');
        expect($passwd)->toContain('rateguru-staging');
        expect($passwd)->toContain(':/home/www/rateguru/staging:/usr/sbin/nologin');
        expect($passwd)->toContain(':/home/deploy-rateguru-staging:/bin/bash');

        // Real directories with exact modes, including setgid bits.
        foreach (hostLayoutContractDirs() as $logical => [, , $mode]) {
            expect(is_dir($fs.$logical))->toBeTrue("missing directory: {$logical}");
            expect(hostLayoutMode($fs.$logical))->toBe($mode, sprintf('%s has mode %o, expected %o', $logical, hostLayoutMode($fs.$logical), $mode));
        }

        // Ownership rows match the contract for every managed directory.
        $rows = hostLayoutOwnerTableRows($scratch);

        foreach (hostLayoutContractDirs() as $logical => [$owner, $groupName]) {
            expect($rows[$fs.$logical] ?? null)->toBe([$owner, $groupName], "wrong ownership for {$logical}");
        }

        // The namespace parent was created root-owned; deployment state and
        // secret material were never fabricated.
        expect($rows[$fs.'/home/www'] ?? null)->toBe(['root', 'root']);
        expect(hostLayoutMode($fs.'/home/www'))->toBe(0o755);
        expect(file_exists($fs.'/home/deploy-rateguru-staging/.ssh/authorized_keys'))->toBeFalse('a fake authorized_keys must never be created');
        expect(file_exists($fs.'/home/www/rateguru/staging/current'))->toBeFalse('current must never be fabricated');
        expect(file_exists($fs.'/home/www/rateguru/staging/previous'))->toBeFalse('previous must never be fabricated');

        // Zero mutation for the planned target.
        expect($identityLog)->not->toContain('tits-guru');
        expect(file_get_contents($fs.'/etc-passwd'))->not->toContain('tits-guru');
        expect(file_get_contents($fs.'/etc-group'))->not->toContain('tits-guru');
        expect(file_exists($fs.'/home/www/rateguru/production'))->toBeFalse('the planned target tree must never be created');
        expect(file_exists($fs.'/home/deploy-rateguru-tits-guru'))->toBeFalse('the planned deploy home must never be created');
    } finally {
        hostLayoutCleanup($scratch);
    }
});

it('remediates the real staging target-root drift — deploy:code 2750 — to exactly root:root 0755', function () {
    $scratch = hostLayoutScratchDir();

    try {
        $fs = $scratch.'/fs';
        $staging = $fs.'/home/www/rateguru/staging';
        $env = hostLayoutFixture($scratch, ['ownerRows' => [
            $staging => ['deploy-rateguru-staging', 'rateguru-staging-code'],
        ]]);

        // The ACTUAL pre-apply state of the real staging VPS: the target
        // root was deploy:code with setgid mode 2750 — the case where a
        // plain numeric `chmod 0755` under GNU coreutils preserves the
        // directory setgid bit and yields 2755, failing the closing verify.
        chmod($staging, 0o2750);
        expect(hostLayoutMode($staging))->toBe(0o2750, 'fixture must reproduce the real 2750 staging mode');

        $before = hostLayoutTreeSnapshot($scratch.'/fs/home/www/rateguru/staging');
        $rowsBefore = hostLayoutOwnerTableRows($scratch);

        [$exit, $output] = hostLayoutRun(['--apply'], $env);

        expect($exit)->toBe(0, "the drift remediation must converge and pass its own closing verify:\n{$output}");
        expect($output)->toContain('APPLY    path:/home/www/rateguru/staging reconciling ownership deploy-rateguru-staging:rateguru-staging-code -> root:root (this directory entry only, never recursive)');
        expect($output)->toContain('APPLY    path:/home/www/rateguru/staging reconciling mode 2750 -> 0755 (this directory entry only, never recursive)');
        expect($output)->toContain('SLICE 5.3 CONTRACT: SATISFIED');

        // Exactly one chown and one complete-mode-replacing chmod, of
        // exactly the drifted entry; nothing else ran. The `=` operator
        // numeric mode is what clears the setgid bit — the final mode is
        // exactly 0755, never 2755.
        expect(hostLayoutLog($scratch, 'chown.log'))->toBe("chown -- root:root {$staging}\n");
        expect(hostLayoutLog($scratch, 'chmod.log'))->toBe("chmod =0755 {$staging}\n");
        expect(hostLayoutLog($scratch, 'install.log'))->toBe('');
        expect(hostLayoutLog($scratch, 'identity.log'))->toBe('');
        expect(hostLayoutMode($staging))->toBe(0o755, sprintf('target root ended mode %o — GNU chmod preserved the setgid bit', hostLayoutMode($staging)));

        // Every nested sentinel — immutable release code, uploaded storage,
        // .env, current/previous — kept its bytes, mode and ownership; only
        // the parent's own ownership row changed.
        $after = hostLayoutTreeSnapshot($scratch.'/fs/home/www/rateguru/staging');
        expect($after)->toBe($before, 'apply must not modify anything beneath the drifted target root');

        $rowsAfter = hostLayoutOwnerTableRows($scratch);
        unset($rowsBefore[$staging], $rowsAfter[$staging]);
        expect($rowsAfter)->toBe($rowsBefore, 'apply re-owned something other than the drifted directory entry');

        // Secret material and history sentinels stayed byte-identical.
        expect(file_get_contents($staging.'/shared/.env'))->toContain('ENV-SECRET-SENTINEL');
        expect(file_get_contents($staging.'/releases/20240101120000/app/config.php'))->toContain('RELEASE-CODE-SENTINEL');
        expect(file_get_contents($fs.'/home/deploy-rateguru-staging/.ssh/authorized_keys'))->toContain('DEPLOY-KEY-SENTINEL');
        expect(file_get_contents($fs.'/home/www/rateguru/backups/db-20240101.tar.gz'))->toBe('BACKUP-HISTORY-SENTINEL');
        expect(readlink($staging.'/current'))->toBe('releases/20240101120000');
        expect(readlink($staging.'/previous'))->toBe('releases/20240101120000');

        // No endless 2755 cycle (verify sees drift -> apply chmods -> mode
        // unchanged -> verify fails again): after exact reconciliation a
        // second apply performs zero mutation and still verifies clean.
        foreach (['identity.log', 'install.log', 'chown.log', 'chmod.log'] as $log) {
            @unlink($scratch.'/log/'.$log);
        }

        [$secondExit, $secondOutput] = hostLayoutRun(['--apply'], $env);
        expect($secondExit)->toBe(0, "second apply after exact reconciliation must verify clean:\n{$secondOutput}");

        foreach (['identity.log', 'install.log', 'chown.log', 'chmod.log'] as $log) {
            expect(hostLayoutLog($scratch, $log))->toBe('', "second apply mutated again ({$log}) — exact-mode convergence did not stick");
        }
    } finally {
        hostLayoutCleanup($scratch);
    }
});

it('converges exact directory modes in both directions of the setgid bit', function () {
    // Real-filesystem matrix through the delegating GNU chmod stub: exact
    // convergence must clear an unwanted setgid, set an intentional one,
    // and strip unwanted permission bits while keeping the expected setgid.
    foreach ([
        // [logical path, pre-apply mode, contract mode string, exact final mode]
        ['/home/www/rateguru/staging', 0o2750, '0755', 0o755],
        ['/home/www/rateguru/staging', 0o2755, '0755', 0o755],
        ['/home/www/rateguru/staging/releases', 0o755, '2750', 0o2750],
        ['/home/www/rateguru/staging/shared', 0o755, '2770', 0o2770],
        ['/home/www/rateguru/staging/shared', 0o2775, '2770', 0o2770],
    ] as [$logical, $preMode, $contractMode, $finalMode]) {
        $scratch = hostLayoutScratchDir();

        try {
            $env = hostLayoutFixture($scratch);
            $physical = $scratch.'/fs'.$logical;

            chmod($physical, $preMode);

            [$exit, $output] = hostLayoutRun(['--apply'], $env);

            expect($exit)->toBe(0, sprintf(
                "apply must converge %s from %o to exactly %o:\n%s",
                $logical,
                $preMode,
                $finalMode,
                $output,
            ));
            expect(hostLayoutLog($scratch, 'chmod.log'))->toBe(
                "chmod ={$contractMode} {$physical}\n",
                'exact convergence must replace the complete mode via the = operator, exactly once',
            );
            expect(hostLayoutMode($physical))->toBe($finalMode, sprintf(
                '%s ended mode %o after converging %o -> %s',
                $logical,
                hostLayoutMode($physical),
                $preMode,
                $contractMode,
            ));

            // Mode-only drift: ownership was already compliant.
            expect(hostLayoutLog($scratch, 'chown.log'))->toBe('');
            expect(hostLayoutLog($scratch, 'install.log'))->toBe('');
        } finally {
            hostLayoutCleanup($scratch);
        }
    }
});

it('creates exact-mode directories even beneath a setgid parent: the contract, not inheritance, decides', function () {
    $scratch = hostLayoutScratchDir();

    try {
        $fs = $scratch.'/fs';
        $env = hostLayoutFixture($scratch, ['profile' => 'clean']);

        // A pre-existing setgid /home/www: mkdir-level group inheritance
        // would mark every child setgid unless creation pins exact modes.
        expect(@mkdir($fs.'/home/www', 0o755, true))->toBeTrue();
        chmod($fs.'/home/www', 0o2775);
        expect(hostLayoutMode($fs.'/home/www'))->toBe(0o2775, 'fixture parent must carry setgid');

        [$exit, $output] = hostLayoutRun(['--apply'], $env);

        expect($exit)->toBe(0, "clean-host apply beneath a setgid parent must converge:\n{$output}");
        expect($output)->toContain('SLICE 5.3 CONTRACT: SATISFIED');

        // Every created directory ends with its exact contract mode — a
        // plain 0755 child under the setgid parent must be 0755, not 2755,
        // and the intentional setgid trees are exactly 2750/2770.
        foreach (hostLayoutContractDirs() as $logical => [, , $mode]) {
            expect(hostLayoutMode($fs.$logical))->toBe($mode, sprintf(
                '%s ended mode %o under a setgid ancestor, expected %o',
                $logical,
                hostLayoutMode($fs.$logical),
                $mode,
            ));
        }

        // The unmanaged existing parent itself is left exactly as it was.
        expect(hostLayoutMode($fs.'/home/www'))->toBe(0o2775, 'the pre-existing unmanaged /home/www must never be re-moded');
    } finally {
        hostLayoutCleanup($scratch);
    }
});

it('is idempotent: a second --apply performs zero mutation of any kind', function () {
    $scratch = hostLayoutScratchDir();

    try {
        $env = hostLayoutFixture($scratch, ['profile' => 'clean']);

        [$firstExit] = hostLayoutRun(['--apply'], $env);
        expect($firstExit)->toBe(0);

        foreach (['identity.log', 'install.log', 'chown.log', 'chmod.log'] as $log) {
            @unlink($scratch.'/log/'.$log);
        }

        $before = hostLayoutTreeSnapshot($scratch.'/fs');
        [$secondExit, $output] = hostLayoutRun(['--apply'], $env);

        expect($secondExit)->toBe(0, "a second apply on a compliant host must verify clean:\n{$output}");
        expect($output)->toContain('SLICE 5.3 CONTRACT: SATISFIED');

        foreach (['identity.log', 'install.log', 'chown.log', 'chmod.log'] as $log) {
            expect(hostLayoutLog($scratch, $log))->toBe('', "second apply invoked a mutation tool ({$log})");
        }

        expect(hostLayoutTreeSnapshot($scratch.'/fs'))->toBe($before, 'second apply changed the filesystem');
    } finally {
        hostLayoutCleanup($scratch);
    }
});

it('appends only the missing code-group membership on an otherwise compliant host', function () {
    $scratch = hostLayoutScratchDir();

    try {
        $group = str_replace(
            'rateguru-staging-code:x:5010:rateguru-staging,deploy-rateguru-staging,www-data',
            'rateguru-staging-code:x:5010:deploy-rateguru-staging,www-data',
            hostLayoutCompliantGroup(),
        );
        $env = hostLayoutFixture($scratch, ['group' => $group]);

        [$exit, $output] = hostLayoutRun(['--apply'], $env);

        expect($exit)->toBe(0, $output);
        expect(hostLayoutLog($scratch, 'identity.log'))
            ->toBe("usermod --append --groups rateguru-staging-code rateguru-staging\n");
        expect(file_get_contents($scratch.'/fs/etc-group'))
            ->toContain('rateguru-staging-code:x:5010:deploy-rateguru-staging,www-data,rateguru-staging');
    } finally {
        hostLayoutCleanup($scratch);
    }
});

it('accepts a safe existing deploy home mode and remediates only a group/other-writable one', function () {
    // 0755 is not the freshly-created 0750, but carries no group/other
    // write bit — accepted, never churned.
    $scratch = hostLayoutScratchDir();

    try {
        $env = hostLayoutFixture($scratch);
        chmod($scratch.'/fs/home/deploy-rateguru-staging', 0o755);

        [$exit] = hostLayoutRun(['--apply'], $env);

        expect($exit)->toBe(0);
        expect(hostLayoutLog($scratch, 'chmod.log'))->toBe('');
        expect(hostLayoutMode($scratch.'/fs/home/deploy-rateguru-staging'))->toBe(0o755);
    } finally {
        hostLayoutCleanup($scratch);
    }

    $scratch = hostLayoutScratchDir();

    try {
        $env = hostLayoutFixture($scratch);
        chmod($scratch.'/fs/home/deploy-rateguru-staging', 0o770);

        [$exit] = hostLayoutRun(['--apply'], $env);

        expect($exit)->toBe(0);
        // Remediation replaces the complete mode (`=`), so the result is
        // exactly 0750 with no inherited special bits.
        expect(hostLayoutLog($scratch, 'chmod.log'))
            ->toBe("chmod =0750 {$scratch}/fs/home/deploy-rateguru-staging\n");
        expect(hostLayoutMode($scratch.'/fs/home/deploy-rateguru-staging'))->toBe(0o750);
    } finally {
        hostLayoutCleanup($scratch);
    }
});

// =============================================================================
// Safety: fail-closed plan validation
// =============================================================================

it('fails closed with zero mutation when a managed directory is a symlink', function () {
    foreach ([
        '/home/www/rateguru/staging',
        '/home/www/rateguru/staging/releases',
        '/home/www/rateguru/staging/shared',
        '/home/deploy-rateguru-staging/incoming',
    ] as $logical) {
        $scratch = hostLayoutScratchDir();

        try {
            $env = hostLayoutFixture($scratch);
            $physical = $scratch.'/fs'.$logical;

            // Replace the managed directory with a symlink to a decoy tree
            // an attacker would want reconciliation redirected into.
            exec('rm -rf '.escapeshellarg($physical));
            @mkdir($scratch.'/fs/decoy', 0o755, true);
            file_put_contents($scratch.'/fs/decoy/sentinel.txt', 'DECOY-SENTINEL');
            symlink($scratch.'/fs/decoy', $physical);

            $before = hostLayoutTreeSnapshot($scratch.'/fs/decoy');

            [$exit, $output] = hostLayoutRun(['--apply'], $env);

            expect($exit)->toBe(1, "a symlinked {$logical} must fail the apply closed:\n{$output}");
            expect($output)->toContain("CONFLICT path:{$logical} is a symbolic link where a managed directory is required");
            expect($output)->toContain('refusing to apply');

            foreach (['identity.log', 'install.log', 'chown.log', 'chmod.log'] as $log) {
                expect(hostLayoutLog($scratch, $log))->toBe('', "apply mutated despite the symlinked {$logical}");
            }

            expect(hostLayoutTreeSnapshot($scratch.'/fs/decoy'))->toBe($before, 'the symlink target was modified');
            expect(is_link($physical))->toBeTrue('the conflicting symlink was removed or replaced');

            // --check reports the same conflict read-only.
            [$checkExit, $checkOutput] = hostLayoutRun(['--check'], $env);
            expect($checkExit)->toBe(1);
            expect($checkOutput)->toContain("CONFLICT path:{$logical} — is a symbolic link, expected directory");
        } finally {
            hostLayoutCleanup($scratch);
        }
    }
});

it('fails closed when a regular file occupies a managed directory path, and never removes it', function () {
    $scratch = hostLayoutScratchDir();

    try {
        $env = hostLayoutFixture($scratch);
        $locks = $scratch.'/fs/home/www/rateguru/staging/locks';

        exec('rm -rf '.escapeshellarg($locks));
        file_put_contents($locks, 'NOT-A-DIRECTORY-SENTINEL');

        [$exit, $output] = hostLayoutRun(['--apply'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT path:/home/www/rateguru/staging/locks is a regular file where a managed directory is required');
        expect(file_get_contents($locks))->toBe('NOT-A-DIRECTORY-SENTINEL', 'the conflicting file was modified or replaced');
        expect(hostLayoutLog($scratch, 'chown.log'))->toBe('');
        expect(hostLayoutLog($scratch, 'install.log'))->toBe('');

        [$checkExit, $checkOutput] = hostLayoutRun(['--check'], $env);
        expect($checkExit)->toBe(1);
        expect($checkOutput)->toContain('CONFLICT path:/home/www/rateguru/staging/locks — is a regular file, expected directory');
    } finally {
        hostLayoutCleanup($scratch);
    }
});

it('refuses a registry whose application root escapes the allowed RateGuru namespace', function () {
    $scratch = hostLayoutScratchDir();

    try {
        // The always-passing targets stub isolates the installer's own
        // containment check from the registry validator's.
        $env = hostLayoutFixture($scratch, [
            'registry' => hostLayoutRegistryWith(['application_root' => '/srv/evil']),
            'targetsCli' => 'stub-pass',
        ]);
        $before = hostLayoutTreeSnapshot($scratch.'/fs');

        foreach (['--check', '--apply', '--verify'] as $mode) {
            [$exit, $output] = hostLayoutRun([$mode], $env);

            expect($exit)->toBe(1);
            expect($output)->toContain('outside the allowed RateGuru root /home/www/rateguru');
        }

        expect(hostLayoutTreeSnapshot($scratch.'/fs'))->toBe($before);
    } finally {
        hostLayoutCleanup($scratch);
    }
});

it('refuses an incoming directory inconsistent with the deploy user home', function () {
    $scratch = hostLayoutScratchDir();

    try {
        $env = hostLayoutFixture($scratch, [
            'registry' => hostLayoutRegistryWith(['incoming_artifacts' => '/home/somebody-else/incoming']),
        ]);

        [$exit, $output] = hostLayoutRun(['--apply'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('inconsistent with the deploy user home /home/deploy-rateguru-staging');
        expect(hostLayoutLog($scratch, 'identity.log'))->toBe('');
        expect(hostLayoutLog($scratch, 'chown.log'))->toBe('');
    } finally {
        hostLayoutCleanup($scratch);
    }
});

it('fails closed on an incompatible existing primary group before any filesystem mutation', function () {
    $scratch = hostLayoutScratchDir();

    try {
        // The runtime user exists but its primary group is www-data (gid 33).
        $passwd = str_replace(
            'rateguru-staging:x:5001:5001:',
            'rateguru-staging:x:5001:33:',
            hostLayoutCompliantPasswd(),
        );
        $env = hostLayoutFixture($scratch, [
            'profile' => 'clean',
            'passwd' => $passwd,
            'group' => hostLayoutCompliantGroup(),
        ]);

        [$checkExit, $checkOutput] = hostLayoutRun(['--check'], $env);
        expect($checkExit)->toBe(1);
        expect($checkOutput)->toContain('CONFLICT user:rateguru-staging — primary group is www-data, required rateguru-staging');

        [$applyExit, $applyOutput] = hostLayoutRun(['--apply'], $env);
        expect($applyExit)->toBe(1);
        expect($applyOutput)->toContain('incompatible existing account');
        expect($applyOutput)->toContain('No filesystem mutation was performed');

        foreach (['identity.log', 'install.log', 'chown.log', 'chmod.log'] as $log) {
            expect(hostLayoutLog($scratch, $log))->toBe('', "apply mutated despite the incompatible account ({$log})");
        }
    } finally {
        hostLayoutCleanup($scratch);
    }
});

it('enforces the canonical deploy home and shell for an existing deploy account without ever rewriting it', function () {
    $compliantDeployLine = 'deploy-rateguru-staging:x:5002:5002::/home/deploy-rateguru-staging:/bin/bash';

    foreach ([
        // [drifted passwd line, expected CONFLICT detail]
        [
            'deploy-rateguru-staging:x:5002:5002::/root:/bin/bash',
            'CONFLICT user:deploy-rateguru-staging — home is /root, required /home/deploy-rateguru-staging',
        ],
        [
            'deploy-rateguru-staging:x:5002:5002::/tmp/wherever:/bin/bash',
            'CONFLICT user:deploy-rateguru-staging — home is /tmp/wherever, required /home/deploy-rateguru-staging',
        ],
        [
            'deploy-rateguru-staging:x:5002:5002::/home/deploy-rateguru-staging:/usr/sbin/nologin',
            'CONFLICT user:deploy-rateguru-staging — shell is /usr/sbin/nologin, required /bin/bash',
        ],
        [
            'deploy-rateguru-staging:x:5002:5002::/home/deploy-rateguru-staging:/bin/false',
            'CONFLICT user:deploy-rateguru-staging — shell is /bin/false, required /bin/bash',
        ],
    ] as [$driftedLine, $expectedConflict]) {
        $scratch = hostLayoutScratchDir();

        try {
            $passwd = str_replace($compliantDeployLine, $driftedLine, hostLayoutCompliantPasswd());
            expect($passwd)->not->toBe(hostLayoutCompliantPasswd(), 'fixture drift line did not apply');

            $env = hostLayoutFixture($scratch, ['passwd' => $passwd]);

            // --check names the incompatibility and the operator-review path.
            [$checkExit, $checkOutput] = hostLayoutRun(['--check'], $env);
            expect($checkExit)->toBe(1, "an incompatible deploy account must fail --check:\n{$checkOutput}");
            expect($checkOutput)->toContain($expectedConflict);
            expect($checkOutput)->toContain('operator review required');

            // --verify fails on the same drift.
            [$verifyExit] = hostLayoutRun(['--verify'], $env);
            expect($verifyExit)->toBe(1);

            // --apply fails closed before any mutation of any kind; the
            // account is never usermod'ed and authorized_keys stays intact.
            $before = hostLayoutTreeSnapshot($scratch.'/fs');
            [$applyExit, $applyOutput] = hostLayoutRun(['--apply'], $env);

            expect($applyExit)->toBe(1);
            expect($applyOutput)->toContain('incompatible existing account');
            expect($applyOutput)->toContain('No filesystem mutation was performed');

            foreach (['identity.log', 'install.log', 'chown.log', 'chmod.log'] as $log) {
                expect(hostLayoutLog($scratch, $log))->toBe('', "apply mutated despite the incompatible deploy account ({$log})");
            }

            expect(hostLayoutTreeSnapshot($scratch.'/fs'))->toBe($before, 'apply changed the filesystem despite the incompatible deploy account');
            expect(file_get_contents($scratch.'/fs/etc-passwd'))->toBe($passwd, 'the incompatible deploy account was rewritten');
            expect(file_get_contents($scratch.'/fs/home/deploy-rateguru-staging/.ssh/authorized_keys'))
                ->toContain('DEPLOY-KEY-SENTINEL');
        } finally {
            hostLayoutCleanup($scratch);
        }
    }
});

it('enforces the non-login runtime shell while keeping the runtime home out of the contract', function () {
    $compliantRuntimeLine = 'rateguru-staging:x:5001:5001::/home/www/rateguru/staging:/usr/sbin/nologin';

    // An interactive runtime shell is CONFLICT and blocks apply pre-mutation.
    $scratch = hostLayoutScratchDir();

    try {
        $passwd = str_replace(
            $compliantRuntimeLine,
            'rateguru-staging:x:5001:5001::/home/www/rateguru/staging:/bin/bash',
            hostLayoutCompliantPasswd(),
        );
        $env = hostLayoutFixture($scratch, ['passwd' => $passwd]);

        [$checkExit, $checkOutput] = hostLayoutRun(['--check'], $env);
        expect($checkExit)->toBe(1);
        expect($checkOutput)->toContain('CONFLICT user:rateguru-staging — shell is /bin/bash, required /usr/sbin/nologin');

        [$applyExit, $applyOutput] = hostLayoutRun(['--apply'], $env);
        expect($applyExit)->toBe(1);
        expect($applyOutput)->toContain('incompatible existing account');
        expect($applyOutput)->toContain('No filesystem mutation was performed');

        foreach (['identity.log', 'install.log', 'chown.log', 'chmod.log'] as $log) {
            expect(hostLayoutLog($scratch, $log))->toBe('', "apply mutated despite the incompatible runtime shell ({$log})");
        }
    } finally {
        hostLayoutCleanup($scratch);
    }

    // A divergent historic runtime home alone is NOT contract drift: the
    // deploy home is critical to SSH, the runtime home is not.
    $scratch = hostLayoutScratchDir();

    try {
        $passwd = str_replace(
            $compliantRuntimeLine,
            'rateguru-staging:x:5001:5001::/var/lib/rateguru-staging:/usr/sbin/nologin',
            hostLayoutCompliantPasswd(),
        );
        $env = hostLayoutFixture($scratch, ['passwd' => $passwd]);

        [$verifyExit, $verifyOutput] = hostLayoutRun(['--verify'], $env);

        expect($verifyExit)->toBe(0, "a divergent runtime home alone must not fail --verify:\n{$verifyOutput}");
        expect($verifyOutput)->toContain('SLICE 5.3 CONTRACT: SATISFIED');
        expect($verifyOutput)->toContain('PASS     user:rateguru-staging — exists (primary group rateguru-staging, shell /usr/sbin/nologin');
    } finally {
        hostLayoutCleanup($scratch);
    }
});

it('validates the source registry through the standalone targets CLI and fails closed on an invalid one', function () {
    $scratch = hostLayoutScratchDir();

    try {
        $env = hostLayoutFixture($scratch, ['registry' => "{ not json\n"]);

        [$exit, $output] = hostLayoutRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('fails targets validate');
    } finally {
        hostLayoutCleanup($scratch);
    }

    $scratch = hostLayoutScratchDir();

    try {
        $env = hostLayoutFixture($scratch);
        unlink($scratch.'/fs/deployment-targets.json');

        [$exit, $output] = hostLayoutRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('source registry not readable');
    } finally {
        hostLayoutCleanup($scratch);
    }
});

// =============================================================================
// --verify
// =============================================================================

it('verifies the compliant host without printing apply hints', function () {
    $scratch = hostLayoutScratchDir();

    try {
        $env = hostLayoutFixture($scratch);
        [$exit, $output] = hostLayoutRun(['--verify'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('SLICE 5.3 CONTRACT: SATISFIED');
        expect($output)->not->toContain('-> apply:');
    } finally {
        hostLayoutCleanup($scratch);
    }
});

// =============================================================================
// Security posture (static)
// =============================================================================

it('never deletes, recurses, renumbers or reaches outside its slice', function () {
    $source = hostLayoutSource();

    // Comment lines may document the forbidden mechanisms; no code line may
    // use them.
    expect(preg_match('/^[^#\n]*\buserdel\b/m', $source))->toBe(0, 'a code line invokes userdel');
    expect(preg_match('/^[^#\n]*\bgroupdel\b/m', $source))->toBe(0, 'a code line invokes groupdel');
    expect(preg_match('/^[^#\n]*\brm\b/m', $source))->toBe(0, 'a code line invokes rm');
    expect(preg_match('/^[^#\n]*\beval\b/m', $source))->toBe(0, 'a code line uses eval');
    expect(preg_match('/^[^#\n]*\bapt(-get)?\b/m', $source))->toBe(0, 'a code line invokes apt');
    expect(preg_match('/^[^#\n]*\bsystemctl\b/m', $source))->toBe(0, 'a code line invokes systemctl');
    expect(preg_match('/^[^#\n]*\b(chown|chmod)\b[^#\n]*(\s-R\b|--recursive)/m', $source))->toBe(0, 'a code line uses recursive chown/chmod');
    expect($source)->not->toContain('bash -c');
    expect($source)->not->toContain(' -R ');

    // Never sources common (it aborts on a clean host) or anything else.
    expect(preg_match('/^\s*(source|\.)\s/m', $source))->toBe(0, 'the installer must not source anything');

    // Secret material is never named by a code line: no authorized_keys
    // fabrication, no .env or rclone.conf handling.
    expect(preg_match('/^[^#\n]*authorized_keys/m', $source))->toBe(0, 'a code line references authorized_keys');
    expect(preg_match('/^[^#\n]*\.env\b/m', $source))->toBe(0, 'a code line references .env');
    expect(preg_match('/^[^#\n]*rclone/m', $source))->toBe(0, 'a code line references rclone');
});

it('honors RATEGURU_HOSTLAYOUT_* overrides only alongside the explicit test-override gate', function () {
    $source = hostLayoutSource();

    preg_match_all('/RATEGURU_HOSTLAYOUT_[A-Z_]+/', $source, $matches);
    $overrides = array_unique($matches[0]);
    expect($overrides)->not->toBe([]);

    foreach ($overrides as $override) {
        expect(
            preg_match('/gated_default '.preg_quote($override, '/').' /', $source),
        )->toBe(1, "{$override} must be read exactly once, through gated_default");
    }

    // Behaviorally: without the gate, every fixture override is ignored —
    // no scratch path can ever appear in the output, and the run degrades
    // to the real host (where the non-root test process is refused).
    $scratch = hostLayoutScratchDir();

    try {
        $env = hostLayoutFixture($scratch);
        unset($env['RATEGURU_ALLOW_TEST_OVERRIDES']);

        [$exit, $output] = hostLayoutRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->not->toContain($scratch);
    } finally {
        hostLayoutCleanup($scratch);
    }
});

it('is listed in the required CLI manifest', function () {
    expect(requiredCliManifestNames())->toContain('install-bootstrap-host-layout');
});

// =============================================================================
// Contract parity with the rest of the repository
// =============================================================================

it('keeps the structural directory contract in parity with bootstrap-host-preflight', function () {
    $layout = hostLayoutSource();
    $preflight = File::get(base_path('infrastructure/scripts/bootstrap-host-preflight'));

    // The preflight asserts the identical per-target owners and modes the
    // installer converges — the two slices can never disagree.
    foreach ([
        ['releases', 'TGT_CODE_GROUP', '2750'],
        ['shared', 'TGT_RUNTIME_GROUP', '2770'],
        ['shared-storage', 'TGT_RUNTIME_GROUP', '2770'],
        ['locks', 'TGT_CODE_GROUP', '2750'],
        ['deployments', 'TGT_CODE_GROUP', '2750'],
    ] as [$name, $groupVariable, $mode]) {
        expect(preg_match(
            '/report_path "'.preg_quote($name, '/').'" [^\n]*'.preg_quote($groupVariable, '/').'[^\n]* '.$mode.' /',
            $preflight,
        ))->toBe(1, "preflight no longer asserts {$name} as {$groupVariable} mode {$mode}");
    }

    expect(preg_match('/report_path "deploy-ssh" [^\n]* 700 /', $preflight))->toBe(1);
    expect(preg_match('/report_path "incoming" [^\n]* 750 /', $preflight))->toBe(1);
    expect(preg_match('/report_path "deploy-home" [^\n]* nw /', $preflight))->toBe(1);
    expect(preg_match('/report_path logs \/var\/log\/rateguru directory root root 750 /', $preflight))->toBe(1);

    // The installer carries the same numbers.
    foreach (['releases" "${deploy_user}" "${code_group}" 2750', 'shared" "${runtime_user}" "${runtime_group}" 2770', 'shared/storage" "${runtime_user}" "${runtime_group}" 2770'] as $needle) {
        expect(str_contains($layout, $needle))->toBeTrue("installer contract drifted: {$needle}");
    }

    expect($layout)->toContain('"${LOG_DIR}" root root 0750');

    // The managed-identity contract is pinned identically in both scripts:
    // the non-login runtime shell and the SSH-capable deploy shell can
    // never drift apart between preflight and installer.
    foreach ([
        'RUNTIME_USER_SHELL="/usr/sbin/nologin"',
        'DEPLOY_USER_SHELL="/bin/bash"',
    ] as $shellPin) {
        expect(str_contains($layout, $shellPin))->toBeTrue("installer no longer pins {$shellPin}");
        expect(str_contains($preflight, $shellPin))->toBeTrue("preflight no longer pins {$shellPin}");
    }

    // Both assert the deploy identity's canonical home and both leave the
    // runtime account's historic home out of the contract ("-").
    expect(preg_match('/report_target_user "\$\{TGT_RUNTIME_USER\[[^\]]+\]\}" "\$\{TGT_RUNTIME_GROUP\[[^\]]+\]\}" \\\\\n\s+- "\$\{RUNTIME_USER_SHELL\}"/', $preflight))
        ->toBe(1, 'preflight no longer skips the runtime home while pinning the runtime shell');
    expect($preflight)->toContain('"/home/${TGT_DEPLOY_USER[${target_id}]}" "${DEPLOY_USER_SHELL}"');
    expect($layout)->toContain('- "${RUNTIME_USER_SHELL}"');
    expect($layout)->toContain('"${TGT_DEPLOY_HOME[${target_id}]}" "${DEPLOY_USER_SHELL}"');
});

// =============================================================================
// Roadmap structure (stable facts only — never prose)
// =============================================================================

it('keeps the roadmap structure: Phase 5 completed, Phase 6 current, Phases 7-10 planned with their slices and rehearsal gates', function () {
    $roadmap = File::get(base_path('infrastructure/ROADMAP.md'));

    // Phase 5 closed once every mutating slice had been accepted on a real
    // host — 5.3 and 5.4 on staging, 5.5 on staging, 5.6 on a clean VPS.
    expect($roadmap)->toMatch('/^\|\s*5\s*\|\s*Infrastructure installer and clean-VPS bootstrap\s*\|\s*✅ completed\s*\|$/m');
    expect($roadmap)->toContain('5.3 Users, groups and filesystem — completed');
    expect($roadmap)->toContain('5.4 Services and configuration — completed');
    expect($roadmap)->toContain('5.5 Bootstrap orchestrator — completed');
    expect($roadmap)->toContain('5.6 Clean-VPS acceptance — completed');

    // Phase 6 took over as the single current phase.
    expect(substr_count($roadmap, '🚧 current'))->toBe(1);
    expect($roadmap)->toMatch('/^\|\s*6\s*\|[^|]+\|\s*🚧 current\s*\|$/m');

    // Phases 7-10 stay planned in the summary table.
    foreach ([7, 8, 9, 10] as $phase) {
        expect($roadmap)->toMatch(
            '/^\|\s*'.$phase.'\s*\|[^|]+\|\s*⏳ planned[^|]*\|$/m',
            "phase {$phase} is no longer a planned row in the summary table",
        );
    }

    // Every architecturally decided future slice identifier exists. Only
    // the identifiers are pinned — the prose is deliberately free to evolve.
    foreach ([6 => 6, 7 => 5, 8 => 7, 9 => 5, 10 => 5] as $phase => $sliceCount) {
        for ($slice = 1; $slice <= $sliceCount; $slice++) {
            expect(str_contains($roadmap, "**{$phase}.{$slice} "))
                ->toBeTrue("roadmap lost the {$phase}.{$slice} slice identifier");
        }
    }

    // The three distinct rehearsal gates and the disposable-rehearsal
    // policy exist as explicit architectural decisions.
    expect($roadmap)->toContain('Three distinct rehearsal gates');
    expect($roadmap)->toContain('Disposable rehearsal policy');

    // Nothing future is marked completed.
    foreach ([6, 7, 8, 9, 10] as $phase) {
        expect($roadmap)->not->toMatch('/^##\s*'.$phase.'\.\s[^\n]*completed/m');
    }
});

// =============================================================================
// www-data as a code-group reader (Phase 5.6 clean-VPS blocker #2).
//
// Nginx serves `root <target>/current/public` with
// `try_files $uri $uri/ /index.php?$query_string`, so its www-data workers
// must traverse the immutable release tree and stat/read public/index.php
// themselves, before FastCGI is involved. Releases are normalized
// deploy_user:code_group 0750/0640, so that requires code-group membership.
// A clean VPS had www-data in its own group only, and every health check
// returned 404 with `stat() ... failed (13: Permission denied)`.
// =============================================================================

it('appends both required code-group readers on a clean host: the runtime user and www-data', function () {
    $scratch = hostLayoutScratchDir();

    try {
        // Clean host: the code group does not exist yet, so neither
        // membership can exist either.
        $env = hostLayoutFixture($scratch, [
            'passwd' => hostLayoutCleanPasswd(),
            'group' => hostLayoutCleanGroup(),
        ]);

        [$checkExit, $checkOutput] = hostLayoutRun(['--check'], $env);
        expect($checkExit)->toBe(1);
        expect($checkOutput)->toContain('MISSING  membership:rateguru-staging:rateguru-staging-code');
        expect($checkOutput)->toContain('MISSING  membership:www-data:rateguru-staging-code');
        // On a truly clean host the group does not exist yet, so the
        // remediation is the create-then-append form; the append-only
        // wording appears once the accounts exist (covered below).
        expect($checkOutput)->toContain('create the accounts, then usermod --append --groups rateguru-staging-code www-data');

        [$exit, $output] = hostLayoutRun(['--apply'], $env);
        expect($exit)->toBe(0, $output);

        $identity = hostLayoutLog($scratch, 'identity.log');
        expect($identity)->toContain('usermod --append --groups rateguru-staging-code rateguru-staging');
        expect($identity)->toContain('usermod --append --groups rateguru-staging-code www-data');

        $group = (string) file_get_contents($scratch.'/fs/etc-group');
        expect($group)->toMatch('/^rateguru-staging-code:x:\d+:.*\brateguru-staging\b/m');
        expect($group)->toMatch('/^rateguru-staging-code:x:\d+:.*\bwww-data\b/m');

        // And the host now verifies.
        [$verifyExit, $verifyOutput] = hostLayoutRun(['--verify'], $env);
        expect($verifyExit)->toBe(0, $verifyOutput);
    } finally {
        hostLayoutCleanup($scratch);
    }
});

it('appends www-data without disturbing its unrelated supplementary memberships', function () {
    $scratch = hostLayoutScratchDir();

    try {
        // www-data legitimately carries unrelated system memberships on a
        // real host; a plain --groups would silently drop every one.
        $group = str_replace(
            'rateguru-staging-code:x:5010:rateguru-staging,deploy-rateguru-staging,www-data',
            "rateguru-staging-code:x:5010:rateguru-staging,deploy-rateguru-staging\n"
                .'unrelated-one:x:6001:www-data,someone-else'."\n"
                .'unrelated-two:x:6002:www-data',
            hostLayoutCompliantGroup(),
        );

        $env = hostLayoutFixture($scratch, ['group' => $group]);

        [$exit, $output] = hostLayoutRun(['--apply'], $env);
        expect($exit)->toBe(0, $output);

        // Exactly one append, and it is --append.
        expect(hostLayoutLog($scratch, 'identity.log'))
            ->toBe("usermod --append --groups rateguru-staging-code www-data\n");

        $after = (string) file_get_contents($scratch.'/fs/etc-group');
        expect($after)->toContain('unrelated-one:x:6001:www-data,someone-else');
        expect($after)->toContain('unrelated-two:x:6002:www-data');
        expect($after)->toMatch('/^rateguru-staging-code:x:5010:.*\bwww-data\b/m');
    } finally {
        hostLayoutCleanup($scratch);
    }
});

it('--verify fails when the runtime user is in the code group but www-data is not', function () {
    $scratch = hostLayoutScratchDir();

    try {
        // Exactly the mature-vs-clean-host difference that caused the
        // blocker: runtime membership present, www-data absent.
        $group = str_replace(
            'rateguru-staging-code:x:5010:rateguru-staging,deploy-rateguru-staging,www-data',
            'rateguru-staging-code:x:5010:rateguru-staging,deploy-rateguru-staging',
            hostLayoutCompliantGroup(),
        );

        $env = hostLayoutFixture($scratch, ['group' => $group]);
        [$exit, $output] = hostLayoutRun(['--verify'], $env);

        expect($exit)->toBe(1, 'a host Nginx cannot serve from must not verify');
        expect($output)->toContain('PASS     membership:rateguru-staging:rateguru-staging-code');
        expect($output)->toContain('MISSING  membership:www-data:rateguru-staging-code');
        expect($output)->toContain('SLICE 5.3 CONTRACT: NOT SATISFIED');
    } finally {
        hostLayoutCleanup($scratch);
    }
});

it('--verify passes when both code-group readers are present', function () {
    $scratch = hostLayoutScratchDir();

    try {
        $env = hostLayoutFixture($scratch);
        [$exit, $output] = hostLayoutRun(['--verify'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('PASS     membership:rateguru-staging:rateguru-staging-code');
        expect($output)->toContain('PASS     membership:www-data:rateguru-staging-code');
    } finally {
        hostLayoutCleanup($scratch);
    }
});

it('never adds www-data to a runtime group, and never provisions a planned target code group', function () {
    $scratch = hostLayoutScratchDir();

    try {
        $env = hostLayoutFixture($scratch, [
            'passwd' => hostLayoutCleanPasswd(),
            'group' => hostLayoutCleanGroup(),
        ]);

        [$exit, $output] = hostLayoutRun(['--apply'], $env);
        expect($exit)->toBe(0, $output);

        $identity = hostLayoutLog($scratch, 'identity.log');

        // The security boundary: www-data reads immutable code, never shared
        // mutable state. Shared/shared-storage access stays the narrow ACL
        // install-public-storage-access owns.
        expect($identity)->not->toContain('--groups rateguru-staging www-data');
        expect(file_get_contents($scratch.'/fs/etc-group'))
            ->toMatch('/^rateguru-staging:x:\d+:\s*$/m');

        // tits-guru is lifecycle=planned: no group, no membership, nothing.
        expect($identity)->not->toContain('tits-guru');
        expect($output)->not->toContain('membership:www-data:tits-guru');
        expect(file_get_contents($scratch.'/fs/etc-group'))->not->toContain('tits-guru');
    } finally {
        hostLayoutCleanup($scratch);
    }
});

// =============================================================================
// Target-scoped mode: --target TARGET_ID
// =============================================================================
//
// The same contract, narrowed to one target, with every host-wide entry turned
// from something this run owns into something it only inspects. The point of
// the mode is that repairing one live target can never quietly become
// bootstrapping the host underneath it.
//
// The first test in this section is the one that matters most: WITHOUT
// --target nothing about this installer changed. Every other test here
// describes new behaviour that only exists when --target is given.

it('leaves host mode exactly as it was: no --target, no target vocabulary anywhere in the report', function () {
    $scratch = hostLayoutScratchDir();

    try {
        $env = hostLayoutFixture($scratch);

        [$exit, $output] = hostLayoutRun(['--check'], $env);

        expect($exit)->toBe(0, "host mode must still verify the compliant fixture clean:\n{$output}");

        // The host-mode header, summary and verdict are unchanged.
        expect($output)->toContain('SLICE 5.3 CONTRACT: SATISFIED');
        expect($output)->toContain('Bootstrap host layout installer (check):');

        // Every host root is still an item this run owns, reported under
        // path: — not a prerequisite reported under host:.
        expect($output)->toContain('path:/home/www/rateguru/run');
        expect($output)->not->toContain('host:/home/www/rateguru');

        // The two things that only exist in target mode must not leak into it.
        expect($output)->not->toContain('HOST-REQ');
        expect($output)->not->toContain('Scope: target');
    } finally {
        hostLayoutCleanup($scratch);
    }
});

it('narrows the contract to one target and reports host roots as prerequisites it never owns', function () {
    $scratch = hostLayoutScratchDir();

    try {
        $env = hostLayoutFixture($scratch);

        [$exit, $output] = hostLayoutRun(['--check', '--target', 'staging-main'], $env);

        expect($exit)->toBe(0, "target mode must verify the compliant fixture clean:\n{$output}");

        expect($output)->toContain('Scope: target staging-main only');
        expect($output)->toContain('HOST LAYOUT CONTRACT (staging-main): SATISFIED');
        expect($output)->toContain('Bootstrap host layout installer (check, target staging-main):');

        // Host roots move from "mine to converge" to "must already be right".
        expect($output)->toContain('host:/home/www/rateguru — directory');
        expect($output)->toContain('host:/var/log/rateguru — directory');
        expect($output)->not->toContain('path:/home/www/rateguru/bin');

        // The target's own entries stay exactly what they were.
        expect($output)->toContain('path:/home/www/rateguru/staging/releases');
        expect($output)->toContain('path:/home/www/rateguru/staging/shared/storage');
    } finally {
        hostLayoutCleanup($scratch);
    }
});

it('reports an unsatisfied host root as HOST-REQ and refuses --apply with zero mutation', function () {
    $scratch = hostLayoutScratchDir();

    try {
        $env = hostLayoutFixture($scratch);

        // A host-wide log directory that is not root-owned. In host mode this
        // is drift the installer chowns; in target mode it is somebody else's
        // job, and the run must say so rather than fixing it.
        hostLayoutWriteOwnerTable($scratch, array_merge(
            hostLayoutOwnerTableRows($scratch),
            [$scratch.'/fs/var/log/rateguru' => ['rateguru-staging', 'rateguru-staging']],
        ));

        [$checkExit, $checkOutput] = hostLayoutRun(['--check', '--target', 'staging-main'], $env);

        expect($checkExit)->toBe(1, "an unsatisfied host root must fail the target contract:\n{$checkOutput}");
        expect($checkOutput)->toContain('HOST-REQ host:/var/log/rateguru');
        expect($checkOutput)->toContain('run install-bootstrap-host-layout --apply WITHOUT --target');
        expect($checkOutput)->toContain('HOST LAYOUT CONTRACT (staging-main): NOT SATISFIED');

        $before = hostLayoutTreeSnapshot($scratch.'/fs');

        [$applyExit, $applyOutput] = hostLayoutRun(['--apply', '--target', 'staging-main'], $env);

        expect($applyExit)->not->toBe(0, "a target-scoped apply must refuse an unsatisfied host root:\n{$applyOutput}");
        expect($applyOutput)->toContain('host-level prerequisite(s) are not satisfied');
        expect($applyOutput)->toContain('No mutation was performed');

        foreach (['identity.log', 'install.log', 'chown.log', 'chmod.log'] as $log) {
            expect(hostLayoutLog($scratch, $log))->toBe('', "the refused target apply invoked a mutation tool ({$log})");
        }

        expect(hostLayoutTreeSnapshot($scratch.'/fs'))->toBe($before, 'the refused target apply changed the filesystem');
    } finally {
        hostLayoutCleanup($scratch);
    }
});

it('converges only the named target and never creates a host root in target mode', function () {
    $scratch = hostLayoutScratchDir();

    try {
        $env = hostLayoutFixture($scratch);

        // Target-owned drift the run IS allowed to fix, on an otherwise
        // compliant host: the deploy home is group-writable.
        chmod($scratch.'/fs/home/deploy-rateguru-staging', 0o770);

        [$exit, $output] = hostLayoutRun(['--apply', '--target', 'staging-main'], $env);

        expect($exit)->toBe(0, "target apply must converge target-owned drift:\n{$output}");

        $chmod = hostLayoutLog($scratch, 'chmod.log');

        expect($chmod)->toContain('/home/deploy-rateguru-staging');

        // Nothing host-wide was ever handed to a mutation tool.
        foreach (['/home/www/rateguru/bin', '/home/www/rateguru/config', '/var/log/rateguru'] as $hostRoot) {
            foreach (['install.log', 'chown.log', 'chmod.log'] as $log) {
                expect(hostLayoutLog($scratch, $log))->not->toContain($hostRoot.PHP_EOL);
            }
        }
    } finally {
        hostLayoutCleanup($scratch);
    }
});

it('refuses an unknown target and a planned target before any work', function () {
    $scratch = hostLayoutScratchDir();

    try {
        $env = hostLayoutFixture($scratch);

        [$unknownExit, $unknownOutput] = hostLayoutRun(['--check', '--target', 'not-a-target'], $env);

        expect($unknownExit)->not->toBe(0);
        expect($unknownOutput)->toContain('unknown target: not-a-target');

        // tits-guru is registered but lifecycle=planned. Target mode must
        // refuse it for the same reason host mode never provisions it.
        [$plannedExit, $plannedOutput] = hostLayoutRun(['--check', '--target', 'tits-guru'], $env);

        expect($plannedExit)->not->toBe(0);
        expect($plannedOutput)->toContain('tits-guru is lifecycle=planned');

        foreach (['identity.log', 'install.log', 'chown.log', 'chmod.log'] as $log) {
            expect(hostLayoutLog($scratch, $log))->toBe('', "a refused target invocation invoked a mutation tool ({$log})");
        }
    } finally {
        hostLayoutCleanup($scratch);
    }
});

it('validates the whole registry before narrowing to one target', function () {
    $scratch = hostLayoutScratchDir();

    try {
        // The invalid entry is on tits-guru, NOT on the target being narrowed
        // to. Breaking staging-main itself would prove nothing: the run would
        // reject it even if narrowing happened first, so the test would pass
        // whether or not the whole registry is validated. Narrowing is a scope
        // reduction, never a licence to run against a registry the validator
        // would refuse.
        $env = hostLayoutFixture($scratch, [
            'registry' => hostLayoutRegistryWithOn('tits-guru', ['application_root' => '/opt/elsewhere']),
        ]);

        [$exit, $output] = hostLayoutRun(['--check', '--target', 'staging-main'], $env);

        expect($exit)->not->toBe(0, "an invalid registry must be rejected in target mode too:\n{$output}");
    } finally {
        hostLayoutCleanup($scratch);
    }
});

it('rejects a malformed --target without touching the host', function () {
    $scratch = hostLayoutScratchDir();

    try {
        $env = hostLayoutFixture($scratch);

        $cases = [
            [['--check', '--target'], '--target requires a target ID'],
            [['--check', '--target', '--apply'], 'requires a target ID'],
            [['--check', '--target', 'staging-main', '--target', 'staging-main'], '--target'],
        ];

        foreach ($cases as [$arguments, $needle]) {
            [$exit, $output] = hostLayoutRun($arguments, $env);

            expect($exit)->not->toBe(0, 'malformed --target must fail: '.implode(' ', $arguments));
            expect($output)->toContain($needle);
        }
    } finally {
        hostLayoutCleanup($scratch);
    }
});

it('treats a target directory with the wrong owner or mode as repairable drift, and repairs it', function () {
    $scratch = hostLayoutScratchDir();

    try {
        $env = hostLayoutFixture($scratch);

        // The single most common thing a target repair is asked to fix, and
        // one this installer already fixes safely: ensure_directory chowns and
        // chmods the entry itself and never recurses.
        hostLayoutWriteOwnerTable($scratch, array_merge(
            hostLayoutOwnerTableRows($scratch),
            [$scratch.'/fs/home/www/rateguru/staging/shared' => ['root', 'root']],
        ));
        chmod($scratch.'/fs/home/www/rateguru/staging/shared', 0o755);

        [$targetExit, $targetOutput] = hostLayoutRun(['--check', '--target', 'staging-main'], $env);

        expect($targetExit)->toBe(1, $targetOutput);
        expect($targetOutput)->toContain('DRIFT    path:/home/www/rateguru/staging/shared');
        expect($targetOutput)->not->toContain('CONFLICT path:/home/www/rateguru/staging/shared');

        // Host mode is unchanged: there this is still a CONFLICT.
        [$hostExit, $hostOutput] = hostLayoutRun(['--check'], $env);

        expect($hostExit)->toBe(1);
        expect($hostOutput)->toContain('CONFLICT path:/home/www/rateguru/staging/shared');
        expect($hostOutput)->not->toContain('DRIFT');

        // And a target-scoped apply actually converges it.
        [$applyExit, $applyOutput] = hostLayoutRun(['--apply', '--target', 'staging-main'], $env);

        expect($applyExit)->toBe(0, "a target apply must repair owner/mode drift:\n{$applyOutput}");
        expect(hostLayoutLog($scratch, 'chown.log'))->toContain('/home/www/rateguru/staging/shared');
    } finally {
        hostLayoutCleanup($scratch);
    }
});

it('never calls a wrong filesystem type repairable, in either mode', function () {
    $scratch = hostLayoutScratchDir();

    try {
        $env = hostLayoutFixture($scratch);

        // Resolving this would mean deleting something to make room, which
        // nothing here ever does — so it stays a conflict even in target mode.
        exec('rm -rf '.escapeshellarg($scratch.'/fs/home/www/rateguru/staging/locks'));
        file_put_contents($scratch.'/fs/home/www/rateguru/staging/locks', "not a directory\n");

        [$exit, $output] = hostLayoutRun(['--check', '--target', 'staging-main'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT path:/home/www/rateguru/staging/locks');
        expect($output)->toContain('expected directory');

        $before = hostLayoutTreeSnapshot($scratch.'/fs');

        [$applyExit] = hostLayoutRun(['--apply', '--target', 'staging-main'], $env);

        expect($applyExit)->not->toBe(0);
        expect(hostLayoutTreeSnapshot($scratch.'/fs'))->toBe($before);
    } finally {
        hostLayoutCleanup($scratch);
    }
});
