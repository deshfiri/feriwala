import { Link } from '@inertiajs/react';
import MoneyAmount from '@/components/money-amount';
import StatusPill from '@/components/status-pill';
import { useTranslation } from '@/hooks/use-translation';
import type { StatusTone } from '@/lib/status';
import type { Financials } from '@/types/cases';

/**
 * What a case costs and who bears it (D35), exactly as the server worked it out.
 *
 * Nothing here is added, multiplied or compared: the summary rows, the totals and
 * the ledger rows all arrive as exact, formatted figures. A charge answered by a
 * correction stays on the list beside the entry that answers it, marked, and the
 * totals count only what stands.
 *
 * Two readers, one component. Staff also see who bears each row, its reason and a
 * link to the wallet transaction when they may open it. A Partner is shown only
 * their own effects: where Banij bears a cost the row says it was covered, with
 * no amount.
 */
export default function FinancialsPanel({
    financials,
    audience,
    section = 'summary',
}: {
    financials: Financials;
    audience: 'staff' | 'partner';
    /** `summary` is the charges and refund; `ledger` is the accounting entries. */
    section?: 'summary' | 'ledger';
}) {
    const { t, locale } = useTranslation();

    if (section === 'ledger') {
        return (
            <div className="space-y-4">
                {financials.ledger.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        {t('cases.financials.no_entries')}
                    </p>
                ) : (
                    <ul className="divide-border divide-y text-sm">
                        {financials.ledger.map((row) => (
                            <li
                                key={row.id}
                                className="flex flex-wrap items-start justify-between gap-x-4 gap-y-1 py-3"
                            >
                                <div className="min-w-0 space-y-0.5">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">
                                            {row.label}
                                        </span>
                                        <StatusPill
                                            tone={row.status_tone as StatusTone}
                                            label={row.status_label}
                                        />
                                    </div>
                                    <div className="text-muted-foreground text-xs">
                                        {new Date(row.posted_at).toLocaleString(
                                            locale,
                                        )}
                                        {audience === 'staff'
                                            ? ` · ${row.bearer_label}`
                                            : ''}
                                    </div>
                                    {row.reason && (
                                        <p className="text-xs break-words">
                                            {row.reason}
                                        </p>
                                    )}
                                    {row.ledger_url && (
                                        <Link
                                            href={row.ledger_url}
                                            className="text-xs underline-offset-4 hover:underline"
                                        >
                                            {t('cases.financials.open_ledger')}
                                        </Link>
                                    )}
                                </div>
                                {row.amount ? (
                                    <MoneyAmount
                                        amount={row.amount}
                                        direction={row.direction}
                                    />
                                ) : (
                                    <span className="text-muted-foreground text-xs">
                                        {t('cases.financials.covered_by_banij')}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        );
    }

    return (
        <div className="space-y-5">
            <dl className="divide-border divide-y text-sm">
                {financials.summary.map((row) => (
                    <div
                        key={row.key}
                        className="flex flex-wrap items-start justify-between gap-x-4 gap-y-1 py-2.5"
                    >
                        <div className="min-w-0">
                            <dt className="font-medium">{row.label}</dt>
                            {row.note && (
                                <dd className="text-muted-foreground text-xs break-words">
                                    {row.note}
                                </dd>
                            )}
                            {audience === 'staff' && row.bearer_label && (
                                <dd className="text-muted-foreground text-xs">
                                    {t('cases.financials.borne_by', {
                                        party: row.bearer_label,
                                    })}
                                </dd>
                            )}
                        </div>
                        <dd>
                            {row.amount ? (
                                <MoneyAmount
                                    amount={row.amount}
                                    direction={row.direction}
                                />
                            ) : row.covered_by_banij ? (
                                <span className="text-muted-foreground text-xs">
                                    {t('cases.financials.covered_by_banij')}
                                </span>
                            ) : (
                                <span className="text-muted-foreground text-xs">
                                    —
                                </span>
                            )}
                        </dd>
                    </div>
                ))}
            </dl>

            <dl className="bg-surface-subtle grid gap-2 rounded-lg border p-3 text-sm sm:grid-cols-2">
                <Total
                    label={t('cases.financials.totals.deducted')}
                    amount={financials.totals.deducted}
                />
                <Total
                    label={t('cases.financials.totals.outstanding')}
                    amount={financials.totals.outstanding}
                />
                <Total
                    label={t('cases.financials.totals.reversed')}
                    amount={financials.totals.reversed}
                />
                <Total
                    label={t('cases.financials.totals.net_partner')}
                    amount={financials.totals.net_partner}
                    strong
                />
                {audience === 'staff' && financials.totals.banij_cost && (
                    <Total
                        label={t('cases.financials.totals.banij_cost')}
                        amount={financials.totals.banij_cost}
                    />
                )}
            </dl>
        </div>
    );
}

function Total({
    label,
    amount,
    strong = false,
}: {
    label: string;
    amount: Financials['totals']['deducted'];
    strong?: boolean;
}) {
    return (
        <div className="flex items-center justify-between gap-3">
            <dt className={strong ? 'font-medium' : 'text-muted-foreground'}>
                {label}
            </dt>
            <dd>
                <MoneyAmount
                    amount={amount}
                    size={strong ? 'default' : 'small'}
                />
            </dd>
        </div>
    );
}
