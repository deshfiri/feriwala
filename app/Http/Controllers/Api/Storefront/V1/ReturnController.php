<?php

namespace App\Http\Controllers\Api\Storefront\V1;

use App\Domain\Order\Actions\RequestOrderReturn;
use App\Domain\Order\Data\ReturnSubmission;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnReason;
use App\Domain\Order\Exceptions\ReturnRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Queries\ReturnPayload;
use App\Domain\Website\Api\StorefrontError;
use App\Domain\Website\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * A customer asking, through their shop, to send something back (contract §6.3,
 * P6-12).
 *
 * **A request, never an action.** The storefront says what the customer wants to
 * return and why; nothing here moves stock or money. Whether the goods are taken
 * back, what is done with them and what is refunded are decided in the ERP by
 * the people who own those decisions — and the shop hears each one through
 * `return.status_changed`.
 *
 * Only this website's own orders, found through the credential's website and
 * nothing the request names: another shop's order is a 404, never a refusal
 * that would confirm it exists.
 */
class ReturnController extends StorefrontController
{
    public function __construct(
        protected RequestOrderReturn $returns,
        protected ReturnPayload $payload,
    ) {}

    public function store(Request $request, string $order): JsonResponse
    {
        $record = $this->find($this->website($request), $order);

        if ($record === null) {
            return StorefrontError::respond($request, 404, 'not_found', 'No such order on this website.');
        }

        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', Rule::in(ReturnReason::values())],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.sku' => ['required', 'string', 'max:64'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'customer_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'evidence' => ['sometimes', 'nullable', 'array', 'max:10'],
            'evidence.*' => ['string', 'max:2048'],
        ]);

        if ($validator->fails()) {
            return StorefrontError::respond($request, 422, 'validation_failed', 'The return could not be read.', [
                'fields' => $validator->errors()->toArray(),
            ]);
        }

        /** @var array{reason: string, lines: array<int, array{sku: string, quantity: int|string}>, customer_note?: string|null, evidence?: array<int, string>|null} $input */
        $input = $validator->validated();

        try {
            [$return, $created] = $this->returns->handle($record, new ReturnSubmission(
                reason: ReturnReason::from($input['reason']),
                lines: array_map(fn (array $line) => [
                    'sku' => mb_strtoupper(trim($line['sku'])),
                    'quantity' => (int) $line['quantity'],
                ], array_values($input['lines'])),
                customerNote: $input['customer_note'] ?? null,
                evidence: $input['evidence'] ?? null,
                idempotencyKey: trim((string) $request->header('Idempotency-Key')),
            ), OrderStatusChangeSource::Storefront);
        } catch (ReturnRefused $refused) {
            return StorefrontError::respond($request, $refused->status, $refused->errorCode, $refused->getMessage(), $refused->details);
        }

        return new JsonResponse($this->payload->for($return), $created ? 201 : 200);
    }

    protected function find(Website $website, string $publicId): ?Order
    {
        /** @var Order|null $order */
        $order = Order::query()
            ->where('source', OrderSource::Website)
            ->where('website_id', $website->id)
            ->where('public_id', $publicId)
            ->first();

        return $order;
    }
}
