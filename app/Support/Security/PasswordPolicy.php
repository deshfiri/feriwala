<?php

namespace App\Support\Security;

use Illuminate\Validation\Rules\Password;

/**
 * What counts as a strong password here (§6).
 *
 * One definition, used by every flow that writes a password and by every screen
 * that asks for one. Registration, reset and change each stating their own would
 * drift, and the drift is invisible: a policy is only ever exercised at the one
 * entry point somebody happened to test.
 *
 * **It applies in every environment.** It used to be production-only, which
 * meant the rules were never exercised anywhere they could be observed —
 * development, staging and the whole test suite ran on Laravel's fallback of
 * eight characters and nothing else. A policy that only exists in production is
 * one nobody has tried.
 */
class PasswordPolicy
{
    /**
     * The minimum length. Twelve rather than eight, because length is the only
     * one of these rules that reliably costs an attacker anything.
     */
    public const MINIMUM_LENGTH = 12;

    /**
     * The rule every password-writing flow validates against.
     */
    public static function rules(): Password
    {
        $password = Password::min(self::MINIMUM_LENGTH)
            ->mixedCase()
            ->letters()
            ->numbers()
            ->symbols();

        /*
         * The breach check is a call to Have I Been Pwned, so it is a network
         * dependency rather than a rule about the password itself. A test suite
         * that reaches the internet is one that fails for reasons having nothing
         * to do with the code — so the structural rules run everywhere and this
         * one sits out the test environment only.
         */
        return app()->runningUnitTests()
            ? $password
            : $password->uncompromised();
    }

    /**
     * The same policy as the browser's `passwordrules` attribute understands.
     *
     * Password managers read it to generate a password that will actually be
     * accepted. Derived from the rule object rather than written out again,
     * because a hint that disagrees with the validator sends people to a
     * generated password the form then rejects.
     */
    public static function hint(): string
    {
        return self::rules()->toPasswordRulesString();
    }
}
