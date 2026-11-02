<?php

namespace App\Http\Resources\Mobile\Websites;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Everything one website's page shows its owner.
 *
 * Built from {@see \App\Domain\Website\Queries\WebsiteOverview::detail()} for
 * the partner (never `$forStaff`), so the registrar, the hosting provider, the
 * DNS verification record, who moved the website and any internal note are not
 * in the array to begin with. Money values are the server's own
 * `{amount, currency, formatted}` objects.
 *
 * @property array<string, mixed> $resource
 */
class WebsiteDetailResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $website = $this->resource;

        return [
            'id' => $website['id'],
            'name' => $website['name'],
            'host' => $website['host'],
            'status' => $website['status'],
            'status_label' => $website['status_label'],
            'is_live' => $website['is_live'],
            'connection_health' => $website['connection_health'],
            'last_synced_at' => $website['last_synced_at'],
            'outstanding_charges' => $website['outstanding_charges'],
            'created_at' => $website['created_at'],
            'subdomain' => $website['subdomain'],
            'domain' => $website['domain'],
            /** The address asked for, kept until staff arrange it. */
            'requested_address' => $website['requested_address'],
            'tagline' => $website['tagline'],
            'about' => $website['about'],
            'theme' => $website['theme'],
            /** Six lowercase hex digits with a leading `#`. */
            'primary_color' => $website['primary_color'],
            'secondary_color' => $website['secondary_color'],
            /** An address the app can load, never a storage path. */
            'logo_url' => $website['logo_url'],
            'banner_url' => $website['banner_url'],
            'banner_link_url' => $website['banner_link_url'],
            /** @var array{email: string|null, phone: string|null, address: string|null} */
            'contact' => $website['contact'],
            /**
             * What was priced when the website was asked for.
             *
             * @var array{setup: array{amount: string, currency: string, formatted: string}, domain: array{amount: string, currency: string, formatted: string}, hosting: array{amount: string, currency: string, formatted: string}, required_deposit: array{amount: string, currency: string, formatted: string}, minimum_balance: array{amount: string, currency: string, formatted: string}}
             */
            'charges_summary' => $website['charges_summary'],
            /** @var array{activated_at: string|null, expires_at: string|null, grace_ends_at: string|null, suspended_at: string|null, suspension_reason: string|null, maintenance_message: string|null} */
            'lifecycle' => $website['lifecycle'],
            /** @var array{health: string, health_label: string, api_connected_at: string|null, webhook_connected_at: string|null, last_synced_at: string|null} */
            'connection' => $website['connection'],
            'charges' => collect($website['charges'])->map(fn (array $charge) => [
                /** Public identifier (ULID). */
                'id' => $charge['id'],
                'type' => $charge['type'],
                'type_label' => $charge['type_label'],
                'status' => $charge['status'],
                'status_label' => $charge['status_label'],
                /** @var array{amount: string, currency: string, formatted: string} */
                'amount' => $charge['amount'],
                'due_at' => $charge['due_at'],
                'paid_at' => $charge['paid_at'],
                'period_start' => $charge['period_start'],
                'period_end' => $charge['period_end'],
            ])->all(),
            'domains' => collect($website['domains'])->map(fn (array $domain) => [
                /** Opaque handle, used in the renewal path. */
                'id' => ServiceHandle::for('domain', (int) $domain['id']),
                'domain' => $domain['domain'],
                'status' => $domain['status'],
                'status_label' => $domain['status_label'],
                'registered_at' => $domain['registered_at'],
                'expires_at' => $domain['expires_at'],
                'days_remaining' => $domain['days_remaining'],
                'auto_renew' => $domain['auto_renew'],
                /** @var array{amount: string, currency: string, formatted: string} */
                'fee' => $domain['fee'],
                'routing_status' => $domain['routing_status'],
                'routing_status_label' => $domain['routing_status_label'],
                /** Whether the shared storefront answers on it. */
                'serves' => $domain['serves'],
                'verified_at' => $domain['verified_at'],
            ])->all(),
            'hostings' => collect($website['hostings'])->map(fn (array $hosting) => [
                /** Opaque handle, used in the renewal path. */
                'id' => ServiceHandle::for('hosting', (int) $hosting['id']),
                'plan' => $hosting['plan'],
                'status' => $hosting['status'],
                'status_label' => $hosting['status_label'],
                'started_at' => $hosting['started_at'],
                'expires_at' => $hosting['expires_at'],
                'days_remaining' => $hosting['days_remaining'],
                'auto_renew' => $hosting['auto_renew'],
                /** @var array{amount: string, currency: string, formatted: string} */
                'fee' => $hosting['fee'],
            ])->all(),
            'history' => collect($website['history'])->map(fn (array $change) => [
                'previous_status' => $change['previous_status'],
                'new_status' => $change['new_status'],
                'new_status_label' => $change['new_status_label'],
                'changed_at' => $change['changed_at'],
                /** In the reader's language for a platform move; as written for a person's. */
                'note' => $change['note'],
            ])->all(),
        ];
    }
}
