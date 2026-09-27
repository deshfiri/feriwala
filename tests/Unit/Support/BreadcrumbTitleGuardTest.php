<?php

/*
 * Guards the app-wide breadcrumb localization pass.
 *
 * `Component.layout = { breadcrumbs: [{ title: '...', href }] }` is a plain
 * object assigned outside any component's render body, so it has no hook to
 * translate with at the point it is written — that is exactly why these were
 * hardcoded English for so long. The fix is that `title` holds a translation
 * key (e.g. `nav.wallet`), and the shared `<Breadcrumbs>` component resolves
 * it with `t()` at render time. This guard catches a page that reverts to a
 * literal capitalized English string instead of a key.
 *
 * `auth/**` and `supplier/auth/**` are outside its scope: those pages are
 * reached before sign-in and use their own, already-appropriate
 * `titleKey`/`descriptionKey` convention (Supplier) or a plain `AuthLayout`
 * headline (platform auth) rather than a breadcrumb trail.
 */

/**
 * @return non-empty-string
 */
function breadcrumbGuardRoot(): string
{
    return dirname(__DIR__, 3);
}

/**
 * @return list<string> absolute paths to every page component
 */
function breadcrumbGuardPageFiles(): array
{
    $directory = breadcrumbGuardRoot().'/resources/js/pages';
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile()) {
            continue;
        }

        if ($file->getExtension() !== 'tsx' || str_contains($file->getFilename(), '.test.')) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));

        if (str_starts_with($relative, 'auth/') || str_starts_with($relative, 'supplier/auth/')) {
            continue;
        }

        $files[] = $file->getPathname();
    }

    return $files;
}

/**
 * A hardcoded, capitalized English breadcrumb title: `title: 'Something'`.
 * A real translation key is always lowercase and dotted (`nav.wallet`,
 * `common.settings.nav.profile`), so this only matches the pattern the pass
 * was fixing, not a page that legitimately needs a runtime value (an order
 * reference, a product name) as its last crumb — those are never string
 * literals in source.
 *
 * @param  list<string>  $files
 * @return list<string> "path:line: text"
 */
function breadcrumbGuardHardcodedTitles(array $files): array
{
    $hits = [];
    $rootLength = strlen(breadcrumbGuardRoot()) + 1;
    $pattern = '/title:\s*\'[A-Z][^\']*\'/';

    foreach ($files as $path) {
        $relative = str_replace('\\', '/', substr($path, $rootLength));

        foreach (file($path) ?: [] as $index => $line) {
            if (preg_match($pattern, $line) === 1) {
                $hits[] = $relative.':'.($index + 1).': '.trim($line);
            }
        }
    }

    return $hits;
}

it('scans a real body of pages, so an empty scan cannot pass by checking nothing', function () {
    expect(count(breadcrumbGuardPageFiles()))->toBeGreaterThan(60);
});

it('names no breadcrumb title as a hardcoded English string', function () {
    expect(breadcrumbGuardHardcodedTitles(breadcrumbGuardPageFiles()))->toBe([]);
});

describe('the guard proves it can actually fail', function () {
    beforeEach(function () {
        $this->probe = tempnam(sys_get_temp_dir(), 'breadcrumb-guard-');
    });

    afterEach(function () {
        @unlink($this->probe);
    });

    it('catches a literal English title and lets a real translation key through', function () {
        file_put_contents($this->probe, <<<'TSX'
            Component.layout = {
                breadcrumbs: [{ title: 'Wallet', href: show() }],
            };
            TSX);

        expect(breadcrumbGuardHardcodedTitles([$this->probe]))->toHaveCount(1);

        file_put_contents($this->probe, <<<'TSX'
            Component.layout = {
                breadcrumbs: [{ title: 'nav.wallet', href: show() }],
            };
            TSX);

        expect(breadcrumbGuardHardcodedTitles([$this->probe]))->toBe([]);
    });
});
