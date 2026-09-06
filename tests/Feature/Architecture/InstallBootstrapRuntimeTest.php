<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * : infrastructure/scripts/install-bootstrap-runtime — the
 * reproducible base/runtime package installer for a clean Ubuntu 22.04 host.
 *
 * Every test executes the real, shipped script as a subprocess — never a
 * reimplementation — against a fully simulated host: fixture os-release and
 * apt sources/keyrings directories, a constrained tool PATH, and stub
 * apt-get/dpkg-query/curl/gpg/php/psql binaries, all injected through
 * RATEGURU_BOOTSTRAP_* overrides the script only honors alongside
 * RATEGURU_ALLOW_TEST_OVERRIDES=true. Nothing here runs apt or touches the
 * CI runner: every stub is pure bash builtins (the constrained PATH the
 * script hands to subprocesses contains no coreutils).
 *
 * The two profiles that matter most mirror the two real situations the
 * installer must serve: a clean Ubuntu 22.04 VPS (everything missing —
 * --apply builds it) and the current staging host (runtime already present,
 * repositories configured by the operator, unrelated NodeSource/ClickHouse/
 * Datadog sources on the side — recognized, satisfied, and never touched).
 *
 * rclone is a managed external runtime binary, not an apt package: the fixture
 * simulates the canonical binary, the committed external-runtimes contract
 * and signing key, the official download origin (through the curl stub) and
 * the clearsign verification (through the gpg stub). The archive bytes are
 * fake, but their SHA256 in the fake SHA256SUMS payload is real, so the
 * script's checksum verification passes and fails exactly like production.
 */

// =============================================================================
// Harness
// =============================================================================

function bootstrapRuntimeScript(): string
{
    return base_path('infrastructure/scripts/install-bootstrap-runtime');
}

function bootstrapRuntimeSource(): string
{
    return File::get(bootstrapRuntimeScript());
}

function bootstrapRuntimeScratchDir(): string
{
    $dir = sys_get_temp_dir().'/bootstrap-runtime-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/bin', '/fs', '/fs/usr-bin', '/tools', '/staged', '/log', '/apt/sources.list.d', '/keyrings'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

function bootstrapRuntimeCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function bootstrapRuntimeRun(array $arguments, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(
        array_merge(['bash', bootstrapRuntimeScript()], $arguments),
        $descriptors,
        $pipes,
        null,
        $env,
    );

    expect($process)->not->toBeFalse('could not start install-bootstrap-runtime subprocess');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

function bootstrapRuntimeWriteStub(string $path, string $content): void
{
    file_put_contents($path, $content);
    chmod($path, 0o755);
}

/**
 * The repository/package contract, mirrored from the script so fixtures can
 * satisfy every probe. Order matters: apt-install assertions compare against
 * the script's own array order.
 *
 * @return list<string>
 */
function bootstrapRuntimeRequiredPackages(): array
{
    $base = [
        'acl', 'bash', 'ca-certificates', 'certbot', 'coreutils', 'curl',
        'diffutils', 'findutils', 'gnupg', 'grep', 'gzip', 'hostname',
        'iproute2', 'jq', 'libc-bin', 'mawk', 'nginx', 'openssh-server',
        'passwd', 'procps', 'redis-server', 'rsync', 'sed', 'sudo',
        'supervisor', 'tar', 'unzip', 'util-linux',
    ];

    $php = array_map(
        fn (string $component): string => "php8.5-{$component}",
        ['cli', 'common', 'fpm', 'bcmath', 'curl', 'gd', 'intl', 'mbstring', 'pgsql', 'redis', 'xml', 'zip'],
    );

    return array_merge($base, $php, ['postgresql-18', 'postgresql-client-18']);
}

/**
 * @return list<string>
 */
function bootstrapRuntimeRequiredPhpModules(): array
{
    return ['bcmath', 'curl', 'exif', 'gd', 'intl', 'mbstring', 'pcntl', 'pdo_pgsql', 'pgsql', 'redis', 'xml', 'zip'];
}

/**
 * Plain exit-0 tools the fixture PATH carries on a compliant host. The
 * version-bearing binaries (php8.5, php-fpm8.5, psql, pg_dump, pg_restore)
 * and unzip are separate behavioral stubs added by the fixture builder.
 * rclone is deliberately not a PATH tool here: the managed external runtime
 * is probed at its canonical contract path, and a PATH-resolvable rclone
 * without the canonical binary is its own CONFLICT scenario.
 *
 * @return list<string>
 */
function bootstrapRuntimeAllTools(): array
{
    return [
        'apt-get', 'dpkg',
        'setfacl', 'getfacl', 'certbot', 'curl', 'cmp', 'diff', 'find',
        'gpg', 'grep', 'gzip', 'hostname', 'ss', 'ip', 'jq', 'getent',
        'awk', 'nginx', 'sshd', 'useradd', 'redis-server',
        'rsync', 'sed', 'sudo', 'visudo', 'supervisord', 'tar', 'flock',
        'namei', 'runuser', 'createdb', 'dropdb',
    ];
}

function bootstrapRuntimePhpPpaFingerprint(): string
{
    return '14AA40EC0831756756D7F66C4F4EA0AAE5267A6C';
}

function bootstrapRuntimePgdgFingerprint(): string
{
    return 'B97B0AFCAA1A47F044F244A07FCC7D46ACCC4CF8';
}

function bootstrapRuntimeRcloneFingerprint(): string
{
    return 'FBF737ECE9F8AB18604BD2AC93935E02FF3B54FA';
}

/**
 * The committed external-runtimes contract, parsed the same way the scripts
 * parse it (plain KEY=VALUE text). The single test-side source of the pinned
 * version — never duplicated as a literal.
 *
 * @return array<string, string>
 */
function bootstrapRuntimeCommittedRcloneContract(): array
{
    $contract = [];

    foreach (explode("\n", File::get(base_path('infrastructure/config/external-runtimes/versions.env'))) as $line) {
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $contract[$key] ??= $value;
    }

    return $contract;
}

/**
 * The rclone version a fixture simulates — the committed pin unless a test
 * deliberately moves it.
 *
 * @param  array<string, mixed>  $options
 */
function bootstrapRuntimeFixtureRcloneVersion(array $options): string
{
    return $options['rcloneContractVersion'] ?? bootstrapRuntimeCommittedRcloneContract()['RCLONE_VERSION'];
}

function bootstrapRuntimeCurrentUser(): string
{
    return posix_getpwuid(posix_geteuid())['name'];
}

function bootstrapRuntimeCurrentGroup(): string
{
    return posix_getgrgid(posix_getegid())['name'];
}

/**
 * The fake pinned-release archive bytes the curl stub serves. Content is
 * arbitrary, but its real SHA256 is what the fixture SHA256SUMS payload
 * carries, so checksum verification is exercised for real.
 *
 * @param  array<string, mixed>  $options
 */
function bootstrapRuntimeRcloneArchiveContent(array $options): string
{
    return 'RCLONE-ARCHIVE:v'.bootstrapRuntimeFixtureRcloneVersion($options);
}

/**
 * The exact deb822 content the installer owns for one repository — must stay
 * byte-identical to sources_file_content() in the script.
 */
function bootstrapRuntimeExpectedSources(string $label, string $uri, string $suite, string $keyring): string
{
    return implode("\n", [
        "# RateGuru {$label} repository — managed by install-bootstrap-runtime",
        '#. Do not edit: re-run --apply to reconcile.',
        'Types: deb',
        "URIs: {$uri}",
        "Suites: {$suite}",
        'Components: main',
        'Architectures: amd64',
        "Signed-By: {$keyring}",
    ])."\n";
}

/**
 * Host identity plus the apt sources/keyrings landscape.
 *
 * @param  array<string, mixed>  $options
 */
function bootstrapRuntimeWriteHostFiles(string $scratch, array $options): void
{
    $os = $options['os'] ?? 'ubuntu-22.04';
    $osRelease = match ($os) {
        'ubuntu-22.04' => "ID=ubuntu\nVERSION_ID=\"22.04\"\nPRETTY_NAME=\"Ubuntu 22.04.4 LTS\"\n",
        'ubuntu-24.04' => "ID=ubuntu\nVERSION_ID=\"24.04\"\nPRETTY_NAME=\"Ubuntu 24.04 LTS\"\n",
        'debian' => "ID=debian\nVERSION_ID=\"12\"\nVERSION=\"12 (sentinel-bookworm)\"\n",
        'absent' => null,
    };

    if ($osRelease !== null) {
        file_put_contents($scratch.'/fs/os-release', $osRelease);
    }

    // The exact keyring content --apply would have installed: dearmored,
    // pin-validated key material (the gpg stub recognizes the embedded URL
    // and answers with the per-repository fingerprint).
    $phpKeyringContent = 'DEARMORED:KEY-FROM:https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x'.bootstrapRuntimePhpPpaFingerprint()."\n";
    $pgdgKeyringContent = "DEARMORED:KEY-FROM:https://www.postgresql.org/media/keys/ACCC4CF8.asc\n";

    // Unrelated host-wide repositories the real staging host carries — never
    // RateGuru dependencies, never managed, never removed. One deb822 file
    // proves the .sources scan ignores foreign stanzas too.
    file_put_contents(
        $scratch.'/apt/sources.list.d/nodesource.list',
        "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_20.x nodistro main\n",
    );
    file_put_contents(
        $scratch.'/apt/sources.list.d/clickhouse.list',
        "deb [signed-by=/usr/share/keyrings/clickhouse-keyring.gpg] https://packages.clickhouse.com/deb stable main\n",
    );
    file_put_contents(
        $scratch.'/apt/sources.list.d/datadog-vector.sources',
        "Types: deb\nURIs: https://apt.vector.dev\nSuites: stable\nComponents: vector\nSigned-By: /usr/share/keyrings/datadog-archive-keyring.gpg\n",
    );

    file_put_contents(
        $scratch.'/apt/sources.list',
        "deb http://archive.ubuntu.com/ubuntu jammy main restricted universe multiverse\n"
        ."deb http://archive.ubuntu.com/ubuntu jammy-updates main restricted universe multiverse\n"
        ."deb http://security.ubuntu.com/ubuntu jammy-security main restricted universe multiverse\n",
    );

    // PHP PPA: the current staging host configured it via add-apt-repository
    // (a classic .list under the operator's own file name).
    if (($options['phpRepo'] ?? 'preexisting') === 'preexisting') {
        file_put_contents(
            $scratch.'/apt/sources.list.d/ondrej-ubuntu-php-jammy.list',
            "deb https://ppa.launchpadcontent.net/ondrej/php/ubuntu jammy main\n",
        );
    } elseif (($options['phpRepo'] ?? null) === 'installer-owned') {
        file_put_contents(
            $scratch.'/apt/sources.list.d/rateguru-php.sources',
            bootstrapRuntimeExpectedSources(
                'php',
                'https://ppa.launchpadcontent.net/ondrej/php/ubuntu',
                'jammy',
                $scratch.'/keyrings/rateguru-php.gpg',
            ),
        );
        file_put_contents($scratch.'/keyrings/rateguru-php.gpg', $phpKeyringContent);
    }

    if (($options['pgdgRepo'] ?? 'preexisting') === 'preexisting') {
        file_put_contents(
            $scratch.'/apt/sources.list.d/pgdg.list',
            "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.gpg] https://apt.postgresql.org/pub/repos/apt jammy-pgdg main\n",
        );
    } elseif (($options['pgdgRepo'] ?? null) === 'installer-owned') {
        file_put_contents(
            $scratch.'/apt/sources.list.d/rateguru-pgdg.sources',
            bootstrapRuntimeExpectedSources(
                'pgdg',
                'https://apt.postgresql.org/pub/repos/apt',
                'jammy-pgdg',
                $scratch.'/keyrings/rateguru-pgdg.gpg',
            ),
        );
        file_put_contents($scratch.'/keyrings/rateguru-pgdg.gpg', $pgdgKeyringContent);
    }

    // A decoy RateGuru runtime tree: install-bootstrap-runtime must never touch application
    // paths, so its continued byte-identity is asserted after --apply.
    expect(@mkdir($scratch.'/fs/home-www-rateguru', 0o755, true))->toBeTrue();
    file_put_contents($scratch.'/fs/home-www-rateguru/decoy.txt', "application files — never touched by install-bootstrap-runtime\n");

    // dpkg database fixture: one package name per line.
    $packages = $options['packages'] ?? 'all';
    $installed = match (true) {
        $packages === 'all' => bootstrapRuntimeRequiredPackages(),
        $packages === 'none' => [],
        default => $packages,
    };
    file_put_contents($scratch.'/fs/dpkg-installed.txt', implode("\n", $installed).($installed === [] ? '' : "\n"));

    bootstrapRuntimeWriteRcloneFixture($scratch, $options);
}

/**
 * The managed rclone runtime landscape: a committed-style contract and
 * signing key, the SHA256SUMS payload the curl stub serves, the canonical
 * binary in whatever state the scenario needs, and the operator's decoy
 * rclone.conf that must never be read or written.
 *
 * @param  array<string, mixed>  $options
 */
function bootstrapRuntimeWriteRcloneFixture(string $scratch, array $options): void
{
    $version = bootstrapRuntimeFixtureRcloneVersion($options);

    file_put_contents($scratch.'/fs/external-runtimes.env', implode("\n", [
        '# Test fixture mirror of infrastructure/config/external-runtimes/versions.env.',
        'RCLONE_VERSION='.$version,
        'RCLONE_PLATFORM=linux-amd64',
        'RCLONE_BINARY='.$scratch.'/fs/usr-bin/rclone',
        'RCLONE_OWNER='.($options['rcloneOwner'] ?? bootstrapRuntimeCurrentUser()),
        'RCLONE_GROUP='.($options['rcloneGroup'] ?? bootstrapRuntimeCurrentGroup()),
        'RCLONE_MODE=0755',
        'RCLONE_RELEASE_SIGNING_FINGERPRINT='.bootstrapRuntimeRcloneFingerprint(),
    ])."\n");

    // Marker content the gpg stub recognizes as the committed signing key.
    file_put_contents($scratch.'/fs/rclone-release-signing-key.asc', "RCLONE-SIGNING-KEY\n");

    // The signed SHA256SUMS payload: a foreign-platform row proves digest
    // selection is by exact artifact name; the linux-amd64 row carries the
    // genuine SHA256 of the fake archive bytes unless a test corrupts it.
    $digest = $options['rcloneSumsDigest'] ?? hash('sha256', bootstrapRuntimeRcloneArchiveContent($options));
    file_put_contents($scratch.'/fs/rclone-sums.txt', implode("\n", [
        str_repeat('0', 64)."  rclone-v{$version}-linux-arm64.zip",
        "{$digest}  rclone-v{$version}-linux-amd64.zip",
    ])."\n");

    // The canonical installed binary. 'compliant' reports the contract
    // version; a version string simulates drift (the real staging v1.74.4,
    // the Ubuntu-package v1.53.3); 'broken' cannot report a version at all;
    // 'absent' is the clean host. Every invocation is logged so tests can
    // prove the binary is only ever asked for --version — never `config`,
    // never `selfupdate`.
    $installedState = $options['rcloneInstalled'] ?? 'compliant';

    if ($installedState !== 'absent') {
        $reported = $installedState === 'compliant' ? 'v'.$version : $installedState;
        $versionLine = $installedState === 'broken'
            ? "    printf 'garbage without a version\\n'"
            : "    printf 'rclone {$reported}\\n'";

        file_put_contents($scratch.'/fs/usr-bin/rclone', implode("\n", [
            '#!/bin/bash',
            'printf \'rclone %s\n\' "$*" >> "${STUB_LOG}/rclone.log"',
            'if [[ "$1" == "--version" ]]; then',
            $versionLine,
            '    exit 0',
            'fi',
            'exit 1',
        ])."\n");
        chmod($scratch.'/fs/usr-bin/rclone', intval($options['rcloneFileMode'] ?? '0755', 8));
    }

    // Operator secret material — presence only; content must survive every
    // mode byte-identically.
    expect(@mkdir($scratch.'/fs/root-config-rclone', 0o700, true))->toBeTrue();
    file_put_contents(
        $scratch.'/fs/root-config-rclone/rclone.conf',
        "[b2]\ntype = b2\naccount = SECRET-B2-ACCOUNT-SENTINEL\nkey = SECRET-B2-KEY-SENTINEL\n",
    );
}

/**
 * @param  array<string, mixed>  $options
 */
function bootstrapRuntimeWriteToolStubs(string $scratch, array $options): void
{
    $tools = $options['tools'] ?? 'all';

    if ($tools === 'all') {
        $tools = bootstrapRuntimeAllTools();
    } elseif ($tools === 'minimal') {
        $tools = ['apt-get', 'dpkg'];
    }

    foreach ($tools as $tool) {
        bootstrapRuntimeWriteStub($scratch.'/tools/'.$tool, "#!/bin/bash\nexit 0\n");
    }

    // Version-bearing runtime binaries. Pure builtins: the constrained PATH
    // has no cat/sed, so stubs may only use printf/read/[[ ]].
    $phpStub = <<<'STUB'
        #!/bin/bash
        if [[ "$1" == "-v" ]]; then
            printf 'PHP %s (%s) (built: Jan  1 2026 00:00:00) (NTS)\n' "${STUB_PHP_VERSION}" "${STUB_PHP_SAPI}"
            exit 0
        fi
        if [[ "$1" == "-m" ]]; then
            printf '[PHP Modules]\n'
            for module in ${STUB_PHP_MODULES}; do
                printf '%s\n' "${module}"
            done
            exit 0
        fi
        exit 1
        STUB;

    bootstrapRuntimeWriteStub($scratch.'/tools/php8.5', str_replace('${STUB_PHP_SAPI}', 'cli', $phpStub));
    bootstrapRuntimeWriteStub($scratch.'/tools/php-fpm8.5', str_replace('${STUB_PHP_SAPI}', 'fpm-fcgi', $phpStub));

    $pgStub = <<<'STUB'
        #!/bin/bash
        if [[ "$1" == "--version" ]]; then
            printf '%s (PostgreSQL) %s (Ubuntu %s-1.pgdg22.04+1)\n' "${0##*/}" "${STUB_PG_VERSION}" "${STUB_PG_VERSION}"
            exit 0
        fi
        exit 1
        STUB;

    foreach (['psql', 'pg_dump', 'pg_restore'] as $tool) {
        bootstrapRuntimeWriteStub($scratch.'/tools/'.$tool, $pgStub);
    }

    // unzip: always present as a behavioral stub (like the php/pg binaries).
    // "Extracting" the fake archive materializes the staged extracted-rclone
    // stub under the exact directory layout the real release archive uses.
    bootstrapRuntimeWriteStub($scratch.'/tools/unzip', <<<'STUB'
        #!/bin/bash
        zip=""
        dest=""
        prev=""
        for arg in "$@"; do
            if [[ "${prev}" == "-d" ]]; then
                dest="${arg}"
            elif [[ "${arg}" != -* ]]; then
                zip="${arg}"
            fi
            prev="${arg}"
        done
        if [[ ! -f "${zip}" || -z "${dest}" ]]; then
            exit 9
        fi
        PATH="${STUB_REAL_PATH}" mkdir -p "${dest}/${STUB_UNZIP_DIR_NAME}"
        if [[ "${STUB_UNZIP_OMIT_BINARY:-}" == "true" ]]; then
            exit 0
        fi
        PATH="${STUB_REAL_PATH}" cp "${STUB_STAGED_DIR}/rclone-extracted" "${dest}/${STUB_UNZIP_DIR_NAME}/rclone"
        exit 0
        STUB);

    // The binary "inside" the archive: reports whatever version the test
    // configured and logs every invocation, so tests can prove the managed
    // binary is only ever asked for --version.
    bootstrapRuntimeWriteStub($scratch.'/staged/rclone-extracted', <<<'STUB'
        #!/bin/bash
        printf 'rclone %s\n' "$*" >> "${STUB_LOG}/rclone.log"
        if [[ "$1" == "--version" ]]; then
            printf 'rclone %s\n' "${STUB_EXTRACTED_RCLONE_VERSION}"
            exit 0
        fi
        exit 1
        STUB);
}

function bootstrapRuntimeWriteMutationStubs(string $scratch, array $options): void
{
    // dpkg-query: reads the fixture package database; builtins only.
    bootstrapRuntimeWriteStub($scratch.'/bin/dpkg-query', <<<'STUB'
        #!/bin/bash
        pkg="${!#}"
        while IFS= read -r line; do
            if [[ "${line}" == "${pkg}" ]]; then
                printf 'installed'
                exit 0
            fi
        done < "${STUB_DPKG_STATE}"
        exit 1
        STUB);

    // apt-get: logs every invocation; a successful install marks its
    // packages installed in the fixture dpkg database, and installing the
    // bootstrap tooling packages materializes the curl/gpg binaries on the
    // fixture tool PATH — exactly like a real host, where those tools only
    // exist after their packages are installed.
    bootstrapRuntimeWriteStub($scratch.'/bin/apt-get', <<<'STUB'
        #!/bin/bash
        printf 'apt-get %s\n' "$*" >> "${STUB_LOG}/apt.log"
        if [[ "$1" == "update" && "${STUB_APT_UPDATE_FAIL:-}" == "true" ]]; then
            exit 100
        fi
        if [[ "$1" == "install" ]]; then
            if [[ "${STUB_APT_INSTALL_FAIL:-}" == "true" ]]; then
                exit 100
            fi
            for arg in "$@"; do
                case "${arg}" in
                    install|--|-*) ;;
                    *)
                        printf '%s\n' "${arg}" >> "${STUB_DPKG_STATE}"
                        if [[ "${arg}" == "curl" ]]; then
                            PATH="${STUB_REAL_PATH}" cp "${STUB_STAGED_DIR}/curl" "${STUB_TOOLS_DIR}/curl"
                        fi
                        if [[ "${arg}" == "gnupg" ]]; then
                            PATH="${STUB_REAL_PATH}" cp "${STUB_STAGED_DIR}/gpg" "${STUB_TOOLS_DIR}/gpg"
                        fi
                        ;;
                esac
            done
        fi
        exit 0
        STUB);

    // curl: records the request and materializes fake key material carrying
    // the requested URL, so the gpg stub can answer per-repository. There is
    // deliberately NO RATEGURU_BOOTSTRAP_* override for curl/gpg — the
    // script resolves both through the fixture tool PATH, so a host profile
    // without them genuinely cannot fetch keys until apt "installs" them.
    // For the official rclone download origin it serves the fake pinned
    // archive and a fake clearsign-enveloped SHA256SUMS instead.
    $curlStub = <<<'STUB'
        #!/bin/bash
        printf 'curl %s\n' "$*" >> "${STUB_LOG}/curl.log"
        url="${!#}"
        if [[ -n "${STUB_CURL_FAIL_PATTERN:-}" && "${url}" == *"${STUB_CURL_FAIL_PATTERN}"* ]]; then
            exit 22
        fi
        out=""
        prev=""
        for arg in "$@"; do
            if [[ "${prev}" == "--output" ]]; then
                out="${arg}"
            fi
            prev="${arg}"
        done
        if [[ "${url}" == *"downloads.rclone.org"* ]]; then
            if [[ "${url}" == *SHA256SUMS ]]; then
                printf 'CLEARSIGNED-BY:%s\n' "${STUB_RCLONE_SUMS_SIGNER}" > "${out}"
                while IFS= read -r line; do
                    printf '%s\n' "${line}" >> "${out}"
                done < "${STUB_RCLONE_SUMS_FILE}"
                exit 0
            fi
            printf '%s' "${STUB_RCLONE_ARCHIVE_CONTENT}" > "${out}"
            exit 0
        fi
        printf 'KEY-FROM:%s' "${url}" > "${out}"
        exit 0
        STUB;

    // gpg: dearmor copies the staged key with a marker; --show-keys answers
    // with the per-repository fingerprint the test configured (garbage
    // content yields no fingerprint at all and therefore never validates);
    // --decrypt models clearsign verification — it only succeeds when the
    // keyring holds the rclone signing-key marker AND the envelope claims
    // the release signer, and emits the enclosed payload.
    $gpgStub = <<<'STUB'
        #!/bin/bash
        printf 'gpg %s\n' "$*" >> "${STUB_LOG}/gpg.log"
        if [[ " $* " == *" --dearmor "* ]]; then
            out=""
            prev=""
            for arg in "$@"; do
                if [[ "${prev}" == "--output" ]]; then
                    out="${arg}"
                fi
                prev="${arg}"
            done
            src="${!#}"
            printf 'DEARMORED:%s\n' "$(<"${src}")" > "${out}"
            exit 0
        fi
        if [[ " $* " == *" --decrypt "* ]]; then
            out=""
            keyring=""
            prev=""
            for arg in "$@"; do
                if [[ "${prev}" == "--output" ]]; then
                    out="${arg}"
                fi
                if [[ "${prev}" == "--keyring" ]]; then
                    keyring="${arg}"
                fi
                prev="${arg}"
            done
            src="${!#}"
            if [[ ! -f "${keyring}" || "$(<"${keyring}")" != *RCLONE-SIGNING-KEY* ]]; then
                exit 2
            fi
            signed=""
            first=1
            : > "${out}"
            while IFS= read -r line; do
                if [[ "${first}" == 1 ]]; then
                    first=0
                    if [[ "${line}" == "CLEARSIGNED-BY:RCLONE-RELEASE" ]]; then
                        signed=1
                    fi
                    continue
                fi
                printf '%s\n' "${line}" >> "${out}"
            done < "${src}"
            if [[ -z "${signed}" ]]; then
                exit 2
            fi
            exit 0
        fi
        if [[ " $* " == *" --show-keys "* ]]; then
            keyfile="${!#}"
            content="$(<"${keyfile}")"
            fpr=""
            [[ "${content}" == *keyserver.ubuntu.com* ]] && fpr="${STUB_FPR_PHP}"
            [[ "${content}" == *postgresql.org* ]] && fpr="${STUB_FPR_PGDG}"
            [[ "${content}" == *RCLONE-SIGNING-KEY* ]] && fpr="${STUB_FPR_RCLONE}"
            if [[ -z "${fpr}" ]]; then
                exit 2
            fi
            printf 'pub:-:4096:1:AAAAAAAAAAAAAAAA:1:::-:::scESC::::::23::0:\n'
            printf 'fpr:::::::::%s:\n' "${fpr}"
            if [[ "${STUB_GPG_EXTRA_KEY:-}" == "true" ]]; then
                printf 'pub:-:4096:1:BBBBBBBBBBBBBBBB:1:::-:::scESC::::::23::0:\n'
                printf 'fpr:::::::::0000000000000000000000000000000000000000:\n'
            fi
            exit 0
        fi
        exit 1
        STUB;

    // stat: the rclone owner/group/mode probe. Delegates to the real stat
    // so freshly installed fixture files report their genuine ownership and
    // mode (a static table could never reflect an --apply). Tries the GNU
    // flavor first and falls back to BSD, so the harness PATH may resolve
    // either implementation (macOS with or without brew coreutils first).
    bootstrapRuntimeWriteStub($scratch.'/bin/stat', <<<'STUB'
        #!/bin/bash
        path="${!#}"
        PATH="${STUB_REAL_PATH}" stat -c '%U|%G|%a' -- "${path}" 2>/dev/null && exit 0
        PATH="${STUB_REAL_PATH}" stat -f '%Su|%Sg|%Lp' "${path}" 2>/dev/null
        STUB);

    // Staged copies for the apt stub to "install"; live copies on the tool
    // PATH only when the simulated host profile actually has the tooling.
    bootstrapRuntimeWriteStub($scratch.'/staged/curl', $curlStub);
    bootstrapRuntimeWriteStub($scratch.'/staged/gpg', $gpgStub);

    if (($options['bootstrapTooling'] ?? 'present') === 'present') {
        if (is_file($scratch.'/tools/curl')) {
            bootstrapRuntimeWriteStub($scratch.'/tools/curl', $curlStub);
        }
        if (is_file($scratch.'/tools/gpg')) {
            bootstrapRuntimeWriteStub($scratch.'/tools/gpg', $gpgStub);
        }
    } else {
        @unlink($scratch.'/tools/curl');
        @unlink($scratch.'/tools/gpg');
    }
}

/**
 * Build a fully simulated host and return the environment to run the script
 * against it. The default is the compliant staging-like profile: runtime
 * installed, repositories pre-existing, unrelated repos on the side. Every
 * option knocks one aspect back toward a clean or broken host.
 *
 * Options:
 *   os:              'ubuntu-22.04' | 'ubuntu-24.04' | 'debian' | 'absent'
 *   arch:            machine string (default x86_64)
 *   euid:            string (default '0')
 *   packages:        'all' | 'none' | list<string>
 *   phpRepo:         'preexisting' | 'installer-owned' | 'absent'
 *   pgdgRepo:        'preexisting' | 'installer-owned' | 'absent'
 *   tools:           'all' | 'minimal' | list<string>
 *   phpVersion:      reported by the php stubs (default 8.5.8)
 *   phpModules:      space-separated module list for `php8.5 -m`
 *   pgVersion:       reported by the pg client stubs (default 18.4)
 *   fprPhp/fprPgdg:  fingerprints the gpg stub reports per repository
 *   curlFailPattern: URL substring that makes the curl stub fail
 *   aptUpdateFail/aptInstallFail: bool
 *   gpgExtraKey:     bool — key material bundles a second key
 *   bootstrapTooling: 'present' | 'missing' — 'missing' removes curl/gpg
 *                    from the tool PATH entirely; they only appear when the
 *                    apt stub installs the bootstrap tooling packages
 *   rcloneInstalled: 'compliant' | 'absent' | 'broken' | 'v<X.Y.Z>' — the
 *                    canonical managed binary's state (default compliant)
 *   rcloneContractVersion: pinned version the fixture contract carries
 *                    (default: the committed pin)
 *   rcloneOwner/rcloneGroup: contract ownership (default: current user, so
 *                    the real fixture files comply; 'root' simulates drift)
 *   rcloneFileMode:  octal string mode of the installed binary (default 0755)
 *   rcloneSumsDigest: digest the SHA256SUMS payload carries for the amd64
 *                    artifact (default: the genuine hash of the fake bytes)
 *   rcloneBadSignature: bool — SHA256SUMS envelope claims an unknown signer
 *   fprRclone:       fingerprint the gpg stub reports for the signing key
 *   rcloneExtractedVersion: version the extracted binary reports
 *   rcloneOmitBinary: bool — the archive extracts without an rclone binary
 *
 * @param  array<string, mixed>  $options
 * @return array<string, string>
 */
function bootstrapRuntimeFixture(string $scratch, array $options = []): array
{
    bootstrapRuntimeWriteHostFiles($scratch, $options);
    bootstrapRuntimeWriteToolStubs($scratch, $options);
    bootstrapRuntimeWriteMutationStubs($scratch, $options);

    $env = [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_BOOTSTRAP_OS_RELEASE_FILE' => $scratch.'/fs/os-release',
        'RATEGURU_BOOTSTRAP_ARCH' => $options['arch'] ?? 'x86_64',
        'RATEGURU_BOOTSTRAP_EUID' => $options['euid'] ?? '0',
        'RATEGURU_BOOTSTRAP_APT_SOURCES_MAIN' => $scratch.'/apt/sources.list',
        'RATEGURU_BOOTSTRAP_APT_SOURCES_DIR' => $scratch.'/apt/sources.list.d',
        'RATEGURU_BOOTSTRAP_APT_KEYRINGS_DIR' => $scratch.'/keyrings',
        'RATEGURU_BOOTSTRAP_TOOL_PATH' => $scratch.'/tools',
        'RATEGURU_BOOTSTRAP_APT_GET_BIN' => $scratch.'/bin/apt-get',
        'RATEGURU_BOOTSTRAP_DPKG_QUERY_BIN' => $scratch.'/bin/dpkg-query',
        'RATEGURU_BOOTSTRAP_PHP_CLI_BIN' => $scratch.'/tools/php8.5',
        'RATEGURU_BOOTSTRAP_PHP_FPM_BIN' => $scratch.'/tools/php-fpm8.5',
        'RATEGURU_BOOTSTRAP_PSQL_BIN' => $scratch.'/tools/psql',
        'RATEGURU_BOOTSTRAP_PG_DUMP_BIN' => $scratch.'/tools/pg_dump',
        'RATEGURU_BOOTSTRAP_PG_RESTORE_BIN' => $scratch.'/tools/pg_restore',
        'RATEGURU_BOOTSTRAP_STAT_BIN' => $scratch.'/bin/stat',
        'RATEGURU_BOOTSTRAP_RCLONE_CONTRACT_FILE' => $scratch.'/fs/external-runtimes.env',
        'RATEGURU_BOOTSTRAP_RCLONE_KEY_FILE' => $scratch.'/fs/rclone-release-signing-key.asc',
        'STUB_LOG' => $scratch.'/log',
        'STUB_DPKG_STATE' => $scratch.'/fs/dpkg-installed.txt',
        'STUB_REAL_PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'STUB_STAGED_DIR' => $scratch.'/staged',
        'STUB_TOOLS_DIR' => $scratch.'/tools',
        'STUB_PHP_VERSION' => $options['phpVersion'] ?? '8.5.8',
        'STUB_PHP_MODULES' => $options['phpModules'] ?? implode(' ', bootstrapRuntimeRequiredPhpModules()),
        'STUB_PG_VERSION' => $options['pgVersion'] ?? '18.4',
        'STUB_FPR_PHP' => $options['fprPhp'] ?? bootstrapRuntimePhpPpaFingerprint(),
        'STUB_FPR_PGDG' => $options['fprPgdg'] ?? bootstrapRuntimePgdgFingerprint(),
        'STUB_FPR_RCLONE' => $options['fprRclone'] ?? bootstrapRuntimeRcloneFingerprint(),
        'STUB_RCLONE_ARCHIVE_CONTENT' => bootstrapRuntimeRcloneArchiveContent($options),
        'STUB_RCLONE_SUMS_FILE' => $scratch.'/fs/rclone-sums.txt',
        'STUB_RCLONE_SUMS_SIGNER' => ($options['rcloneBadSignature'] ?? false) ? 'UNKNOWN-KEY' : 'RCLONE-RELEASE',
        'STUB_UNZIP_DIR_NAME' => 'rclone-v'.bootstrapRuntimeFixtureRcloneVersion($options).'-linux-amd64',
        'STUB_EXTRACTED_RCLONE_VERSION' => $options['rcloneExtractedVersion'] ?? 'v'.bootstrapRuntimeFixtureRcloneVersion($options),
    ];

    if ($options['rcloneOmitBinary'] ?? false) {
        $env['STUB_UNZIP_OMIT_BINARY'] = 'true';
    }

    if (isset($options['curlFailPattern'])) {
        $env['STUB_CURL_FAIL_PATTERN'] = $options['curlFailPattern'];
    }

    if ($options['aptUpdateFail'] ?? false) {
        $env['STUB_APT_UPDATE_FAIL'] = 'true';
    }

    if ($options['aptInstallFail'] ?? false) {
        $env['STUB_APT_INSTALL_FAIL'] = 'true';
    }

    if ($options['gpgExtraKey'] ?? false) {
        $env['STUB_GPG_EXTRA_KEY'] = 'true';
    }

    return $env;
}

/**
 * The clean-VPS profile: fresh Ubuntu 22.04, no RateGuru repositories, no
 * packages, only the package manager itself (fixture keeps the full tool
 * PATH so the closing --verify of a successful --apply models the
 * post-install host).
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, string>
 */
function bootstrapRuntimeCleanHostFixture(string $scratch, array $extra = []): array
{
    return bootstrapRuntimeFixture($scratch, array_merge([
        'packages' => 'none',
        'phpRepo' => 'absent',
        'pgdgRepo' => 'absent',
        'rcloneInstalled' => 'absent',
    ], $extra));
}

/**
 * Recursive path => content snapshot for mutation-free assertions.
 *
 * @return array<string, string>
 */
function bootstrapRuntimeTreeSnapshot(string $dir): array
{
    $snapshot = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $snapshot[substr($file->getPathname(), strlen($dir))] = (string) file_get_contents($file->getPathname());
        }
    }

    ksort($snapshot);

    return $snapshot;
}

function bootstrapRuntimeAptLog(string $scratch): string
{
    $path = $scratch.'/log/apt.log';

    return is_file($path) ? (string) file_get_contents($path) : '';
}

// =============================================================================
// CLI contract
// =============================================================================

it('prints usage on --help and rejects unknown or duplicated modes', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch);

        [$exit, $output] = bootstrapRuntimeRun(['--help'], $env);
        expect($exit)->toBe(0);
        expect($output)->toContain('--check')->toContain('--apply')->toContain('--verify');

        [$exit, $output] = bootstrapRuntimeRun([], $env);
        expect($exit)->toBe(1);
        expect($output)->toContain('one of --check, --apply or --verify is required');

        [$exit, $output] = bootstrapRuntimeRun(['--check', '--verify'], $env);
        expect($exit)->toBe(1);
        expect($output)->toContain('mode given more than once');

        [$exit, $output] = bootstrapRuntimeRun(['--frobnicate'], $env);
        expect($exit)->toBe(1);
        expect($output)->toContain('unknown argument: --frobnicate');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

// =============================================================================
// --check
// =============================================================================

it('recognizes the current staging host as satisfied: pre-existing repos, installed runtime, unrelated repos ignored', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(0, "the already-bootstrapped staging host must satisfy --check:\n{$output}");
        expect($output)->toContain('HOST RUNTIME CONTRACT: SATISFIED');
        expect($output)->toContain('PASS     os-release — ID=ubuntu VERSION_ID=22.04');
        expect($output)->toContain('PASS     repo:php — provided by a pre-existing apt source');
        expect($output)->toContain('PASS     repo:pgdg — provided by a pre-existing apt source');
        expect($output)->toContain('PASS     php-cli — PHP 8.5.8');
        expect($output)->toContain('PASS     psql — PostgreSQL 18.4');
        expect($output)->toContain("MISSING: 0\n");
        expect($output)->toContain("CONFLICT: 0\n");

        // The managed rclone runtime reports in its own section, and never
        // as an apt package requirement.
        $rcloneVersion = bootstrapRuntimeFixtureRcloneVersion([]);
        expect($output)->toContain("\nEXTERNAL RUNTIME\n");
        expect($output)->toContain(sprintf(
            'PASS     rclone — v%s, %s, %s:%s 0755 (managed external runtime)',
            $rcloneVersion,
            $scratch.'/fs/usr-bin/rclone',
            bootstrapRuntimeCurrentUser(),
            bootstrapRuntimeCurrentGroup(),
        ));
        expect($output)->not->toContain('package:rclone');

        // Unrelated repositories are never inspected, reported or required
        // absent — they simply do not appear.
        expect($output)->not->toContain('nodesource');
        expect($output)->not->toContain('clickhouse');
        expect($output)->not->toContain('datadog');
        expect($output)->not->toContain('vector');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('reports the full work list on a clean Ubuntu 22.04 host and exits non-zero', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch, ['tools' => 'minimal']);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1, "a clean host cannot satisfy the install-bootstrap-runtime contract:\n{$output}");
        expect($output)->toContain('HOST RUNTIME CONTRACT: NOT SATISFIED');
        expect($output)->toContain('MISSING  repo:php');
        expect($output)->toContain('MISSING  repo:pgdg');
        expect($output)->toContain('MISSING  package:php8.5-fpm — not installed');
        expect($output)->toContain('MISSING  package:postgresql-18 — not installed');
        expect($output)->toContain('MISSING  package:nginx — not installed');
        expect($output)->toContain('MISSING  package:unzip — not installed');

        // --check annotates every unsatisfied item with the intended action.
        expect($output)->toContain('-> apply: install '.$scratch.'/apt/sources.list.d/rateguru-php.sources');
        expect($output)->toContain('-> apply: apt-get install postgresql-18');

        // The absent managed rclone runtime is MISSING in its own section,
        // with the verified-install action — never an apt proposal.
        $rcloneVersion = bootstrapRuntimeFixtureRcloneVersion([]);
        expect($output)->toContain('MISSING  rclone — '.$scratch.'/fs/usr-bin/rclone absent');
        expect($output)->toContain('-> apply: install verified rclone v'.$rcloneVersion);
        expect($output)->not->toContain('package:rclone');
        expect($output)->not->toContain('apt-get install rclone');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('reports a missing package manager as MISSING and fails --check', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['tools' => ['dpkg']]);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  apt-dpkg — apt-get/dpkg not available');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('treats a wrong OS family as CONFLICT', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['os' => 'debian']);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT os-release — unsupported OS family ID=debian');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('rejects Ubuntu 24.04: only the exact 22.04 staging baseline is supported', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['os' => 'ubuntu-24.04']);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT os-release — ID=ubuntu VERSION_ID=24.04 is not the supported baseline ubuntu 22.04');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('treats an unproven architecture as CONFLICT instead of silently claiming support', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['arch' => 'aarch64']);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT architecture — aarch64 is not a supported architecture (supported: x86_64 amd64)');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('reports each RateGuru repository independently when only one is missing', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['phpRepo' => 'absent']);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  repo:php — no apt source provides ppa.launchpadcontent.net/ondrej/php/ubuntu jammy');
        expect($output)->toContain('PASS     repo:pgdg');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }

    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['pgdgRepo' => 'absent']);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  repo:pgdg — no apt source provides apt.postgresql.org/pub/repos/apt jammy-pgdg');
        expect($output)->toContain('PASS     repo:php');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('recognizes installer-owned repository files and flags a sources file whose keyring vanished', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['phpRepo' => 'installer-owned', 'pgdgRepo' => 'installer-owned']);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('PASS     repo:php — configured by this installer');
        expect($output)->toContain('PASS     repo:pgdg — configured by this installer');

        unlink($scratch.'/keyrings/rateguru-php.gpg');

        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);
        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT repo:php');
        expect($output)->toContain('keyring');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('reports missing packages individually against the dpkg database', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $installed = array_values(array_diff(
            bootstrapRuntimeRequiredPackages(),
            ['php8.5-fpm', 'postgresql-18', 'unzip'],
        ));
        $env = bootstrapRuntimeFixture($scratch, ['packages' => $installed]);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  package:php8.5-fpm');
        expect($output)->toContain('MISSING  package:postgresql-18');
        expect($output)->toContain('MISSING  package:unzip');
        expect($output)->toContain('PASS     package:nginx — installed');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('treats a wrong PHP series as CONFLICT for both SAPIs', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['phpVersion' => '8.4.13']);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT php-cli — reports PHP 8.4.13, required series is 8.5');
        expect($output)->toContain('CONFLICT php-fpm — reports PHP 8.4.13, required series is 8.5');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('treats a wrong PostgreSQL major as CONFLICT for every client tool', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['pgVersion' => '16.6']);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT psql — reports PostgreSQL 16.6, required major is 18');
        expect($output)->toContain('CONFLICT pg_dump — reports PostgreSQL 16.6, required major is 18');
        expect($output)->toContain('CONFLICT pg_restore — reports PostgreSQL 16.6, required major is 18');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('keeps --check strictly read-only: no apt, no curl, no gpg, no file mutation', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch, ['tools' => 'minimal']);
        $before = bootstrapRuntimeTreeSnapshot($scratch);

        [, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect(bootstrapRuntimeTreeSnapshot($scratch))->toBe($before, "--check mutated the fixture:\n{$output}");
        expect(is_file($scratch.'/log/apt.log'))->toBeFalse('--check invoked apt-get');
        expect(is_file($scratch.'/log/curl.log'))->toBeFalse('--check invoked curl');
        expect(is_file($scratch.'/log/gpg.log'))->toBeFalse('--check invoked gpg');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('warns about a non-root --check without failing a satisfied host', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['euid' => '1000']);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('WARN     effective-uid — 1000');
        expect($output)->toContain('HOST RUNTIME CONTRACT: SATISFIED');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

// =============================================================================
// --apply
// =============================================================================

it('bootstraps a clean host: pinned repositories, one apt update, one install, closing verify', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch);
        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(0, "apply on a clean compliant host must converge and verify:\n{$output}");
        expect($output)->toContain('HOST RUNTIME CONTRACT: SATISFIED');

        // Both installer-owned repositories exist with the exact deb822
        // content: HTTPS URI, pinned dedicated keyring, amd64 only.
        expect((string) file_get_contents($scratch.'/apt/sources.list.d/rateguru-php.sources'))->toBe(
            bootstrapRuntimeExpectedSources(
                'php',
                'https://ppa.launchpadcontent.net/ondrej/php/ubuntu',
                'jammy',
                $scratch.'/keyrings/rateguru-php.gpg',
            ),
        );
        expect((string) file_get_contents($scratch.'/apt/sources.list.d/rateguru-pgdg.sources'))->toBe(
            bootstrapRuntimeExpectedSources(
                'pgdg',
                'https://apt.postgresql.org/pub/repos/apt',
                'jammy-pgdg',
                $scratch.'/keyrings/rateguru-pgdg.gpg',
            ),
        );

        // Keyrings hold the dearmored (validated) key material.
        expect((string) file_get_contents($scratch.'/keyrings/rateguru-php.gpg'))->toStartWith('DEARMORED:KEY-FROM:https://keyserver.ubuntu.com/');
        expect((string) file_get_contents($scratch.'/keyrings/rateguru-pgdg.gpg'))->toStartWith('DEARMORED:KEY-FROM:https://www.postgresql.org/');

        // Two-phase apt: update + bootstrap repository tooling from the
        // existing Ubuntu sources, then update (indexes now include the new
        // repositories) + one deterministic noninteractive install of the
        // remaining required packages. Never an upgrade.
        $bootstrapInstall = 'apt-get install -y --no-install-recommends -- ca-certificates curl gnupg';
        $remaining = array_values(array_diff(
            bootstrapRuntimeRequiredPackages(),
            ['ca-certificates', 'curl', 'gnupg'],
        ));
        $runtimeInstall = 'apt-get install -y --no-install-recommends -- '.implode(' ', $remaining);
        expect(bootstrapRuntimeAptLog($scratch))->toBe(
            "apt-get update\n{$bootstrapInstall}\napt-get update\n{$runtimeInstall}\n",
        );

        // The managed rclone runtime converged through the verified-download
        // path — never through apt (the exact apt log above already proves
        // no apt action mentioned rclone).
        $rcloneVersion = bootstrapRuntimeFixtureRcloneVersion([]);
        expect($output)->toContain('rclone v'.$rcloneVersion.' installed at '.$scratch.'/fs/usr-bin/rclone');
        expect(is_file($scratch.'/fs/usr-bin/rclone'))->toBeTrue();

        // Key material never leaks into the report.
        expect($output)->not->toContain('KEY-FROM');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('never runs apt upgrade in any form', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch);
        bootstrapRuntimeRun(['--apply'], $env);

        $aptLog = bootstrapRuntimeAptLog($scratch);
        expect($aptLog)->not->toBe('');
        expect($aptLog)->not->toContain('upgrade');

        foreach (explode("\n", trim($aptLog)) as $line) {
            expect(
                str_starts_with($line, 'apt-get update') || str_starts_with($line, 'apt-get install'),
            )->toBeTrue("unexpected apt invocation: {$line}");
        }
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('preserves unrelated repositories byte-for-byte across --apply', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch);

        $unrelated = [
            '/apt/sources.list.d/nodesource.list',
            '/apt/sources.list.d/clickhouse.list',
            '/apt/sources.list.d/datadog-vector.sources',
            '/apt/sources.list',
        ];
        $before = [];
        foreach ($unrelated as $file) {
            $before[$file] = (string) file_get_contents($scratch.$file);
        }

        [$exit] = bootstrapRuntimeRun(['--apply'], $env);
        expect($exit)->toBe(0);

        foreach ($unrelated as $file) {
            expect((string) file_get_contents($scratch.$file))->toBe($before[$file], "{$file} was modified");
        }
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('is idempotent: a second --apply performs no apt call, no key fetch and no file rewrite', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch);

        [$exit] = bootstrapRuntimeRun(['--apply'], $env);
        expect($exit)->toBe(0);

        $sourcesBefore = bootstrapRuntimeTreeSnapshot($scratch.'/apt');
        $keyringsBefore = bootstrapRuntimeTreeSnapshot($scratch.'/keyrings');

        foreach (['apt.log', 'curl.log', 'gpg.log'] as $log) {
            file_put_contents($scratch.'/log/'.$log, '');
        }

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(0, "second apply must converge trivially:\n{$output}");
        expect($output)->toContain('repo:php already configured by this installer — nothing to do');
        expect($output)->toContain('repo:pgdg already configured by this installer — nothing to do');
        expect($output)->toContain('packages: all 42 required packages already installed');
        expect($output)->toContain('rclone v'.bootstrapRuntimeFixtureRcloneVersion([]).' already installed');

        expect((string) file_get_contents($scratch.'/log/apt.log'))->toBe('', 'second apply ran apt-get');
        expect((string) file_get_contents($scratch.'/log/curl.log'))->toBe('', 'second apply re-fetched key material or release artifacts');

        // The only gpg activity is the local read-only keyring validation —
        // never a dearmor/import of new material.
        $gpgLog = (string) file_get_contents($scratch.'/log/gpg.log');
        expect($gpgLog)->not->toContain('--dearmor');
        foreach (array_filter(explode("\n", $gpgLog)) as $line) {
            expect($line)->toContain('--show-keys');
        }

        expect(bootstrapRuntimeTreeSnapshot($scratch.'/apt'))->toBe($sourcesBefore);
        expect(bootstrapRuntimeTreeSnapshot($scratch.'/keyrings'))->toBe($keyringsBefore);
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('leaves pre-existing operator-configured repositories untouched while installing missing packages', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, [
            'packages' => array_values(array_diff(bootstrapRuntimeRequiredPackages(), ['php8.5-zip'])),
        ]);

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('repo:php provided by a pre-existing apt source — left untouched');
        expect($output)->toContain('repo:pgdg provided by a pre-existing apt source — left untouched');

        expect(is_file($scratch.'/apt/sources.list.d/rateguru-php.sources'))->toBeFalse('installer duplicated a pre-existing PHP source');
        expect(is_file($scratch.'/apt/sources.list.d/rateguru-pgdg.sources'))->toBeFalse('installer duplicated a pre-existing PGDG source');
        expect(is_file($scratch.'/log/curl.log'))->toBeFalse('installer fetched keys for repositories it does not own');

        expect(bootstrapRuntimeAptLog($scratch))->toBe(
            "apt-get update\napt-get install -y --no-install-recommends -- php8.5-zip\n",
        );
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('fails closed when one repository key cannot be fetched: earlier repo intact, no partial files, no install', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch, ['curlFailPattern' => 'postgresql.org']);
        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('ERROR: repo:pgdg: cannot download signing key');

        // The PHP repository completed transactionally before the failure.
        expect(is_file($scratch.'/apt/sources.list.d/rateguru-php.sources'))->toBeTrue();
        expect(is_file($scratch.'/keyrings/rateguru-php.gpg'))->toBeTrue();

        // No partial PGDG artifacts and no staged temp files anywhere.
        $leftovers = array_merge(
            glob($scratch.'/apt/sources.list.d/rateguru-pgdg*') ?: [],
            glob($scratch.'/keyrings/rateguru-pgdg*') ?: [],
            glob($scratch.'/apt/sources.list.d/*.XXXXXX*') ?: [],
            glob($scratch.'/apt/sources.list.d/*.sources.*') ?: [],
            glob($scratch.'/keyrings/*.gpg.*') ?: [],
        );
        expect($leftovers)->toBe([]);

        // Dependent package installation never started: only the bootstrap
        // repository tooling phase reached apt.
        expect(bootstrapRuntimeAptLog($scratch))->toBe(
            "apt-get update\napt-get install -y --no-install-recommends -- ca-certificates curl gnupg\n",
        );
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('refuses key material whose fingerprint does not match the pin and stops before any mutation', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch, [
            'fprPhp' => str_repeat('DEADBEEF', 5),
        ]);
        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('signing key fingerprint does not match the pinned '.bootstrapRuntimePhpPpaFingerprint());

        expect(glob($scratch.'/apt/sources.list.d/rateguru-*') ?: [])->toBe([]);
        expect(glob($scratch.'/keyrings/*') ?: [])->toBe([]);
        expect(bootstrapRuntimeAptLog($scratch))->toBe(
            "apt-get update\napt-get install -y --no-install-recommends -- ca-certificates curl gnupg\n",
        );
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('refuses key material that bundles extra keys beyond the pinned one', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch, ['gpgExtraKey' => true]);
        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('refusing to install it');
        expect(glob($scratch.'/keyrings/*') ?: [])->toBe([]);
        expect(bootstrapRuntimeAptLog($scratch))->toBe(
            "apt-get update\napt-get install -y --no-install-recommends -- ca-certificates curl gnupg\n",
        );
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

// =============================================================================
// --apply bootstrap ordering (clean host without curl/gpg)
// =============================================================================

it('bootstraps a host that genuinely lacks curl and gpg: tooling first, external repositories second', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch, ['bootstrapTooling' => 'missing']);

        expect(is_file($scratch.'/tools/curl'))->toBeFalse('fixture must start without curl');
        expect(is_file($scratch.'/tools/gpg'))->toBeFalse('fixture must start without gpg');

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(0, "a genuinely minimal host must bootstrap end to end:\n{$output}");
        expect($output)->toContain('bootstrap repository tooling missing: ca-certificates curl gnupg');
        expect($output)->toContain('HOST RUNTIME CONTRACT: SATISFIED');

        // The tooling appeared exactly the way a real host gets it —
        // through the apt install — and both repositories followed.
        expect(is_file($scratch.'/tools/curl'))->toBeTrue();
        expect(is_file($scratch.'/tools/gpg'))->toBeTrue();
        expect(is_file($scratch.'/apt/sources.list.d/rateguru-php.sources'))->toBeTrue();
        expect(is_file($scratch.'/apt/sources.list.d/rateguru-pgdg.sources'))->toBeTrue();

        $bootstrapInstall = 'apt-get install -y --no-install-recommends -- ca-certificates curl gnupg';
        $remaining = array_values(array_diff(
            bootstrapRuntimeRequiredPackages(),
            ['ca-certificates', 'curl', 'gnupg'],
        ));
        $runtimeInstall = 'apt-get install -y --no-install-recommends -- '.implode(' ', $remaining);
        expect(bootstrapRuntimeAptLog($scratch))->toBe(
            "apt-get update\n{$bootstrapInstall}\napt-get update\n{$runtimeInstall}\n",
        );

        // Both keys were fetched only after the tooling existed, and the
        // rclone release material only after both repositories converged.
        $curlLog = (string) file_get_contents($scratch.'/log/curl.log');
        $curlLines = array_values(array_filter(explode("\n", $curlLog)));
        expect(count($curlLines))->toBe(4, "unexpected curl activity:\n{$curlLog}");
        expect($curlLines[0])->toContain('keyserver.ubuntu.com');
        expect($curlLines[1])->toContain('postgresql.org');
        expect($curlLines[2])->toContain('downloads.rclone.org');
        expect($curlLines[3])->toContain('downloads.rclone.org');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('aborts before any external key fetch when the bootstrap-tooling apt update fails', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch, [
            'bootstrapTooling' => 'missing',
            'aptUpdateFail' => true,
        ]);
        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('ERROR: apt-get update failed');
        expect(bootstrapRuntimeAptLog($scratch))->toBe("apt-get update\n");
        expect(is_file($scratch.'/log/curl.log'))->toBeFalse('a key fetch happened before the tooling existed');
        expect(glob($scratch.'/apt/sources.list.d/rateguru-*') ?: [])->toBe([]);
        expect(glob($scratch.'/keyrings/*') ?: [])->toBe([]);
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('aborts before any external key fetch when the bootstrap-tooling apt install fails', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch, [
            'bootstrapTooling' => 'missing',
            'aptInstallFail' => true,
        ]);
        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('ERROR: apt-get install failed for the bootstrap repository tooling');
        expect(bootstrapRuntimeAptLog($scratch))->toBe(
            "apt-get update\napt-get install -y --no-install-recommends -- ca-certificates curl gnupg\n",
        );
        expect(is_file($scratch.'/log/curl.log'))->toBeFalse('a key fetch happened before the tooling existed');
        expect(glob($scratch.'/apt/sources.list.d/rateguru-*') ?: [])->toBe([]);
        expect(glob($scratch.'/keyrings/*') ?: [])->toBe([]);
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('fails closed when repository work would need curl/gpg that dpkg claims installed but the PATH does not provide', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        // dpkg says everything (including the bootstrap tooling) is
        // installed, so the tooling phase is skipped — but the tools are
        // genuinely absent. The fetch must refuse rather than pretend.
        $env = bootstrapRuntimeFixture($scratch, [
            'bootstrapTooling' => 'missing',
            'phpRepo' => 'absent',
            'pgdgRepo' => 'absent',
        ]);
        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('curl unavailable — bootstrap repository tooling must be installed before any external repository work');
        expect(is_file($scratch.'/log/curl.log'))->toBeFalse();
        expect(glob($scratch.'/apt/sources.list.d/rateguru-*') ?: [])->toBe([]);
        expect(glob($scratch.'/keyrings/*') ?: [])->toBe([]);
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('aborts when apt-get update fails, before any package installation', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch, ['aptUpdateFail' => true]);
        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('ERROR: apt-get update failed');
        expect(bootstrapRuntimeAptLog($scratch))->toBe("apt-get update\n");
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('propagates an apt-get install failure as its own error', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch, ['aptInstallFail' => true]);
        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('ERROR: apt-get install failed');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('requires root for --apply and mutates nothing without it', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch, ['euid' => '1000']);
        $before = bootstrapRuntimeTreeSnapshot($scratch);

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('ERROR: --apply must run as root');
        expect(bootstrapRuntimeTreeSnapshot($scratch))->toBe($before);
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('fails --apply closed on an unsupported OS or architecture before any mutation', function () {
    foreach ([
        ['os' => 'ubuntu-24.04'],
        ['os' => 'debian'],
        ['arch' => 'aarch64'],
    ] as $brokenHost) {
        $scratch = bootstrapRuntimeScratchDir();

        try {
            $env = bootstrapRuntimeCleanHostFixture($scratch, $brokenHost);
            $before = bootstrapRuntimeTreeSnapshot($scratch);

            [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

            expect($exit)->toBe(1, $output);
            expect($output)->toContain('ERROR: unsupported');
            expect(bootstrapRuntimeTreeSnapshot($scratch))->toBe($before, 'a hard-gated apply still mutated the fixture');
            expect(is_file($scratch.'/log/apt.log'))->toBeFalse();
            expect(is_file($scratch.'/log/curl.log'))->toBeFalse();
        } finally {
            bootstrapRuntimeCleanup($scratch);
        }
    }
});

it('never touches RateGuru application paths, users or unrelated host state during --apply', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch);
        $fsBefore = bootstrapRuntimeTreeSnapshot($scratch.'/fs/home-www-rateguru');

        [$exit] = bootstrapRuntimeRun(['--apply'], $env);
        expect($exit)->toBe(0);

        // The decoy application tree is byte-identical.
        expect(bootstrapRuntimeTreeSnapshot($scratch.'/fs/home-www-rateguru'))->toBe($fsBefore);

        // Every mutation is accounted for: apt log holds only update/install,
        // and the only new files are the four installer-owned repo files.
        foreach (explode("\n", trim(bootstrapRuntimeAptLog($scratch))) as $line) {
            expect(
                str_starts_with($line, 'apt-get update') || str_starts_with($line, 'apt-get install'),
            )->toBeTrue("unexpected apt invocation: {$line}");
        }

        $newRepoFiles = array_map(
            fn (string $path): string => basename($path),
            array_merge(
                glob($scratch.'/apt/sources.list.d/rateguru-*') ?: [],
                glob($scratch.'/keyrings/*') ?: [],
            ),
        );
        sort($newRepoFiles);
        expect($newRepoFiles)->toBe([
            'rateguru-pgdg.gpg', 'rateguru-pgdg.sources',
            'rateguru-php.gpg', 'rateguru-php.sources',
        ]);
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

// =============================================================================
// --verify
// =============================================================================

it('verifies the full contract on a compliant host without printing apply hints', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch);
        [$exit, $output] = bootstrapRuntimeRun(['--verify'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('HOST RUNTIME CONTRACT: SATISFIED');
        expect($output)->toContain('PASS     php-modules — all required modules loaded');
        expect($output)->toContain('PASS     tool:createdb');
        expect($output)->toContain('PASS     tool:dropdb');
        expect($output)->not->toContain('-> apply:');

        // Optional development tooling is never part of the runtime
        // contract: the compliant fixture has no shellcheck/actionlint and
        // verify does not even mention them.
        expect($output)->not->toContain('shellcheck');
        expect($output)->not->toContain('actionlint');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('fails --verify when a required runtime binary is missing', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch);
        unlink($scratch.'/tools/unzip');
        [$exit, $output] = bootstrapRuntimeRun(['--verify'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  tool:unzip — not found (package: unzip)');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('verifies PHP extensions through php -m, not dpkg alone', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $modules = implode(' ', array_diff(bootstrapRuntimeRequiredPhpModules(), ['redis', 'pdo_pgsql']));
        $env = bootstrapRuntimeFixture($scratch, ['phpModules' => $modules]);
        [$exit, $output] = bootstrapRuntimeRun(['--verify'], $env);

        expect($exit)->toBe(1, 'all packages are installed, so only the module probe can catch this');
        expect($output)->toContain('PASS     package:php8.5-redis — installed');
        expect($output)->toContain('MISSING  php-modules — not loaded: pdo_pgsql redis');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('fails --verify on a wrong PostgreSQL client major even with all packages installed', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['pgVersion' => '17.2']);
        [$exit, $output] = bootstrapRuntimeRun(['--verify'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT psql — reports PostgreSQL 17.2, required major is 18');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('fails --verify on any drift of an installer-owned .sources file and prints the reconciliation action in --check', function () {
    foreach ([
        'wrong URI' => ['https://ppa.launchpadcontent.net/ondrej/php/ubuntu', 'https://evil.example/ondrej/php/ubuntu'],
        'wrong suite' => ['Suites: jammy', 'Suites: noble'],
        'wrong Signed-By' => ['Signed-By: ', 'Signed-By: /usr/share/keyrings/other-'],
    ] as $case => [$search, $replace]) {
        $scratch = bootstrapRuntimeScratchDir();

        try {
            $env = bootstrapRuntimeFixture($scratch, ['phpRepo' => 'installer-owned', 'pgdgRepo' => 'installer-owned']);

            $sourcesFile = $scratch.'/apt/sources.list.d/rateguru-php.sources';
            file_put_contents($sourcesFile, str_replace($search, $replace, (string) file_get_contents($sourcesFile)));

            [$exit, $output] = bootstrapRuntimeRun(['--verify'], $env);
            expect($exit)->toBe(1, "{$case} must fail --verify:\n{$output}");
            expect($output)->toContain('CONFLICT repo:php — installer-owned');
            expect($output)->toContain('drifted');
            expect($output)->toContain('PASS     repo:pgdg');

            [, $checkOutput] = bootstrapRuntimeRun(['--check'], $env);
            expect($checkOutput)->toContain('-> apply: re-run --apply to reconcile the php repository transactionally');
        } finally {
            bootstrapRuntimeCleanup($scratch);
        }
    }
});

it('fails --verify for a keyring that is garbage, carries a wrong fingerprint, or bundles an extra key', function () {
    // Non-empty garbage that is not an OpenPGP keyring at all.
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['phpRepo' => 'installer-owned', 'pgdgRepo' => 'installer-owned']);
        file_put_contents($scratch.'/keyrings/rateguru-php.gpg', "GARBAGE-NOT-A-KEYRING\n");

        [$exit, $output] = bootstrapRuntimeRun(['--verify'], $env);
        expect($exit)->toBe(1, $output);
        expect($output)->toContain('CONFLICT repo:php — keyring');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }

    // A structurally valid key whose fingerprint is not the pin.
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, [
            'phpRepo' => 'installer-owned',
            'pgdgRepo' => 'installer-owned',
            'fprPhp' => str_repeat('DEADBEEF', 5),
        ]);

        [$exit, $output] = bootstrapRuntimeRun(['--verify'], $env);
        expect($exit)->toBe(1, $output);
        expect($output)->toContain('CONFLICT repo:php — keyring');
        expect($output)->toContain('does not hold exactly the pinned key '.bootstrapRuntimePhpPpaFingerprint());
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }

    // The pinned key plus an extra primary key in the same keyring.
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, [
            'phpRepo' => 'installer-owned',
            'pgdgRepo' => 'installer-owned',
            'gpgExtraKey' => true,
        ]);

        [$exit, $output] = bootstrapRuntimeRun(['--verify'], $env);
        expect($exit)->toBe(1, $output);
        expect($output)->toContain('CONFLICT repo:php — keyring');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('validates installer-owned repositories without any network, apt or key-fetch activity', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['phpRepo' => 'installer-owned', 'pgdgRepo' => 'installer-owned']);
        [$exit, $output] = bootstrapRuntimeRun(['--verify'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('keyring matches pinned '.bootstrapRuntimePhpPpaFingerprint());
        expect($output)->toContain('keyring matches pinned '.bootstrapRuntimePgdgFingerprint());

        expect(is_file($scratch.'/log/apt.log'))->toBeFalse('--verify invoked apt-get');
        expect(is_file($scratch.'/log/curl.log'))->toBeFalse('--verify fetched key material');

        // gpg ran, but only as the local read-only keyring inspection.
        $gpgLog = (string) file_get_contents($scratch.'/log/gpg.log');
        expect($gpgLog)->not->toBe('');
        expect($gpgLog)->not->toContain('--dearmor');
        foreach (array_filter(explode("\n", $gpgLog)) as $line) {
            expect($line)->toContain('--show-keys');
        }
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('keeps --verify strictly read-only', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch, ['tools' => 'minimal']);
        $before = bootstrapRuntimeTreeSnapshot($scratch);

        bootstrapRuntimeRun(['--verify'], $env);

        expect(bootstrapRuntimeTreeSnapshot($scratch))->toBe($before);
        expect(is_file($scratch.'/log/apt.log'))->toBeFalse();
        expect(is_file($scratch.'/log/curl.log'))->toBeFalse();
        expect(is_file($scratch.'/log/gpg.log'))->toBeFalse();
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

// =============================================================================
// Managed external runtime: rclone
// =============================================================================

it('passes the managed rclone check when the exact standalone binary is present without any apt rclone package', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        // The corrected real-staging model: no rclone package in dpkg (the
        // required-package fixture set no longer contains one), a compliant
        // standalone binary at the canonical path.
        $env = bootstrapRuntimeFixture($scratch);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(0, "a compliant standalone rclone must satisfy the contract:\n{$output}");
        expect($output)->toContain('PASS     rclone — v'.bootstrapRuntimeFixtureRcloneVersion([]));
        expect($output)->not->toContain('package:rclone');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('reports the real staging drift — standalone v1.74.4 — as version reconciliation, never as an apt action', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['rcloneInstalled' => 'v1.74.4']);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        $rcloneVersion = bootstrapRuntimeFixtureRcloneVersion([]);
        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT rclone — v1.74.4 installed, required v'.$rcloneVersion);
        expect($output)->toContain('-> apply: replace with verified rclone v'.$rcloneVersion);
        expect($output)->not->toContain('apt-get install rclone');
        expect($output)->not->toContain('package:rclone');

        // The version drift is the only unsatisfied item on this host.
        expect($output)->toContain("MISSING: 0\n");
        expect($output)->toContain("CONFLICT: 1\n");
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('treats the old Ubuntu-package rclone 1.53.3 as version drift even when dpkg lists the package', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        // An operator-installed apt rclone: the dpkg database carries the
        // package (harmless — it is no longer a requirement) and the binary
        // reports the ancient jammy version.
        $env = bootstrapRuntimeFixture($scratch, [
            'packages' => array_merge(bootstrapRuntimeRequiredPackages(), ['rclone']),
            'rcloneInstalled' => 'v1.53.3',
        ]);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT rclone — v1.53.3 installed, required v'.bootstrapRuntimeFixtureRcloneVersion([]));
        expect($output)->not->toContain('package:rclone');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('flags wrong ownership, wrong mode, a foreign path and a version-less binary as CONFLICT', function () {
    // Wrong owner/group: the contract demands root:root, the fixture binary
    // belongs to the test user.
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['rcloneOwner' => 'root', 'rcloneGroup' => 'root']);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain(sprintf(
            'CONFLICT rclone — owned by %s:%s, required root:root',
            bootstrapRuntimeCurrentUser(),
            bootstrapRuntimeCurrentGroup(),
        ));
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }

    // Wrong mode.
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['rcloneFileMode' => '0700']);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT rclone — mode 700, required 0755');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }

    // Foreign path: nothing at the canonical path, but a PATH-resolvable
    // rclone elsewhere.
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['rcloneInstalled' => 'absent']);
        bootstrapRuntimeWriteStub($scratch.'/tools/rclone', "#!/bin/bash\nexit 0\n");
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain(sprintf(
            'CONFLICT rclone — found at %s, but the managed binary path is %s',
            $scratch.'/tools/rclone',
            $scratch.'/fs/usr-bin/rclone',
        ));
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }

    // Malformed version output.
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['rcloneInstalled' => 'broken']);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT rclone — '.$scratch.'/fs/usr-bin/rclone cannot report a version');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('upgrades the real staging v1.74.4 atomically through signed, checksummed, version-verified material', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['rcloneInstalled' => 'v1.74.4']);
        $rcloneVersion = bootstrapRuntimeFixtureRcloneVersion([]);
        $confBefore = (string) file_get_contents($scratch.'/fs/root-config-rclone/rclone.conf');

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(0, "the staged upgrade must converge and verify:\n{$output}");
        expect($output)->toContain('rclone requires reconciliation (state: version:v1.74.4)');
        expect($output)->toContain('rclone: signature and checksum verified — extracting');
        expect($output)->toContain('rclone v'.$rcloneVersion.' installed at '.$scratch.'/fs/usr-bin/rclone');
        expect($output)->toContain('PASS     rclone — v'.$rcloneVersion);
        expect($output)->toContain('HOST RUNTIME CONTRACT: SATISFIED');

        // The binary was replaced (it is now the extracted stub) with the
        // contract mode, and no staged temp file was left beside it.
        expect((string) file_get_contents($scratch.'/fs/usr-bin/rclone'))->toContain('STUB_EXTRACTED_RCLONE_VERSION');
        expect(fileperms($scratch.'/fs/usr-bin/rclone') & 0o777)->toBe(0o755);
        expect(glob($scratch.'/fs/usr-bin/rclone.*') ?: [])->toBe([]);

        // Exactly two HTTPS downloads from the official origin: the exact
        // versioned artifact and its SHA256SUMS. No apt activity at all.
        $curlLines = array_values(array_filter(explode("\n", (string) file_get_contents($scratch.'/log/curl.log'))));
        expect(count($curlLines))->toBe(2, 'unexpected download activity');
        expect($curlLines[0])->toContain("https://downloads.rclone.org/v{$rcloneVersion}/rclone-v{$rcloneVersion}-linux-amd64.zip");
        expect($curlLines[1])->toContain("https://downloads.rclone.org/v{$rcloneVersion}/SHA256SUMS");
        expect(is_file($scratch.'/log/apt.log'))->toBeFalse('the rclone upgrade must never touch apt');

        // The operator's rclone.conf is byte-identical, and the managed
        // binary was only ever asked for --version — never `config`, never
        // `selfupdate`.
        expect((string) file_get_contents($scratch.'/fs/root-config-rclone/rclone.conf'))->toBe($confBefore);
        foreach (array_filter(explode("\n", (string) file_get_contents($scratch.'/log/rclone.log'))) as $line) {
            expect($line)->toBe('rclone --version');
        }

        // A second apply is a no-op: no downloads, no replacement.
        file_put_contents($scratch.'/log/curl.log', '');
        $binaryBefore = (string) file_get_contents($scratch.'/fs/usr-bin/rclone');

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('rclone v'.$rcloneVersion.' already installed');
        expect((string) file_get_contents($scratch.'/log/curl.log'))->toBe('');
        expect((string) file_get_contents($scratch.'/fs/usr-bin/rclone'))->toBe($binaryBefore);
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('leaves the working rclone untouched when the download, signature, checksum, archive or extracted version fails', function () {
    foreach ([
        'download failure' => ['curlFailPattern' => 'downloads.rclone.org'],
        'bad SHA256SUMS signature' => ['rcloneBadSignature' => true],
        'wrong signing-key fingerprint' => ['fprRclone' => str_repeat('DEADBEEF', 5)],
        'wrong artifact checksum' => ['rcloneSumsDigest' => hash('sha256', 'not-the-artifact')],
        'archive missing rclone' => ['rcloneOmitBinary' => true],
        'extracted binary reports wrong version' => ['rcloneExtractedVersion' => 'v1.74.9'],
    ] as $case => $breakage) {
        $scratch = bootstrapRuntimeScratchDir();

        try {
            $env = bootstrapRuntimeFixture($scratch, array_merge(['rcloneInstalled' => 'v1.74.4'], $breakage));
            $binaryBefore = (string) file_get_contents($scratch.'/fs/usr-bin/rclone');

            [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

            expect($exit)->toBe(1, "{$case} must abort the apply:\n{$output}");
            expect($output)->toContain('ERROR: rclone:');

            expect((string) file_get_contents($scratch.'/fs/usr-bin/rclone'))
                ->toBe($binaryBefore, "{$case}: the working binary was modified");
            expect(glob($scratch.'/fs/usr-bin/rclone.*') ?: [])
                ->toBe([], "{$case}: a staged candidate was left behind");
        } finally {
            bootstrapRuntimeCleanup($scratch);
        }
    }
});

it('names the failure precisely for each broken verification step', function () {
    $rcloneVersion = bootstrapRuntimeFixtureRcloneVersion([]);

    foreach ([
        ['rcloneBadSignature' => true, 'SHA256SUMS signature verification failed'],
        ['fprRclone' => str_repeat('DEADBEEF', 5), 'does not match the pinned fingerprint '.bootstrapRuntimeRcloneFingerprint()],
        ['rcloneSumsDigest' => hash('sha256', 'not-the-artifact'), 'checksum mismatch'],
        ['rcloneOmitBinary' => true, 'does not contain the rclone binary'],
        ['rcloneExtractedVersion' => 'v1.74.9', "extracted binary reports v1.74.9, expected v{$rcloneVersion}"],
    ] as $case) {
        $message = array_pop($case);
        $scratch = bootstrapRuntimeScratchDir();

        try {
            $env = bootstrapRuntimeFixture($scratch, array_merge(['rcloneInstalled' => 'absent'], $case));
            [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

            expect($exit)->toBe(1);
            expect($output)->toContain($message);
        } finally {
            bootstrapRuntimeCleanup($scratch);
        }
    }
});

it('keeps the operator rclone.conf byte-identical across a clean-host apply and never invokes rclone beyond --version', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch);
        $confBefore = (string) file_get_contents($scratch.'/fs/root-config-rclone/rclone.conf');

        [$exit] = bootstrapRuntimeRun(['--apply'], $env);
        expect($exit)->toBe(0);

        expect((string) file_get_contents($scratch.'/fs/root-config-rclone/rclone.conf'))->toBe($confBefore);

        $rcloneLog = (string) file_get_contents($scratch.'/log/rclone.log');
        expect($rcloneLog)->not->toBe('', 'the verified install must have version-probed the binary');
        foreach (array_filter(explode("\n", $rcloneLog)) as $line) {
            expect($line)->toBe('rclone --version');
        }
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('fails closed when the external-runtimes contract is unreadable or mangled', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch);
        file_put_contents($scratch.'/fs/external-runtimes.env', "RCLONE_VERSION=latest\n");

        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);
        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT rclone — external-runtimes contract unreadable or incomplete');

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);
        expect($exit)->toBe(1);
        expect($output)->toContain('ERROR: rclone: external-runtimes contract unreadable or incomplete');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

// =============================================================================
// Security posture
// =============================================================================

it('never uses apt-key, eval, bash -c or wget', function () {
    $source = bootstrapRuntimeSource();

    // Comment lines may mention the forbidden commands (the header
    // documents the policy); no code line may invoke them.
    expect(preg_match('/^[^#\n]*\bapt-key\b/m', $source))->toBe(0, 'a code line invokes apt-key');
    expect(preg_match('/^[^#\n]*\beval\b/m', $source))->toBe(0, 'a code line uses eval');
    expect(preg_match('/^[^#\n]*\bwget\b/m', $source))->toBe(0, 'a code line uses wget');
    expect($source)->not->toContain('bash -c');
});

it('pins HTTPS-only key sources and dedicated keyrings under /etc/apt/keyrings', function () {
    $source = bootstrapRuntimeSource();

    expect($source)->toContain('PHP_REPO_KEY_URL="https://');
    expect($source)->toContain('PGDG_REPO_KEY_URL="https://');
    expect($source)->toContain('PHP_REPO_URI="https://');
    expect($source)->toContain('PGDG_REPO_URI="https://');
    expect($source)->toContain("--proto '=https' --tlsv1.2");
    expect($source)->toContain('RATEGURU_BOOTSTRAP_APT_KEYRINGS_DIR /etc/apt/keyrings');
    expect($source)->toContain('rateguru-php.gpg');
    expect($source)->toContain('rateguru-pgdg.gpg');
});

it('honors RATEGURU_BOOTSTRAP_* overrides only alongside the explicit test-override gate', function () {
    $source = bootstrapRuntimeSource();

    // Every override is read through gated_default — no direct expansion.
    preg_match_all('/RATEGURU_BOOTSTRAP_[A-Z_]+/', $source, $matches);
    $overrides = array_unique($matches[0]);
    expect($overrides)->not->toBe([]);

    foreach ($overrides as $override) {
        expect(
            preg_match('/gated_default '.preg_quote($override, '/').' /', $source),
        )->toBe(1, "{$override} must be read exactly once, through gated_default");
    }

    // Behaviorally: without the gate, a fixture override must be ignored.
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['os' => 'debian']);
        unset($env['RATEGURU_ALLOW_TEST_OVERRIDES']);

        [, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($output)->not->toContain('ID=debian');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('runs apt noninteractively and deterministically', function () {
    $source = bootstrapRuntimeSource();

    expect($source)->toContain('DEBIAN_FRONTEND=noninteractive');
    expect($source)->toContain('--no-install-recommends');
});

// =============================================================================
// Contract parity with the rest of the repository
// =============================================================================

it('keeps the OS baseline pins byte-identical to bootstrap-host-preflight', function () {
    $installer = bootstrapRuntimeSource();
    $preflight = File::get(base_path('infrastructure/scripts/bootstrap-host-preflight'));

    foreach (['SUPPORTED_OS_ID', 'SUPPORTED_OS_VERSION_ID'] as $pin) {
        preg_match('/^'.$pin.'="([^"]+)"$/m', $installer, $installerPin);
        preg_match('/^'.$pin.'="([^"]+)"$/m', $preflight, $preflightPin);

        expect($installerPin[1] ?? null)->not->toBeNull("installer does not pin {$pin}");
        expect($preflightPin[1] ?? null)->not->toBeNull("preflight does not pin {$pin}");
        expect($installerPin[1])->toBe($preflightPin[1], "{$pin} drifted between preflight and installer");
    }
});

it('keeps the PHP series aligned with the committed deployment.conf template', function () {
    $installer = bootstrapRuntimeSource();
    $template = File::get(base_path('infrastructure/templates/deployment.conf.example'));

    preg_match('/^PHP_SERIES="([^"]+)"$/m', $installer, $series);
    expect($series[1] ?? null)->not->toBeNull();

    expect($template)->toContain('PHP_BIN=/usr/bin/php'.$series[1]);
    expect($template)->toContain('PHP_FPM_SERVICE=php'.$series[1].'-fpm');
});

/**
 * The shared build action, parsed.
 */
function buildActionDefinition(): array
{
    return Yaml::parse(File::get(base_path('.github/actions/build-rateguru/action.yml')));
}

/**
 * The setup-php extension list the shared build action declares, read from
 * the step that actually configures PHP rather than by first-match regex: any
 * other `extensions:` key in the file — a later step, a different action, a
 * commented example — must never be able to stand in for it.
 *
 * @return list<string>
 */
function buildActionPhpExtensions(array $action): array
{
    $step = collect(data_get($action, 'runs.steps'))
        ->first(fn (array $step): bool => data_get($step, 'name') === 'Setup PHP');

    $extensions = data_get($step, 'with.extensions');

    if (! is_string($extensions) || trim($extensions) === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $extensions))));
}

it('covers every PHP extension the deploy workflow and composer.json require', function () {
    $installer = bootstrapRuntimeSource();

    preg_match('/^REQUIRED_PHP_MODULES=\(([^)]+)\)$/m', $installer, $modulesMatch);
    expect($modulesMatch[1] ?? null)->not->toBeNull();
    $verifiedModules = preg_split('/\s+/', trim($modulesMatch[1]));

    // The deployment build's PHP toolchain is declared once, in the shared
    // build action both deployment workflows call.
    $workflowExtensions = buildActionPhpExtensions(buildActionDefinition());
    expect($workflowExtensions)->not->toBeEmpty('the shared build action no longer declares setup-php extensions');

    foreach ($workflowExtensions as $extension) {
        expect(in_array($extension, $verifiedModules, true))
            ->toBeTrue("deploy build extension {$extension} is not verified by the installer");
    }

    $composer = json_decode(File::get(base_path('composer.json')), true);
    foreach (array_keys($composer['require']) as $requirement) {
        if (str_starts_with($requirement, 'ext-')) {
            $extension = substr($requirement, 4);
            expect(in_array($extension, $verifiedModules, true))
                ->toBeTrue("composer.json {$requirement} is not verified by the installer");
        }
    }
});

it('reads the build action PHP extensions from the Setup PHP step, never from another extensions key', function () {
    // A first-match regex over the file would have taken this decoy — a
    // different step's unrelated `extensions:` — and then compared the wrong
    // list against the installer's verified modules, passing or failing for a
    // reason that has nothing to do with the PHP runtime contract.
    $decoy = Yaml::parse(<<<'YAML'
        runs:
          using: composite
          steps:
            - name: Setup Node
              with:
                extensions: not-a-php-extension
            - name: Setup PHP
              with:
                extensions: bcmath, curl
            - name: Later step
              with:
                extensions: also-not-a-php-extension
        YAML);

    expect(buildActionPhpExtensions($decoy))->toBe(['bcmath', 'curl']);

    // An action with no Setup PHP step resolves to nothing rather than to
    // some other step's value, so the caller's non-empty assertion catches it.
    expect(buildActionPhpExtensions(['runs' => ['steps' => [['name' => 'Setup Node', 'with' => ['extensions' => 'x']]]]]))
        ->toBe([]);

    // And the real action still resolves through that same path.
    expect(buildActionPhpExtensions(buildActionDefinition()))
        ->toContain('pdo_pgsql')
        ->toContain('redis');
});

it('never installs build-time or unwanted packages: no Node.js, npm, Composer, SQLite or dev validators', function () {
    $installer = bootstrapRuntimeSource();

    preg_match('/^BASE_PACKAGES=\(\n(.*?)\n\)$/ms', $installer, $baseMatch);
    expect($baseMatch[1] ?? null)->not->toBeNull();

    $packages = array_values(array_filter(array_map('trim', explode("\n", $baseMatch[1]))));

    foreach (['nodejs', 'npm', 'composer', 'sqlite3', 'php8.5-sqlite3', 'shellcheck', 'actionlint', 'wget'] as $forbidden) {
        expect($packages)->not->toContain($forbidden);
    }

    // The php package family is bcmath..zip only — sqlite/readline/igbinary
    // are deliberately absent (igbinary arrives as a php8.5-redis
    // dependency; readline is not a runtime requirement).
    preg_match('/^for _php_component in ([^;]+);/m', $installer, $phpMatch);
    expect($phpMatch[1] ?? null)->not->toBeNull();
    $phpComponents = preg_split('/\s+/', trim($phpMatch[1]));
    expect($phpComponents)->toBe(['cli', 'common', 'fpm', 'bcmath', 'curl', 'gd', 'intl', 'mbstring', 'pgsql', 'redis', 'xml', 'zip']);
});

it('never converges rclone through apt, selfupdate, the upstream installer or the operator configuration', function () {
    $source = bootstrapRuntimeSource();

    // The package contract must not name rclone at all — neither as a
    // package nor as a package-supplied tool.
    preg_match('/^BASE_PACKAGES=\(\n(.*?)\n\)$/ms', $source, $baseMatch);
    expect($baseMatch[1] ?? null)->not->toBeNull();
    $packages = array_values(array_filter(array_map('trim', explode("\n", $baseMatch[1]))));
    expect($packages)->not->toContain('rclone');

    preg_match('/^REQUIRED_RUNTIME_TOOLS=\(\n(.*?)\n\)$/ms', $source, $toolsMatch);
    expect($toolsMatch[1] ?? null)->not->toBeNull();
    expect($toolsMatch[1])->not->toContain('rclone:rclone');

    // Comment lines may document the forbidden mechanisms; no code line may
    // use them: no selfupdate, no upstream install.sh, no pipe-to-shell
    // download, and no reference to the operator's rclone.conf at all.
    expect(preg_match('/^[^#\n]*\bselfupdate\b/m', $source))->toBe(0, 'a code line invokes rclone selfupdate');
    expect(preg_match('/^[^#\n]*install\.sh/m', $source))->toBe(0, 'a code line references the upstream install.sh');
    expect(preg_match('/^[^#\n]*curl[^#\n]*\|\s*(ba)?sh\b/m', $source))->toBe(0, 'a code line pipes a download into a shell');
    expect(preg_match('/^[^#\n]*rclone\.conf/m', $source))->toBe(0, 'a code line references the operator rclone.conf');
    expect(preg_match('/^[^#\n]*rclone config\b/m', $source))->toBe(0, 'a code line runs rclone config');
});

it('commits the external-runtimes contract with the canonical rclone install contract and pinned fingerprint', function () {
    $contract = bootstrapRuntimeCommittedRcloneContract();

    // The exact version is deliberately not duplicated here: it lives only
    // in the committed contract, and an upgrade is that file's own reviewed
    // change. Shape and security-critical pins are what this test freezes.
    expect($contract['RCLONE_VERSION'] ?? null)->toMatch('/^\d+\.\d+\.\d+$/');
    expect($contract['RCLONE_PLATFORM'] ?? null)->toBe('linux-amd64');
    expect($contract['RCLONE_BINARY'] ?? null)->toBe('/usr/bin/rclone');
    expect($contract['RCLONE_OWNER'] ?? null)->toBe('root');
    expect($contract['RCLONE_GROUP'] ?? null)->toBe('root');
    expect($contract['RCLONE_MODE'] ?? null)->toBe('0755');
    expect($contract['RCLONE_RELEASE_SIGNING_FINGERPRINT'] ?? null)->toBe(bootstrapRuntimeRcloneFingerprint());
});

it('commits the rclone release-signing public key holding exactly the pinned fingerprint', function () {
    $keyPath = base_path('infrastructure/config/external-runtimes/rclone-release-signing-key.asc');
    $key = File::get($keyPath);

    expect($key)->toStartWith('-----BEGIN PGP PUBLIC KEY BLOCK-----');
    expect(trim($key))->toEndWith('-----END PGP PUBLIC KEY BLOCK-----');

    // With gpg available (always, on the Ubuntu CI runners), apply the
    // installer's own acceptance to the committed material: exactly one
    // primary key whose fingerprint equals the pin.
    $gpg = trim((string) shell_exec('command -v gpg'));

    if ($gpg === '') {
        return;
    }

    exec($gpg.' --batch --show-keys --with-colons '.escapeshellarg($keyPath).' 2>/dev/null', $lines, $status);
    expect($status)->toBe(0, 'the committed key is not a valid OpenPGP key');

    $primaries = 0;
    $fingerprint = null;
    $expectFpr = false;

    foreach ($lines as $line) {
        $fields = explode(':', $line);

        if ($fields[0] === 'pub') {
            $primaries++;
            $expectFpr = true;

            continue;
        }

        if ($fields[0] === 'fpr' && $expectFpr) {
            $fingerprint ??= $fields[9];
            $expectFpr = false;
        }
    }

    expect($primaries)->toBe(1, 'the committed key must hold exactly one primary key');
    expect($fingerprint)->toBe(bootstrapRuntimeRcloneFingerprint());
});

it('shares the committed external-runtimes contract with bootstrap-host-preflight instead of duplicating the pin', function () {
    $contractPath = 'infrastructure/config/external-runtimes/versions.env';

    expect(bootstrapRuntimeSource())->toContain($contractPath);
    expect(File::get(base_path('infrastructure/scripts/bootstrap-host-preflight')))->toContain($contractPath);
});

it('derives its required tool inventory from the clean-host bootstrap.1 canonical contract', function () {
    $preflight = File::get(base_path('infrastructure/scripts/bootstrap-host-preflight'));
    $installerPackages = bootstrapRuntimeRequiredPackages();

    // Every package the preflight's REQUIRED_BASE_TOOLS inventory names
    // (except bash-builtins carriers already guaranteed by Ubuntu's
    // essential set) must be in the installer's required package list.
    preg_match('/^REQUIRED_BASE_TOOLS=\(\n(.*?)\n\)$/ms', $preflight, $match);
    expect($match[1] ?? null)->not->toBeNull();

    foreach (array_filter(array_map('trim', explode("\n", $match[1]))) as $entry) {
        [, $package] = explode(':', $entry);

        expect(in_array($package, $installerPackages, true))
            ->toBeTrue("preflight requires package {$package}, but the installer does not install it");
    }
});
