<?php

namespace App\Domain\Wallet\Enums;

/**
 * §24.3's actions, in the order they are allowed to happen.
 *
 * §24.3 lists them as a sequence and the ordering is the substance of it: an
 * account is told, then given time, then loses the things it can lose without
 * losing the business, and only at the end loses the business. Skipping to the
 * end because a balance dipped would be the opposite of what it describes.
 *
 * Each stage is also **optional**, configured per rule (§24.1). A platform that
 * only ever warns is a legitimate configuration; so is one that restricts
 * services and never touches the account.
 *
 * Gaps of 10 leave room to insert a stage without renumbering a column that
 * rows already carry.
 */
enum WalletRestrictionStage: string
{
    /** Told, and shown what to top up. Nothing is taken away. */
    case Notified = 'notified';

    /** Chargeable services stop (§24.3). The account still trades. */
    case ServicesRestricted = 'services_restricted';

    /** No new website setup or renewal goes ahead (§24.3). */
    case WebsiteSetupPaused = 'website_setup_paused';

    /** The dedicated website goes dark (§24.3). */
    case WebsiteDisabled = 'website_disabled';

    /** Account features are restricted (§24.3). */
    case AccountRestricted = 'account_restricted';

    /** The account is temporarily disabled, where configured (§24.3). */
    case AccountDisabled = 'account_disabled';

    /**
     * How far along §24.3's sequence this is. Higher is more severe.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Notified => 10,
            self::ServicesRestricted => 20,
            self::WebsiteSetupPaused => 30,
            self::WebsiteDisabled => 40,
            self::AccountRestricted => 50,
            self::AccountDisabled => 60,
        };
    }

    /**
     * Whether this stage actually takes something away.
     *
     * Being told is not a restriction, and counting it as one would make a
     * warning look like a punishment on every screen that lists them.
     */
    public function restrictsAnything(): bool
    {
        return $this !== self::Notified;
    }

    /**
     * The stages in the order §24.3 puts them.
     *
     * @return array<int, self>
     */
    public static function graded(): array
    {
        $stages = self::cases();

        usort($stages, fn (self $a, self $b) => $a->severity() <=> $b->severity());

        return $stages;
    }

    public function label(): string
    {
        return match ($this) {
            self::Notified => 'Low balance notice',
            self::ServicesRestricted => 'Chargeable services restricted',
            self::WebsiteSetupPaused => 'Website setup and renewal paused',
            self::WebsiteDisabled => 'Website disabled',
            self::AccountRestricted => 'Account features restricted',
            self::AccountDisabled => 'Account temporarily disabled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Notified => 'warning',
            self::ServicesRestricted, self::WebsiteSetupPaused => 'warning',
            self::WebsiteDisabled, self::AccountRestricted, self::AccountDisabled => 'danger',
        };
    }
}
