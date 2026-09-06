import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { StatusTone } from '@/lib/status';
import { create as kycForm } from '@/routes/kyc';

type RequirementStatus = 'supplied' | 'outstanding' | 'not_supplied';

type Round = {
    id: string;
    round: number;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    is_editable: boolean;
    opened_at: string | null;
    submitted_at: string | null;
    reviewed_at: string | null;
    deadline_at: string | null;
    days_remaining: number | null;
    is_overdue: boolean;
    was_requested: boolean;
    instructions: string | null;
    requirements: {
        key: string;
        name: string;
        instructions: string | null;
        is_required: boolean;
        status: RequirementStatus;
    }[];
    feedback: {
        outcome: string;
        feedback: string;
        at: string | null;
    }[];
};

/**
 * The applicant's own verification history (§7.3).
 *
 * Everything here was written *to* them — the reviewer's feedback, the
 * instruction on a requested update. Nothing written *about* them reaches this
 * page: the internal reason and the reviewer's note stay on the decision
 * record, and the server payload never carries them.
 */
export default function KycHistory({ rounds }: { rounds: Round[] }) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('Verification history')} />

            <div className="mx-auto max-w-3xl space-y-6 p-4">
                <Heading
                    title={t('Verification history')}
                    description={t(
                        'Every round of verification on this account, and what came of it.',
                    )}
                />

                {rounds.length === 0 ? (
                    <EmptyState
                        title={t('Nothing to show yet')}
                        description={t(
                            'Your verification history will appear here once you start.',
                        )}
                        action={
                            <Button asChild size="sm">
                                <Link href={kycForm()}>
                                    {t('Start verification')}
                                </Link>
                            </Button>
                        }
                    />
                ) : null}

                {rounds.map((round) => (
                    <RoundCard key={round.id} round={round} />
                ))}
            </div>
        </>
    );
}

function RoundCard({ round }: { round: Round }) {
    const { t } = useTranslation();

    return (
        <section className="border-sidebar-border/70 dark:border-sidebar-border space-y-4 rounded-xl border p-4">
            <header className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="font-medium">
                        {t('Round :number', { number: round.round })}
                        {round.was_requested ? (
                            <span className="text-muted-foreground">
                                {' '}
                                — {t('requested by Feriwala')}
                            </span>
                        ) : null}
                    </h2>
                    <Dates round={round} />
                </div>

                {/* Never colour alone (§33.9): the pill carries its label. */}
                <StatusPill
                    tone={round.status_tone}
                    label={round.status_label}
                />
            </header>

            {round.instructions ? (
                <p className="text-sm">{round.instructions}</p>
            ) : null}

            <Deadline round={round} />

            <ul className="divide-border divide-y rounded-lg border text-sm">
                {round.requirements.map((requirement) => (
                    <li
                        key={requirement.key}
                        className="flex flex-wrap items-center justify-between gap-2 p-3"
                    >
                        <div className="min-w-0">
                            <p className="truncate">
                                {requirement.name}
                                {!requirement.is_required ? (
                                    <span className="text-muted-foreground">
                                        {' '}
                                        ({t('optional')})
                                    </span>
                                ) : null}
                            </p>
                            {requirement.instructions ? (
                                <p className="text-muted-foreground truncate">
                                    {requirement.instructions}
                                </p>
                            ) : null}
                        </div>

                        <span className="text-muted-foreground">
                            {requirement.status === 'supplied'
                                ? t('Provided')
                                : requirement.status === 'outstanding'
                                  ? t('Still needed')
                                  : t('Not provided')}
                        </span>
                    </li>
                ))}
            </ul>

            {round.feedback.length > 0 ? (
                <div className="space-y-2 text-sm">
                    <h3 className="font-medium">{t('What we told you')}</h3>
                    {round.feedback.map((entry, index) => (
                        <p key={index} className="text-muted-foreground">
                            {entry.feedback}
                        </p>
                    ))}
                </div>
            ) : null}

            {round.is_editable ? (
                <Button asChild size="sm">
                    <Link href={kycForm()}>{t('Continue this round')}</Link>
                </Button>
            ) : null}
        </section>
    );
}

function Dates({ round }: { round: Round }) {
    const { t } = useTranslation();
    const format = (value: string | null) =>
        value === null ? null : new Date(value).toLocaleDateString();

    const parts = [
        round.opened_at
            ? t('Opened :date', { date: format(round.opened_at)! })
            : null,
        round.submitted_at
            ? t('Submitted :date', { date: format(round.submitted_at)! })
            : null,
        round.reviewed_at
            ? t('Reviewed :date', { date: format(round.reviewed_at)! })
            : null,
    ].filter(Boolean);

    return <p className="text-muted-foreground text-sm">{parts.join(' · ')}</p>;
}

function Deadline({ round }: { round: Round }) {
    const { t } = useTranslation();

    if (round.deadline_at === null) {
        return null;
    }

    const due = new Date(round.deadline_at).toLocaleDateString();

    if (round.is_overdue) {
        return (
            <p className="text-sm font-medium">
                {t('This was due on :date.', { date: due })}
            </p>
        );
    }

    return (
        <p className="text-muted-foreground text-sm">
            {round.days_remaining === null
                ? t('Due :date', { date: due })
                : t('Due :date — :count days left', {
                      date: due,
                      count: Math.max(round.days_remaining, 0),
                  })}
        </p>
    );
}
