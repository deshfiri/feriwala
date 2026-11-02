<?php

namespace App\Http\Resources\Mobile\Websites;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One website the account's package entitles it to: set up, or still waiting.
 *
 * Built from {@see \App\Domain\Website\Queries\WebsiteOverview::allocationCard()}.
 * The package period and the provider's own record are kept apart: `package_based`
 * says the dates are the package's, not yet a registration.
 *
 * @property array<string, mixed> $resource
 */
class WebsiteAllocationResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $card = $this->resource;

        return [
            /** Public identifier (ULID) of the allocation. */
            'id' => $card['id'],
            /** Which of the package's website slots this is, from 1. */
            'slot' => $card['slot'],
            'name' => $card['name'],
            /** @var array{id: string, host: string, url: string}|null */
            'website' => $card['website'],
            'domain_name' => $card['domain_name'],
            'partner_name' => $card['partner_name'],
            'package_name' => $card['package_name'],
            'status' => $card['status'],
            'status_label' => $card['status_label'],
            'status_tone' => $card['status_tone'],
            /** @var array{included: bool, starts_at: string, expires_at: string|null, days_remaining: int|null, package_based: bool, provider: array{started_at: string|null, expires_at: string|null}|null} */
            'domain' => $card['domain'],
            /** @var array{included: bool, starts_at: string, expires_at: string|null, days_remaining: int|null, package_based: bool, provider: array{plan: string, started_at: string|null, expires_at: string|null}|null} */
            'hosting' => $card['hosting'],
        ];
    }
}
