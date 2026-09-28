<?php

use App\Domain\Bank\Actions\ImportBdBanks;
use App\Domain\Bank\Models\BdBank;
use App\Domain\Bank\Models\BdBankBranch;
use App\Domain\Bank\Models\BdBankImport;
use App\Domain\Location\Actions\ImportBdLocations;
use App\Domain\Location\Models\BdLocation;
use Illuminate\Database\QueryException;

/**
 * A minimal, valid bank/branch fixture — one bank, one branch — small enough
 * to assert exact counts against, unlike the real bundled files (~8,649
 * branches).
 *
 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
 */
function bdBankTestFixture(string $bankCode = '001', string $routingNumber = '001120100', string $district = 'DHAKA'): array
{
    $meta = ['generated_utc' => '2026-01-01T00:00:00+00:00', 'coverage' => 'test'];

    $website = [
        'meta' => $meta,
        'banks' => [
            [
                'bank_code' => $bankCode,
                'name' => "Test Bank {$bankCode}",
                'slug' => "TEST_BANK_{$bankCode}",
                'aliases' => [],
                'payable' => true,
                'available_in_selector' => true,
                'reference' => null,
                'branch_count' => 1,
            ],
        ],
    ];

    $flat = [
        'meta' => $meta,
        'branches' => [
            [
                'bank_code' => $bankCode,
                'bank_name' => "Test Bank {$bankCode}",
                'bank_selectable' => true,
                'routing_number' => $routingNumber,
                'name' => 'Head Office',
                'slug' => 'HEAD_OFFICE',
                'district' => $district,
                'branch_code' => '001',
                'original_branch_code' => '001',
                'swift_code' => 'TESTBDDH',
                'address' => '123 Test Road',
                'telephone' => '01700000000',
                'email' => 'branch@test.example',
                'fax' => null,
                'source' => 'uploaded_file',
                'status' => 'legacy_unverified',
            ],
        ],
    ];

    $audit = ['meta' => $meta, 'issue_counts' => [], 'issues' => []];

    return [$website, $flat, $audit];
}

function bdBankTestDistrict(string $nameEn = 'Dhaka'): BdLocation
{
    $division = BdLocation::query()->create([
        'type' => 'division',
        'source_id' => 'test-division-'.strtolower($nameEn),
        'source_parent_id' => null,
        'name_en' => $nameEn.' Division',
        'name_bn' => $nameEn.' Division',
        'is_active' => true,
    ]);

    return BdLocation::query()->create([
        'type' => 'district',
        'parent_id' => $division->id,
        'source_id' => 'test-district-'.strtolower($nameEn),
        'source_parent_id' => $division->source_id,
        'name_en' => $nameEn,
        'name_bn' => $nameEn,
        'is_active' => true,
    ]);
}

it('validates the bundled bank data with no problems', function () {
    app(ImportBdLocations::class)->handle('import');

    $report = app(ImportBdBanks::class)->handle('validate');

    expect($report->isValid())->toBeTrue()
        ->and($report->problems)->toBe([])
        ->and($report->unmatchedDistricts)->toBe([]);
});

it('imports the bundled bank data, resolving every district including the three reviewed aliases', function () {
    app(ImportBdLocations::class)->handle('import');

    $report = app(ImportBdBanks::class)->handle('import');

    expect($report->isValid())->toBeTrue()
        ->and($report->counts['banks'])->toBe(59)
        ->and($report->counts['branches'])->toBe(8649)
        ->and($report->unmatchedDistricts)->toBe([]);

    expect(BdBankImport::query()->count())->toBe(1);
    expect(BdBankBranch::query()->whereNull('district_location_id')->count())->toBe(0);

    $abBank = BdBank::query()->where('bank_code', '020')->first();
    expect($abBank)->not->toBeNull()
        ->and($abBank->name)->toBe('AB BANK LIMITED');

    foreach (['BARISHAL' => 'Barisal', 'CUMILLA' => 'Comilla', 'JHALOKATI' => 'Jhalakathi'] as $sourceDistrict => $bdLocationDistrict) {
        $branch = BdBankBranch::query()->where('district_source_name', $sourceDistrict)->first();

        expect($branch)->not->toBeNull("expected at least one branch with district_source_name={$sourceDistrict}")
            ->and($branch->district)->not->toBeNull()
            ->and($branch->district->name_en)->toBe($bdLocationDistrict);
    }
});

it('does not write anything in dry-run mode', function () {
    bdBankTestDistrict();
    [$website, $flat, $audit] = bdBankTestFixture();

    $report = app(ImportBdBanks::class)->handleDecoded('dry-run', $website, $flat, $audit);

    expect($report->isValid())->toBeTrue()
        ->and($report->counts)->toBe([
            'banks' => 1,
            'branches' => 1,
            'deactivated_banks' => 0,
            'deactivated_branches' => 0,
        ]);

    expect(BdBank::query()->count())->toBe(0)
        ->and(BdBankBranch::query()->count())->toBe(0)
        ->and(BdBankImport::query()->count())->toBe(0);
});

it('resolves a district through the reviewed alias map, never by guessing', function () {
    $barisal = bdBankTestDistrict('Barisal');
    [$website, $flat, $audit] = bdBankTestFixture(district: 'BARISHAL');

    $report = app(ImportBdBanks::class)->handleDecoded('dry-run', $website, $flat, $audit);

    expect($report->isValid())->toBeTrue()
        ->and($report->unmatchedDistricts)->toBe([]);
});

it('reports, but does not reject, a district with no match in either a direct name or the reviewed alias map', function () {
    bdBankTestDistrict('Dhaka');
    [$website, $flat, $audit] = bdBankTestFixture(district: 'NOT_A_REAL_DISTRICT');

    $report = app(ImportBdBanks::class)->handleDecoded('dry-run', $website, $flat, $audit);

    expect($report->isValid())->toBeTrue()
        ->and($report->unmatchedDistricts)->toBe(['NOT_A_REAL_DISTRICT'])
        ->and($report->counts['branches'])->toBe(1);
});

it('rejects a duplicate routing number rather than importing either copy', function () {
    bdBankTestDistrict();
    [$website, $flat, $audit] = bdBankTestFixture();
    $flat['branches'][] = $flat['branches'][0];

    $report = app(ImportBdBanks::class)->handleDecoded('validate', $website, $flat, $audit);

    expect($report->isValid())->toBeFalse()
        ->and(collect($report->problems)->contains(fn (string $p) => str_contains($p, 'duplicate routing_number')))->toBeTrue();
});

it('rejects a routing number that is not nine digits', function () {
    bdBankTestDistrict();
    [$website, $flat, $audit] = bdBankTestFixture(routingNumber: '12345');

    $report = app(ImportBdBanks::class)->handleDecoded('validate', $website, $flat, $audit);

    expect($report->isValid())->toBeFalse()
        ->and(collect($report->problems)->contains(fn (string $p) => str_contains($p, 'invalid routing_number')))->toBeTrue();
});

it('rejects a branch whose routing number references a bank code absent from the bank list', function () {
    bdBankTestDistrict();
    [$website, $flat, $audit] = bdBankTestFixture(bankCode: '001', routingNumber: '001120100');
    $website['banks'] = [];

    $report = app(ImportBdBanks::class)->handleDecoded('validate', $website, $flat, $audit);

    expect($report->isValid())->toBeFalse()
        ->and(collect($report->problems)->contains(fn (string $p) => str_contains($p, 'not in the bank list')))->toBeTrue();
});

it('refuses a batch whose three files disagree on when they were generated', function () {
    bdBankTestDistrict();
    [$website, $flat, $audit] = bdBankTestFixture();
    $audit['meta']['generated_utc'] = '2020-01-01T00:00:00+00:00';

    $report = app(ImportBdBanks::class)->handleDecoded('validate', $website, $flat, $audit);

    expect($report->isValid())->toBeFalse()
        ->and(collect($report->problems)->contains(fn (string $p) => str_contains($p, 'generated_utc disagrees')))->toBeTrue();
});

it('deactivates a bank and branch missing from a later import instead of deleting them', function () {
    bdBankTestDistrict();
    [$website, $flat, $audit] = bdBankTestFixture(bankCode: '001', routingNumber: '001120100');
    app(ImportBdBanks::class)->handleDecoded('import', $website, $flat, $audit);

    $firstBank = BdBank::query()->where('bank_code', '001')->sole();
    $firstBranch = BdBankBranch::query()->where('routing_number', '001120100')->sole();

    [$website2, $flat2, $audit2] = bdBankTestFixture(bankCode: '002', routingNumber: '002120100');
    $report = app(ImportBdBanks::class)->handleDecoded('import', $website2, $flat2, $audit2);

    expect($report->counts['deactivated_banks'])->toBe(1)
        ->and($report->counts['deactivated_branches'])->toBe(1);

    expect($firstBank->refresh()->is_active)->toBeFalse()
        ->and($firstBranch->refresh()->is_active)->toBeFalse();

    // Never deleted -- the row is still there, just inactive.
    expect(BdBank::query()->count())->toBe(2)
        ->and(BdBankBranch::query()->count())->toBe(2);
});

it('is idempotent: importing the same batch twice writes the same rows, not duplicates', function () {
    bdBankTestDistrict();
    [$website, $flat, $audit] = bdBankTestFixture();

    app(ImportBdBanks::class)->handleDecoded('import', $website, $flat, $audit);
    $report = app(ImportBdBanks::class)->handleDecoded('import', $website, $flat, $audit);

    expect($report->counts['deactivated_banks'])->toBe(0)
        ->and($report->counts['deactivated_branches'])->toBe(0);

    expect(BdBank::query()->count())->toBe(1)
        ->and(BdBankBranch::query()->count())->toBe(1);
});

it('never deletes a branch row at the database level either', function () {
    bdBankTestDistrict();
    [$website, $flat, $audit] = bdBankTestFixture();
    app(ImportBdBanks::class)->handleDecoded('import', $website, $flat, $audit);

    $branch = BdBankBranch::query()->sole();

    expect(fn () => $branch->delete())->toThrow(QueryException::class);
});

it('never deletes a bank row at the database level either', function () {
    bdBankTestDistrict();
    [$website, $flat, $audit] = bdBankTestFixture();
    app(ImportBdBanks::class)->handleDecoded('import', $website, $flat, $audit);

    $bank = BdBank::query()->sole();

    expect(fn () => $bank->delete())->toThrow(QueryException::class);
});
