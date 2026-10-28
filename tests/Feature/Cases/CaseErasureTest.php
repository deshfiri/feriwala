<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Actions\EraseAccount;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Cases\Actions\AttachDraftEvidence;
use App\Domain\Cases\Actions\StoreCaseEvidence;
use App\Domain\Cases\Models\CaseEvidence;
use App\Domain\Damage\Actions\RequestDamageDelivery;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Storage\Enums\StorageVisibility;
use App\Domain\Storage\ManagedStorage;
use App\Domain\Storage\Models\StoredFile;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Wallet\Models\LedgerEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/*
 * Erasing an account that has returns, exchanges and damages (D35).
 *
 * The cases, their history, their charges and the ledger entries stay whole — the
 * books and the stock rest on them, and the database forbids changing the
 * append-only ones. What goes is what identified the people behind them: the
 * customer's note, the Partner's own words, the address a damaged item was sent
 * to, and every evidence file. Another account's records are never touched.
 */
beforeEach(function () {
    caseTestSetUp();

    $this->admin = testPlatformStaff(PlatformRole::Admin);
    $this->stranger = websiteTestAccount();
    websiteTestWallet($this->account, '30000.00');
});

/**
 * An account with every kind of case data: a return with the customer's note and a
 * photo, a draft that was never sent, and a posted damage sent to the Partner.
 *
 * @return array{return: OrderReturn, damage: OrderDamage, photo: CaseEvidence, draft: CaseEvidence}
 */
function caseErasureData(): array
{
    $case = caseTestOpenReturn(caseTestDeliveredOrder(), 2);
    $photo = app(StoreCaseEvidence::class)->draft(test()->partner, $case->order, caseTestPng('seam.png'), 'The seam on Ayesha\'s order.');
    app(AttachDraftEvidence::class)->handle(test()->partner, $case, [$photo->public_id]);
    $draft = app(StoreCaseEvidence::class)->draft(test()->partner, $case->order, caseTestPng('unsent.png'));

    caseTestAdvance($case, ReturnStatus::InspectionApproved);
    test()->actingAs(test()->viewer)->post(route('admin.cases.returns.settlement', $case->public_id));

    $damage = caseTestDamagePosted(caseTestDeliveredOrder());
    $damage->forceFill(['description' => 'Customer Ayesha says it tore at her house.'])->saveQuietly();
    $address = addressTestCreate(AddressOwnerType::BusinessAccount, test()->account->id, ['contactName' => 'Karim Real Name']);
    app(RequestDamageDelivery::class)->handle(test()->partner, $damage, $address->public_id);

    return ['return' => $case->refresh(), 'damage' => $damage->refresh(), 'photo' => $photo->refresh(), 'draft' => $draft];
}

it('keeps every case, charge and ledger entry, and removes what identified the people behind them', function () {
    ['return' => $case, 'damage' => $damage, 'photo' => $photo, 'draft' => $draft] = caseErasureData();
    $file = StoredFile::query()->findOrFail($photo->stored_file_id);
    $disk = app(ManagedStorage::class)->resolveNamedDisk($file->disk);
    $path = $file->path;

    expect($disk->exists($path))->toBeTrue();

    $ledger = LedgerEntry::query()->count();
    $charges = DB::table('order_return_charges')->count() + DB::table('order_damage_charges')->count();
    $history = DB::table('order_return_status_history')->count() + DB::table('order_damage_status_history')->count();

    $result = app(EraseAccount::class)->handle($this->partner, $this->admin, 'Requested by the owner.', $this->partner->email);

    // What must stay, stays.
    expect(OrderReturn::query()->whereKey($case->id)->exists())->toBeTrue()
        ->and(OrderDamage::query()->whereKey($damage->id)->exists())->toBeTrue()
        ->and(LedgerEntry::query()->count())->toBe($ledger)
        ->and(DB::table('order_return_charges')->count() + DB::table('order_damage_charges')->count())->toBe($charges)
        ->and(DB::table('order_return_status_history')->count() + DB::table('order_damage_status_history')->count())->toBe($history)
        ->and($result->deleted)->not->toHaveKey('order_returns')->not->toHaveKey('order_damages')->not->toHaveKey('ledger_entries');

    // What identified people goes.
    expect($case->refresh()->customer_note)->toBeNull()
        ->and($damage->refresh()->description)->toBeNull();

    // The delivery address is locked by the database once written: attempted, refused and reported —
    // never forced past the guard.
    expect($result->retained)->toHaveKey('order_damage_dispatches.address_snapshot')
        ->and(json_encode(DB::table('order_damage_dispatches')->where('order_damage_id', $damage->id)->value('address_snapshot')))->toContain('Karim Real Name');

    // The evidence record stays as history; its file, name and note do not.
    $kept = CaseEvidence::query()->findOrFail($photo->id);

    expect($kept->status)->toBe('submitted')
        ->and($kept->display_name)->toBe('[redacted]')
        ->and($kept->note)->toBe('[redacted]')
        ->and($kept->isErased())->toBeTrue()
        ->and($kept->file_erased_at)->not->toBeNull()
        ->and($disk->exists($path))->toBeFalse()
        // The file and its stored-file row are gone; the evidence row is not.
        ->and(StoredFile::query()->whereKey($photo->stored_file_id)->exists())->toBeFalse()
        // An unsent draft is simply gone.
        ->and(CaseEvidence::query()->whereKey($draft->id)->exists())->toBeFalse()
        ->and(StoredFile::query()->whereKey($draft->stored_file_id)->exists())->toBeFalse();
});

it('never touches another account\'s cases, evidence or files', function () {
    ['return' => $case, 'damage' => $damage, 'photo' => $photo] = caseErasureData();
    $file = StoredFile::query()->findOrFail($photo->stored_file_id);

    // A different Partner is erased: this Partner's data is not theirs to lose.
    app(EraseAccount::class)->handle($this->stranger->owner, $this->admin, 'Requested by the owner.', $this->stranger->owner->email);

    expect($this->partner->refresh()->erased_at)->toBeNull()
        ->and($case->refresh()->customer_note)->not->toBeNull()
        ->and($damage->refresh()->description)->not->toBeNull()
        ->and(CaseEvidence::query()->findOrFail($photo->id)->display_name)->toContain('seam')
        ->and(app(ManagedStorage::class)->resolveNamedDisk($file->disk)->exists($file->path))->toBeTrue();
});

it('says what it kept, and never switches a trigger off or changes the audit trail', function () {
    caseErasureData();
    $triggers = (int) DB::selectOne("select count(*) as c from pg_trigger where not tgisinternal and tgenabled <> 'O'")->c;
    $audit = AuditLog::query()->pluck('id')->all();

    $result = app(EraseAccount::class)->handle($this->partner, $this->admin, 'Requested by the owner.', $this->partner->email);

    expect((int) DB::selectOne("select count(*) as c from pg_trigger where not tgisinternal and tgenabled <> 'O'")->c)->toBe($triggers)
        ->and(AuditLog::query()->whereIn('id', $audit)->count())->toBe(count($audit))
        ->and(collect($result->notes)->implode(' '))->toContain('Returns, exchanges and damages were kept');
});

it('previews an erasure without changing any case data', function () {
    ['return' => $case, 'damage' => $damage] = caseErasureData();

    $preview = app(EraseAccount::class)->preview($this->partner);

    expect($preview->preview)->toBeTrue()
        ->and($case->refresh()->customer_note)->not->toBeNull()
        ->and($damage->refresh()->description)->not->toBeNull();
});

it('leaves a Partner\'s cases and evidence whole when a Supplier is erased', function () {
    ['return' => $case, 'damage' => $damage, 'photo' => $photo] = caseErasureData();
    $supplier = Supplier::factory()->create();

    app(EraseAccount::class)->handle($supplier, $this->admin, 'Requested by the Supplier.', (string) $supplier->reference);

    expect($supplier->refresh()->erased_at)->not->toBeNull()
        ->and($case->refresh()->customer_note)->not->toBeNull()
        ->and($damage->refresh()->description)->not->toBeNull()
        ->and(CaseEvidence::query()->findOrFail($photo->id)->display_name)->toContain('seam')
        ->and(StoredFile::query()->findOrFail($photo->stored_file_id)->path)->not->toStartWith('ERASED');
});

/*
 * The evidence guard (D35): submitted evidence is immutable, except that an erasure may
 * redact its name and note and detach its file — one way, to a fixed value, once.
 */
/** A stored file that belongs to nobody's case: it must survive any erasure of a case account. */
function caseEvidenceUnrelatedFile(string $purpose): StoredFile
{
    return StoredFile::query()->create([
        'purpose' => $purpose,
        'disk' => app(ManagedStorage::class)->diskNameFor(StorageVisibility::Private),
        'path' => $purpose.'/'.Str::random(20).'.png',
        'visibility' => StorageVisibility::Private,
        'mime_type' => 'image/png',
        'size_bytes' => 10,
        'checksum' => hash('sha256', Str::random(8)),
        'is_encrypted' => false,
    ]);
}
function caseEvidenceGuardRefuses(Closure $change): bool
{
    try {
        DB::transaction($change);
    } catch (QueryException) {
        return true;
    }

    return false;
}

function caseEvidenceGuardRow(): CaseEvidence
{
    $case = caseTestOpenReturn(caseTestDeliveredOrder(), 1);
    $draft = app(StoreCaseEvidence::class)->draft(test()->partner, $case->order, caseTestPng('seam.png'), 'A note about Ayesha.');
    app(AttachDraftEvidence::class)->handle(test()->partner, $case, [$draft->public_id]);

    return $draft->refresh();
}

it('keeps submitted evidence immutable for everything else', function () {
    $row = caseEvidenceGuardRow();
    $other = caseEvidenceUnrelatedFile('kyc');
    $update = fn (array $values) => caseEvidenceGuardRefuses(fn () => DB::table('case_evidence')->where('id', $row->id)->update($values));

    expect($update(['purpose' => 'inspection']))->toBeTrue()
        ->and($update(['uploader_id' => $this->admin->id]))->toBeTrue()
        ->and($update(['uploaded_at' => now()->subYear()]))->toBeTrue()
        ->and($update(['business_account_id' => $this->stranger->id]))->toBeTrue()
        ->and($update(['order_id' => caseTestDeliveredOrder()->id]))->toBeTrue()
        ->and($update(['display_name' => 'another-name.png']))->toBeTrue()
        ->and($update(['note' => 'Different words.']))->toBeTrue()
        ->and($update(['stored_file_id' => $other->id]))->toBeTrue()
        ->and($update(['status' => 'draft']))->toBeTrue()
        ->and($update(['file_erased_at' => now()]))->toBeTrue()
        ->and(caseEvidenceGuardRefuses(fn () => DB::table('case_evidence')->where('id', $row->id)->delete()))->toBeTrue();

    expect($row->refresh()->display_name)->toBe('seam.png')->and($row->note)->toBe('A note about Ayesha.');
});

it('lets a name and note be redacted, only to the fixed value and only once', function () {
    $row = caseEvidenceGuardRow();
    $update = fn (array $values) => caseEvidenceGuardRefuses(fn () => DB::table('case_evidence')->where('id', $row->id)->update($values));

    expect($update(['display_name' => '[redacted]', 'note' => '[redacted]']))->toBeFalse()
        // Redacted values cannot be restored, nor turned into other words.
        ->and($update(['display_name' => 'seam.png']))->toBeTrue()
        ->and($update(['display_name' => 'someone-else.png']))->toBeTrue()
        ->and($update(['note' => 'A note about Ayesha.']))->toBeTrue()
        ->and($update(['note' => null]))->toBeTrue();

    expect($row->refresh()->display_name)->toBe('[redacted]')->and($row->note)->toBe('[redacted]');
});

it('never lets a note appear where there was none', function () {
    $case = caseTestOpenReturn(caseTestDeliveredOrder(), 1);
    $draft = app(StoreCaseEvidence::class)->draft($this->partner, $case->order, caseTestPng('plain.png'));
    app(AttachDraftEvidence::class)->handle($this->partner, $case, [$draft->public_id]);

    expect(caseEvidenceGuardRefuses(fn () => DB::table('case_evidence')->where('id', $draft->id)->update(['note' => 'New personal detail.'])))->toBeTrue()
        ->and(caseEvidenceGuardRefuses(fn () => DB::table('case_evidence')->where('id', $draft->id)->update(['note' => '[redacted]'])))->toBeTrue();
});

it('detaches the file once, stamped as gone in the same statement', function () {
    $row = caseEvidenceGuardRow();
    $fileId = $row->stored_file_id;
    $update = fn (array $values) => caseEvidenceGuardRefuses(fn () => DB::table('case_evidence')->where('id', $row->id)->update($values));

    // Detaching without saying so, or saying so without detaching, is refused.
    expect($update(['stored_file_id' => null]))->toBeTrue()
        ->and($update(['file_erased_at' => now()]))->toBeTrue()
        ->and($update(['stored_file_id' => null, 'file_erased_at' => now()]))->toBeFalse()
        // Once: it cannot be re-attached, re-stamped or pointed at another file.
        ->and($update(['stored_file_id' => $fileId]))->toBeTrue()
        ->and($update(['file_erased_at' => null]))->toBeTrue()
        ->and($update(['file_erased_at' => now()->addDay()]))->toBeTrue();

    expect($row->refresh()->isErased())->toBeTrue();
});

it('redacts and detaches on erasure, once, leaving the case readable and auditable', function () {
    ['return' => $case, 'photo' => $photo] = caseErasureData();
    $unrelated = caseEvidenceUnrelatedFile('case-evidence');
    $audit = AuditLog::query()->where('action', 'order_return.evidence_added')->count();

    app(EraseAccount::class)->handle($this->partner, $this->admin, 'Requested by the owner.', $this->partner->email);

    $row = CaseEvidence::query()->findOrFail($photo->id);

    // Nothing personal is left in the retained evidence row.
    expect(json_encode($row->getAttributes()))->not->toContain('seam')->not->toContain('Ayesha')
        ->and($row->order_return_id)->toBe($case->id)
        ->and($row->purpose->value)->toBe('request')
        ->and($row->uploader_id)->toBe($this->partner->id)
        ->and($row->submitted_at)->not->toBeNull()
        // A file that was never this account's evidence is not touched, even with the same purpose.
        ->and(StoredFile::query()->whereKey($unrelated->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'order_return.evidence_added')->count())->toBe($audit);

    // Running the same erasure statement again finds nothing: it is a one-way, once-only move.
    expect(DB::table('case_evidence')->where('business_account_id', $this->account->id)->where('status', 'submitted')->whereNotNull('stored_file_id')->count())->toBe(0);

    // Staff still read the case; the evidence is listed as removed, with nothing to open.
    $this->actingAs($this->orderManager)->get(route('admin.cases.returns.show', $case->public_id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('evidence.0.erased', true)
            ->where('evidence.0.preview_url', null)
            ->where('evidence.0.download_url', null)
            ->where('evidence.0.name', '[redacted]'));

    $signed = URL::temporarySignedRoute('cases.evidence.show', now()->addMinutes(5), ['evidence' => $row->public_id]);
    $this->actingAs($this->orderManager)->get($signed)->assertNotFound();
});

it('never reaches another Partner\'s evidence when an account is erased', function () {
    caseErasureData();
    $theirs = caseEvidenceUnrelatedFile('case-evidence');
    $theirsCount = StoredFile::query()->count();

    app(EraseAccount::class)->handle($this->partner, $this->admin, 'Requested by the owner.', $this->partner->email);

    expect(StoredFile::query()->whereKey($theirs->id)->exists())->toBeTrue()
        ->and(StoredFile::query()->count())->toBeLessThan($theirsCount);
});
