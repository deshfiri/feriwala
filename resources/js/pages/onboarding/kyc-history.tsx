import { Head, Link } from '@inertiajs/react';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { create as kycForm } from '@/routes/kyc';
import type { KycHistoryRound } from '@/types';

/**
 * The applicant's own verification history (§7.3, P1-27).
 *
 * Everything here was written *to* them — the reviewer's feedback, the
 * instruction on a requested update. Nothing written *about* them appears: the
 * internal reason and the reviewer's note stay on the decision record, and the
 * server payload never carries them, so this page cannot leak one by accident.
 */
export default function KycHistory({ rounds }: { rounds: KycHistoryRound[] }) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('kyc.history.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('kyc.history.title')}
                    description={t('kyc.history.description')}
                />

                {rounds.length === 0 ? (
                    <EmptyState
                        title={t('kyc.history.empty_title')}
                        description={t('kyc.history.empty_description')}
                        action={
                            <Button asChild size="sm">
                                <Link href={kycForm()}>
                                    {t('kyc.history.start')}
                                </Link>
                            </Button>
                        }
                    />
                ) : (
                    rounds.map((round) => (
                        <RoundCard key={round.id} round={round} />
                    ))
                )}
            </PageContainer>
        </>
    );
}

function RoundCard({ round }: { round: KycHistoryRound }) {
    const { t } = useTranslation();

    return (
        <section className="bg-card space-y-4 rounded-xl border p-4">
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="font-medium">
                        {t('kyc.history.round', { number: round.round })}
                        {round.was_requested && (
                            <span className="text-muted-foreground font-normal">
                                {' — '}
                                {t('kyc.history.requested')}
                            </span>
                        )}
                    </h2>
                    <Dates round={round} />
                </div>

                {/* Never colour alone (§33.9): the pill carries its label. */}
                <StatusPill
                    tone={round.status_tone}
                    label={round.status_label}
                />
            </header>

            {round.instructions && (
                <p className="text-sm">{round.instructions}</p>
            )}

            <Deadline round={round} />

            {round.requirements.length > 0 && (
                <div className="space-y-2">
                    <h3 className="text-sm font-medium">
                        {t('kyc.history.requirements')}
                    </h3>

                    <ul className="bg-card divide-border divide-y rounded-xl border text-sm">
                        {round.requirements.map((requirement) => (
                            <li
                                key={requirement.key}
                                className="flex flex-wrap items-start justify-between gap-2 p-3"
                            >
                                <div className="min-w-0">
                                    <p>
                                        {requirement.name}
                                        {!requirement.is_required && (
                                            <span className="text-muted-foreground">
                                                {' ('}
                                                {t('kyc.history.optional')}
                                                {')'}
                                            </span>
                                        )}
                                    </p>
                                    {requirement.instructions && (
                                        <p className="text-muted-foreground text-xs">
                                            {requirement.instructions}
                                        </p>
                                    )}
                                </div>

                                <span className="text-muted-foreground shrink-0 text-xs">
                                    {t(
                                        `kyc.history.status.${requirement.status}`,
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {round.feedback.length > 0 && (
                <div className="space-y-2">
                    <h3 className="text-sm font-medium">
                        {t('kyc.history.feedback')}
                    </h3>
                    {round.feedback.map((entry, index) => (
                        <p
                            key={index}
                            className="text-muted-foreground text-sm"
                        >
                            {entry.feedback}
                        </p>
                    ))}
                </div>
            )}

            {round.is_editable && (
                <Button asChild size="sm">
                    <Link href={kycForm()}>{t('kyc.history.continue')}</Link>
                </Button>
            )}
        </section>
    );
}

function Dates({ round }: { round: KycHistoryRound }) {
    const { t, locale } = useTranslation();

    const format = (value: string) =>
        new Date(value).toLocaleDateString(locale, {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        });

    const parts = [
        round.opened_at &&
        t('kyc.history.opened', { date: format(round.opened_at) }),
        round.submitted_at &&
        t('kyc.history.submitted', { date: format(round.submitted_at) }),
        round.reviewed_at &&
        t('kyc.history.reviewed', { date: format(round.reviewed_at) }),
    ].filter(Boolean);

    return <p className="text-muted-foreground text-sm">{parts.join(' · ')}</p>;
}

function Deadline({ round }: { round: KycHistoryRound }) {
    const { t, locale } = useTranslation();

    if (round.deadline_at === null) {
        return null;
    }

    const due = new Date(round.deadline_at).toLocaleDateString(locale, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

    if (round.is_overdue) {
        return (
            <p className="text-danger text-sm font-medium">
                {t('kyc.history.overdue', { date: due })}
            </p>
        );
    }

    return (
        <p className="text-muted-foreground text-sm">
            {round.days_remaining === null
                ? t('kyc.history.due', { date: due })
                : t('kyc.history.due_with_days', {
                    date: due,
                    count: Math.max(round.days_remaining, 0),
                })}
        </p>
    );
}
