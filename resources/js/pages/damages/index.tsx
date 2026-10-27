import { Head, Link, router } from '@inertiajs/react';
import { PackageX } from 'lucide-react';
import MoneyAmount from '@/components/money-amount';
import LineThumb from '@/components/line-thumb';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import TablePagination from '@/components/data-table/table-pagination';
import { useTranslation } from '@/hooks/use-translation';
import { index, show } from '@/routes/damages';
import type { Paginator } from '@/types';
import type { PartnerDamageRow } from '@/types/cases';

type Props = { damages: Paginator<PartnerDamageRow> };

/**
 * `My Products > My Damages` (D35): the damaged items the Partner bears the loss
 * for, as cards that read well on a phone.
 *
 * Each says what it is, which order it came from, what it cost them, where the
 * item is now and where its delivery request stands. Only the Partner's own, and
 * only the ones they bear — a damage that was Banij's fault is not here at all.
 */
export default function MyDamages({ damages }: Props) {
    const { t, locale } = useTranslation();

    return (
        <>
            <Head title={t('damages.title')} />

            <PageContainer>
                <PageHeader
                    title={t('damages.title')}
                    description={t('damages.description')}
                />

                {damages.data.length === 0 ? (
                    <EmptyState
                        icon={PackageX}
                        title={t('damages.empty')}
                        description={t('damages.empty_help')}
                    />
                ) : (
                    <ul
                        className="grid gap-3 md:grid-cols-2 xl:grid-cols-3"
                        aria-label={t('damages.title')}
                    >
                        {damages.data.map((damage) => (
                            <li
                                key={damage.id}
                                className="border-border bg-card flex min-w-0 flex-col gap-3 rounded-xl border p-4"
                            >
                                <div className="flex items-start gap-3">
                                    {damage.image ? (
                                        <img
                                            src={damage.image.url}
                                            alt={
                                                damage.image.alt ??
                                                damage.product.name
                                            }
                                            loading="lazy"
                                            className="size-14 shrink-0 rounded-lg border object-cover"
                                        />
                                    ) : (
                                        <LineThumb name={damage.product.name} />
                                    )}
                                    <div className="min-w-0 flex-1">
                                        <Link
                                            href={show(damage.id)}
                                            className="block truncate font-mono text-sm font-medium underline-offset-4 hover:underline"
                                        >
                                            {damage.reference}
                                        </Link>
                                        <div className="truncate text-sm">
                                            {damage.product.name}
                                        </div>
                                        <div className="text-muted-foreground truncate text-xs">
                                            {damage.product.variation
                                                ? `${damage.product.variation} · `
                                                : ''}
                                            {t('cases.quantity_short', {
                                                count: String(damage.quantity),
                                            })}
                                        </div>
                                    </div>
                                </div>

                                <dl className="grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                                    <Fact
                                        label={t('damages.order')}
                                        value={
                                            <span className="font-mono">
                                                {damage.order_number}
                                            </span>
                                        }
                                    />
                                    <Fact
                                        label={t('damages.cause')}
                                        value={damage.cause_label ?? '—'}
                                    />
                                    <Fact
                                        label={t('damages.loss')}
                                        value={
                                            damage.approved_loss ? (
                                                <MoneyAmount
                                                    amount={
                                                        damage.approved_loss
                                                    }
                                                    direction="debit"
                                                />
                                            ) : (
                                                '—'
                                            )
                                        }
                                    />
                                    <Fact
                                        label={t('damages.responsibility')}
                                        value={
                                            damage.responsibility_label ?? '—'
                                        }
                                    />
                                    <Fact
                                        label={t('damages.storage')}
                                        value={t(
                                            `damages.storage_states.${damage.storage_state}`,
                                        )}
                                    />
                                    <Fact
                                        label={t('damages.delivery')}
                                        value={t(
                                            `damages.delivery_states.${damage.delivery_state}`,
                                        )}
                                    />
                                </dl>

                                <div className="text-muted-foreground mt-auto flex items-center justify-between text-xs">
                                    <span>
                                        {new Date(
                                            damage.updated_at,
                                        ).toLocaleDateString(locale)}
                                    </span>
                                    <Link
                                        href={show(damage.id)}
                                        className="text-foreground underline-offset-4 hover:underline"
                                    >
                                        {t('damages.open')}
                                    </Link>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                <TablePagination
                    paginator={damages}
                    onPageChange={(page) =>
                        router.get(
                            index(),
                            { page },
                            {
                                preserveScroll: true,
                                preserveState: true,
                                only: ['damages'],
                            },
                        )
                    }
                />
            </PageContainer>
        </>
    );
}

MyDamages.layout = {
    breadcrumbs: [{ title: 'nav.my_damages', href: index() }],
};

function Fact({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="min-w-0">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="break-words">{value}</dd>
        </div>
    );
}
