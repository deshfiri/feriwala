<?php

namespace App\Domain\Account\Queries;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\PreferredDomainExtensions;
use Illuminate\Support\Facades\DB;

/**
 * What Banij can honestly say about a preferred domain (D39 step 6) — which is
 * not whether it is available.
 *
 * There is no registrar connection (D9, D39), so this never answers
 * "available". It answers what is knowable here: whether the name is a valid
 * domain label, whether the extension is one Banij offers, and whether the
 * domain is already asked for or held by another partner on Banij. Everything
 * else is "not verified": staff check the domain by hand during review and
 * again before buying it. Alternatives are offered either way, so a partner
 * can save a second choice.
 */
class PreferredDomainCheck
{
    public const STATUS_INVALID = 'invalid';

    public const STATUS_TAKEN_ON_BANIJ = 'taken_on_banij';

    /** Not checked with any registrar: availability is not guaranteed. */
    public const STATUS_UNVERIFIED = 'unverified';

    public function __construct(
        protected PreferredDomainExtensions $extensions,
    ) {}

    /**
     * @return array{domain: string|null, status: string, reason: string|null, suggestions: list<array{name: string, extension: string, domain: string, status: string}>}
     */
    public function check(BusinessAccount $account, string $name, string $extension): array
    {
        $name = mb_strtolower(trim($name));
        $extension = PreferredDomainExtensions::normalise($extension);

        if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $name) !== 1) {
            return ['domain' => null, 'status' => self::STATUS_INVALID, 'reason' => __('onboarding.refused.domain_name_invalid'), 'suggestions' => []];
        }

        if ($extension === null || ! $this->extensions->allows($extension)) {
            return ['domain' => null, 'status' => self::STATUS_INVALID, 'reason' => __('onboarding.refused.extension_not_offered'), 'suggestions' => []];
        }

        return [
            'domain' => $name.$extension,
            'status' => $this->statusOf($account, $name.$extension),
            'reason' => null,
            'suggestions' => $this->suggestions($account, $name, $extension),
        ];
    }

    /** Taken on Banij, or not verified — never "available". */
    protected function statusOf(BusinessAccount $account, string $domain): string
    {
        $requestedByAnother = BusinessAccount::query()
            ->whereKeyNot($account->id)
            ->whereRaw('lower(preferred_domain_name || preferred_domain_extension) = ?', [$domain])
            ->exists();

        $heldOnBanij = DB::table('website_domains')->whereRaw('lower(domain) = ?', [$domain])->exists()
            || DB::table('websites')->where(fn ($query) => $query
                ->whereRaw('lower(domain) = ?', [$domain])
                ->orWhereRaw('lower(requested_address) = ?', [$domain]))
                ->exists();

        return $requestedByAnother || $heldOnBanij ? self::STATUS_TAKEN_ON_BANIJ : self::STATUS_UNVERIFIED;
    }

    /**
     * The same name on the other offered extensions, then a few common
     * variations on the chosen one — each with what Banij knows about it.
     *
     * @return list<array{name: string, extension: string, domain: string, status: string}>
     */
    protected function suggestions(BusinessAccount $account, string $name, string $extension): array
    {
        $candidates = [];

        foreach ($this->extensions->enabled() as $other) {
            if ($other !== $extension) {
                $candidates[] = [$name, $other];
            }
        }

        foreach (['shop', 'store', 'bd', 'online'] as $suffix) {
            $variant = mb_substr($name, 0, 63 - mb_strlen($suffix) - 1).'-'.$suffix;

            if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $variant) === 1) {
                $candidates[] = [$variant, $extension];
            }
        }

        $suggestions = [];

        foreach (array_slice($candidates, 0, 6) as [$candidateName, $candidateExtension]) {
            $domain = $candidateName.$candidateExtension;
            $suggestions[] = [
                'name' => $candidateName,
                'extension' => $candidateExtension,
                'domain' => $domain,
                'status' => $this->statusOf($account, $domain),
            ];
        }

        return $suggestions;
    }
}
