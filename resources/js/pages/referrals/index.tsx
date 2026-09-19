import { Head, Link } from '@inertiajs/react';
import { Share2, Users } from 'lucide-react';
import { useState } from 'react';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import { commissionStatusTone, referredStateTone } from '@/lib/referral';
import { index } from '@/routes/referrals';
import type { Paginator } from '@/types';
import type { Earning } from '@/types/referral';

export type ReferredBusiness = {
    id: string;
    name: string;
    state: 'active' | 'joining' | 'inactive';
    since: string;
};

type Props = {
    code: string | null;
    link: string | null;
    summary: {
        direct: number;
        direct_active: number;
        paid: Money;
        pending: Money;
        reversed: Money;
    };
    direct: Paginator<ReferredBusiness>;
    earnings: Paginator<Earning>;
};

/**
 * A business's own referrals (§25.1, D24, P7-10, P7-18).
 *
 * Its code and link, the businesses it referred directly — by name and a
 * plain state — and its own earnings by level, status and date. Nothing about
 * anyone below its direct referrals, and never whose activation paid a deeper
 * level.
 */
export default function Referrals({
    code,
    link,
    summary,
    direct,
    earnings,
}: Props) {
    const { t, locale } = useTranslation();
    const [copied, setCopied] = useState<string | null>(null);
    const when = (value: string) => new Date(value).toLocaleDateString(locale);

    const copy = (value: string) => {
        void navigator.clipboard?.writeText(value).then(() => setCopied(value));
    };

    const pager = (
        paginator: Paginator<unknown>,
        name: 'referrals' | 'earnings',
    ) =>
        paginator.last_page > 1 && (
            <nav className="mt-3 flex justify-between text-sm">
                {paginator.current_page > 1 ? (
                    <Link
                        href={index({
                            query: { [name]: paginator.current_page - 1 },
                        })}
                        preserveScroll
                    >
                        {t('common.actions.back')}
                    </Link>
                ) : (
                    <span />
                )}
                {paginator.current_page < paginator.last_page && (
                    <Link
                        href={index({
                            query: { [name]: paginator.current_page + 1 },
                        })}
                        preserveScroll
                    >
                        {t('common.actions.next')}
                    </Link>
                )}
            </nav>
        );

    const stats: { label: string; value: React.ReactNode }[] = [
        {
            label: t('referral.mine.direct'),
            value: <span className="tabular-nums">{summary.direct}</span>,
        },
        {
            label: t('referral.mine.direct_active'),
            value: (
                <span className="tabular-nums">{summary.direct_active}</span>
            ),
        },
        {
            label: t('referral.mine.paid'),
            value: <MoneyAmount amount={summary.paid} />,
        },
        {
            label: t('referral.mine.pending'),
            value: <MoneyAmount amount={summary.pending} />,
        },
        {
            label: t('referral.mine.reversed'),
            value: <MoneyAmount amount={summary.reversed} />,
        },
    ];

    return (
        <>
            <Head title={t('referral.mine.title')} />

            <PageContainer>
                <PageHeader
                    title={t('referral.mine.title')}
                    description={t('referral.mine.description')}
                />

                <SectionCard title={t('referral.mine.code')}>
                    {code && link ? (
                        <div className="space-y-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <span
                                    className="font-mono text-lg tracking-widest"
                                    data-testid="referral-code"
                                >
                                    {code}
                                </span>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => copy(code)}
                                >
                                    {copied === code
                                        ? t('referral.mine.copied')
                                        : t('referral.mine.copy')}
                                </Button>
                            </div>
                            <div className="space-y-1">
                                <p className="text-muted-foreground text-xs">
                                    {t('referral.mine.link')}
                                </p>
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-mono text-sm break-all">
                                        {link}
                                    </span>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => copy(link)}
                                    >
                                        {copied === link
                                            ? t('referral.mine.copied')
                                            : t('referral.mine.copy')}
                                    </Button>
                                </div>
                            </div>
                        </div>
                    ) : (
                        <p className="text-muted-foreground text-sm">
                            {t('referral.mine.not_yet')}
                        </p>
                    )}
                </SectionCard>

                <dl className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                    {stats.map((stat) => (
                        <div
                            key={stat.label}
                            className="border-border bg-card rounded-xl border p-3"
                        >
                            <dt className="text-muted-foreground text-xs">
                                {stat.label}
                            </dt>
                            <dd className="mt-1 text-lg font-semibold">
                                {stat.value}
                            </dd>
                        </div>
                    ))}
                </dl>

                <SectionCard title={t('referral.mine.referred')}>
                    {direct.data.length === 0 ? (
                        <EmptyState
                            icon={Share2}
                            title={t('referral.mine.no_referred')}
                            description={t('referral.mine.no_referred_help')}
                        />
                    ) : (
                        <>
                            <ul className="divide-border divide-y">
                                {direct.data.map((business) => (
                                    <li
                                        key={business.id}
                                        className="flex flex-wrap items-center justify-between gap-2 py-3 first:pt-0"
                                    >
                                        <span className="text-sm font-medium">
                                            {business.name}
                                        </span>
                                        <span className="flex items-center gap-2">
                                            <StatusPill
                                                tone={referredStateTone(
                                                    business.state,
                                                )}
                                                label={t(
                                                    `referral.referred_states.${business.state}`,
                                                )}
                                            />
                                            <span className="text-muted-foreground text-xs">
                                                {t('referral.mine.since', {
                                                    date: when(business.since),
                                                })}
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                            {pager(direct, 'referrals')}
                        </>
                    )}
                </SectionCard>

                <SectionCard title={t('referral.mine.earnings')}>
                    {earnings.data.length === 0 ? (
                        <EmptyState
                            icon={Users}
                            title={t('referral.mine.no_earnings')}
                            description={t('referral.mine.no_earnings_help')}
                        />
                    ) : (
                        <>
                            <ul className="divide-border divide-y">
                                {earnings.data.map((earning) => (
                                    <li
                                        key={earning.id}
                                        className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0"
                                    >
                                        <div className="space-y-1 text-sm">
                                            <div className="font-medium">
                                                {earning.is_joining_reward
                                                    ? t('referral.mine.joining')
                                                    : t('referral.mine.level', {
                                                          level: String(
                                                              earning.level,
                                                          ),
                                                      })}
                                            </div>
                                            <div className="text-muted-foreground text-xs">
                                                {when(earning.created_at)}
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <StatusPill
                                                tone={commissionStatusTone(
                                                    earning.status,
                                                )}
                                                label={earning.status_label}
                                            />
                                            <MoneyAmount
                                                amount={earning.amount}
                                                direction="credit"
                                            />
                                        </div>
                                    </li>
                                ))}
                            </ul>
                            {pager(earnings, 'earnings')}
                        </>
                    )}
                </SectionCard>
            </PageContainer>
        </>
    );
}
