<?php

namespace App\Domain\Cases\Actions;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Cases\Enums\EvidencePurpose;
use App\Domain\Cases\Exceptions\CaseEvidenceRefused;
use App\Domain\Cases\Models\CaseEvidence;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Storage\Actions\StoreManagedFile;
use App\Domain\Storage\Enums\StorageVisibility;
use App\Domain\Storage\Exceptions\UnacceptableFile;
use App\Domain\Website\Models\Website;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * Accept one file of evidence for a case (D35).
 *
 * Written through the shared managed-storage abstraction: **private**, its type
 * read from its own bytes (JPG, PNG, WebP or PDF — never the browser's claim),
 * its size held to the configured limit and to what the server itself accepts,
 * and its path never leaving the server.
 *
 * Two doors, kept apart:
 *
 *   - **A Partner's draft**, while they are still writing the request. It belongs
 *     to the order, the account and the uploader; it is not on any case, and may
 *     be removed. They must be able to manage the website the order came through.
 *   - **Staff evidence on a case that exists**, tied to the moment it was added —
 *     receipt, inspection or responsibility review — and allowed only to the
 *     staff who hold the right to do that step. It is submitted at once.
 *
 * The audit trail records that evidence was added and for what, and nothing about
 * the file: not its name, type, size or checksum.
 */
class StoreCaseEvidence
{
    public const MAX_NOTE = 500;

    public function __construct(
        protected StoreManagedFile $files,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * A Partner's unsent attachment for a request on `$order`.
     *
     * @throws CaseEvidenceRefused
     */
    public function draft(User $actor, Order $order, UploadedFile $file, ?string $note = null): CaseEvidence
    {
        $account = $actor->businessAccount;
        $website = $order->website_id === null ? null : Website::query()->find($order->website_id);

        if ($account === null || $account->id !== $order->business_account_id
            || $website === null || ! Gate::forUser($actor)->allows('manage', $website)) {
            throw CaseEvidenceRefused::notAllowedHere();
        }

        $drafts = CaseEvidence::query()
            ->where('uploader_id', $actor->id)
            ->where('order_id', $order->id)
            ->where('status', CaseEvidence::DRAFT)
            ->count();

        if ($drafts >= $this->maxPerCase()) {
            throw CaseEvidenceRefused::tooMany($this->maxPerCase());
        }

        return $this->write($actor, $order, null, null, EvidencePurpose::Request, $file, $note, CaseEvidence::DRAFT);
    }

    /**
     * Staff evidence on a return, exchange or damage that exists.
     *
     * @throws CaseEvidenceRefused
     */
    public function onCase(User $actor, OrderReturn|OrderDamage $case, EvidencePurpose $purpose, UploadedFile $file, ?string $note = null): CaseEvidence
    {
        if (! in_array($purpose, EvidencePurpose::forStaff(), true)) {
            throw CaseEvidenceRefused::wrongPurpose();
        }

        if (! $actor->can($this->requiredPermission($case, $purpose))) {
            throw CaseEvidenceRefused::notAllowedHere();
        }

        $held = CaseEvidence::query()
            ->where($case instanceof OrderReturn ? 'order_return_id' : 'order_damage_id', $case->id)
            ->count();

        if ($held >= $this->maxPerCase() * 2) {
            throw CaseEvidenceRefused::tooMany($this->maxPerCase() * 2);
        }

        $order = Order::query()->findOrFail($case->order_id);

        $evidence = $this->write(
            $actor,
            $order,
            $case instanceof OrderReturn ? $case : null,
            $case instanceof OrderDamage ? $case : null,
            $purpose,
            $file,
            $note,
            CaseEvidence::SUBMITTED,
        );

        $this->audit->handle(new AuditEntry(
            action: $case instanceof OrderReturn ? 'order_return.evidence_added' : 'damage.evidence_added',
            actorId: $actor->id,
            auditableType: $case::class,
            auditableId: $case->id,
            // The case and what the evidence is for: nothing about the file itself.
            after: ['reference' => $case->reference, 'purpose' => $purpose->value],
            accountId: $case->business_account_id,
            module: ($case instanceof OrderReturn ? PermissionModule::ReturnCase : PermissionModule::Damage)->value,
        ));

        return $evidence;
    }

    /**
     * The permission that says staff may add this kind of evidence to this case.
     */
    protected function requiredPermission(OrderReturn|OrderDamage $case, EvidencePurpose $purpose): string
    {
        if ($case instanceof OrderDamage) {
            return PermissionCatalogue::name(PermissionModule::Damage, $purpose === EvidencePurpose::ResponsibilityReview
                ? PermissionAction::AssignLiability
                : PermissionAction::Inspect);
        }

        $module = $case->isExchange() ? PermissionModule::Exchange : PermissionModule::ReturnCase;

        // An exchange has only `view`, `create` and `review`: the rest of its work is the return's.
        if ($case->isExchange()) {
            return PermissionCatalogue::name(PermissionModule::ReturnCase, match ($purpose) {
                EvidencePurpose::Receipt => PermissionAction::Receive,
                EvidencePurpose::Inspection => PermissionAction::Inspect,
                default => PermissionAction::Review,
            });
        }

        return PermissionCatalogue::name($module, match ($purpose) {
            EvidencePurpose::Receipt => PermissionAction::Receive,
            EvidencePurpose::Inspection => PermissionAction::Inspect,
            default => PermissionAction::Review,
        });
    }

    /**
     * @throws CaseEvidenceRefused
     */
    protected function write(
        User $actor,
        Order $order,
        ?OrderReturn $return,
        ?OrderDamage $damage,
        EvidencePurpose $purpose,
        UploadedFile $file,
        ?string $note,
        string $status,
    ): CaseEvidence {
        $mimes = (array) config('case_evidence.mime_types');
        $maxBytes = $this->maxBytes();

        try {
            $stored = $this->files->handle(
                $file,
                'case-evidence',
                StorageVisibility::Private,
                array_keys($mimes),
                $maxBytes,
                createdBy: $actor->id,
                extensionsByMime: $mimes,
            );
        } catch (UnacceptableFile $refused) {
            throw $refused->reason === UnacceptableFile::TOO_LARGE
                ? CaseEvidenceRefused::tooLarge($maxBytes)
                : CaseEvidenceRefused::typeNotAccepted();
        }

        $note = $note === null ? null : trim($note);
        $now = CarbonImmutable::now();

        /** @var CaseEvidence $evidence */
        $evidence = $this->database->transaction(fn () => CaseEvidence::query()->create([
            'stored_file_id' => $stored->id,
            'business_account_id' => $order->business_account_id,
            'order_id' => $order->id,
            'order_return_id' => $return?->id,
            'order_damage_id' => $damage?->id,
            'uploader_type' => $actor->businessAccount === null ? CaseEvidence::UPLOADED_BY_STAFF : CaseEvidence::UPLOADED_BY_PARTNER,
            'uploader_id' => $actor->id,
            'purpose' => $purpose,
            'display_name' => $this->displayName($file, (string) $stored->mime_type),
            'note' => $note === null || $note === '' ? null : mb_substr($note, 0, self::MAX_NOTE),
            'status' => $status,
            'uploaded_at' => $now,
            'submitted_at' => $status === CaseEvidence::SUBMITTED ? $now : null,
        ]));

        return $evidence;
    }

    /**
     * What the file is called on screen: its own name, cleaned, or a plain word.
     */
    protected function displayName(UploadedFile $file, string $mime): string
    {
        $name = preg_replace('/[^\p{L}\p{N}._ -]+/u', '', basename($file->getClientOriginalName())) ?? '';
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'evidence.'.((array) config('case_evidence.mime_types'))[$mime];
        }

        return mb_substr($name, -120);
    }

    public function maxPerCase(): int
    {
        return max(1, (int) config('case_evidence.max_per_case'));
    }

    /**
     * The largest file: the configured limit, held down to what the server
     * itself will take in one upload.
     */
    public function maxBytes(): int
    {
        $limits = array_filter([
            (int) config('case_evidence.max_bytes'),
            $this->iniBytes((string) ini_get('upload_max_filesize')),
            $this->iniBytes((string) ini_get('post_max_size')),
        ], fn (int $bytes) => $bytes > 0);

        return $limits === [] ? 8 * 1024 * 1024 : min($limits);
    }

    protected function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
