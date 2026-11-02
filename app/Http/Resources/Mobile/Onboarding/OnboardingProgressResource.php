<?php

namespace App\Http\Resources\Mobile\Onboarding;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Where the account is in the activation funnel (§33.4), as
 * {@see \App\Domain\Account\OnboardingProgress} works it out — the same words
 * the web stepper uses.
 *
 * @property array<string, mixed> $resource
 */
class OnboardingProgressResource extends JsonResource
{
    /**
     * The mobile screen each web address stands for. The web's `action.url` is a
     * path the app cannot open, so it is translated to a name the app can route on.
     *
     * @var array<string, string>
     */
    protected const SCREENS = [
        'verification.mobile' => 'mobile_verification',
        'verification.notice' => 'email_verification',
        'kyc.create' => 'kyc',
        'packages.index' => 'packages',
        'checkout.show' => 'checkout',
    ];

    /**
     * @return array{
     *     is_complete: bool,
     *     current_step: string,
     *     steps: list<array{key: string, label: string, position: int, state: string}>,
     *     action: array{label: string, screen: string|null}|null,
     *     blocked_reason: string|null,
     *     feedback: string|null,
     *     status: array{value: string, label: string, tone: string}
     * }
     */
    public function toArray(Request $request): array
    {
        $progress = $this->resource;

        return [
            /** True once the account is activated. */
            'is_complete' => $progress['is_complete'],
            /** Key of the step the account is on. */
            'current_step' => $progress['current_step'],
            /** Every step in order. `state` is `done`, `current`, `blocked` or `upcoming`. */
            'steps' => array_map(fn (array $step) => [
                'key' => $step['key'],
                'label' => $step['label'],
                'position' => $step['position'],
                'state' => $step['state'],
            ], $progress['steps']),
            /** The single next thing to do, or null when there is only waiting. `screen` is one of `mobile_verification`, `email_verification`, `kyc`, `packages`, `checkout`. */
            'action' => $progress['action'] === null ? null : [
                'label' => $progress['action']['label'],
                'screen' => $this->screenFor($progress['action']['url']),
            ],
            /** Why the account cannot proceed, in the applicant's own words. */
            'blocked_reason' => $progress['blocked_reason'],
            /** The reviewer's message to the applicant, never the internal note. */
            'feedback' => $progress['feedback'],
            'status' => [
                'value' => $progress['status']['value'],
                'label' => $progress['status']['label'],
                'tone' => $progress['status']['tone'],
            ],
        ];
    }

    protected function screenFor(string $url): ?string
    {
        foreach (self::SCREENS as $route => $screen) {
            if (route($route, absolute: false) === $url) {
                return $screen;
            }
        }

        return null;
    }
}
