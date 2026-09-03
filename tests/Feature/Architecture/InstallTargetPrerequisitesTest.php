<?php

use Illuminate\Support\Facades\File;

/**
 * Prepare Host: infrastructure/scripts/install-target-prerequisites — the safe
 * delivery of operator-supplied external material onto a host, and the refusal
 * to overwrite or rotate anything already there.
 *
 * Every test runs the real, shipped script as a subprocess against a scratch
 * filesystem root, through the RATEGURU_TARGETPREREQ_* overrides it honors only
 * alongside RATEGURU_ALLOW_TEST_OVERRIDES=true. Nothing here touches /etc.
 */
function itpScript(): string
{
    return base_path('infrastructure/scripts/install-target-prerequisites');
}

function itpScratchDir(): string
{
    $dir = sys_get_temp_dir().'/target-prereq-'.uniqid('', true).'-'.getmypid();
    expect(@mkdir($dir.'/root/material', 0o700, true))->toBeTrue();
    chmod($dir.'/root/material', 0o700);

    return $dir;
}

function itpCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $environment
 * @return array{0: int, 1: string}
 */
function itpRun(string $scratch, array $arguments, array $environment = []): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(
        array_merge(['bash', itpScript()], $arguments),
        $descriptors,
        $pipes,
        null,
        array_merge([
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: '/tmp',
            'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
            'RATEGURU_TARGETPREREQ_EUID' => '0',
            'RATEGURU_TARGETPREREQ_FS_ROOT' => $scratch,
            // The scratch tree has no rateguru-staging, www-data or root-owned
            // files, so ownership comparison is off by default. The test that
            // exercises it turns it on, and every row then mismatches — the
            // first one reached is shared/.env, declared
            // rateguru-staging:rateguru-staging.
            'RATEGURU_TARGETPREREQ_ENFORCE_OWNERSHIP' => 'false',
        ], $environment),
    );

    expect($process)->not->toBeFalse();

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

/** @param  list<string>  $names */
function itpSupply(string $scratch, array $names, string $content = 'material-content'): void
{
    foreach ($names as $name) {
        file_put_contents($scratch.'/root/material/'.$name, $content."-{$name}\n");
        chmod($scratch.'/root/material/'.$name, 0o600);
    }
}

/**
 * A genuine certbot layout for one certificate: numbered files in `archive/`
 * and the two stable links in `live/`, with certbot's own modes.
 */
function itpCertbotCertificate(string $scratch, string $certificate): void
{
    mkdir($scratch.'/etc/letsencrypt/archive/'.$certificate, 0o755, true);
    mkdir($scratch.'/etc/letsencrypt/live/'.$certificate, 0o755, true);

    foreach (['fullchain' => 0o644, 'privkey' => 0o600] as $leaf => $mode) {
        $archived = $scratch.'/etc/letsencrypt/archive/'.$certificate.'/'.$leaf.'1.pem';

        file_put_contents($archived, "certbot-{$leaf}\n");
        chmod($archived, $mode);

        symlink(
            '../../archive/'.$certificate.'/'.$leaf.'1.pem',
            $scratch.'/etc/letsencrypt/live/'.$certificate.'/'.$leaf.'.pem',
        );
    }
}

/**
 * Every host-scope destination present with the mode the table declares, so a
 * test can make exactly one of them wrong and see that one reported.
 */
function itpValidHostScope(string $scratch): void
{
    itpCertbotCertificate($scratch, 'rateguru.staging.myprojects.pp.ua');
    itpCertbotCertificate($scratch, 'staging-mail-capture');

    mkdir($scratch.'/etc/nginx', 0o755, true);
    file_put_contents($scratch.'/etc/nginx/rateguru-staging.htpasswd', "hashes\n");
    chmod($scratch.'/etc/nginx/rateguru-staging.htpasswd', 0o640);

    foreach ([
        '/etc/letsencrypt/options-ssl-nginx.conf',
        '/etc/letsencrypt/ssl-dhparams.pem',
    ] as $shared) {
        file_put_contents($scratch.$shared, "shared\n");
        chmod($scratch.$shared, 0o644);
    }
}

/** The identities and directories install-bootstrap-host-layout creates. */
function itpCreateTargetDirectories(string $scratch): void
{
    foreach ([
        '/home/www/rateguru/staging/shared',
        '/home/deploy-rateguru-staging/.ssh',
        '/root/.config/rclone',
    ] as $dir) {
        mkdir($scratch.$dir, 0o755, true);
    }
}

const ITP_HOST_MATERIAL = [
    'basic-auth',
    'tls-certificate',
    'tls-private-key',
    'nginx-tls-options',
    'tls-dhparams',
    'mail-tls-certificate',
    'mail-tls-private-key',
];

const ITP_TARGET_MATERIAL = [
    'laravel-env',
    'deploy-authorized-keys',
    'rclone-config',
];

// =============================================================================
// The prerequisite set is derived, never hard-coded in GitHub
// =============================================================================

it('derives every Nginx-referenced destination from the committed vhosts', function () {
    $scratch = itpScratchDir();

    try {
        [$exit, $output] = itpRun($scratch, ['--check', '--target', 'staging-main', '--scope', 'host']);

        expect($exit)->toBe(1, 'a bare host satisfies nothing yet');

        // Exactly the files install-bootstrap-services fails closed on, with
        // the paths taken straight out of the committed vhosts.
        foreach ([
            '/etc/nginx/rateguru-staging.htpasswd',
            '/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/fullchain.pem',
            '/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/privkey.pem',
            '/etc/letsencrypt/live/staging-mail-capture/fullchain.pem',
            '/etc/letsencrypt/live/staging-mail-capture/privkey.pem',
            '/etc/letsencrypt/options-ssl-nginx.conf',
            '/etc/letsencrypt/ssl-dhparams.pem',
        ] as $destination) {
            expect($output)->toContain($destination);
        }

        expect($output)->toContain('missing 7');
    } finally {
        itpCleanup($scratch);
    }
});

it('uses the same external-prerequisite contract install-bootstrap-services gates on', function () {
    $prerequisites = File::get(itpScript());
    $services = File::get(base_path('infrastructure/scripts/install-bootstrap-services'));

    // If the two ever diverged, preparation would produce a host that 5.4
    // still refuses to converge. The directive set and the glob exclusion are
    // therefore pinned together.
    $awk = "awk '\$1 ~ /^(auth_basic_user_file|ssl_certificate|ssl_certificate_key|ssl_dhparam|include)\$/ { print \$1, \$2 }'";

    expect($prerequisites)->toContain($awk);
    expect($services)->toContain($awk);

    foreach ([$prerequisites, $services] as $source) {
        expect($source)->toContain("*'*'*|*'?'*|*'['*) ;;");
    }

    // And the same two shared mail-capture vhosts, listed rather than globbed.
    foreach (['mailpit-staging', 'mailtrap-local-staging'] as $vhost) {
        expect($prerequisites)->toContain('/'.$vhost.'"');
        expect($services)->toContain('/'.$vhost.'"');
    }
});

it('derives target-scope destinations from the registry', function () {
    $scratch = itpScratchDir();

    try {
        [, $output] = itpRun($scratch, ['--check', '--target', 'staging-main', '--scope', 'target']);

        expect($output)->toContain('/home/www/rateguru/staging/shared/.env');
        expect($output)->toContain('/home/deploy-rateguru-staging/.ssh/authorized_keys');
        expect($output)->toContain('/root/.config/rclone/rclone.conf');
        expect($output)->toContain('missing 3');
    } finally {
        itpCleanup($scratch);
    }
});

// =============================================================================
// Installing what is absent
// =============================================================================

it('installs missing material with the right modes and never reads its content', function () {
    $scratch = itpScratchDir();

    try {
        itpSupply($scratch, ITP_HOST_MATERIAL);

        [$exit, $output] = itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'host',
            '--material-dir', '/root/material',
        ]);

        expect($exit)->toBe(0);
        expect($output)->toContain('content never read or logged');
        expect($output)->not->toContain('material-content');

        $expectedModes = [
            '/etc/nginx/rateguru-staging.htpasswd' => '0640',
            '/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/fullchain.pem' => '0644',
            '/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/privkey.pem' => '0600',
            '/etc/letsencrypt/live/staging-mail-capture/privkey.pem' => '0600',
            '/etc/letsencrypt/ssl-dhparams.pem' => '0644',
        ];

        foreach ($expectedModes as $path => $mode) {
            expect(is_file($scratch.$path))->toBeTrue("{$path} should have been installed");
            expect(substr(sprintf('%04o', fileperms($scratch.$path)), -4))->toBe($mode,
                "{$path} must be installed with mode {$mode}");
        }
    } finally {
        itpCleanup($scratch);
    }
});

it('refuses to install target-scope material before host bootstrap created its directories', function () {
    $scratch = itpScratchDir();

    try {
        itpSupply($scratch, ITP_TARGET_MATERIAL);

        [$exit, $output] = itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'target',
            '--material-dir', '/root/material',
        ]);

        expect($exit)->toBe(1);
        expect($output)->toContain('its owner is created by host bootstrap');

        // A directory created here with the wrong owner is a directory slice
        // 5.3 then has to disagree with, so nothing was created.
        expect(is_dir($scratch.'/home/www/rateguru/staging/shared'))->toBeFalse();
    } finally {
        itpCleanup($scratch);
    }
});

it('installs target-scope material once its directories exist', function () {
    $scratch = itpScratchDir();

    try {
        itpCreateTargetDirectories($scratch);
        itpSupply($scratch, ITP_TARGET_MATERIAL);

        [$exit] = itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'target',
            '--material-dir', '/root/material',
        ]);

        expect($exit)->toBe(0);
        expect(substr(sprintf('%04o', fileperms($scratch.'/home/www/rateguru/staging/shared/.env')), -4))->toBe('0640');
        expect(substr(sprintf('%04o', fileperms($scratch.'/home/deploy-rateguru-staging/.ssh/authorized_keys')), -4))->toBe('0600');
        expect(substr(sprintf('%04o', fileperms($scratch.'/root/.config/rclone/rclone.conf')), -4))->toBe('0600');
    } finally {
        itpCleanup($scratch);
    }
});

// =============================================================================
// Safe existing-file semantics — the heart of the contract
// =============================================================================

it('preserves existing material and does not even rewrite an identical file', function () {
    $scratch = itpScratchDir();

    try {
        itpSupply($scratch, ITP_HOST_MATERIAL);

        [$firstExit] = itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'host',
            '--material-dir', '/root/material',
        ]);
        expect($firstExit)->toBe(0);

        $key = $scratch.'/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/privkey.pem';
        $inodeBefore = fileinode($key);

        [$secondExit, $secondOutput] = itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'host',
            '--material-dir', '/root/material',
        ]);

        expect($secondExit)->toBe(0);
        expect(substr_count($secondOutput, 'already present and identical to the supplied material'))->toBe(7);
        expect($secondOutput)->not->toContain('INSTALLED');

        // Not rewritten at all: the file on disk is literally the same file.
        expect(fileinode($key))->toBe($inodeBefore);
    } finally {
        itpCleanup($scratch);
    }
});

it('preserves an existing file when no material is supplied for it', function () {
    $scratch = itpScratchDir();

    try {
        mkdir($scratch.'/etc/nginx', 0o755, true);
        file_put_contents($scratch.'/etc/nginx/rateguru-staging.htpasswd', "live-hashes\n");

        [, $output] = itpRun($scratch, ['--check', '--target', 'staging-main', '--scope', 'host']);

        expect($output)->toContain('already present; left untouched');
        expect(File::get($scratch.'/etc/nginx/rateguru-staging.htpasswd'))->toBe("live-hashes\n");
    } finally {
        itpCleanup($scratch);
    }
});

it('fails closed rather than rotating a secret that differs from the supplied material', function () {
    $scratch = itpScratchDir();

    try {
        itpSupply($scratch, ITP_HOST_MATERIAL);
        itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'host',
            '--material-dir', '/root/material',
        ]);

        $key = $scratch.'/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/privkey.pem';
        $liveContent = File::get($key);

        // A rotated secret in GitHub, against a host still holding the old one.
        file_put_contents($scratch.'/root/material/tls-private-key', "A-DIFFERENT-PRIVATE-KEY\n");

        [$exit, $output] = itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'host',
            '--material-dir', '/root/material',
        ]);

        expect($exit)->toBe(1);
        expect($output)->toContain('DIFFERS from the supplied material');
        expect($output)->toContain('rotation is a separate deliberate operation');

        // The live key is untouched — this is the whole point.
        expect(File::get($key))->toBe($liveContent);
    } finally {
        itpCleanup($scratch);
    }
});

it('refuses before installing anything when an existing prerequisite has drifted', function () {
    $scratch = itpScratchDir();

    try {
        itpSupply($scratch, ITP_HOST_MATERIAL);

        // One row already present but with the wrong mode, every other row
        // absent. Installing the absent ones first and only then discovering
        // the drift would leave the host half-converged on a path that fails
        // closed by design.
        mkdir($scratch.'/etc/nginx', 0o755, true);
        file_put_contents($scratch.'/etc/nginx/rateguru-staging.htpasswd', "material-content-basic-auth\n");
        chmod($scratch.'/etc/nginx/rateguru-staging.htpasswd', 0o600);

        [$exit, $output] = itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'host',
            '--material-dir', '/root/material',
        ]);

        expect($exit)->toBe(1);
        expect($output)->toContain('has mode 600, expected 0640');
        expect($output)->not->toContain('INSTALLED');

        expect(is_dir($scratch.'/etc/letsencrypt'))->toBeFalse('nothing may be installed before the drift is reported');
    } finally {
        itpCleanup($scratch);
    }
});

it('refuses the entire run on a conflict, before installing anything else', function () {
    $scratch = itpScratchDir();

    try {
        itpSupply($scratch, ITP_HOST_MATERIAL);

        // One destination already present and different; the rest absent.
        mkdir($scratch.'/etc/nginx', 0o755, true);
        file_put_contents($scratch.'/etc/nginx/rateguru-staging.htpasswd', "existing-and-different\n");

        [$exit] = itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'host',
            '--material-dir', '/root/material',
        ]);

        expect($exit)->toBe(1);

        // Nothing was installed: a half-converged host with one new secret
        // beside a conflicting old one is worse than an unconverged one.
        expect(is_dir($scratch.'/etc/letsencrypt'))->toBeFalse();
    } finally {
        itpCleanup($scratch);
    }
});

it('never discloses secret content, length or a digest in a conflict diagnostic', function () {
    $scratch = itpScratchDir();

    try {
        mkdir($scratch.'/etc/nginx', 0o755, true);
        file_put_contents($scratch.'/etc/nginx/rateguru-staging.htpasswd', "LIVE-SECRET-VALUE\n");
        file_put_contents($scratch.'/root/material/basic-auth', "SUPPLIED-SECRET-VALUE\n");

        [$exit, $output] = itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'host',
            '--material-dir', '/root/material',
        ]);

        expect($exit)->toBe(1);
        expect($output)->not->toContain('LIVE-SECRET-VALUE');
        expect($output)->not->toContain('SUPPLIED-SECRET-VALUE');

        // No diff, no hash, no byte offset either.
        expect($output)->not->toMatch('/\bdiffer.* byte \d+/');
        expect($output)->not->toMatch('/[0-9a-f]{32,}/');
    } finally {
        itpCleanup($scratch);
    }
});

it('generates nothing when material is absent', function () {
    $scratch = itpScratchDir();

    try {
        [$exit, $output] = itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'host',
            '--material-dir', '/root/material',
        ]);

        expect($exit)->toBe(1);
        expect($output)->toContain('absent and no material supplied');
        expect($output)->toContain('it is never generated here');
        expect(is_dir($scratch.'/etc'))->toBeFalse();
    } finally {
        itpCleanup($scratch);
    }
});

it('accepts a real certbot layout and never replaces it', function () {
    $scratch = itpScratchDir();

    try {
        itpValidHostScope($scratch);

        [$exit, $output] = itpRun($scratch, ['--verify', '--target', 'staging-main', '--scope', 'host']);

        expect($exit)->toBe(0, $output);

        // And a re-run with differing material still refuses to write through
        // the link.
        itpSupply($scratch, ITP_HOST_MATERIAL);

        [$exit] = itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'host',
            '--material-dir', '/root/material',
        ]);

        expect($exit)->toBe(1, 'differing material must still fail closed');
        expect(is_link($scratch.'/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/privkey.pem'))->toBeTrue();
        expect(File::get($scratch.'/etc/letsencrypt/archive/rateguru.staging.myprojects.pp.ua/privkey1.pem'))
            ->toBe("certbot-privkey\n");
    } finally {
        itpCleanup($scratch);
    }
});

it('refuses a TLS link repointed at another certificate or at a non-archive file', function () {
    foreach ([
        'another certificate' => '../../archive/other-certificate/privkey1.pem',
        'a non-archive file in the tree' => '../../ssl-dhparams.pem',
        'somewhere outside the tree entirely' => '/attacker-key.pem',
    ] as $case => $linkTarget) {
        $scratch = itpScratchDir();

        try {
            itpValidHostScope($scratch);

            mkdir($scratch.'/etc/letsencrypt/archive/other-certificate', 0o755, true);
            file_put_contents($scratch.'/etc/letsencrypt/archive/other-certificate/privkey1.pem', "other\n");
            chmod($scratch.'/etc/letsencrypt/archive/other-certificate/privkey1.pem', 0o600);
            file_put_contents($scratch.'/attacker-key.pem', "attacker\n");
            chmod($scratch.'/attacker-key.pem', 0o600);

            // "It is a TLS row, and it points at some regular file" is not
            // enough: the link has to resolve to its OWN certificate's
            // numbered archive file, or a substituted key would be blessed as
            // correct state.
            $key = $scratch.'/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/privkey.pem';
            unlink($key);
            symlink($linkTarget, $key);

            [$exit, $output] = itpRun($scratch, ['--verify', '--target', 'staging-main', '--scope', 'host']);

            expect($exit)->toBe(1, "a key repointed at {$case} must be refused");
            expect($output)->toContain('does not resolve to its own /etc/letsencrypt/archive/<certificate>/ file');
        } finally {
            itpCleanup($scratch);
        }
    }
});

it('refuses a TLS link at a destination certbot would never publish', function () {
    $scratch = itpScratchDir();

    try {
        itpValidHostScope($scratch);

        // The four TLS logical names may only be links at
        // live/<certificate>/{fullchain,privkey}.pem. The shared dhparams file
        // is a TLS row certbot does not publish as a link at all.
        file_put_contents($scratch.'/etc/letsencrypt/real-dhparams.pem', "dh\n");
        chmod($scratch.'/etc/letsencrypt/real-dhparams.pem', 0o644);
        unlink($scratch.'/etc/letsencrypt/ssl-dhparams.pem');
        symlink($scratch.'/etc/letsencrypt/real-dhparams.pem', $scratch.'/etc/letsencrypt/ssl-dhparams.pem');

        [$exit, $output] = itpRun($scratch, ['--verify', '--target', 'staging-main', '--scope', 'host']);

        expect($exit)->toBe(1);
        expect($output)->toContain('only ACME-published certificate/key material');
    } finally {
        itpCleanup($scratch);
    }
});

it('refuses a symlinked destination for anything but ACME-published TLS material', function () {
    $scratch = itpScratchDir();

    try {
        itpCreateTargetDirectories($scratch);

        // A target's shared/ directory is writable by the application's own
        // runtime user, so blessing a link there would let a compromised
        // runtime point .env at material it controls and have preparation call
        // it present and correct.
        file_put_contents($scratch.'/attacker-env', "DB_PASSWORD=owned\n");
        chmod($scratch.'/attacker-env', 0o600);
        symlink($scratch.'/attacker-env', $scratch.'/home/www/rateguru/staging/shared/.env');

        [$exit, $output] = itpRun($scratch, ['--verify', '--target', 'staging-main', '--scope', 'target']);

        expect($exit)->toBe(1);
        expect($output)->toContain('only ACME-published certificate/key material');

        [$exit, $output] = itpRun($scratch, ['--check', '--target', 'staging-main', '--scope', 'target']);
        expect($exit)->toBe(1);
        expect($output)->toContain('only ACME-published certificate/key material');

        // Refused, never followed and never replaced.
        expect(is_link($scratch.'/home/www/rateguru/staging/shared/.env'))->toBeTrue();
        expect(File::get($scratch.'/attacker-env'))->toBe("DB_PASSWORD=owned\n");
    } finally {
        itpCleanup($scratch);
    }
});

it('enforces the declared mode, not merely the absence of world-read', function () {
    $scratch = itpScratchDir();

    try {
        itpCreateTargetDirectories($scratch);
        itpSupply($scratch, ITP_TARGET_MATERIAL);

        itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'target',
            '--material-dir', '/root/material',
        ]);

        $env = $scratch.'/home/www/rateguru/staging/shared/.env';

        // Wider than declared: an exposure.
        chmod($env, 0o644);

        [$exit, $output] = itpRun($scratch, ['--verify', '--target', 'staging-main', '--scope', 'target']);

        expect($exit)->toBe(1);
        expect($output)->toContain('has mode 644, expected 0640');
        expect($output)->toContain('chmod 0640 /home/www/rateguru/staging/shared/.env');

        // Narrower than declared is a failure too, and this is the case a
        // world-read check misses entirely: the secret is perfectly protected
        // and the runtime group can no longer read it, so PHP-FPM and the
        // queue worker fail on their first real use.
        chmod($env, 0o600);

        [$exit, $output] = itpRun($scratch, ['--verify', '--target', 'staging-main', '--scope', 'target']);

        expect($exit)->toBe(1);
        expect($output)->toContain('has mode 600, expected 0640');

        chmod($env, 0o640);

        [$exit] = itpRun($scratch, ['--verify', '--target', 'staging-main', '--scope', 'target']);
        expect($exit)->toBe(0);
    } finally {
        itpCleanup($scratch);
    }
});

it('enforces the declared owner and group, which is what makes the file readable at all', function () {
    $scratch = itpScratchDir();

    try {
        itpCreateTargetDirectories($scratch);
        itpSupply($scratch, ITP_TARGET_MATERIAL);

        itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'target',
            '--material-dir', '/root/material',
        ]);

        // In a scratch tree every file belongs to whoever ran the suite, so
        // turning ownership comparison on is enough to exercise the mismatch
        // path against the real declared owner — here the runtime identity the
        // application actually reads .env as.
        [$exit, $output] = itpRun(
            $scratch,
            ['--verify', '--target', 'staging-main', '--scope', 'target'],
            ['RATEGURU_TARGETPREREQ_ENFORCE_OWNERSHIP' => 'true'],
        );

        expect($exit)->toBe(1);
        expect($output)->toContain('expected rateguru-staging:rateguru-staging');
        expect($output)->toContain('the declared owner is the account that has to read it');
        expect($output)->toContain('chown rateguru-staging:rateguru-staging');
    } finally {
        itpCleanup($scratch);
    }
});

it('refuses a dangling symlink even where a symlink is otherwise allowed', function () {
    $scratch = itpScratchDir();

    try {
        // The ACME certificate rows may legitimately be links — but only ones
        // that resolve to a real file. A dangling link is broken state this
        // must never write through or paper over.
        mkdir($scratch.'/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua', 0o755, true);
        symlink(
            $scratch.'/does-not-exist',
            $scratch.'/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/privkey.pem',
        );

        itpSupply($scratch, ITP_HOST_MATERIAL);

        [$exit, $output] = itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'host',
            '--material-dir', '/root/material',
        ]);

        expect($exit)->toBe(1);
        expect($output)->toContain('does not resolve to a regular file');
        expect(is_link($scratch.'/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/privkey.pem'))->toBeTrue();
        expect(file_exists($scratch.'/does-not-exist'))->toBeFalse();
    } finally {
        itpCleanup($scratch);
    }
});

it('refuses a symlinked htpasswd, where no link is allowed at all', function () {
    $scratch = itpScratchDir();

    try {
        mkdir($scratch.'/etc/nginx', 0o755, true);
        file_put_contents($scratch.'/decoy', "decoy\n");
        symlink($scratch.'/decoy', $scratch.'/etc/nginx/rateguru-staging.htpasswd');

        itpSupply($scratch, ITP_HOST_MATERIAL);

        [$exit, $output] = itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'host',
            '--material-dir', '/root/material',
        ]);

        expect($exit)->toBe(1);
        expect($output)->toContain('only ACME-published certificate/key material');
        expect(File::get($scratch.'/decoy'))->toBe("decoy\n");
    } finally {
        itpCleanup($scratch);
    }
});

// =============================================================================
// The material directory itself
// =============================================================================

it('refuses a material directory readable by anyone but root', function () {
    $scratch = itpScratchDir();

    try {
        chmod($scratch.'/root/material', 0o755);

        [$exit, $output] = itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'host',
            '--material-dir', '/root/material',
        ]);

        expect($exit)->toBe(1);
        expect($output)->toContain('must not be readable by group or other');
    } finally {
        itpCleanup($scratch);
    }
});

it('never consults supplied material during --verify', function () {
    $scratch = itpScratchDir();

    try {
        [$exit, $output] = itpRun($scratch, [
            '--verify', '--target', 'staging-main', '--material-dir', '/root/material',
        ]);

        expect($exit)->toBe(1);
        expect($output)->toContain('--verify never consults supplied material');
    } finally {
        itpCleanup($scratch);
    }
});

// =============================================================================
// Lifecycle and root
// =============================================================================

it('rejects a lifecycle=planned target before computing a single destination', function () {
    $scratch = itpScratchDir();

    try {
        itpSupply($scratch, ITP_HOST_MATERIAL);

        [$exit, $output] = itpRun($scratch, [
            '--apply', '--target', 'tits-guru', '--scope', 'host',
            '--material-dir', '/root/material',
        ]);

        expect($exit)->toBe(1);
        expect($output)->toContain('lifecycle=planned, not active');
        expect(is_dir($scratch.'/etc'))->toBeFalse();
    } finally {
        itpCleanup($scratch);
    }
});

it('never sources the installed common, so it works on a clean host', function () {
    $source = File::get(itpScript());

    // common aborts when /home/www/rateguru/config/deployment.conf is
    // unreadable, and the host scope runs before any of that exists. Same
    // deliberate decision bootstrap-host-preflight documents.
    expect($source)->not->toContain('/home/www/rateguru/bin/common');
    expect($source)->toContain('infrastructure/config/deployment-targets.json');
    expect($source)->toContain('validate --file');
});

it('never eval-sources any operator-authored file', function () {
    $source = File::get(itpScript());

    expect($source)->not->toMatch('/\beval\b/');
    expect($source)->not->toMatch('/^\s*source\s/m');
    expect($source)->not->toMatch('/^\s*\.\s+["$]/m');
});
