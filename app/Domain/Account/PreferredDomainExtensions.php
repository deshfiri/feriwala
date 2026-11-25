<?php

namespace App\Domain\Account;

use App\Domain\Settings\SettingsRepository;

/**
 * The domain extensions a partner may choose their preferred domain from at
 * business setup (D39).
 *
 * Set by an administrator; `.com`, `.shop` and `.store` until one is. There is
 * no live availability check (D39) and nothing is bought automatically (D9):
 * the choice is saved with the application, and staff check and buy it by hand
 * during review.
 */
class PreferredDomainExtensions
{
    public const SETTING = 'onboarding.domain_extensions';

    /** @var list<string> */
    public const DEFAULT = ['.com', '.shop', '.store'];

    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    /**
     * @return list<string> e.g. ['.com', '.shop']
     */
    public function enabled(): array
    {
        $value = $this->settings->get(self::SETTING);

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (! is_array($value) || $value === []) {
            return self::DEFAULT;
        }

        $extensions = [];

        foreach ($value as $extension) {
            $normalised = self::normalise((string) $extension);

            if ($normalised !== null && ! in_array($normalised, $extensions, true)) {
                $extensions[] = $normalised;
            }
        }

        return $extensions === [] ? self::DEFAULT : $extensions;
    }

    public function allows(?string $extension): bool
    {
        return $extension !== null && in_array(self::normalise($extension), $this->enabled(), true);
    }

    /** `com`, `.COM` and ` .com ` are all `.com`; anything that is not an extension is null. */
    public static function normalise(string $extension): ?string
    {
        $extension = '.'.ltrim(mb_strtolower(trim($extension)), '.');

        return preg_match('/^\.[a-z]{2,20}(\.[a-z]{2,3})?$/', $extension) === 1 ? $extension : null;
    }
}
