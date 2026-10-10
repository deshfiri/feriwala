import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import ReferralCommissionController from '@/actions/App/Http/Controllers/Admin/ReferralCommissionController';
import type { Money } from '@/lib/money';
import { commissionStatusTone, describeRule } from '@/lib/referral';
import { show as chains } from '@/routes/admin/referral-chains';
import { confirm as confirmPassword } from '@/routes/password';
import { index } from '@/routes/admin/referral-commissions';
import type { AccountRef, CommissionRow } from '@/types/referral';
import ReasonTextarea from '@/components/forms/reason-textarea';

export type EventDetail = {
    id: string;
    trigger_label: string;
    occurred_at: string;
    status: 'recorded' | 'reversed';
    source: AccountRef;
    payment: string | null;
    base: Money;
    plan: { id: string; max_depth: number; effective_from: string };
    chain: {
        level: number;
        account: { id: string; name: string | null } | null;
        outcome: string;
        outcome_label: string;
    }[];
    reversed_at: string | null;
    reversal_reason: string | null;
    commissions: (CommissionRow & { can_reverse: boolean })[];
};

type Props = {
    event: EventDetail;
    can_reverse: boolean;
    password_confirmed?: boolean;
    causes: { value: string; label: string }[];
};

const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

/**
 * One qualifying event, from its trigger to every beneficiary (D24, P7-44).
 *
 * The chain shows every level the plan asked about — paid, skipped with its
 * reason, or with nobody there — so a level that was not paid is visibly still
 * that level. Taking commission back is offered only to those who may, and
 * the route asks for the password again.
 */
export default function ReferralEvent({
    event,
    can_reverse: mayReverse,
    password_confirmed: passwordConfirmed = false,
    causes,
}: Props) {
    // The forms appear once the password was confirmed; until then, the way
    // to confirm it, which brings the person back here (§32.2).
    const canReverse = mayReverse && passwordConfirmed;
    const { t, locale } = useTranslation();
    const when = (value: string) => new Date(value).toLocaleString(locale);

    const reverseFields = (errors: Record<string, string>, id: string) => (
        <div className="grid gap-3 sm:grid-cols-2">
            <div className="grid gap-1.5">
                <Label htmlFor={`cause-${id}`}>
                    {t('referral.event.cause')}
                </Label>
                <select
                    id={`cause-${id}`}
                    name="cause"
                    className={selectClass}
                    required
                >
                    {causes.map((cause) => (
                        <option key={cause.value} value={cause.value}>
                            {cause.label}
                        </option>
                    ))}
                </select>
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor={`reason-${id}`}>
                    {t('referral.event.reason')}
                </Label>
                <ReasonTextarea
                    context="referral"
                    id={`reason-${id}`}
                    name="reason"
                    minLength={10}
                    maxLength={500}
                    required
                />
                <InputError message={errors.reason ?? errors.cause} />
            </div>
        </div>
    );

    return (
        <>
            <Head title={t('referral.event.title')} />

            <PageContainer>
                <PageHeader
                    title={t('referral.event.title')}
                    description={`${event.trigger_label} · ${event.source.name}`}
                    actions={
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={index()}>
                                {t('referral.commissions.title')}
                            </Link>
                        </Button>
                    }
                />

                {mayReverse && !passwordConfirmed && (
                    <SectionCard
                        title={t('referral.event.reverse_title')}
                        description={t('referral.event.confirm_first')}
                    >
                        <Button size="sm" variant="outline" asChild>
                            <Link href={confirmPassword()}>
                                {t('referral.event.confirm')}
                            </Link>
                        </Button>
                    </SectionCard>
                )}

                {event.status === 'reversed' && (
                    <p
                        className="text-warning-foreground text-sm font-medium"
                        role="status"
                    >
                        {t('referral.event.event_reversed', {
                            reason: event.reversal_reason ?? '',
                        })}
                    </p>
                )}

                <SectionCard title={t('referral.event.title')}>
                    <dl className="grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t('referral.event.source')}
                            </dt>
                            <dd>
                                <Link
                                    href={chains({
                                        query: { account: event.source.id },
                                    })}
                                    className="underline-offset-4 hover:underline"
                                >
                                    {event.source.name}
                                </Link>
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t('referral.event.occurred_at')}
                            </dt>
                            <dd>{when(event.occurred_at)}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t('referral.event.payment')}
                            </dt>
                            <dd className="font-mono">
                                {event.payment ?? '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t('referral.event.base')}
                            </dt>
                            <dd>
                                <MoneyAmount amount={event.base} />
                            </dd>
                        </div>
                        <div className="sm:col-span-2">
                            <dt className="text-muted-foreground text-xs">
                                {t('referral.event.plan')}
                            </dt>
                            <dd>
                                {t('referral.event.plan_depth', {
                                    depth: String(event.plan.max_depth),
                                    date: when(event.plan.effective_from),
                                })}
                            </dd>
                        </div>
                    </dl>
                </SectionCard>

                <SectionCard title={t('referral.event.chain')}>
                    <ol className="divide-border divide-y text-sm">
                        {event.chain.map((level) => (
                            <li
                                key={level.level}
                                className="flex flex-wrap items-center justify-between gap-2 py-2 first:pt-0 last:pb-0"
                            >
                                <span className="text-muted-foreground">
                                    {level.level === 0
                                        ? t('referral.commissions.joining')
                                        : t('referral.mine.level', {
                                              level: String(level.level),
                                          })}
                                </span>
                                <span className="font-medium">
                                    {level.account?.name ?? '—'}
                                </span>
                                <span className="text-muted-foreground text-xs">
                                    {level.outcome_label}
                                </span>
                            </li>
                        ))}
                    </ol>
                </SectionCard>

                <SectionCard title={t('referral.event.commissions')}>
                    <ul className="divide-border divide-y">
                        {event.commissions.map((commission) => (
                            <li
                                key={commission.id}
                                className="space-y-3 py-4 first:pt-0 last:pb-0"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0 space-y-1 text-sm">
                                        <div className="font-medium">
                                            {commission.is_joining_reward
                                                ? t(
                                                      'referral.commissions.joining',
                                                  )
                                                : t('referral.mine.level', {
                                                      level: String(
                                                          commission.level,
                                                      ),
                                                  })}{' '}
                                            · {commission.beneficiary.name}
                                        </div>
                                        <div className="text-muted-foreground text-xs">
                                            {t('referral.event.rule')}:{' '}
                                            {describeRule(commission.rule)}
                                            {commission.capped &&
                                                ` (${t('referral.event.capped')})`}
                                        </div>
                                        {commission.skip_reason && (
                                            <div className="text-muted-foreground text-xs">
                                                {commission.skip_reason}
                                            </div>
                                        )}
                                        {commission.paid_at ? (
                                            <div className="text-muted-foreground text-xs">
                                                {t('referral.event.paid_on', {
                                                    date: when(
                                                        commission.paid_at,
                                                    ),
                                                })}
                                            </div>
                                        ) : (
                                            commission.status === 'pending' && (
                                                <div className="text-muted-foreground text-xs">
                                                    {t(
                                                        'referral.event.available_on',
                                                        {
                                                            date: when(
                                                                commission.available_at,
                                                            ),
                                                        },
                                                    )}
                                                </div>
                                            )
                                        )}
                                        {commission.reversal && (
                                            <div className="text-xs">
                                                {
                                                    commission.reversal
                                                        .cause_label
                                                }
                                                : {commission.reversal.reason}
                                            </div>
                                        )}
                                    </div>
                                    <div className="flex flex-col items-end gap-1">
                                        <MoneyAmount
                                            amount={commission.amount}
                                        />
                                        <StatusPill
                                            tone={commissionStatusTone(
                                                commission.status,
                                            )}
                                            label={commission.status_label}
                                        />
                                    </div>
                                </div>

                                {canReverse && commission.can_reverse && (
                                    <Form
                                        {...ReferralCommissionController.reverseCommission.form(
                                            commission.id,
                                        )}
                                        options={{ preserveScroll: true }}
                                        className="bg-muted/40 space-y-3 rounded-lg p-3"
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                {reverseFields(
                                                    errors,
                                                    commission.id,
                                                )}
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={processing}
                                                >
                                                    {processing && <Spinner />}
                                                    {t(
                                                        'referral.event.reverse_one',
                                                    )}
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                )}
                            </li>
                        ))}
                    </ul>
                </SectionCard>

                {canReverse && event.status === 'recorded' && (
                    <SectionCard
                        title={t('referral.event.reverse_title')}
                        description={t('referral.event.reverse_hint')}
                    >
                        <Form
                            {...ReferralCommissionController.reverseEvent.form(
                                event.id,
                            )}
                            options={{ preserveScroll: true }}
                            className="space-y-3"
                        >
                            {({ processing, errors }) => (
                                <>
                                    {reverseFields(errors, event.id)}
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="destructive"
                                        disabled={processing}
                                    >
                                        {processing && <Spinner />}
                                        {t('referral.event.reverse_all')}
                                    </Button>
                                </>
                            )}
                        </Form>
                    </SectionCard>
                )}
            </PageContainer>
        </>
    );
}

ReferralEvent.layout = {
    breadcrumbs: [{ title: 'nav.referral_commissions', href: index() }],
};
