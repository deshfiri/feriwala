<?php

namespace App\Http\Controllers\Api\Mobile\V1\Cases\Concerns;

use App\Domain\Account\Enums\AccountPermission;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Cases\Exceptions\CaseEvidenceRefused;
use App\Domain\Cases\Models\CaseEvidence;
use App\Domain\Cases\Queries\EvidencePayload;
use App\Domain\Damage\Exceptions\DamageRefused;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Exceptions\ReturnRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Website\Models\Website;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;

/**
 * What the mobile Returns & Exchanges and My Damages endpoints share (D35).
 *
 * The same self-scoping as the web screens: every lookup starts from the
 * signed-in person's own account, so another Partner's order, case, damage or
 * file is a 404. Evidence links point at the mobile route, which a bearer token
 * opens; the web's signed link needs a browser session the app does not have.
 */
trait PresentsCasesToMobile
{
    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    protected function mobileEvidence(array $evidence): array
    {
        $erased = (bool) ($evidence['erased'] ?? false);

        return [
            ...$evidence,
            'preview_url' => $erased ? null : route('mobile.v1.cases.evidence.show', ['evidence' => $evidence['id']]),
            'download_url' => $erased ? null : route('mobile.v1.cases.evidence.show', ['evidence' => $evidence['id'], 'download' => 1]),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $evidence
     * @return list<array<string, mixed>>
     */
    protected function mobileEvidenceList(array $evidence): array
    {
        return array_values(array_map(fn (array $file) => $this->mobileEvidence($file), $evidence));
    }

    /**
     * The Partner's own unsent attachments for an order, to show again if the form is reopened.
     *
     * @return list<array<string, mixed>>
     */
    protected function drafts(EvidencePayload $payload, User $actor, Order $order): array
    {
        return $this->mobileEvidenceList(CaseEvidence::query()
            ->where('uploader_id', $actor->id)
            ->where('order_id', $order->id)
            ->where('status', CaseEvidence::DRAFT)
            ->with('file')
            ->get()
            ->map(fn (CaseEvidence $file) => $payload->for($file))
            ->all());
    }

    /**
     * An order of this account, from one of its own stores — a 404 otherwise.
     */
    protected function storeOrderFor(int $accountId, string $publicId): Order
    {
        /** @var Order|null $order */
        $order = Order::query()
            ->where('public_id', $publicId)
            ->where('business_account_id', $accountId)
            ->whereIn('source', [OrderSource::Website->value, OrderSource::Exchange->value])
            ->first();

        abort_if($order === null, 404);

        return $order;
    }

    /**
     * Whether this person manages the store the order came through, as for any store action.
     */
    protected function managesStoreOf(User $actor, Order $order): bool
    {
        $website = $order->website_id === null ? null : Website::query()->find($order->website_id);

        return $website !== null && Gate::forUser($actor)->allows('manage', $website);
    }

    /**
     * The account, for someone in it who may read its orders.
     */
    protected function orderReader(Request $request): BusinessAccount
    {
        $account = $this->businessAccountFor($request);

        abort_unless($this->person($request)->accountRole()?->hasPermission(AccountPermission::ViewOrders) ?? false, 403);

        return $account;
    }

    protected function person(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }

    /**
     * A refusal in the reader's own language, where there is a line for it;
     * otherwise what the refusal itself says.
     */
    protected function refusal(ReturnRefused|DamageRefused|CaseEvidenceRefused $refused): string
    {
        $key = 'cases.errors.'.$refused->errorCode;

        return Lang::has($key) ? (string) __($key, array_map('strval', array_filter($refused->details, 'is_scalar'))) : $refused->getMessage();
    }

    /**
     * @return array{max_bytes: int, max_per_case: int, mime_types: list<string>}
     */
    protected function evidenceLimits(int $maxBytes, int $maxPerCase): array
    {
        return [
            'max_bytes' => $maxBytes,
            'max_per_case' => $maxPerCase,
            'mime_types' => array_map('strval', array_keys((array) config('case_evidence.mime_types'))),
        ];
    }
}
