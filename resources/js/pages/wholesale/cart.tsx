import { Form, Head, Link } from '@inertiajs/react';
import { AlertTriangle, ImageOff, ShoppingBasket, Trash2 } from 'lucide-react';
import WholesaleCartController from '@/actions/App/Http/Controllers/Erp/WholesaleCartController';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import EmptyState from '@/components/states/empty-state';
import PermissionDeniedState from '@/components/states/permission-denied-state';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import {
    index as wholesaleIndex,
    show as wholesaleProduct,
} from '@/routes/catalog/wholesale';
import { show } from '@/routes/wholesale/cart';
import type { CartLine, CartProblem, CartSummary } from '@/types/wholesale';

type Props = {
    facility_allowed: boolean;
    cart: CartSummary | null;
};

/**
 * The ERP wholesale cart (§14).
 *
 * Every figure on this page is the server's, worked out again on every request:
 * unit prices from the quantity pricing in force, what is available from central
 * stock for this account, and whether each line may be bought at all. The page
 * sends only a quantity; it never totals, discounts or prices anything.
 */
export default function WholesaleCart({ facility_allowed, cart }: Props) {
    const { t } = useTranslation();
    const title = t('wholesale.cart.title');

    if (!facility_allowed || cart === null) {
        return (
            <>
                <Head title={title} />
                <PageContainer>
                    <PermissionDeniedState
                        title={t('wholesale.cart.not_included_title')}
                        description={t('wholesale.cart.not_included')}
                    />
                </PageContainer>
            </>
        );
    }

    return (
        <>
            <Head title={title} />

            <PageContainer>
                <PageHeader
                    title={title}
                    description={t('wholesale.cart.description')}
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={wholesaleIndex()}>
                                {t('wholesale.cart.browse')}
                            </Link>
                        </Button>
                    }
                />

                {cart.lines.length === 0 ? (
                    <EmptyState
                        icon={ShoppingBasket}
                        title={t('wholesale.cart.empty')}
                        description={t('wholesale.cart.empty_help')}
                        action={
                            <Button asChild>
                                <Link href={wholesaleIndex()}>
                                    {t('wholesale.cart.browse')}
                                </Link>
                            </Button>
                        }
                    />
                ) : (
                    <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                        <div className="min-w-0 space-y-4">
                            {cart.has_price_changes && (
                                <div
                                    role="status"
                                    className="border-warning bg-warning-subtle flex flex-col gap-3 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div className="space-y-1">
                                        <p className="font-medium">
                                            {t(
                                                'wholesale.cart.prices_changed_title',
                                            )}
                                        </p>
                                        <p className="text-muted-foreground text-sm">
                                            {t(
                                                'wholesale.cart.prices_changed_help',
                                            )}
                                        </p>
                                    </div>
                                    <Form
                                        {...WholesaleCartController.acceptPrices.form()}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                {processing && <Spinner />}
                                                {t(
                                                    'wholesale.cart.accept_prices',
                                                )}
                                            </Button>
                                        )}
                                    </Form>
                                </div>
                            )}

                            {cart.has_problems && (
                                <div
                                    role="alert"
                                    className="border-warning bg-warning-subtle flex gap-3 rounded-xl border p-4"
                                >
                                    <AlertTriangle
                                        className="mt-0.5 size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <div className="space-y-1">
                                        <p className="font-medium">
                                            {t('wholesale.cart.problems_title')}
                                        </p>
                                        <p className="text-muted-foreground text-sm">
                                            {t('wholesale.cart.problems_help')}
                                        </p>
                                    </div>
                                </div>
                            )}

                            <SectionCard
                                title={t('wholesale.cart.caption')}
                                contentClassName="p-0"
                            >
                                <ul className="divide-border divide-y">
                                    {cart.lines.map((line) => (
                                        <li key={line.id}>
                                            <CartLineRow line={line} />
                                        </li>
                                    ))}
                                </ul>
                            </SectionCard>
                        </div>

                        <SectionCard title={t('wholesale.cart.summary')}>
                            <dl className="space-y-3 text-sm">
                                <div className="flex justify-between gap-3">
                                    <dt className="text-muted-foreground">
                                        {t('wholesale.cart.lines', {
                                            count: cart.line_count,
                                        })}
                                    </dt>
                                </div>
                                <div className="flex items-baseline justify-between gap-3">
                                    <dt className="font-medium">
                                        {t('wholesale.cart.subtotal')}
                                    </dt>
                                    <dd>
                                        <MoneyAmount
                                            amount={cart.subtotal}
                                            size="large"
                                        />
                                    </dd>
                                </div>
                            </dl>
                            <p className="text-muted-foreground mt-3 text-xs">
                                {t('wholesale.cart.subtotal_help')}
                            </p>
                        </SectionCard>
                    </div>
                )}
            </PageContainer>
        </>
    );
}

function CartLineRow({ line }: { line: CartLine }) {
    const { t } = useTranslation();

    const problem = (code: CartProblem) =>
        t(`wholesale.problems.${code}`, {
            min: line.min_order_quantity,
            max: line.max_order_quantity ?? '',
            available: line.available,
        });

    return (
        <div className="grid gap-3 p-4 sm:grid-cols-[4rem_minmax(0,1fr)_auto]">
            <div className="bg-muted flex size-16 items-center justify-center overflow-hidden rounded-md border">
                {line.product.image ? (
                    <img
                        src={line.product.image.url}
                        alt={line.product.image.alt ?? line.product.name}
                        className="size-full object-cover"
                        loading="lazy"
                    />
                ) : (
                    <ImageOff
                        className="text-muted-foreground size-5"
                        aria-hidden="true"
                    />
                )}
            </div>

            <div className="min-w-0 space-y-2">
                <div className="min-w-0">
                    <Link
                        href={wholesaleProduct(line.product.slug)}
                        className="font-medium underline-offset-4 hover:underline"
                    >
                        {line.product.name}
                    </Link>
                    <p className="text-muted-foreground font-mono text-xs">
                        {line.variant ? line.variant.sku : line.product.sku}
                        {line.variant?.label ? ` · ${line.variant.label}` : ''}
                    </p>
                </div>

                {line.unit_price && (
                    <p className="text-sm">
                        {t('wholesale.cart.each', {
                            amount: line.unit_price.formatted,
                        })}
                        {line.base_price &&
                            line.base_price.minor_units !==
                                line.unit_price.minor_units && (
                                <span className="text-muted-foreground ml-2 text-xs">
                                    {t('wholesale.cart.quantity_price', {
                                        amount: line.base_price.formatted,
                                    })}
                                </span>
                            )}
                    </p>
                )}

                {line.price_changed &&
                    line.unit_price_seen &&
                    line.unit_price && (
                        <p className="text-warning-foreground text-xs font-medium">
                            {t('wholesale.cart.price_changed', {
                                from: line.unit_price_seen.formatted,
                                to: line.unit_price.formatted,
                            })}
                        </p>
                    )}

                <p className="text-muted-foreground text-xs">
                    {line.max_order_quantity
                        ? t('wholesale.cart.order_between', {
                              min: line.min_order_quantity,
                              max: line.max_order_quantity,
                          })
                        : t('wholesale.cart.order_at_least', {
                              min: line.min_order_quantity,
                          })}
                    {line.unit_price
                        ? ` · ${t('wholesale.cart.available', { count: line.available })}`
                        : ''}
                </p>

                {line.problems.length > 0 && (
                    <ul className="space-y-1" role="list">
                        {line.problems.map((code) => (
                            <li
                                key={code}
                                className="text-destructive flex items-center gap-1.5 text-xs font-medium"
                            >
                                <AlertTriangle
                                    className="size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                                {problem(code)}
                            </li>
                        ))}
                    </ul>
                )}

                <div className="flex flex-wrap items-start gap-2">
                    <Form
                        {...WholesaleCartController.update.form(line.id)}
                        options={{ preserveScroll: true }}
                        className="flex flex-wrap items-start gap-2"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-1">
                                    <Input
                                        name="quantity"
                                        type="number"
                                        inputMode="numeric"
                                        min={1}
                                        step={1}
                                        defaultValue={line.quantity}
                                        aria-label={t(
                                            'wholesale.cart.quantity',
                                        )}
                                        className="w-24"
                                    />
                                    <InputError message={errors.quantity} />
                                </div>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    size="sm"
                                    disabled={processing}
                                    className="h-9"
                                >
                                    {processing && <Spinner />}
                                    {t('wholesale.cart.update')}
                                </Button>
                            </>
                        )}
                    </Form>

                    <Form
                        {...WholesaleCartController.destroy.form(line.id)}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="ghost"
                                size="sm"
                                disabled={processing}
                                className="h-9"
                                aria-label={t('wholesale.cart.remove', {
                                    name: line.product.name,
                                })}
                            >
                                <Trash2 className="size-4" aria-hidden="true" />
                            </Button>
                        )}
                    </Form>
                </div>
            </div>

            <div className="text-right sm:min-w-28">
                <p className="text-muted-foreground text-xs">
                    {t('wholesale.cart.line_total')}
                </p>
                {line.line_total && line.purchasable ? (
                    <MoneyAmount
                        amount={line.line_total}
                        className="font-semibold"
                    />
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
            </div>
        </div>
    );
}

WholesaleCart.layout = {
    breadcrumbs: [
        {
            title: 'Wholesale cart',
            href: show(),
        },
    ],
};
