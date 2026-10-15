<?php

use Symfony\Component\Process\Process;

/*
 * Guards against committing environment files.
 *
 * `.env.backup-before-port-5433` — a developer's whole local environment, with its
 * application key, database password and mail password — was committed on
 * 2026-09-30 and sat in a public repository until 2026-10-15. Only the template
 * `.env.example` may ever be tracked; every other `.env*` file is local and secret.
 */

/**
 * @return non-empty-string
 */
function trackedEnvironmentRoot(): string
{
    return dirname(__DIR__, 3);
}

/**
 * @param  list<string>  $arguments
 */
function trackedEnvironmentGit(array $arguments): Process
{
    $process = new Process(['git', ...$arguments], trackedEnvironmentRoot());
    $process->run();

    return $process;
}

beforeEach(function () {
    if (! is_dir(trackedEnvironmentRoot().'/.git') && ! is_file(trackedEnvironmentRoot().'/.git')) {
        $this->markTestSkipped('Not a git checkout.');
    }
});

it('tracks no environment file except the template', function () {
    $process = trackedEnvironmentGit(['ls-files', '-z']);

    expect($process->isSuccessful())->toBeTrue();

    $environmentFiles = array_values(array_filter(
        explode("\0", trim($process->getOutput(), "\0")),
        fn (string $path) => preg_match('#(^|/)\.env(\.|$)#', $path) === 1,
    ));

    expect($environmentFiles)->toBe(['.env.example']);
});

it('ignores every local environment file but keeps the template', function (string $path, bool $ignored) {
    // --no-index: judge the path by .gitignore alone, whether or not it is tracked.
    $process = trackedEnvironmentGit(['check-ignore', '--no-index', '--quiet', $path]);

    expect($process->getExitCode())->toBe($ignored ? 0 : 1);
})->with([
    '.env' => ['.env', true],
    '.env.backup-before-port-5433' => ['.env.backup-before-port-5433', true],
    '.env.production' => ['.env.production', true],
    '.env.testing' => ['.env.testing', true],
    '.env.local' => ['.env.local', true],
    '.env.example' => ['.env.example', false],
]);
