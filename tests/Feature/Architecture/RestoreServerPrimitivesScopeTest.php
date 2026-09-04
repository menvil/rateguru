<?php

use Illuminate\Support\Facades\File;

/**
 * Restore Target Data's own scope guard: what this phase establishes, and — more
 * importantly — everything it deliberately does not begin.
 *
 * 7.3 ends when a live target's DATA can be restored from one exact,
 * fully-verified backup, safely, with an emergency backup taken first and a
 * compensating undo for every live step. The GitHub-facing restore surface is
 * 7.4, Repair Target is 7.5, Recover Host is 7.6/7.7, the rejected durable
 * artifact archive stays rejected, the accepted backup subsystem is untouched,
 * and production stays unprovisioned until the production launch.
 */

/** The five restore CLIs and the one restore-only library this phase adds. */
function restorePrimitiveScripts(): array
{
    return [
        'infrastructure/scripts/fetch-backup',
        'infrastructure/scripts/verify-backup',
        'infrastructure/scripts/restore-database',
        'infrastructure/scripts/restore-storage',
        'infrastructure/scripts/restore-target',
        'infrastructure/scripts/restore-common',
    ];
}

// =============================================================================
// What Restore Target Data adds
// =============================================================================

it('adds exactly the five restore primitives and one restore-only library', function () {
    foreach (restorePrimitiveScripts() as $path) {
        expect(File::exists(base_path($path)))->toBeTrue("{$path} is missing");
    }

    // And nothing else: infrastructure/scripts is exactly the CLI manifest
    // plus the two sourced libraries.
    $flat = collect(glob(base_path('infrastructure/scripts/*')) ?: [])
        ->filter(static fn (string $path): bool => is_file($path))
        ->map(static fn (string $path): string => basename($path))
        ->sort()
        ->values()
        ->all();

    $expected = [...requiredCliManifestNames(), ...sourcedLibraryNames()];
    sort($expected);

    expect($flat)->toBe($expected);
});

it('keeps one implementation of each restore concern rather than five copies', function () {
    $library = File::get(base_path('infrastructure/scripts/restore-common'));

    // The kinds of validation that must be identical everywhere live in
    // restore-common, once each.
    //
    // validate_operation_id is deliberately NOT in this list any more. Phase
    // 7.4 moved it (and the two identifier FORMATS) up into `common`, because
    // `deploy` has to validate the same operation ID and must not source the
    // restore library. It is still exactly one implementation — asserted
    // below — and every restore primitive still calls that one.
    foreach ([
        'validate_backup_id()',
        'restore_assert_directory_safe()',
        'restore_assert_backup_file_set()',
        'restore_assert_sha256sums_entries()',
        'restore_assert_storage_archive_safe()',
        'restore_assert_manifest_identity()',
        'restore_assert_recovery_identity()',
        'restore_state_require_phase()',
        'restore_remove_operation_path()',
        'restore_remove_storage_sibling()',
    ] as $function) {
        expect(substr_count($library, "\n{$function} {"))->toBe(1, "{$function} must be defined exactly once in restore-common");
    }

    // The two identifier concerns that now live in `common`, once each, and
    // nowhere else.
    $common = File::get(base_path('infrastructure/scripts/common'));

    expect(substr_count($common, "\nvalidate_operation_id() {"))->toBe(1);
    expect(substr_count($common, "\nRESTORE_OPERATION_ID_REGEX="))->toBe(1);
    expect(substr_count($common, "\nRESTORE_BACKUP_ID_REGEX="))->toBe(1);
    expect(substr_count($library, "\nRESTORE_OPERATION_ID_REGEX="))->toBe(0);
    expect(substr_count($library, "\nRESTORE_BACKUP_ID_REGEX="))->toBe(0);

    // No CLI redefines any of them.
    foreach (['fetch-backup', 'verify-backup', 'restore-database', 'restore-storage', 'restore-target'] as $cli) {
        $source = File::get(base_path('infrastructure/scripts/'.$cli));

        foreach ([
            'validate_backup_id()',
            'validate_operation_id()',
            'restore_assert_storage_archive_safe()',
            'restore_assert_manifest_identity()',
        ] as $function) {
            // toContain is variadic in Pest, so the diagnostic stays a comment.
            expect($source)->not->toContain("\n{$function} {");
        }
    }
});

it('installs every new primitive through the existing target-operations installer', function () {
    $installer = File::get(base_path('infrastructure/scripts/install-target-operations'));

    foreach (['restore-common', 'fetch-backup', 'verify-backup', 'restore-database', 'restore-storage', 'restore-target'] as $name) {
        expect($installer)->toContain("infrastructure/scripts/{$name}");
        // Destinations compose from the two fixed root constants, so the
        // literal installed path is "${DST_BIN_ROOT}/<name>".
        expect($installer)->toContain('DST_BIN_ROOT}/'.$name.'"');
    }

    expect($installer)->toContain('DST_BIN_ROOT="/home/www/rateguru/bin"');

    // The authoritative counts were updated honestly, not left stale.
    expect($installer)
        ->toContain('twenty-two files')
        ->toContain('all twenty-two source files are present regular files')
        ->toContain('bash -n passed for all twenty source shell scripts')
        ->not->toContain('sixteen files')
        ->not->toContain('all fourteen source');

    // restore-common is installed as a library, never a CLI.
    expect($installer)->toContain('install_regular_file_transactional "${STAGE_DIR}/restore-common" "${DST_RESTORE_COMMON}" "${INSTALL_OWNER}" "${INSTALL_GROUP}" "${COMMON_MODE}"');

    // Only the five executables joined the required-CLI manifest.
    $manifest = requiredCliManifestNames();
    foreach (['fetch-backup', 'verify-backup', 'restore-database', 'restore-storage', 'restore-target'] as $cli) {
        expect($manifest)->toContain($cli);
    }
    expect($manifest)->not->toContain('restore-common');
});

// =============================================================================
// The GitHub restore surface belongs to the controlled code alignment, and only to it
// =============================================================================
//
// 7.3 was entirely server-side. 7.4 added the GitHub layer, and its exact
// inventory is RestoreOperatorSurfaceScopeTest's business. What stays 7.3's business is the
// boundary between them: the GitHub layer may DRIVE restore-target through the
// generic wrapper, and may drive nothing else.

it('exposes only restore-target to GitHub, and only through the generic wrapper', function () {
    foreach (array_merge(
        glob(base_path('.github/workflows/*.yml')) ?: [],
        glob(base_path('.github/actions/*/action.yml')) ?: [],
    ) as $path) {
        $source = File::get($path);

        // The four primitives restore-target orchestrates are internal to it.
        // Nothing outside the server may fetch a backup, verify one, or touch
        // a database or storage tree directly.
        foreach (['fetch-backup', 'verify-backup', 'restore-database', 'restore-storage'] as $primitive) {
            expect($source)->not->toContain($primitive);
        }

        // And restore-target itself is reachable only as the wrapper's
        // documented target, never as a path GitHub asks the server to run.
        expect($source)->not->toContain('/home/www/rateguru/bin/restore-target');
    }
});

it('reaches restore only through one wrapper, granted to the existing deploy account', function () {
    $wrappers = collect(glob(base_path('infrastructure/config/wrappers/*')) ?: [])
        ->map(static fn (string $path): string => basename($path))
        ->sort()
        ->values()
        ->all();

    // Exactly one restore wrapper, and it is generic — never per-environment.
    expect($wrappers)->toContain('rateguru-restore')
        ->not->toContain('rateguru-restore-staging')
        ->not->toContain('rateguru-restore-production');

    // No new sudoers FILE: the restore grant extends the one that already
    // exists for this deploy account.
    $sudoers = collect(glob(base_path('infrastructure/config/sudoers/*')) ?: [])
        ->map(static fn (string $path): string => basename($path))
        ->sort()
        ->values()
        ->all();

    expect($sudoers)->toBe(['rateguru-deploy', 'rateguru-nightwatch-deployment']);

    // Only the restore wrapper names the restore binary; no other wrapper and
    // no sudoers rule mentions any restore primitive.
    foreach (array_merge(
        glob(base_path('infrastructure/config/wrappers/*')) ?: [],
        glob(base_path('infrastructure/config/sudoers/*')) ?: [],
    ) as $path) {
        $source = File::get($path);
        $isRestoreWrapper = basename($path) === 'rateguru-restore';

        foreach (['fetch-backup', 'verify-backup', 'restore-database', 'restore-storage'] as $primitive) {
            expect($source)->not->toContain($primitive);
        }

        if (! $isRestoreWrapper) {
            expect($source)->not->toContain('restore-target');
        }
    }
});

// =============================================================================
// No Recover, no production activation
// =============================================================================

it('implements no Recover Host', function () {
    foreach ([
        'infrastructure/scripts/recover-host',
        '.github/workflows/recover-staging-host.yml',
        '.github/workflows/recover-production-host.yml',
        '.github/actions/recover-rateguru-host/action.yml',
    ] as $path) {
        expect(File::exists(base_path($path)))->toBeFalse("{$path} belongs to a later phase, not 7.3");
    }

    // Recovery rebuilds an application from the backup's source_sha. Restore
    // reads that commit to DECIDE alignment, and never builds anything.
    $restore = File::get(base_path('infrastructure/scripts/restore-target'));

    foreach (['composer', 'npm ', 'node ', 'vite', 'build-rateguru', 'git clone', 'git checkout'] as $forbidden) {
        expect($restore)->not->toContain($forbidden, "restore-target must never build: {$forbidden}");
    }
});

it('activates no production target and changes no DNS', function () {
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true);

    expect($registry['targets']['tits-guru']['lifecycle'])->toBe('planned');

    foreach (operationalFiles() as $path) {
        $source = File::get($path);

        foreach (['cloudflare', 'route53', 'dns_record', 'certbot --force-renewal'] as $forbidden) {
            expect($source)->not->toContain($forbidden);
        }
    }
});

// =============================================================================
// No durable artifact archive, no backup redesign
// =============================================================================

it('adds no durable release-artifact archive and no artifact bucket', function () {
    foreach (operationalFiles() as $path) {
        $source = File::get($path);

        foreach ([
            'rateguru-release-artifacts',
            'B2_ARTIFACT_',
            'ARTIFACT_BUCKET',
            'artifact_retention',
        ] as $rejected) {
            expect($source)->not->toContain($rejected);
        }
    }
});

it('leaves the accepted backup subsystem and its manifest schema untouched', function () {
    $changed = branchChangedCodeFiles();

    // toContain is variadic in Pest, so a second "message" argument is read as
    // another needle and the negation passes on any file — the diagnostic
    // belongs in a comment, not in the call.
    foreach ([
        'infrastructure/scripts/backup-cycle',
        'infrastructure/scripts/offsite-backup',
        'infrastructure/scripts/offsite-retention',
        'infrastructure/scripts/offsite-restore-test',
        'infrastructure/scripts/restore-test',
        'infrastructure/config/cron/rateguru-backups',
        'infrastructure/config/supervisor/rateguru-staging-queue.conf',
        'infrastructure/config/cron/rateguru-staging-scheduler',
    ] as $untouched) {
        expect($changed)->not->toContain($untouched);
    }
})->skip(fn (): bool => branchBaseRevision() === null,
    'requires the PR base SHA or an origin/develop reference');

it('gives backup exactly one fail-closed guard, and no other restore-awareness', function () {
    // The end state, asserted without a diff so it holds on every branch. A
    // target held after a restore has data belonging to a different commit than
    // current/release.json names, and a backup taken there would label it with
    // that commit — so backup refuses before creating anything, and that is the
    // ONLY thing it knows about restores.
    $backup = committedFile('infrastructure/scripts/backup');

    expect(substr_count($backup, 'assert_no_restore_hold'))->toBe(1);

    // A refusal, not a step: it runs before perform_backup.
    expect(mb_strpos($backup, 'assert_no_restore_hold'))
        ->toBeLessThan(mb_strpos($backup, "\n    perform_backup\n"));

    // And nothing else restore-shaped leaked in: backup does not read a
    // workspace, drive a restore primitive or clear a guard.
    foreach ([
        'restore-target',
        'restore-database',
        'restore-storage',
        'fetch-backup',
        'restore_guard_file',
        'write_restore_guard',
        'clear_restore_guard',
        'selected-backup',
    ] as $forbidden) {
        expect($backup)->not->toContain($forbidden);
    }
});

it('changes backup by nothing more than that guard', function () {
    // The bounded-diff half, which only says anything when a branch actually
    // touches the file. Once Restore Target Data merged, a later branch diffs clean here
    // — and if one does touch backup, the guard line is the only addition it
    // may carry.
    $diff = branchFileDiff('infrastructure/scripts/backup');

    // Both halves are judged as code. The additions already were; the
    // removals were not, which made this guard fire on a comment being
    // reworded — a change that by definition cannot weaken backup.
    expect(sourceCodeLines($diff['removed']))->toBe([]);

    $addedCode = sourceCodeLines($diff['added']);

    expect($addedCode)->toBeIn([
        [],
        ['assert_no_restore_hold "${TARGET_ID}" "${RUN_ROOT}" "a backup"'],
    ]);
})->skip(fn (): bool => branchBaseRevision() === null,
    'requires the PR base SHA or an origin/develop reference');

it('reads the existing backup format and defines no second one', function () {
    $library = File::get(base_path('infrastructure/scripts/restore-common'));

    // The exact seven files backup produces, named once, in the order backup
    // itself writes them.
    expect($library)->toContain("RESTORE_BACKUP_CHECKSUMMED_FILES=(\n    database.dump\n    storage-app.tar.gz\n    environment.env\n    release.json\n    server-configuration.tar.gz\n    manifest.json\n)");

    // Manifest classification is the shared one from common, not a second
    // incompatible implementation.
    expect($library)->toContain('manifest_schema_classify "${manifest_path}"');
    expect($library)->not->toContain('manifest_schema_version | type');

    // The emergency backup is the existing backup implementation, and it is
    // verified by the existing restore-test.
    $restore = File::get(base_path('infrastructure/scripts/restore-target'));
    expect($restore)
        ->toContain('"${RESTORE_BACKUP_BIN}" --target "${TARGET_ID}"')
        ->toContain('"${RESTORE_RESTORE_TEST_BIN}" --target "${TARGET_ID}"');
});

// =============================================================================
// Hard invariants
// =============================================================================

it('runs no migration anywhere in the restore path', function () {
    foreach (restorePrimitiveScripts() as $path) {
        $source = File::get(base_path($path));

        foreach ([
            'artisan migrate',
            'migrate --force',
            'migrate:fresh',
            'migrate:refresh',
            'migrate:reset',
            'migrate:rollback',
            'db:wipe',
            'schema:dump',
            'DROP SCHEMA',
            'CREATE SCHEMA',
        ] as $forbidden) {
            expect($source)->not->toContain($forbidden, basename($path)." must never run {$forbidden}");
        }
    }

    // And the one artisan surface restore-target does use is a closed set:
    // maintenance mode plus the scheduler barrier, nothing else.
    preg_match_all('/artisan_as_runtime_user (\w[\w:.-]*)/', File::get(base_path('infrastructure/scripts/restore-target')), $matches);

    expect(array_values(array_unique($matches[1])))
        ->toEqualCanonicalizing(['down', 'up', 'schedule:interrupt']);
});

it('never applies environment.env or server-configuration.tar.gz to a live target', function () {
    foreach (restorePrimitiveScripts() as $path) {
        $source = File::get(base_path($path));

        foreach (preg_split('/\R/', $source) as $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            foreach (['environment.env', 'server-configuration.tar.gz'] as $neverApplied) {
                if (! str_contains($trimmed, $neverApplied)) {
                    continue;
                }

                expect($trimmed)->not->toMatch(
                    '/(^|[;&|]\s*)(cp|install|mv|ln|source|eval)\s/',
                    basename($path)." must never copy, install, move, link or source {$neverApplied}: {$trimmed}",
                );

                expect($trimmed)->not->toMatch(
                    '/(^|[;&|]\s*)tar\s+[^;&|]*-x/',
                    basename($path)." must never extract {$neverApplied}: {$trimmed}",
                );
            }
        }

        // Nothing in the restore path writes the target's own environment
        // file or any /etc path.
        expect($source)->not->toMatch('#>\s*"?\$\{?TARGET_ROOT\}?/shared/\.env#');
        expect($source)->not->toMatch('#tar\s+[^;&|]*-x[^;&|]*-C\s+/etc#');
    }

    // The only file a restore ever applies, besides the database dump, is the
    // storage archive — declared as a closed list.
    expect(File::get(base_path('infrastructure/scripts/restore-common')))
        ->toContain("RESTORE_APPLIED_FILES=(\n    database.dump\n    storage-app.tar.gz\n)")
        ->toContain("RESTORE_NEVER_APPLIED_FILES=(\n    environment.env\n    server-configuration.tar.gz\n)");
});

it('never switches a release, and never touches the current or previous link', function () {
    foreach (restorePrimitiveScripts() as $path) {
        $source = File::get(base_path($path));

        expect($source)->not->toMatch('/\bln\s+-sfn?\b/', basename($path).' must never create a release link');
        expect($source)->not->toContain('mv -Tf "${CURRENT_LINK}');

        // The links may be READ (that is how alignment is decided) but never
        // written: no redirection into either of them, anywhere.
        expect($source)->not->toMatch('/>\s*"?\$\{(CURRENT|PREVIOUS)_LINK\}/');
    }

    // The links are compared, never written.
    expect(File::get(base_path('infrastructure/scripts/restore-target')))
        ->toContain('the current release symlink changed during the restore — a restore never switches releases');
});

it('confines every restore concern in the shared library to its own sections', function () {
    // `common` is sourced by every operational script, so a change to it
    // reaches deploy, rollback, cleanup and backup at once. Two phases have
    // added to it — 7.3 the guard, 7.4 the alignment authorization — and both
    // additions must stay inside their own delimited sections rather than
    // reaching into anything that was already there.
    $diff = branchFileDiff('infrastructure/scripts/common');

    $common = committedFile('infrastructure/scripts/common');

    // Matched by PREFIX, not by the whole banner. These markers end in a run
    // of dashes padded to a fixed width, so any edit to the words inside them
    // changes the full string — and a guard that silently stops finding its
    // own section does not fail, it just stops guarding.
    $guardStart = mb_strpos($common, '# --- restore guard');
    $alignmentStart = mb_strpos($common, '# --- restore alignment authorization');
    $end = mb_strpos($common, '# --- deployment target registry (end) ---');

    expect($guardStart)->not->toBeFalse('the restore guard section is missing from common');
    expect($alignmentStart)->toBeGreaterThan($guardStart);
    expect($end)->toBeGreaterThan($alignmentStart);

    $restoreSections = mb_substr($common, $guardStart, $end - $guardStart);

    // Every line of CODE this branch added to common belongs to one of them.
    // Comments are excluded deliberately: `common` carries prose all over it,
    // and rewording a comment somewhere else in the file is not a restore
    // concern leaking out of its section.
    foreach (sourceCodeLines($diff['added']) as $line) {
        expect($restoreSections)->toContain($line);
    }

    // And so does every line it removed — measured against the version it was
    // removed from. A restore phase may rewrite its OWN text (7.4 had to: the
    // hold refusal used to say controlled alignment did not exist yet), but it
    // may never touch a line of anything that was already in common.
    $base = baseRevisionFile('infrastructure/scripts/common');
    $baseGuardStart = mb_strpos($base, '# --- restore guard');
    $baseEnd = mb_strpos($base, '# --- deployment target registry (end) ---');

    if ($base === '') {
        // The base blob is not readable in this checkout. The added-line bound
        // above is unaffected and still ran; only the removal half is skipped,
        // rather than being turned into an assertion about nothing.
        expect($diff['added'])->toBeArray();
    } elseif ($baseGuardStart !== false && $baseEnd !== false && $baseEnd > $baseGuardStart) {
        $baseSections = mb_substr($base, $baseGuardStart, $baseEnd - $baseGuardStart);

        foreach (sourceCodeLines($diff['removed']) as $line) {
            expect($baseSections)->toContain($line);
        }
    } else {
        // A base predating the restore guard entirely: there is nothing of ours
        // in it, so no line of code may have been removed.
        expect(sourceCodeLines($diff['removed']))->toBe([]);
    }

    // The four helpers, and not one filesystem mutation between them: `common`
    // is sourced by everything, and a helper here that wrote to disk would be
    // running in every operational script on the host.
    foreach ([
        'restore_guard_file()',
        'assert_no_restore_hold()',
        'restore_operation_state_file()',
        'assert_restore_alignment_operation()',
    ] as $helper) {
        expect($restoreSections)->toContain($helper);
    }

    expect($restoreSections)->not->toMatch('/^\s*(rm|mv|install|touch|chmod|chown)\s/m');
})->skip(fn (): bool => branchBaseRevision() === null,
    'requires the PR base SHA or an origin/develop reference');

it('does not weaken deploy, rollback, cleanup or any earlier phase contract', function () {
    $changed = branchChangedCodeFiles();

    // Files no phase after 7.3 has any business touching to build a restore
    // surface. deploy/rollback/cleanup are deliberately NOT in this list any
    // more: the controlled code alignment gives each of them a fail-closed refusal while a restore
    // guard exists, and gives deploy its controlled-alignment mode — both of
    // which are asserted in full by RestoreOperatorSurfaceScopeTest and by their own test
    // files. (See the note above on toContain's variadic signature.)
    foreach ([
        'infrastructure/scripts/targets',
        'infrastructure/scripts/health-check',
        'infrastructure/scripts/status',
        'infrastructure/scripts/bootstrap-host',
        // prepare-host is deliberately NOT here any more. It printed one
        // operator-facing label carrying a phase number, which the naming
        // convention in CLAUDE.md — enforced by ReleaseBookkeepingTest over
        // every operational script — requires to be removed. Two guards asked
        // for opposite things about the same line; freezing a label is not
        // what this one is for, and its remaining entries still hold.
        'infrastructure/config/deployment-targets.json',
        'infrastructure/templates/deployment.conf.example',
    ] as $untouched) {
        expect($changed)->not->toContain($untouched);
    }

    // What deploy may never gain, in any phase: a backup selector, a restore
    // of its own, or a way to run a migration it was not explicitly asked for.
    // Its ONE restore-aware entry point is --restore-operation, which names an
    // operation and never a commit, a backup or a path.
    $deploy = File::get(base_path('infrastructure/scripts/deploy'));

    // `restore_hold` is deliberately absent from this list: deploy now calls
    // assert_no_restore_hold, whose own name contains it.
    foreach (['--backup', '--source ', 'fetch-backup', 'restore-database', 'restore-storage'] as $forbidden) {
        expect($deploy)->not->toContain($forbidden);
    }

    expect(substr_count($deploy, '--restore-operation)'))->toBe(1, 'deploy has exactly one restore-aware flag');
})->skip(fn (): bool => branchBaseRevision() === null,
    'requires the PR base SHA or an origin/develop reference');

it('leaves a state and journal contract a later phase can build the alignment deploy on', function () {
    $restore = File::get(base_path('infrastructure/scripts/restore-target'));

    // The held state is machine-readable and names exactly what is required
    // to resume.
    foreach ([
        'status held',
        'code_alignment required',
        'runtime_resumed no',
        'backup_source_sha',
        'restore-target --resume --target %s --operation %s',
    ] as $contract) {
        expect($restore)->toContain($contract);
    }

    // And the journal records every field an operator or a later workflow
    // needs, with no secret among them.
    foreach ([
        'status:', 'operation_id:', 'started_at:', 'completed_at:', 'target:',
        'environment:', 'backup_namespace:', 'source:', 'backup:',
        'backup_release:', 'backup_source_sha:', 'current_release_before:',
        'current_source_sha_before:', 'emergency_backup:', 'code_alignment:',
        'runtime_resumed:', 'failed_step:', 'compensation_status:',
    ] as $field) {
        expect($restore)->toContain($field);
    }

    foreach (['DB_PASSWORD', 'PGPASSWORD', 'rclone.conf', 'authorized_keys'] as $secret) {
        expect($restore)->not->toMatch('/--arg \w+ "\$\{'.preg_quote($secret, '/').'/');
    }
});

it('ships the runbook and points the README and roadmap at it', function () {
    expect(File::exists(base_path('infrastructure/runbooks/restore-target.md')))->toBeTrue();

    $runbook = File::get(base_path('infrastructure/runbooks/restore-target.md'));

    expect($runbook)
        ->toContain('RESTORE TARGET DATA')
        ->toContain('RECOVER HOST')
        ->toContain('--resume')
        ->toContain('emergency')
        ->toContain('code alignment')
        ->toContain('No migrations');

    expect(File::get(base_path('infrastructure/README.md')))
        ->toContain('runbooks/restore-target.md');

    $roadmap = File::get(base_path('infrastructure/ROADMAP.md'));

    expect($roadmap)->toContain('7.3 Restore Target Data — ACCEPTED');
    expect($roadmap)->toContain('runbooks/restore-target.md');

    // A real destructive staging run happened, and the roadmap records what it
    // actually proved rather than merely that it ran.
    expect(preg_replace('/\s+/', ' ', $roadmap))
        ->toContain('PHASE 7 SLICE 7.3 ACCEPTED')
        ->toContain('RESTORE DATA COMPLETE: YES')
        ->toContain('CODE ALIGNMENT: ALIGNED')
        ->toContain('TARGET RESUMED: YES');
});
