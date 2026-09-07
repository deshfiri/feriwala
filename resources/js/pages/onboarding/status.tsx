import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, CheckCircle2, Clock } from 'lucide-react';
import ActivationStepper from '@/components/onboarding/activation-stepper';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import type { OnboardingProgress } from '@/types';

/**
 * Where a user sees how far through activation they are (§33.4).
 *
 * The page answers, in this order: where am I, what is wrong, what do I do
 * next. Anything else is secondary — someone who has just been told their KYC
 * was rejected is not reading a feature list.
 */
export default function OnboardingStatus({
    progress,
}: {
    progress: OnboardingProgress;
}) {
    const isBlocked = progress.blocked_reason !== null;

    return (
        <>
            <Head title="Activation status" />

            <div className="mx-auto w-full max-w-2xl space-y-6 px-4 py-10">
                <header className="space-y-1.5 text-center">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {progress.is_complete
                            ? 'Your account is active'
                            : 'Setting up your account'}
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        {progress.is_complete
                            ? 'Everything is ready. You can start using Feriwala.'
                            : 'A few steps remain before you can start trading.'}
                    </p>
                </header>

                <div className="bg-card border-border rounded-xl border p-5 shadow-sm">
                    <ActivationStepper steps={progress.steps} />
                </div>

                {/* What is wrong comes before what to do — the reason explains
                    the action. */}
                {isBlocked && (
                    <section
                        className="bg-danger-subtle border-danger/30 rounded-lg border p-4"
                        role="alert"
                    >
                        <div className="flex gap-3">
                            <AlertTriangle
                                className="text-danger mt-0.5 size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <div className="space-y-1.5">
                                <p className="text-sm font-semibold">
                                    {progress.blocked_reason}
                                </p>
                                {progress.feedback && (
                                    <p className="text-ink-2 text-sm">
                                        {progress.feedback}
                                    </p>
                                )}
                            </div>
                        </div>
                    </section>
                )}

                <section className="bg-card border-border flex flex-wrap items-center gap-4 rounded-xl border p-5 shadow-sm">
                    <div className="min-w-0 flex-1 space-y-1">
                        <p className="text-muted-foreground text-xs font-medium">
                            Current status
                        </p>
                        <StatusPill
                            tone={progress.status.tone}
                            label={progress.status.label}
                        />
                    </div>

                    {progress.action ? (
                        <Button asChild>
                            <Link href={progress.action.route}>
                                {progress.action.label}
                                <ArrowRight
                                    className="size-4"
                                    aria-hidden="true"
                                />
                            </Link>
                        </Button>
                    ) : progress.is_complete ? (
                        <span className="text-success flex items-center gap-1.5 text-sm font-medium">
                            <CheckCircle2
                                className="size-4"
                                aria-hidden="true"
                            />
                            Complete
                        </span>
                    ) : (
                        /* Saying "nothing to do" is better than a button that
                           does nothing. */
                        <span className="text-muted-foreground flex items-center gap-1.5 text-sm">
                            <Clock className="size-4" aria-hidden="true" />
                            Waiting for our review
                        </span>
                    )}
                </section>

                <p className="text-muted-foreground text-center text-xs">
                    Need help?{' '}
                    <Link
                        href="/support"
                        className="text-foreground underline underline-offset-4"
                    >
                        Contact support
                    </Link>
                </p>
            </div>
        </>
    );
}
