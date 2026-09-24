<?php

/*
 * D26 source guard: the withdrawn minor-unit vocabulary stays withdrawn.
 *
 * `MoneyColumnConventionTest` guards the schema. This guards the code around
 * it, so a stale `Money::of()`, a `->minorUnits` read, a `*_minor` field name
 * or a float cast on a money value cannot quietly return through a new file.
 *
 * Production code only: `app`, factories, seeders, language files and the
 * ERP's own frontend. Historical migrations, and the tests that deliberately
 * model the old schema or a hostile browser payload, are outside its scope by
 * design. Every allow-list entry below names the reason it is allowed; an
 * entry without one does not belong here.
 */

/**
 * @return non-empty-string
 */
function moneyGuardRoot(): string
{
    return dirname(__DIR__, 4);
}

/**
 * @param  list<string>  $roots  directories relative to the repository root
 * @param  list<string>  $extensions
 * @return list<string> absolute paths
 */
function moneyGuardFiles(array $roots, array $extensions, bool $skipTests = false): array
{
    $files = [];

    foreach ($roots as $root) {
        $directory = moneyGuardRoot().'/'.$root;

        if (! is_dir($directory)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            if (! in_array($file->getExtension(), $extensions, true)) {
                continue;
            }

            if ($skipTests && str_contains($file->getFilename(), '.test.')) {
                continue;
            }

            $files[] = $file->getPathname();
        }
    }

    return $files;
}

/**
 * Every line in `$files` matching `$pattern`, outside the allow-list.
 *
 * @param  list<string>  $files
 * @param  array<string, string>  $allowed  repository-relative path => why it is allowed
 * @return list<string> "path:line: text"
 */
function moneyGuardHits(array $files, string $pattern, array $allowed = []): array
{
    $hits = [];
    $rootLength = strlen(moneyGuardRoot()) + 1;

    foreach ($files as $path) {
        $relative = str_replace('\\', '/', substr($path, $rootLength));

        if (array_key_exists($relative, $allowed)) {
            continue;
        }

        foreach (file($path) ?: [] as $index => $line) {
            if (preg_match($pattern, $line) === 1) {
                $hits[] = $relative.':'.($index + 1).': '.trim($line);
            }
        }
    }

    return $hits;
}

/**
 * @return list<string>
 */
function moneyGuardBackendFiles(): array
{
    return moneyGuardFiles(['app', 'database/factories', 'database/seeders', 'lang'], ['php']);
}

/**
 * @return list<string>
 */
function moneyGuardFrontendFiles(): array
{
    return moneyGuardFiles(['resources/js'], ['ts', 'tsx'], skipTests: true);
}

it('scans a real body of code, so an empty scan cannot pass by checking nothing', function () {
    expect(count(moneyGuardBackendFiles()))->toBeGreaterThan(500)
        ->and(count(moneyGuardFrontendFiles()))->toBeGreaterThan(100);
});

it('never calls the removed Money::of()', function () {
    expect(moneyGuardHits(moneyGuardBackendFiles(), '/Money::of\(/'))->toBe([])
        ->and(moneyGuardHits(moneyGuardFrontendFiles(), '/Money::of\(/'))->toBe([]);
});

it('never reads the removed ->minorUnits property', function () {
    expect(moneyGuardHits(moneyGuardBackendFiles(), '/->minorUnits\b/'))->toBe([]);
});

it('names no internal field with the withdrawn _minor suffix', function () {
    $pattern = '/\b[a-z][a-z0-9]*(?:_[a-z0-9]+)*_minor\b/i';

    expect(moneyGuardHits(moneyGuardBackendFiles(), $pattern))->toBe([])
        ->and(moneyGuardHits(moneyGuardFrontendFiles(), $pattern))->toBe([]);
});

it('carries a minor_units key only through the frozen Storefront API compatibility adapter', function () {
    $allowed = [
        'app/Http/Controllers/Api/Storefront/V1/OrderController.php' => 'the frozen contract §4.1 adapter: reads legacy input and adds the legacy output key, both derived from `amount`',
        'app/Domain/Website/Api/StorefrontProductPayload.php' => 'the frozen contract §4.1 adapter for catalogue prices, derived exactly from `amount`',
        'app/Support/Money/Money.php' => 'a docblock stating the wire adapter, not Money, adds the legacy key',
    ];

    expect(moneyGuardHits(moneyGuardBackendFiles(), '/\bminor_units\b/', $allowed))->toBe([])
        ->and(moneyGuardHits(moneyGuardFrontendFiles(), '/\bminor_units\b/'))->toBe([]);
});

it('never reads a money figure from a `.decimal` property the server does not send', function () {
    expect(moneyGuardHits(moneyGuardFrontendFiles(), '/\.decimal\b/'))->toBe([]);
});

it('never casts a money value to a float', function () {
    $cast = '/\((?:float|double)\)[^;]*\b(?:amount|price|fee|total|balance|charge|cost|tax|discount|refund|deposit|revenue|margin)/i';

    expect(moneyGuardHits(moneyGuardBackendFiles(), $cast))->toBe([])
        ->and(moneyGuardHits(moneyGuardBackendFiles(), '/\b(?:floatval|doubleval)\s*\(/'))->toBe([]);
});

describe('the guard proves it can actually fail', function () {
    beforeEach(function () {
        $this->probe = tempnam(sys_get_temp_dir(), 'money-guard-');
    });

    afterEach(function () {
        @unlink($this->probe);
    });

    it('catches a removed call, a withdrawn field name and a float cast', function () {
        file_put_contents($this->probe, <<<'PHP'
            $a = Money::of(100);
            $b = $order->total_minor;
            $c = (float) $payment->amount;
            PHP);

        expect(moneyGuardHits([$this->probe], '/Money::of\(/'))->toHaveCount(1)
            ->and(moneyGuardHits([$this->probe], '/\b[a-z][a-z0-9]*(?:_[a-z0-9]+)*_minor\b/i'))->toHaveCount(1)
            ->and(moneyGuardHits([$this->probe], '/\((?:float|double)\)[^;]*\b(?:amount|price)/i'))->toHaveCount(1);
    });

    it('lets an explicitly allow-listed file through, and only that file', function () {
        file_put_contents($this->probe, '$a = Money::of(100);');

        $relative = str_replace('\\', '/', substr($this->probe, strlen(moneyGuardRoot()) + 1));

        expect(moneyGuardHits([$this->probe], '/Money::of\(/', [$relative => 'a documented reason']))->toBe([])
            ->and(moneyGuardHits([$this->probe], '/Money::of\(/', ['some/other/file.php' => 'unrelated']))->toHaveCount(1);
    });
});
