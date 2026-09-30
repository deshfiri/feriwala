import { Head, Link } from '@inertiajs/react';
import { PackageSearch } from 'lucide-react';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { index as wholesaleCatalogue } from '@/routes/catalog/wholesale';
import { index, show } from '@/routes/wholesale/orders';
import type { Paginated, WholesaleOrderSummary } from '@/types/orders';

type Props = {
    orders: Paginated<WholesaleOrderSummary>;
};

/**
 * The account's own wholesale orders (§10.2, P4-12).
 *
 * Newest first, each with its status in words and colour together (§33.9), and
 * where its payment stands. Everything shown was worked out by the server.
 */
export default function WholesaleOrders({ orders }: Props) {
    const { t, locale } = useTranslation();
    const title = t('orders.title');

    return (
        <>
            <Head title={title} />

            <PageContainer>
                <PageHeader
                    title={title}
                    description={t('orders.description')}
                />

                {orders.data.length === 0 ? (
                    <EmptyState
                        icon={PackageSearch}
                        title={t('orders.empty')}
                        description={t('orders.empty_help')}
                        action={
                            <Button asChild>
                                <Link href={wholesaleCatalogue()}>
                                    {t('orders.browse')}
                                </Link>
                            </Button>
                        }
                    />
                ) : (
                    <div className="space-y-4">
                        <ul className="bg-card divide-border divide-y rounded-xl border text-sm shadow-sm">
                            {orders.data.map((order) => (
                                <li
                                    key={order.id}
                                    className="flex flex-wrap items-center justify-between gap-3 p-4"
                                >
                                    <div className="min-w-0 space-y-1">
                                        <Link
                                            href={show(order.id)}
                                            className="font-mono font-medium underline-offset-4 hover:underline"
                                        >
                                            {order.reference}
                                        </Link>
                                        <p className="text-muted-foreground text-xs">
                                            {t('orders.placed_on', {
                                                date: new Date(
                                                    order.placed_at,
                                                ).toLocaleDateString(locale),
                                            })}{' '}
                                            ·{' '}
                                            {t('orders.items_count', {
                                                count: order.item_count,
                                            })}
                                        </p>
                                    </div>

                                    <div className="flex flex-wrap items-center gap-3">
                                        <MoneyAmount amount={order.total} />
                                        <StatusPill
                                            tone={order.status_tone}
                                            label={t(
                                                `orders.statuses.${order.status}`,
                                            )}
                                        />
                                        {order.payment_state && (
                                            <span className="text-muted-foreground text-xs">
                                                {t(
                                                    `orders.payment_states.${order.payment_state}`,
                                                )}
                                            </span>
                                        )}
                                        <Button
                                            asChild
                                            variant="ghost"
                                            size="sm"
                                        >
                                            <Link href={show(order.id)}>
                                                {t('orders.view')}
                                            </Link>
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>

                        {orders.last_page > 1 && (
                            <nav
                                className="flex items-center justify-between gap-3 text-sm"
                                aria-label={t('orders.title')}
                            >
                                {orders.current_page > 1 ? (
                                    <Button asChild variant="outline" size="sm">
                                        <Link
                                            href={index({
                                                query: {
                                                    page:
                                                        orders.current_page - 1,
                                                },
                                            })}
                                        >
                                            {t('orders.previous')}
                                        </Link>
                                    </Button>
                                ) : (
                                    <span />
                                )}
                                <span className="text-muted-foreground">
                                    {t('orders.page', {
                                        current: orders.current_page,
                                        last: orders.last_page,
                                    })}
                                </span>
                                {orders.current_page < orders.last_page ? (
                                    <Button asChild variant="outline" size="sm">
                                        <Link
                                            href={index({
                                                query: {
                                                    page:
                                                        orders.current_page + 1,
                                                },
                                            })}
                                        >
                                            {t('orders.next')}
                                        </Link>
                                    </Button>
                                ) : (
                                    <span />
                                )}
                            </nav>
                        )}
                    </div>
                )}
            </PageContainer>
        </>
    );
}

WholesaleOrders.layout = {
    breadcrumbs: [
        {
            title: 'nav.wholesale_orders',
            href: index(),
        },
    ],
};
