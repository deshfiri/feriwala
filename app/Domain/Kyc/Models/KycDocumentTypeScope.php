<?php

namespace App\Domain\Kyc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Limits a document type to a package, a country, or both (§7.2).
 *
 * Two nullable dimensions rather than a `scope_type` + `scope_value` pair,
 * because §7.2 asks for package-**and**-country rules and one column per row
 * cannot express "Enterprise accounts in Bangladesh".
 *
 * The package is held by its **public id**, not its slug. A slug is a routing
 * decision and may be edited; a rule keyed on one is silently orphaned the day
 * somebody renames a URL, and nothing says so until an applicant is asked for
 * the wrong documents. A ULID is assigned once and never changes, so it can
 * carry the relationship while the slug goes back to being a URL.
 *
 * `is_required` is a nullable **override**. A trade licence can be optional in
 * general and mandatory in one country; forcing that into two document types
 * would show an applicant the same requirement twice. Null means "use the
 * type's own setting", which is the safe fallback.
 *
 * @property string|null $package_public_id
 * @property string|null $country_code
 * @property bool|null $is_required
 */
class KycDocumentTypeScope extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<KycDocumentType, $this>
     */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(KycDocumentType::class, 'kyc_document_type_id');
    }

    /**
     * Whether this rule covers an account on `$package` in `$country`.
     *
     * A null dimension matches anything: a rule naming only a country applies
     * on every package there.
     */
    /**
     * @param  string|null  $package  the account's package **public id**
     */
    public function matches(?string $package, ?string $country): bool
    {
        if ($this->package_public_id !== null && $this->package_public_id !== $package) {
            return false;
        }

        if ($this->country_code !== null
            && mb_strtoupper((string) $country) !== mb_strtoupper($this->country_code)) {
            return false;
        }

        return true;
    }

    /**
     * How specific this rule is. Higher wins.
     *
     * The documented priority (§7.2):
     *
     *   3. package **and** country — the narrowest statement of intent
     *   2. country — statutory, and a package must not waive a regulator
     *   1. package — commercial
     *   0. neither — a rule that names nothing, treated as global
     *
     * Country outranking package is the deliberate part. A country rule is
     * usually there because the law requires it; letting a commercial package
     * rule override it would make a plan choice able to drop a legal
     * requirement.
     */
    public function specificity(): int
    {
        return match (true) {
            $this->package_public_id !== null && $this->country_code !== null => 3,
            $this->country_code !== null => 2,
            $this->package_public_id !== null => 1,
            default => 0,
        };
    }
}
