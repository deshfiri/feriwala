import { Head } from '@inertiajs/react';
import { Ban, RotateCcw, ShieldCheck } from 'lucide-react';
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
    type ConsequenceOption,
    type DocumentTypeOption,
} from './request-kyc-update-dialog';
import AssignPackageDialog, {
    type AssignablePackage,
} from './assign-package-dialog';
import SignInAccessDialog, { type Person } from './sign-in-access-dialog';
import WithdrawKycRequestDialog from './withdraw-kyc-request-dialog';
import SuspensionDialog from './suspension-dialog';

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
    purpose: string;
    purpose_label: string;
    /** What this round costs the business while it stands (§7.4). */
    consequences: { value: string; label: string; in_force: boolean }[];
    cancelled_at: string | null;
    cancelled_by: string | null;
    cancellation_reason: string | null;
    is_withdrawable: boolean;
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
    /**
     * Who can sign in, and whether they still can (§6). A different question
     * from `staff`, which is about what somebody may do inside the business.
     */
    people: Person[];
    /** Plans an administrator may grant this account without a sale (§8.3). */
    assignable_packages: AssignablePackage[];
    document_types: DocumentTypeOption[];
    /** What staff may attach to a re-verification round (§7.4). */
    kyc_consequences: ConsequenceOption[];
    can: {
        request_kyc_update: boolean;
        withdraw_kyc_request: boolean;
        suspend: boolean;
        reactivate: boolean;
    };
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
    people,
    assignable_packages: assignablePackages,
    document_types: documentTypes,
    kyc_consequences: kycConsequences,
    can,
    blockers,
}: Props) {
    const { t, locale } = useTranslation();
    const [requesting, setRequesting] = useState(false);
    /** The round being withdrawn, or null. Holds the row so the dialog can
     *  name which one it is about. */
    const [withdrawing, setWithdrawing] = useState<KycRound | null>(null);
    const [changingAccessFor, setChangingAccessFor] = useState<Person | null>(
        null,
    );
    const [assigning, setAssigning] = useState(false);
    const [suspending, setSuspending] = useState<
        'suspend' | 'reactivate' | null
    >(null);

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

                            {/* §5.3 both ways. Mutually exclusive by status,
                                so only the one that can actually happen is
                                offered. */}
                            {can.suspend && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setSuspending('suspend')}
                                >
                                    <Ban
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                    {t('account.suspension.suspend_action')}
                                </Button>
                            )}

                            {can.reactivate && (
                                <Button
                                    size="sm"
                                    onClick={() => setSuspending('reactivate')}
                                >
                                    <RotateCcw
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                    {t('account.suspension.reactivate_action')}
                                </Button>
                            )}

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

                                            {/*
                                             * What this round costs the
                                             * business (§7.4). The only place
                                             * staff can see what they imposed,
                                             * so it names each restriction in
                                             * words and says whether it is in
                                             * force yet — a consequence that
                                             * only bites after the deadline is
                                             * not restricting anybody today.
                                             */}
                                            {round.consequences.length > 0 && (
                                                <ul className="flex flex-wrap gap-1.5">
                                                    {round.consequences.map(
                                                        (consequence) => (
                                                            <li
                                                                key={
                                                                    consequence.value
                                                                }
                                                            >
                                                                <StatusPill
                                                                    tone={
                                                                        consequence.in_force
                                                                            ? 'warning'
                                                                            : 'neutral'
                                                                    }
                                                                    label={
                                                                        consequence.in_force
                                                                            ? consequence.label
                                                                            : t(
                                                                                  'account.detail.consequence_pending',
                                                                                  {
                                                                                      label: consequence.label,
                                                                                  },
                                                                              )
                                                                    }
                                                                />
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            )}

                                            {/*
                                             * A round that was asked and then
                                             * taken back keeps both facts: the
                                             * business was notified, and
                                             * possibly restricted, while it
                                             * stood.
                                             */}
                                            {round.cancelled_at && (
                                                <p className="text-muted-foreground text-xs">
                                                    <span className="font-medium">
                                                        {t(
                                                            'account.detail.withdrawn_by',
                                                            {
                                                                name:
                                                                    round.cancelled_by ??
                                                                    t(
                                                                        'account.detail.by_system',
                                                                    ),
                                                                date: dateTime(
                                                                    round.cancelled_at,
                                                                ),
                                                            },
                                                        )}
                                                    </span>
                                                    {round.cancellation_reason &&
                                                        ` — ${round.cancellation_reason}`}
                                                </p>
                                            )}

                                            {can.withdraw_kyc_request &&
                                                round.is_withdrawable && (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            setWithdrawing(
                                                                round,
                                                            )
                                                        }
                                                    >
                                                        {t(
                                                            'account.kyc_withdraw.action',
                                                        )}
                                                    </Button>
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
                        <SectionCard
                            title={t('account.detail.subscription')}
                            actions={
                                assignablePackages.length > 0 ? (
                                    <Button
                                        variant="secondary"
                                        size="sm"
                                        onClick={() => setAssigning(true)}
                                    >
                                        {t('package.assign.action')}
                                    </Button>
                                ) : undefined
                            }
                        >
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
                            title={t('security.lock.title')}
                            description={t('security.lock.description')}
                            contentClassName="p-0"
                        >
                            <ul className="divide-border divide-y text-sm">
                                {people.map((person) => (
                                    <li
                                        key={person.id}
                                        className="flex flex-wrap items-center justify-between gap-2 px-5 py-3"
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate">
                                                {person.name}
                                            </p>
                                            <p className="text-muted-foreground truncate text-xs">
                                                {person.role_label} ·{' '}
                                                {person.email}
                                            </p>
                                        </div>

                                        <div className="flex items-center gap-3">
                                            <StatusPill
                                                tone={
                                                    person.identity_status_tone as StatusTone
                                                }
                                                label={
                                                    person.identity_status_label
                                                }
                                            />

                                            {person.can_change && (
                                                <Button
                                                    variant={
                                                        person.is_locked
                                                            ? 'secondary'
                                                            : 'ghost'
                                                    }
                                                    size="sm"
                                                    className={
                                                        person.is_locked
                                                            ? undefined
                                                            : 'text-destructive hover:bg-destructive/10 hover:text-destructive'
                                                    }
                                                    onClick={() =>
                                                        setChangingAccessFor(
                                                            person,
                                                        )
                                                    }
                                                >
                                                    {t(
                                                        person.is_locked
                                                            ? 'security.lock.unlock'
                                                            : 'security.lock.lock',
                                                    )}
                                                </Button>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
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

            <SuspensionDialog
                accountId={business.id}
                mode={suspending ?? 'suspend'}
                open={suspending !== null}
                onOpenChange={(open) => !open && setSuspending(null)}
            />

            <RequestKycUpdateDialog
                open={requesting}
                onOpenChange={setRequesting}
                accountId={business.id}
                documentTypes={documentTypes}
                consequenceOptions={kycConsequences}
            />

            <WithdrawKycRequestDialog
                round={withdrawing}
                onOpenChange={(open) => !open && setWithdrawing(null)}
            />

            <AssignPackageDialog
                accountId={business.id}
                packages={assignablePackages}
                open={assigning}
                onOpenChange={setAssigning}
            />

            <SignInAccessDialog
                person={changingAccessFor}
                open={changingAccessFor !== null}
                onOpenChange={(open) => !open && setChangingAccessFor(null)}
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
