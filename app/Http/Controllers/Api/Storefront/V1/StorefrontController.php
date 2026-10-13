<?php

namespace App\Http\Controllers\Api\Storefront\V1;

use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCredential;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Storefront\LogStorefrontRequest;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;
use LogicException;

/**
 * What every storefront endpoint shares (contract §3.5, §4.4).
 *
 * **The website comes from the credential, and from nowhere else.** It was
 * bound to the request by the authentication middleware after the signature
 * checked out; a controller that read a website from a parameter, a header or
 * a body would be a door into somebody else's shop, and none of them does.
 */
abstract class StorefrontController extends Controller
{
    protected function website(Request $request): Website
    {
        $website = $request->attributes->get(LogStorefrontRequest::WEBSITE);

        if (! $website instanceof Website) {
            throw new LogicException('A storefront endpoint was reached without an authenticated website.');
        }

        return $website;
    }

    protected function credential(Request $request): WebsiteCredential
    {
        $credential = $request->attributes->get(LogStorefrontRequest::CREDENTIAL);

        if (! $credential instanceof WebsiteCredential) {
            throw new LogicException('A storefront endpoint was reached without an authenticated credential.');
        }

        return $credential;
    }

    /**
     * The page size asked for, within the contract's bounds (§4.4).
     */
    protected function limit(Request $request): int
    {
        $asked = $request->integer('limit', (int) config('website.api.default_page_size', 50));

        return max(1, min($asked, (int) config('website.api.max_page_size', 200)));
    }

    /**
     * The contract's list envelope (§4.4).
     *
     * @param  CursorPaginator<array-key, mixed>  $page
     * @param  array<int, mixed>  $data
     * @return array{data: array<int, mixed>, meta: array{next_cursor: string|null, has_more: bool}}
     */
    protected function envelope(CursorPaginator $page, array $data): array
    {
        return [
            'data' => $data,
            'meta' => [
                'next_cursor' => $page->nextCursor()?->encode(),
                'has_more' => $page->hasMorePages(),
            ],
        ];
    }

    /**
     * Every money object in an outbound payload, with the frozen contract's
     * legacy `minor_units` and `decimal` keys added beside its flat-Taka
     * `amount` (contract §4.1's minor-unit compatibility period). The one place
     * outbound legacy money keys are produced.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    protected function withLegacyMoney(array $payload): array
    {
        if ($this->isMoneyShape($payload)) {
            $amount = (string) $payload['amount'];
            assert(is_numeric($amount));

            return $payload + [
                // Exact: `amount` always has two decimal places here, so
                // ×100 never truncates anything a person typed or the ERP
                // computed.
                'minor_units' => (int) bcmul($amount, '100', 0),
                'decimal' => $amount,
            ];
        }

        return array_map(
            fn (mixed $value) => is_array($value) ? $this->withLegacyMoney($value) : $value,
            $payload,
        );
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    protected function isMoneyShape(array $value): bool
    {
        return array_key_exists('amount', $value)
            && array_key_exists('currency', $value)
            && array_key_exists('formatted', $value);
    }
}
