#!/usr/bin/env bash
#
# ---------------------------------------------------------------------------
# THIS IS NOT AN ERROR-SUPPRESSION SCRIPT.
#
# It recovers from exactly one known infrastructure failure and passes every
# other PHPStan result through untouched. If you are reading this because a
# PHPStan error will not go away, this script is not why: it reports failures
# faithfully and exits non-zero when the analysis fails.
# ---------------------------------------------------------------------------
#
# The failure it handles
# ----------------------
# PHPStan's result cache in build/phpstan can reach a state where a run restores
# from cache, builds its DI container anyway, and never executes the configured
# bootstrap files. Larastan's LarastanStubFilesExtension then reads
# LARAVEL_VERSION — a constant only a bootstrap file defines — and the run dies
# during container construction with:
#
#     Undefined constant "Larastan\Larastan\LARAVEL_VERSION"
#
# Nothing is wrong with the code. No file is analysed. The message names a
# Larastan internal and points nowhere near the cause, the failure persists on
# every subsequent run until the cache is cleared, and `--debug` hides it
# because that path is single-process.
#
# How that was established
# ------------------------
# By bisection, not by guesswork — two earlier explanations (parallel workers,
# then the PHAR's isolated autoloader) were wrong. With the failure reproducing:
#
#     wipe build/phpstan   -> passes, and keeps passing
#     warm re-run          -> passes
#     touch a source file  -> passes
#     touch the bootstrap  -> passes
#
# Only the cache wipe changes the outcome. phpstan-bootstrap.php separately
# hardens the *other* half of the problem, where the bootstrap does run but
# Larastan's own application lookup fails.
#
# Why a retry rather than always clearing
# ---------------------------------------
# Clearing the cache on every run costs the ~40 seconds it exists to save. So
# the recovery is conditional and bounded:
#
#   - it matches only the exact marker below, on a failing run;
#   - it clears the cache and retries exactly once, never in a loop;
#   - if the retry also fails, that failure is reported and the exit code is
#     non-zero;
#   - every other PHPStan error — a real type error — is passed through on the
#     first run, unmodified and unretried.
#
# CI calls `composer types:check` like everyone else, and starts from a clean
# checkout with no cache present, so the recovery path is never exercised there:
# a genuine failure in CI is always a genuine failure.

set -uo pipefail

cd "$(dirname "$0")/.." || exit 1

# Deliberately narrow. Broadening this is how a recovery script turns into an
# error-suppression script.
readonly MARKER='Undefined constant "Larastan\Larastan\LARAVEL_VERSION"'

OUTPUT=$(./vendor/bin/phpstan analyse "$@" 2>&1)
STATUS=$?

if [ "$STATUS" -ne 0 ] && printf '%s' "$OUTPUT" | grep -qF -- "$MARKER"; then
    echo 'PHPStan result cache was stale (see scripts/phpstan.sh); clearing it and retrying once.' >&2

    rm -rf build/phpstan

    OUTPUT=$(./vendor/bin/phpstan analyse "$@" 2>&1)
    STATUS=$?

    if [ "$STATUS" -ne 0 ]; then
        echo 'The retry also failed. The output below is the real result.' >&2
    fi
fi

printf '%s\n' "$OUTPUT"

exit "$STATUS"
