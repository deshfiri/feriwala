<?php

namespace App\Http\Controllers\Erp;

use App\Domain\Account\Actions\SendMobileVerificationCode;
use App\Domain\Account\Actions\VerifyMobile;
use App\Domain\Account\Exceptions\ResendTooSoon;
use App\Domain\Account\VerificationCodes;
use App\Domain\Notification\Exceptions\SmsEventSwitchedOff;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Navigation\HomeRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Confirming the mobile number an account registered with (§5.1, P1-9).
 *
 * The codes and their limits live in {@see VerificationCodes}; this only carries
 * them to and from the person. Nothing is sent on opening the page: a GET that
 * texts somebody is one a prefetch or a reload fires again, at Feriwala's cost,
 * so a code goes out only when it is asked for.
 *
 * An identity question, like the email address. Moving the business on once the
 * number is confirmed is {@see VerifyMobile}'s decision, not this screen's.
 */
class MobileVerificationController extends Controller
{
    public function __construct(
        protected VerificationCodes $codes,
        protected HomeRoute $home,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $this->userFor($request);

        if (! $this->hasSomethingToVerify($user)) {
            return $this->onward($user);
        }

        $mobile = (string) $user->mobile;

        return Inertia::render('onboarding/verify-mobile', [
            'mobile' => $this->mask($mobile),
            'code_pending' => $this->codes->isPending(SendMobileVerificationCode::PURPOSE, $mobile),
            'code_length' => VerificationCodes::LENGTH,
            'expires_in_minutes' => intdiv(VerificationCodes::TTL_SECONDS, 60),
        ]);
    }

    public function send(Request $request, SendMobileVerificationCode $send): RedirectResponse
    {
        $user = $this->userFor($request);

        if (! $this->hasSomethingToVerify($user)) {
            return $this->onward($user);
        }

        try {
            $send->handle($user);
        } catch (ResendTooSoon) {
            // A wait, not a failure — they have done nothing wrong.
            throw ValidationException::withMessages([
                'resend' => __('common.verify_mobile.wait'),
            ]);
        } catch (SmsEventSwitchedOff) {
            // Said plainly rather than claiming a code is on its way.
            throw ValidationException::withMessages([
                'resend' => __('sms.event_switch.code_off'),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('common.verify_mobile.sent', ['mobile' => $this->mask((string) $user->mobile)]),
        ]);

        return to_route('verification.mobile');
    }

    public function verify(Request $request, VerifyMobile $verify): RedirectResponse
    {
        $user = $this->userFor($request);

        if (! $this->hasSomethingToVerify($user)) {
            return $this->onward($user);
        }

        $validated = $request->validate([
            'code' => ['required', 'digits:'.VerificationCodes::LENGTH],
        ]);

        /*
         * One message for wrong, expired, used up, and never sent alike —
         * {@see VerificationCodes::verify()} does not tell them apart either,
         * so the screen cannot leak which it was.
         */
        if (! $verify->handle($user, (string) $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => __('common.verify_mobile.invalid'),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('common.verify_mobile.verified')]);

        return $this->onward($user);
    }

    protected function userFor(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * A number that is on file and not yet confirmed.
     *
     * A confirmed number is not confirmed again, and a code cannot be sent to a
     * number nobody gave us.
     */
    protected function hasSomethingToVerify(User $user): bool
    {
        return $user->mobile !== null && $user->mobile_verified_at === null;
    }

    /**
     * The next step: the stepper for a business, the usual landing otherwise.
     *
     * Not the stepper for everyone — it refuses somebody with no business
     * account, and that is the loop {@see HomeRoute} exists to prevent.
     */
    protected function onward(User $user): RedirectResponse
    {
        if ($user->businessAccount !== null) {
            return to_route('onboarding.status');
        }

        return redirect()->to($this->home->urlFor($user));
    }

    /**
     * `+8801712345678` becomes `+88017****5678`.
     *
     * Enough for somebody to recognise their own number and spot a typo in it,
     * not enough to read off a screen over their shoulder.
     */
    protected function mask(string $number): string
    {
        $length = strlen($number);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($number, 0, 6).str_repeat('*', $length - 10).substr($number, -4);
    }
}
