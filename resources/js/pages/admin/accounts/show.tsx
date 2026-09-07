import { Head } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import type { StatusTone } from '@/lib/status';
import type { AccountSubscriptionRow } from '@/types';
import RequestKycUpdateDialog, {
    type DocumentTypeOption,
} from './request-kyc-update-dialog';

type Owner = {
    name: string;
    email: string;
    mobile: string | null;
    country: string | null;
    identity_status: string | null;
    identity_status_label: string | null;
    identity_status_tone: StatusTone | null;
};

type KycRound = {
    id: string;
    round: number;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    submitted_at: string | null;
    reviewed_at: string | null;
    deadline_at: string | null;
    documents_count: number;
    requirements: { name: string; is_required: boolean }[];
    requested_at: string | null;
    requested_by: string | null;
    request_reason: string | null;
    request_instructions: string | null;
};

type Props = {
    /**
     * The business being looked at. Deliberately not called `account`: that name
     * belongs to the shared prop describing the *viewer's* own account, and a
     * page prop would overwrite it for the whole shell.
     */
    business: {
        id: string;
        name: string;
        status: string;
        status_label: string;
        status_tone: StatusTone;
        registered_at: string | null;
        activated_at: string | null;
        owner: Owner | null;
    };
    status_history: {
        id: number;
        to_status: string | null;
        from_status: string | null;
        changed_by: string | null;
        reason: string | null;
        created_at: string | null;
    }[];
    kyc_rounds: KycRound[];
    subscription: AccountSubscriptionRow | null;
    subscription_history: AccountSubscriptionRow[];
    payments: {
        id: string;
        reference: string;
        status: string;
        status_label: string;
        status_tone: StatusTone;
        purpose: string;
        amount: Money;
        initiated_at: string | null;
        completed_at: string | null;
    }[];
    staff: {
        id: number;
        name: string | null;
        email: string | null;
        role: string;
        role_label: string;
    }[];
    document_types: DocumentTypeOption[];
    can: { request_kyc_update: boolean };
    blockers: string[];
};

/**
 * One trading business, in full (P1-79).
 *
 * The activation queue answers "should this account be let in"; this answers
 * "what is going on with this account" — which is a different question asked
 * about a different set of businesses, long after the first one was settled.
 *
 * Read-only except for the §7.2 verification request (FD-1). Everything else
 * here belongs to a screen that owns it, and duplicating those actions would
 * mean duplicating their guards.
 */
export default function AdminAccountShow({
    business,
    status_history: statusHistory,
    kyc_rounds: kycRounds,
    subscription,
    payments,
    staff,
    document_types: documentTypes,
    can,
    blockers,
}: Props) {
    const { t, locale } = useTranslation();
    const [requesting, setRequesting] = useState(false);

    const date = (value: string | null) =>
        value === null ? '—' : new Date(value).toLocaleDateString(locale);

    const dateTime = (value: string | null) =>
        value === null ? '—' : new Date(value).toLocaleString(locale);

    return (
        <>
            <Head title={`${business.name} — ${t('account.detail.title')}`} />

            <PageContainer>
                <PageHeader
                    title={business.name}
                    description={business.owner?.name ?? undefined}
                    actions={
                        <>
                            <StatusPill
                                tone={business.status_tone}
                                label={business.status_label}
                            />

                            {can.request_kyc_update ? (
                                <Button
                                    size="sm"
                                    onClick={() => setRequesting(true)}
                                >
                                    <ShieldCheck
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                    {t('account.kyc_update.action')}
                                </Button>
                            ) : (
                                /*
                                 * Naming the blocker beats a disabled button
                                 * that will not say why — the reason is a fact
                                 * about the account, not about permissions.
                                 */
                                blockers.length > 0 && (
                                    <p className="text-muted-foreground max-w-xs text-xs">
                                        {blockers.join(' ')}
                                    </p>
                                )
                            )}
                        </>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="space-y-6 lg:col-span-2">
                        <SectionCard title={t('account.detail.identity')}>
                            <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                                <Detail label={t('account.detail.owner')}>
                                    {business.owner?.name ?? '—'}
                                </Detail>
                                <Detail label={t('account.detail.email')}>
                                    {business.owner?.email ?? '—'}
                                </Detail>
                                <Detail label={t('account.detail.mobile')}>
                                    {business.owner?.mobile ?? '—'}
                                </Detail>
                                <Detail label={t('account.detail.country')}>
                                    {business.owner?.country ?? '—'}
                                </Detail>
                                <Detail
                                    label={t('account.detail.registered_at')}
                                >
                                    {date(business.registered_at)}
                                </Detail>
                                <Detail
                                    label={t('account.detail.activated_at')}
                                >
                                    {date(business.activated_at)}
                                </Detail>

                                {business.owner?.identity_status_label && (
                                    <Detail
                                        label={t(
                                            'account.detail.identity_status',
                                        )}
                                    >
                                        <StatusPill
                                            tone={
                                                business.owner
                                                    .identity_status_tone ??
                                                'neutral'
                                            }
                                            label={
                                                business.owner
                                                    .identity_status_label
                                            }
                                        />
                                    </Detail>
                                )}
                            </dl>
                        </SectionCard>

                        <SectionCard
                            title={t('account.detail.verification')}
                            description={t('account.detail.verification_help')}
                            contentClassName="p-0"
                        >
                            {kycRounds.length === 0 ? (
                                <p className="text-muted-foreground px-5 py-4 text-sm">
                                    {t('account.detail.no_verification')}
                                </p>
                            ) : (
                                <ol className="divide-border divide-y">
                                    {kycRounds.map((round) => (
                                        <li
                                            key={round.id}
                                            className="space-y-2 px-5 py-4"
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <span className="text-sm font-medium">
                                                    {t('account.detail.round', {
                                                        number: round.round,
                                                    })}
                                                </span>
                                                <StatusPill
                                                    tone={round.status_tone}
                                                    label={round.status_label}
                                                />
                                            </div>

                                            <p className="text-muted-foreground text-xs">
                                                {[
                                                    round.submitted_at &&
                                                        t(
                                                            'account.detail.submitted',
                                                            {
                                                                date: date(
                                                                    round.submitted_at,
                                                                ),
                                                            },
                                                        ),
                                                    round.reviewed_at &&
                                                        t(
                                                            'account.detail.reviewed',
                                                            {
                                                                date: date(
                                                                    round.reviewed_at,
                                                                ),
                                                            },
                                                        ),
                                                    round.deadline_at &&
                                                        t(
                                                            'account.detail.due',
                                                            {
                                                                date: date(
                                                                    round.deadline_at,
                                                                ),
                                                            },
                                                        ),
                                                    t(
                                                        'account.detail.documents_count',
                                                        {
                                                            count: round.documents_count,
                                                        },
                                                    ),
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </p>

                                            {round.requirements.length > 0 && (
                                                <p className="text-muted-foreground text-xs">
                                                    <span className="font-medium">
                                                        {t(
                                                            'account.detail.asked_for',
                                                        )}
                                                        :
                                                    </span>{' '}
                                                    {round.requirements
                                                        .map(
                                                            (requirement) =>
                                                                `${requirement.name} (${
                                                                    requirement.is_required
                                                                        ? t(
                                                                              'account.detail.required',
                                                                          )
                                                                        : t(
                                                                              'account.detail.optional',
                                                                          )
                                                                })`,
                                                        )
                                                        .join(', ')}
                                                </p>
                                            )}

                                            {/*
                                             * The request half. Present only on
                                             * rounds an administrator opened,
                                             * which is what makes this list the
                                             * §7.2 request history as well.
                                             */}
                                            {round.requested_at && (
                                                <div className="bg-muted/40 space-y-1 rounded-lg p-3 text-xs">
                                                    <p className="text-muted-foreground">
                                                        {t(
                                                            'account.detail.requested_by',
                                                            {
                                                                name:
                                                                    round.requested_by ??
                                                                    t(
                                                                        'account.detail.by_system',
                                                                    ),
                                                                date: dateTime(
                                                                    round.requested_at,
                                                                ),
                                                            },
                                                        )}
                                                    </p>

                                                    {round.request_reason && (
                                                        <p>
                                                            <span className="font-medium">
                                                                {t(
                                                                    'account.detail.internal_reason',
                                                                )}
                                                                :
                                                            </span>{' '}
                                                            {
                                                                round.request_reason
                                                            }
                                                        </p>
                                                    )}

                                                    {round.request_instructions && (
                                                        <p>
                                                            <span className="font-medium">
                                                                {t(
                                                                    'account.detail.instructions_sent',
                                                                )}
                                                                :
                                                            </span>{' '}
                                                            {
                                                                round.request_instructions
                                                            }
                                                        </p>
                                                    )}
                                                </div>
                                            )}
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </SectionCard>

                        <SectionCard
                            title={t('account.detail.payments')}
                            contentClassName="p-0"
                        >
                            {payments.length === 0 ? (
                                <p className="text-muted-foreground px-5 py-4 text-sm">
                                    {t('account.detail.no_payments')}
                                </p>
                            ) : (
                                <ul className="divide-border divide-y text-sm">
                                    {payments.map((payment) => (
                                        <li
                                            key={payment.id}
                                            className="flex flex-wrap items-center justify-between gap-2 px-5 py-3"
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate font-medium">
                                                    {payment.reference}
                                                </p>
                                                <p className="text-muted-foreground text-xs">
                                                    {payment.purpose} ·{' '}
                                                    {date(
                                                        payment.completed_at ??
                                                            payment.initiated_at,
                                                    )}
                                                </p>
                                            </div>

                                            <div className="flex items-center gap-3">
                                                <MoneyAmount
                                                    amount={payment.amount}
                                                />
                                                <StatusPill
                                                    tone={payment.status_tone}
                                                    label={payment.status_label}
                                                />
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </SectionCard>
                    </div>

                    <div className="space-y-6">
                        <SectionCard title={t('account.detail.subscription')}>
                            {subscription === null ? (
                                <p className="text-muted-foreground text-sm">
                                    {t('account.detail.no_subscription')}
                                </p>
                            ) : (
                                <div className="space-y-2 text-sm">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <span className="font-medium">
                                            {subscription.package}
                                        </span>
                                        <StatusPill
                                            tone={subscription.status_tone}
                                            label={subscription.status_label}
                                        />
                                    </div>

                                    {subscription.paid && (
                                        <MoneyAmount
                                            amount={subscription.paid}
                                        />
                                    )}

                                    <p className="text-muted-foreground text-xs">
                                        {subscription.source_label}
                                        {subscription.expires_at &&
                                            ` · ${date(subscription.expires_at)}`}
                                    </p>
                                </div>
                            )}
                        </SectionCard>

                        <SectionCard
                            title={t('account.detail.staff')}
                            contentClassName="p-0"
                        >
                            {staff.length === 0 ? (
                                <p className="text-muted-foreground px-5 py-4 text-sm">
                                    {t('account.detail.no_staff')}
                                </p>
                            ) : (
                                <ul className="divide-border divide-y text-sm">
                                    {staff.map((member) => (
                                        <li
                                            key={member.id}
                                            className="flex flex-wrap items-center justify-between gap-2 px-5 py-3"
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate">
                                                    {member.name}
                                                </p>
                                                <p className="text-muted-foreground truncate text-xs">
                                                    {member.email}
                                                </p>
                                            </div>
                                            <span className="text-muted-foreground text-xs">
                                                {member.role_label}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </SectionCard>

                        <SectionCard
                            title={t('account.detail.status_history')}
                            contentClassName="p-0"
                        >
                            {statusHistory.length === 0 ? (
                                <p className="text-muted-foreground px-5 py-4 text-sm">
                                    {t('account.detail.no_status_history')}
                                </p>
                            ) : (
                                <ol className="divide-border divide-y text-sm">
                                    {statusHistory.map((entry) => (
                                        <li
                                            key={entry.id}
                                            className="space-y-0.5 px-5 py-3"
                                        >
                                            <p className="font-medium">
                                                {entry.to_status}
                                            </p>
                                            <p className="text-muted-foreground text-xs">
                                                {entry.changed_by ??
                                                    t(
                                                        'account.detail.by_system',
                                                    )}{' '}
                                                · {dateTime(entry.created_at)}
                                            </p>
                                            {entry.reason && (
                                                <p className="text-muted-foreground text-xs">
                                                    {entry.reason}
                                                </p>
                                            )}
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </SectionCard>
                    </div>
                </div>
            </PageContainer>

            <RequestKycUpdateDialog
                open={requesting}
                onOpenChange={setRequesting}
                accountId={business.id}
                documentTypes={documentTypes}
            />
        </>
    );
}

function Detail({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="font-medium break-words">{children}</dd>
        </div>
    );
}
