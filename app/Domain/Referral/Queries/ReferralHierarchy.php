<?php

namespace App\Domain\Referral\Queries;

use App\Domain\Referral\Models\AccountReferral;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;

/**
 * Walking the referral hierarchy (§25.1, D24, P7-11).
 *
 * Upward only, and only as far as asked. The commission engine asks for the
 * chain to its plan's depth; nothing in the ERP ever asks for a whole downline,
 * because nobody may see one (D24).
 */
class ReferralHierarchy
{
    /** As far as the database's own cycle guard walks. */
    public const WHOLE_CHAIN = 100000;

    public function __construct(
        protected DatabaseManager $database,
    ) {}

    /**
     * The account's ancestors, level by level: `[1 => direct referrer, 2 => …]`.
     *
     * Ends where the chain ends, so a short chain returns fewer levels than
     * asked for. A level is never renumbered: level 3 is always three steps up.
     *
     * @return array<int, int> level => business account id
     */
    public function ancestors(int $accountId, int $depth): array
    {
        if ($depth < 1) {
            return [];
        }

        $rows = $this->database->select(<<<'SQL'
            WITH RECURSIVE chain(account_id, level) AS (
                SELECT referrer_account_id, 1 FROM account_referrals WHERE referred_account_id = ?
                UNION ALL
                SELECT r.referrer_account_id, c.level + 1
                FROM account_referrals r
                JOIN chain c ON r.referred_account_id = c.account_id
                WHERE c.level < ?
            )
            SELECT account_id, level FROM chain ORDER BY level
        SQL, [$accountId, $depth]);

        $ancestors = [];

        foreach ($rows as $row) {
            $ancestors[(int) $row->level] = (int) $row->account_id;
        }

        return $ancestors;
    }

    /**
     * Whether `$candidate` is `$accountId` or sits anywhere above it.
     */
    public function isSelfOrAncestor(int $candidate, int $accountId): bool
    {
        return $candidate === $accountId
            || in_array($candidate, $this->ancestors($accountId, self::WHOLE_CHAIN), true);
    }

    /**
     * The accounts this one referred directly — level 1 and nothing below it.
     *
     * @return Builder<AccountReferral>
     */
    public function directReferrals(int $accountId): Builder
    {
        return AccountReferral::query()->where('referrer_account_id', $accountId);
    }
}
