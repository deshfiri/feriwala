import { AlertTriangle, Check } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { OnboardingStep, OnboardingStepState } from '@/types';

/**
 * The six-step activation stepper (§33.4).
 *
 * A blocked step is marked in place rather than pulled out into a banner, so
 * someone can see at a glance that it is their KYC needing attention and not
 * wonder which part of the process went wrong.
 *
 * State is never carried by colour alone — each step also has a shape and, for
 * the current and blocked ones, a text label (§33.9).
 */
export default function ActivationStepper({
    steps,
    className,
}: {
    steps: OnboardingStep[];
    className?: string;
}) {
    return (
        <ol
            className={cn(
                'flex flex-wrap items-start gap-x-2 gap-y-4',
                className,
            )}
            aria-label="Activation progress"
        >
            {steps.map((step, index) => (
                <li key={step.key} className="flex flex-1 items-start gap-2">
                    <div className="flex min-w-0 flex-1 flex-col items-center gap-1.5 text-center">
                        <StepMarker
                            state={step.state}
                            position={step.position}
                        />

                        <span
                            className={cn(
                                'text-xs leading-tight',
                                step.state === 'current' &&
                                    'text-foreground font-semibold',
                                step.state === 'blocked' &&
                                    'text-danger font-semibold',
                                step.state === 'done' &&
                                    'text-muted-foreground',
                                step.state === 'upcoming' &&
                                    'text-muted-foreground',
                            )}
                        >
                            {step.label}
                        </span>

                        {/* Announced to screen readers; the marker carries it visually. */}
                        <span className="sr-only">{describe(step.state)}</span>
                    </div>

                    {index < steps.length - 1 && (
                        <div
                            aria-hidden="true"
                            className={cn(
                                'mt-3.5 h-px min-w-4 flex-1',
                                step.state === 'done'
                                    ? 'bg-success'
                                    : 'bg-border',
                            )}
                        />
                    )}
                </li>
            ))}
        </ol>
    );
}

function StepMarker({
    state,
    position,
}: {
    state: OnboardingStepState;
    position: number;
}) {
    const base =
        'flex size-7 shrink-0 items-center justify-center rounded-full border text-xs font-semibold';

    if (state === 'done') {
        return (
            <span className={cn(base, 'bg-success border-success text-white')}>
                <Check className="size-3.5" aria-hidden="true" />
            </span>
        );
    }

    if (state === 'blocked') {
        return (
            <span className={cn(base, 'bg-danger border-danger text-white')}>
                <AlertTriangle className="size-3.5" aria-hidden="true" />
            </span>
        );
    }

    if (state === 'current') {
        return (
            <span
                className={cn(base, 'border-brand text-brand bg-brand-subtle')}
            >
                {position}
            </span>
        );
    }

    return (
        <span
            className={cn(base, 'border-border text-muted-foreground bg-card')}
        >
            {position}
        </span>
    );
}

function describe(state: OnboardingStepState): string {
    return {
        done: 'Completed',
        current: 'In progress',
        blocked: 'Needs your attention',
        upcoming: 'Not started',
    }[state];
}
