<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Billing\PaymentLogRedactor;
use App\Domain\Notification\Actions\ConfigureSms;
use App\Domain\Notification\Models\SmsMessageRecord;
use App\Domain\Notification\Policies\SmsSettingsPolicy;
use App\Http\Controllers\Controller;
use App\Integrations\Sms\SmsProviderManager;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * SMS: whether it is on, and who carries it (§30).
 *
 * Every provider §30.1 names is listed, including the ones with no driver yet —
 * "why is Twilio not an option" is answered by seeing it marked as not built,
 * not by its absence.
 *
 * No provider credential is ever sent to the browser. There are none stored yet
 * either, but the screen is shaped so that adding them in Phase 8 does not need
 * this rule discovered again (§42).
 */
class SmsController extends Controller
{
    public function __construct(
        protected ConfigureSms $configure,
        protected SmsProviderManager $providers,
        protected PaymentLogRedactor $redactor,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        abort_unless(SmsSettingsPolicy::canView($actor), 403);

        return Inertia::render('admin/sms', [
            'settings' => [
                'enabled' => $this->providers->isEnabled(),
                'provider' => $this->providers->active(),
                'can_send' => $this->providers->canSend(),
            ],
            'providers' => $this->providers->catalogue(),

            /*
             * §30.2's delivery status, failed-SMS log and history, in one list.
             * Recent rather than paginated: the question this answers is "did
             * the last hour of messages go out", and anything older belongs in
             * the reporting module.
             */
            'messages' => SmsMessageRecord::query()
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (SmsMessageRecord $message) => [
                    'id' => $message->public_id,
                    'event' => $message->event,

                    // Masked here even though the row holds it in full: this is
                    // a screen, and a screen full of phone numbers is a screen
                    // that leaks them over somebody's shoulder (§42).
                    'recipient' => $this->redactor->mask($message->recipient),

                    'status' => $message->status->value,
                    'status_label' => $message->status->label(),
                    'status_tone' => $message->status->tone(),
                    'segments' => $message->segments,
                    'attempts' => $message->attempts,
                    'provider' => $message->provider,
                    'error' => $message->error,
                    'at' => $message->created_at?->toIso8601String(),
                ])
                ->all(),

            'can' => ['manage' => SmsSettingsPolicy::canManage($actor)],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(SmsSettingsPolicy::canManage($actor), 403);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'provider' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $this->configure->handle(
                $actor,
                (bool) $validated['enabled'],
                $validated['provider'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['provider' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('sms.saved')]);

        return back();
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
