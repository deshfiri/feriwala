import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, CheckCircle2, Clock } from 'lucide-react';
import ActivationStepper from '@/components/onboarding/activation-stepper';
import PageContainer from '@/components/page-container';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
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
    const { t } = useTranslation();
    const isBlocked = progress.blocked_reason !== null;

    return (
        <>
            <Head title={t('onboarding.status.title')} />

            <PageContainer width="narrow">
                <header className="space-y-1.5 text-center">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {progress.is_complete
                            ? t('onboarding.status.complete_heading')
                            : t('onboarding.status.incomplete_heading')}
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        {progress.is_complete
                            ? t('onboarding.status.complete_description')
                            : t('onboarding.status.incomplete_description')}
                    </p>
                </header>

                <div className="bg-card border-border rounded-xl border p-5">
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

                <section className="bg-card border-border flex flex-wrap items-center gap-4 rounded-xl border p-5">
                    <div className="min-w-0 flex-1 space-y-1">
                        <p className="text-muted-foreground text-xs font-medium">
                            {t('onboarding.status.current_status')}
                        </p>
                        <StatusPill
                            tone={progress.status.tone}
                            label={progress.status.label}
                        />
                    </div>

                    {progress.action ? (
                        <Button asChild>
                            <Link href={progress.action.url}>
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
                            {t('onboarding.status.complete')}
                        </span>
                    ) : (
                        /* Saying "nothing to do" is better than a button that
                           does nothing. */
                        <span className="text-muted-foreground flex items-center gap-1.5 text-sm">
                            <Clock className="size-4" aria-hidden="true" />
                            {t('onboarding.status.waiting')}
                        </span>
                    )}
                </section>

                <p className="text-muted-foreground text-center text-xs">
                    {t('onboarding.status.help')}{' '}
                    <Link
                        href="/support"
                        className="text-foreground underline underline-offset-4"
                    >
                        {t('onboarding.status.contact_support')}
                    </Link>
                </p>
            </PageContainer>
        </>
    );
}
