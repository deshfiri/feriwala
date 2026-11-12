import { useForm, useHttp } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import LocationPicker from '@/components/address-book/location-picker';
import type { LocationChain } from '@/components/address-book/location-picker';
import NativeSelect from '@/components/forms/native-select';
import Textarea from '@/components/forms/textarea';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
import MoneyInput from '@/components/money-input';
import ProductSearchSelect from '@/components/product-search-select';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import { children, divisions } from '@/routes/locations';

type Quote = {
    lines: {
        sku: string;
        name: string;
        quantity: number;
        unit_price: Money;
        line_total: Money;
    }[];
    subtotal: Money;
    discount: Money;
    delivery: Money;
    tax: Money;
    total: Money;
};

type Line = { sku: string; label: string | null; quantity: string };

export type NewStoreOrderUrls = {
    products: string;
    quote: string;
    store: string;
};

const emptyLine = (): Line => ({ sku: '', label: null, quantity: '1' });

const divisionsUrl = divisions.url();

// Stable on purpose: the location picker refetches whenever this changes.
const childrenUrl = (
    parentType: 'division' | 'district' | 'upazila',
    parentSourceId: string,
) => children.url([parentType, parentSourceId]);

/**
 * A Partner takes an order for their own customer, in a popup.
 *
 * The delivery place is picked Division, then District, then Upazila, then
 * Union, each list opening once the one before it is chosen. Products are found
 * by typing. The price is never worked out here: every change to the lines asks
 * the server for a quote and shows exactly what it answers (§36.1). The order is
 * cash on delivery, and a double click is one order because the form carries a
 * single token for its whole life.
 */
export default function NewStoreOrderDialog({
    open,
    onOpenChange,
    storefronts,
    urls,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    storefronts: { value: string; label: string }[];
    urls: NewStoreOrderUrls;
}) {
    const { t } = useTranslation();
    const [token, setToken] = useState(() => crypto.randomUUID());
    const [lines, setLines] = useState<Line[]>([emptyLine()]);
    const [quote, setQuote] = useState<Quote | null>(null);
    const [place, setPlace] = useState<LocationChain | null>(null);

    const form = useForm({
        storefront: storefronts.length === 1 ? storefronts[0].value : '',
        token,
        customer_name: '',
        customer_phone: '',
        customer_email: '',
        division_id: '',
        district_id: '',
        upazila_id: '',
        union_id: '',
        line1: '',
        postcode: '',
        note: '',
        discount: '',
        items: [] as { sku: string; quantity: number }[],
    });

    const quoting = useHttp<
        {
            storefront: string;
            discount: string;
            items: { sku: string; quantity: number }[];
        },
        { data: Quote }
    >({ storefront: '', discount: '', items: [] });

    const errors = form.errors as Record<string, string | undefined>;
    const quoteErrors = quoting.errors as Record<string, string | undefined>;
    const storefront = form.data.storefront;
    const discountText = form.data.discount.trim();

    const picked = useMemo(
        () =>
            lines
                .filter((line) => line.sku !== '' && Number(line.quantity) > 0)
                .map((line) => ({
                    sku: line.sku,
                    quantity: Number(line.quantity),
                })),
        [lines],
    );
    const pickedKey = JSON.stringify(picked);

    const searchUrl = useCallback(
        (term: string) =>
            `${urls.products}?${new URLSearchParams({ storefront, search: term })}`,
        [urls.products, storefront],
    );

    // The price: asked of the server whenever what is being bought changes.
    useEffect(() => {
        if (!open || storefront === '' || picked.length === 0) {
            return;
        }

        quoting.transform(() => ({
            storefront,
            discount: discountText,
            items: picked,
        }));

        const timer = window.setTimeout(async () => {
            try {
                const answer = await quoting.post(urls.quote);
                setQuote(answer.data);
            } catch {
                setQuote(null);
            }
        }, 300);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, storefront, pickedKey, discountText]);

    const shownQuote = picked.length === 0 ? null : quote;

    const setLine = (index: number, patch: Partial<Line>) =>
        setLines((previous) =>
            previous.map((line, at) =>
                at === index ? { ...line, ...patch } : line,
            ),
        );

    const close = (next: boolean) => {
        onOpenChange(next);

        if (!next) {
            form.reset();
            form.clearErrors();
            setLines([emptyLine()]);
            setQuote(null);
            setPlace(null);
            setToken(crypto.randomUUID());
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.transform((data) => ({
            ...data,
            token,
            division_id: place?.division?.id ?? '',
            district_id: place?.district?.id ?? '',
            upazila_id: place?.upazila?.id ?? '',
            union_id: place?.union?.id ?? '',
            items: picked,
        }));

        form.post(urls.store, { preserveScroll: true });
    };

    const field = (
        name: string,
        label: string,
        props: {
            type?: string;
            autoComplete?: string;
            required?: boolean;
        } = {},
    ) => (
        <div className="grid gap-1.5">
            <Label htmlFor={`new-order-${name}`}>{label}</Label>
            <Input
                id={`new-order-${name}`}
                value={
                    (form.data as unknown as Record<string, string>)[name] ?? ''
                }
                onChange={(event) =>
                    form.setData(
                        name as keyof typeof form.data,
                        event.target.value as never,
                    )
                }
                aria-invalid={errors[name] ? true : undefined}
                {...props}
            />
            <InputError message={errors[name]} />
        </div>
    );

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent className="max-h-[94vh] w-[96vw] gap-0 overflow-y-auto p-0 sm:max-w-6xl lg:overflow-hidden">
                <DialogHeader className="border-b px-6 py-4">
                    <DialogTitle className="text-xl">
                        {t('store_orders.create.title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('store_orders.create.description')}
                    </DialogDescription>
                </DialogHeader>

                {storefronts.length === 0 ? (
                    <p className="text-muted-foreground p-6 text-sm">
                        {t('store_orders.create.no_storefronts')}
                    </p>
                ) : (
                    <form
                        onSubmit={submit}
                        className="grid lg:max-h-[78vh] lg:grid-cols-[minmax(0,1fr)_25rem]"
                    >
                        <div className="space-y-5 p-6 lg:overflow-y-auto">
                            <Step
                                number={1}
                                title={t('store_orders.create.customer')}
                            >
                                <div className="grid gap-1.5">
                                    <Label htmlFor="new-order-storefront">
                                        {t('store_orders.create.storefront')}
                                    </Label>
                                    <NativeSelect
                                        id="new-order-storefront"
                                        value={storefront}
                                        onChange={(event) => {
                                            form.setData(
                                                'storefront',
                                                event.target.value,
                                            );
                                            setLines([emptyLine()]);
                                            setQuote(null);
                                        }}
                                    >
                                        <option value="">
                                            {t(
                                                'store_orders.create.storefront_placeholder',
                                            )}
                                        </option>
                                        {storefronts.map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                    <InputError message={errors.storefront} />
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {field(
                                        'customer_name',
                                        t('store_orders.create.customer_name'),
                                        { autoComplete: 'off', required: true },
                                    )}
                                    {field(
                                        'customer_phone',
                                        t('store_orders.create.customer_phone'),
                                        {
                                            type: 'tel',
                                            autoComplete: 'off',
                                            required: true,
                                        },
                                    )}
                                </div>
                                {field(
                                    'customer_email',
                                    t('store_orders.create.customer_email'),
                                    { type: 'email', autoComplete: 'off' },
                                )}
                            </Step>

                            <Step
                                number={2}
                                title={t('store_orders.create.address')}
                            >
                                <LocationPicker
                                    divisionsUrl={divisionsUrl}
                                    childrenUrl={childrenUrl}
                                    onChange={setPlace}
                                    errors={{
                                        division_id: errors.division_id,
                                        district_id: errors.district_id,
                                        upazila_id: errors.upazila_id,
                                        union_id: errors.union_id,
                                    }}
                                />
                                <div className="grid gap-4 sm:grid-cols-[1fr_11rem]">
                                    {field(
                                        'line1',
                                        t('store_orders.create.line1'),
                                        { required: true },
                                    )}
                                    {field(
                                        'postcode',
                                        t('store_orders.create.postcode'),
                                    )}
                                </div>
                            </Step>

                            <Step
                                number={3}
                                title={t('store_orders.create.items')}
                            >
                                <ul className="space-y-2">
                                    {lines.map((line, index) => (
                                        <li
                                            key={index}
                                            className="grid grid-cols-[1fr_6.5rem_auto] items-start gap-2"
                                        >
                                            <ProductSearchSelect
                                                value={line.sku}
                                                selectedLabel={line.label}
                                                searchUrl={searchUrl}
                                                disabled={storefront === ''}
                                                ariaLabel={t(
                                                    'store_orders.create.product',
                                                )}
                                                onSelect={(option) =>
                                                    setLine(index, {
                                                        sku: option.sku,
                                                        label: option.label,
                                                    })
                                                }
                                                onClear={() =>
                                                    setLine(index, {
                                                        sku: '',
                                                        label: null,
                                                    })
                                                }
                                            />
                                            <Input
                                                type="number"
                                                min={1}
                                                max={10000}
                                                inputMode="numeric"
                                                value={line.quantity}
                                                onChange={(event) =>
                                                    setLine(index, {
                                                        quantity:
                                                            event.target.value,
                                                    })
                                                }
                                                aria-label={t(
                                                    'store_orders.create.quantity',
                                                )}
                                            />
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                onClick={() =>
                                                    setLines((previous) =>
                                                        previous.length === 1
                                                            ? [emptyLine()]
                                                            : previous.filter(
                                                                  (_, at) =>
                                                                      at !==
                                                                      index,
                                                              ),
                                                    )
                                                }
                                                aria-label={t(
                                                    'store_orders.create.remove_line',
                                                )}
                                            >
                                                <Trash2 aria-hidden="true" />
                                            </Button>
                                        </li>
                                    ))}
                                </ul>

                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        setLines((previous) => [
                                            ...previous,
                                            emptyLine(),
                                        ])
                                    }
                                    disabled={storefront === ''}
                                >
                                    <Plus aria-hidden="true" />
                                    {t('store_orders.create.add_line')}
                                </Button>

                                <InputError
                                    message={errors.items ?? quoteErrors.items}
                                />
                            </Step>
                        </div>

                        <aside className="bg-surface-subtle flex flex-col gap-4 border-t p-6 lg:overflow-y-auto lg:border-t-0 lg:border-l">
                            <h3 className="text-base font-semibold">
                                {t('store_orders.create.summary')}
                            </h3>

                            {shownQuote ? (
                                <ul className="divide-y text-sm">
                                    {shownQuote.lines.map((line) => (
                                        <li
                                            key={line.sku}
                                            className="flex justify-between gap-3 py-2"
                                        >
                                            <span className="min-w-0">
                                                <span className="block truncate font-medium">
                                                    {line.name}
                                                </span>
                                                <span className="text-muted-foreground text-xs">
                                                    {line.quantity} ×{' '}
                                                    {line.unit_price.formatted}
                                                </span>
                                            </span>
                                            <MoneyAmount
                                                amount={line.line_total}
                                            />
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-muted-foreground text-sm">
                                    {picked.length > 0 && quoting.processing
                                        ? t('store_orders.create.pricing')
                                        : t(
                                              'store_orders.create.summary_empty',
                                          )}
                                </p>
                            )}

                            <MoneyInput
                                id="new-order-discount"
                                name="discount"
                                label={t('store_orders.create.discount_label')}
                                value={form.data.discount}
                                onChange={(value) =>
                                    form.setData('discount', value)
                                }
                                helpText={t(
                                    'store_orders.create.discount_help',
                                )}
                                error={errors.discount ?? quoteErrors.discount}
                                disabled={storefront === ''}
                            />

                            {shownQuote && (
                                <dl className="space-y-1.5 border-t pt-3 text-sm">
                                    <div className="flex justify-between">
                                        <dt className="text-muted-foreground">
                                            {t('store_orders.create.subtotal')}
                                        </dt>
                                        <dd>
                                            <MoneyAmount
                                                amount={shownQuote.subtotal}
                                            />
                                        </dd>
                                    </div>
                                    {shownQuote.discount.amount !== '0.00' && (
                                        <div className="flex justify-between">
                                            <dt className="text-muted-foreground">
                                                {t(
                                                    'store_orders.create.discount',
                                                )}
                                            </dt>
                                            <dd>
                                                <MoneyAmount
                                                    amount={shownQuote.discount}
                                                    direction="debit"
                                                />
                                            </dd>
                                        </div>
                                    )}
                                    <div className="flex justify-between">
                                        <dt className="text-muted-foreground">
                                            {t('store_orders.create.delivery')}
                                        </dt>
                                        <dd>
                                            <MoneyAmount
                                                amount={shownQuote.delivery}
                                            />
                                        </dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="text-muted-foreground">
                                            {t('store_orders.create.tax')}
                                        </dt>
                                        <dd>
                                            <MoneyAmount
                                                amount={shownQuote.tax}
                                            />
                                        </dd>
                                    </div>
                                    <div className="flex items-center justify-between border-t pt-3 font-semibold">
                                        <dt>
                                            {t('store_orders.create.total')}
                                        </dt>
                                        <dd>
                                            <MoneyAmount
                                                amount={shownQuote.total}
                                                size="large"
                                            />
                                        </dd>
                                    </div>
                                </dl>
                            )}

                            <div className="grid gap-1.5">
                                <Label htmlFor="new-order-note">
                                    {t('store_orders.create.note')}
                                </Label>
                                <Textarea
                                    id="new-order-note"
                                    value={form.data.note}
                                    onChange={(event) =>
                                        form.setData('note', event.target.value)
                                    }
                                    maxLength={1000}
                                />
                                <InputError message={errors.note} />
                            </div>

                            <p className="text-muted-foreground text-xs">
                                {t('store_orders.create.cod_notice')}
                            </p>

                            <div className="mt-auto flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => close(false)}
                                >
                                    {t('store_orders.create.cancel')}
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={
                                        form.processing ||
                                        storefront === '' ||
                                        picked.length === 0 ||
                                        place?.upazila == null
                                    }
                                    data-test="place-store-order"
                                >
                                    {form.processing
                                        ? t('store_orders.create.submitting')
                                        : t('store_orders.create.submit')}
                                </Button>
                            </div>
                        </aside>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}

/** One numbered block of the order form. */
function Step({
    number,
    title,
    children: content,
}: {
    number: number;
    title: string;
    children: ReactNode;
}) {
    return (
        <section className="space-y-4 rounded-xl border p-5">
            <h3 className="flex items-center gap-2.5 text-sm font-semibold">
                <span
                    aria-hidden="true"
                    className="bg-primary text-primary-foreground flex size-6 items-center justify-center rounded-full text-xs"
                >
                    {number}
                </span>
                {title}
            </h3>
            {content}
        </section>
    );
}
