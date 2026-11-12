import { useForm, useHttp } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import NativeSelect from '@/components/forms/native-select';
import Textarea from '@/components/forms/textarea';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';

type ProductOption = { sku: string; label: string; price: Money };

type Quote = {
    lines: {
        sku: string;
        name: string;
        quantity: number;
        unit_price: Money;
        line_total: Money;
    }[];
    subtotal: Money;
    delivery: Money;
    tax: Money;
    total: Money;
};

type Line = { sku: string; quantity: string };

export type NewStoreOrderUrls = {
    products: string;
    quote: string;
    store: string;
};

const emptyLine = (): Line => ({ sku: '', quantity: '1' });

/**
 * A Partner takes an order for their own customer, in a popup.
 *
 * The price is never worked out here: every change to the lines asks the server
 * for a quote and shows exactly what it answers (§36.1). The order is cash on
 * delivery, and a double click is one order because the form carries a single
 * token for its whole life.
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
    const [search, setSearch] = useState('');
    const [options, setOptions] = useState<ProductOption[]>([]);
    const [known, setKnown] = useState<Record<string, string>>({});
    const [loadingProducts, setLoadingProducts] = useState(false);
    const [quote, setQuote] = useState<Quote | null>(null);

    const form = useForm({
        storefront: storefronts.length === 1 ? storefronts[0].value : '',
        token,
        customer_name: '',
        customer_phone: '',
        customer_email: '',
        line1: '',
        city: '',
        district: '',
        postcode: '',
        note: '',
        items: [] as { sku: string; quantity: number }[],
    });

    const quoting = useHttp<
        { storefront: string; items: { sku: string; quantity: number }[] },
        { data: Quote }
    >({ storefront: '', items: [] });

    const errors = form.errors as Record<string, string | undefined>;
    const quoteErrors = quoting.errors as Record<string, string | undefined>;
    const storefront = form.data.storefront;

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

    // The picker: this storefront's products, narrowed by the search box.
    useEffect(() => {
        if (!open || storefront === '') {
            return;
        }

        const timer = window.setTimeout(async () => {
            setLoadingProducts(true);

            try {
                const response = await fetch(
                    `${urls.products}?${new URLSearchParams({ storefront, search })}`,
                    {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    },
                );
                const body = (await response.json()) as {
                    data: ProductOption[];
                };

                setOptions(body.data);
                setKnown((previous) => ({
                    ...previous,
                    ...Object.fromEntries(
                        body.data.map((option) => [option.sku, option.label]),
                    ),
                }));
            } catch {
                setOptions([]);
            } finally {
                setLoadingProducts(false);
            }
        }, 250);

        return () => window.clearTimeout(timer);
    }, [open, storefront, search, urls.products]);

    // The price: asked of the server whenever what is being bought changes.
    useEffect(() => {
        if (!open || storefront === '' || picked.length === 0) {
            return;
        }

        quoting.transform(() => ({ storefront, items: picked }));

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
    }, [open, storefront, pickedKey]);

    const shownQuote = picked.length === 0 ? null : quote;

    const choices = useMemo(() => {
        const merged = new Map(
            options.map((option) => [option.sku, option.label]),
        );

        for (const line of lines) {
            if (line.sku !== '' && !merged.has(line.sku)) {
                merged.set(line.sku, known[line.sku] ?? line.sku);
            }
        }

        return [...merged.entries()];
    }, [options, lines, known]);

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
            setSearch('');
            setQuote(null);
            setToken(crypto.randomUUID());
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.transform((data) => ({
            ...data,
            token,
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
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>{t('store_orders.create.title')}</DialogTitle>
                    <DialogDescription>
                        {t('store_orders.create.description')}
                    </DialogDescription>
                </DialogHeader>

                {storefronts.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        {t('store_orders.create.no_storefronts')}
                    </p>
                ) : (
                    <form onSubmit={submit} className="space-y-5">
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

                        <fieldset className="space-y-3">
                            <legend className="text-sm font-medium">
                                {t('store_orders.create.customer')}
                            </legend>
                            <div className="grid gap-3 sm:grid-cols-2">
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
                        </fieldset>

                        <fieldset className="space-y-3">
                            <legend className="text-sm font-medium">
                                {t('store_orders.create.address')}
                            </legend>
                            {field('line1', t('store_orders.create.line1'), {
                                required: true,
                            })}
                            <div className="grid gap-3 sm:grid-cols-3">
                                {field('city', t('store_orders.create.city'), {
                                    required: true,
                                })}
                                {field(
                                    'district',
                                    t('store_orders.create.district'),
                                )}
                                {field(
                                    'postcode',
                                    t('store_orders.create.postcode'),
                                )}
                            </div>
                        </fieldset>

                        <fieldset className="space-y-3">
                            <legend className="text-sm font-medium">
                                {t('store_orders.create.items')}
                            </legend>

                            <Input
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                placeholder={t(
                                    'store_orders.create.product_search',
                                )}
                                aria-label={t(
                                    'store_orders.create.product_search',
                                )}
                                disabled={storefront === ''}
                            />

                            {storefront !== '' &&
                                !loadingProducts &&
                                options.length === 0 && (
                                    <p className="text-muted-foreground text-xs">
                                        {t('store_orders.create.no_products')}
                                    </p>
                                )}

                            <ul className="space-y-2">
                                {lines.map((line, index) => (
                                    <li
                                        key={index}
                                        className="grid grid-cols-[1fr_6rem_auto] items-center gap-2"
                                    >
                                        <NativeSelect
                                            value={line.sku}
                                            onChange={(event) =>
                                                setLine(index, {
                                                    sku: event.target.value,
                                                })
                                            }
                                            disabled={storefront === ''}
                                            aria-label={t(
                                                'store_orders.create.product',
                                            )}
                                        >
                                            <option value="">
                                                {t(
                                                    'store_orders.create.product_placeholder',
                                                )}
                                            </option>
                                            {choices.map(([sku, label]) => (
                                                <option key={sku} value={sku}>
                                                    {label} ({sku})
                                                </option>
                                            ))}
                                        </NativeSelect>
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
                                                                  at !== index,
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
                        </fieldset>

                        {shownQuote ? (
                            <dl className="bg-surface-subtle space-y-1 rounded-lg p-3 text-sm">
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
                                        <MoneyAmount amount={shownQuote.tax} />
                                    </dd>
                                </div>
                                <div className="flex justify-between border-t pt-2 font-semibold">
                                    <dt>{t('store_orders.create.total')}</dt>
                                    <dd>
                                        <MoneyAmount
                                            amount={shownQuote.total}
                                            size="large"
                                        />
                                    </dd>
                                </div>
                            </dl>
                        ) : (
                            picked.length > 0 &&
                            quoting.processing && (
                                <p className="text-muted-foreground text-xs">
                                    {t('store_orders.create.pricing')}
                                </p>
                            )
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

                        <DialogFooter>
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
                                    picked.length === 0
                                }
                                data-test="place-store-order"
                            >
                                {form.processing
                                    ? t('store_orders.create.submitting')
                                    : t('store_orders.create.submit')}
                            </Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
