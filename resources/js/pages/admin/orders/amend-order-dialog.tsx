import { useForm, useHttp } from '@inertiajs/react';
import { Pencil, Plus, Trash2, Undo2 } from 'lucide-react';
import {
    useEffect,
    useMemo,
    useRef,
    useState,
    type FormEvent,
    type ReactNode,
} from 'react';
import OrderRevisionController from '@/actions/App/Http/Controllers/Admin/OrderRevisionController';
import Textarea from '@/components/forms/textarea';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
import MoneyInput from '@/components/money-input';
import PasswordInput from '@/components/password-input';
import SectionTabs, { SectionTabPanel } from '@/components/section-tabs';
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
import { Spinner } from '@/components/ui/spinner';
import { useConfirm } from '@/hooks/use-confirm';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import type { OrderRevisionPanel } from '@/pages/admin/orders/editable-order-details';

type Fields = Record<string, string | null>;

export type OrderAmendPanel = {
    reference: string;
    lines_editable: boolean;
    lines: {
        id: string;
        sku: string;
        name: string;
        variant: string | null;
        quantity: number;
        unit_price: string;
        line_total: Money;
    }[];
    discount: string;
    delivery: string;
    totals: Record<
        'subtotal' | 'discount' | 'delivery' | 'tax' | 'total',
        Money
    >;
    payment: {
        status: string;
        method: 'cod' | 'online' | 'partner_gateway';
        amount: Money;
    } | null;
    fulfilment: { courier: string; delivery: string };
    products: { sku: string; name: string; price: string }[];
};

type Line = {
    key: string;
    id: string | null;
    sku: string;
    name: string;
    quantity: string;
    unitPrice: string;
    removed: boolean;
};

type Preview =
    | {
          ok: true;
          totals: Record<
              | 'subtotal'
              | 'discount'
              | 'delivery'
              | 'tax'
              | 'total'
              | 'total_before'
              | 'difference',
              Money
          >;
          payment_consequence: string;
          payment_consequence_label: string;
          stock: { sku: string; before: number; after: number }[];
          profit: {
              net_selling: Money;
              product_base_price: Money | null;
              delivery_charge: Money;
              partner_profit: Money | null;
              banij_receivable: Money | null;
              unproven: boolean;
              negative: boolean;
          } | null;
      }
    | { ok: false; refusal: string; field: string; message: string };

type Tab =
    | 'customer'
    | 'address'
    | 'items'
    | 'pricing'
    | 'payment'
    | 'fulfilment';

const newKey = (): string =>
    typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(36).slice(2)}`;

const initialLines = (amend: OrderAmendPanel): Line[] =>
    amend.lines.map((line) => ({
        key: line.id,
        id: line.id,
        sku: line.sku,
        name: line.variant ? `${line.name} — ${line.variant}` : line.name,
        quantity: String(line.quantity),
        unitPrice: line.unit_price,
        removed: false,
    }));

const changedFields = (before: Fields | null, after: Fields | null): Fields =>
    Object.fromEntries(
        Object.entries(after ?? {}).filter(
            ([field, value]) => (before ?? {})[field] !== value,
        ),
    );

/**
 * The Order Details editor (D38, amended 2026-11-26): every part of an order
 * staff may correct, in one dialog, saved as one revision with a reason.
 *
 * Nothing here computes money. Each change asks the server what saving would
 * do — totals, the payment's consequence, stock and the Partner's profit — by
 * doing it and rolling it back, and shows that answer. There is no status
 * control: statuses move only through the order's own steps.
 */
export default function AmendOrderDialog({
    orderId,
    revision,
    amend,
    open: openProp,
    onOpenChange,
}: {
    orderId: string;
    revision: OrderRevisionPanel;
    amend: OrderAmendPanel;
    /** When given, the page opens the editor too (from the customer card); otherwise it opens itself. */
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
}) {
    const { t } = useTranslation();
    const confirm = useConfirm();
    const [ownOpen, setOwnOpen] = useState(false);
    const open = openProp ?? ownOpen;
    const setOpen = (next: boolean) =>
        onOpenChange ? onOpenChange(next) : setOwnOpen(next);
    const [tab, setTab] = useState<Tab>('customer');
    const [customer, setCustomer] = useState<Fields>(
        revision.form.customer ?? {},
    );
    const [shipping, setShipping] = useState<Fields>(
        revision.form.shipping_address ?? {},
    );
    const [billing, setBilling] = useState<Fields>(
        revision.form.billing_address ?? {},
    );
    const [note, setNote] = useState(revision.form.customer_note ?? '');
    const [lines, setLines] = useState<Line[]>(() => initialLines(amend));
    const [discount, setDiscount] = useState(amend.discount);
    const [delivery, setDelivery] = useState(amend.delivery);
    const [adding, setAdding] = useState('');
    const [preview, setPreview] = useState<Preview | null>(null);
    const [previewing, setPreviewing] = useState(false);
    const sequence = useRef(0);
    const submitting = useRef(false);
    const form = useForm({
        reason: '',
        internal_note: '',
        password: '',
        idempotency_key: newKey(),
    });
    // The request body is the editor's payload, set just before each preview.
    const http = useHttp<{ base_revision: number }, Preview>({
        base_revision: revision.number,
    });
    const errors = form.errors as Record<string, string | undefined>;

    const canItems = amend.lines_editable && revision.can.revise_items === true;
    const canMoney =
        amend.lines_editable && revision.can.revise_financials === true;

    const reset = () => {
        setCustomer(revision.form.customer ?? {});
        setShipping(revision.form.shipping_address ?? {});
        setBilling(revision.form.billing_address ?? {});
        setNote(revision.form.customer_note ?? '');
        setLines(initialLines(amend));
        setDiscount(amend.discount);
        setDelivery(amend.delivery);
        setAdding('');
        setPreview(null);
        setTab('customer');
        form.setData({
            reason: '',
            internal_note: '',
            password: '',
            idempotency_key: newKey(),
        });
        form.clearErrors();
    };

    const linesChanged = useMemo(() => {
        const start = initialLines(amend);

        return (
            lines.length !== start.length ||
            lines.some((line, index) => {
                const was = start[index];

                return (
                    !was ||
                    line.removed ||
                    line.id !== was.id ||
                    line.quantity !== was.quantity ||
                    line.unitPrice !== was.unitPrice
                );
            })
        );
    }, [lines, amend]);

    const pricesChanged =
        discount !== amend.discount ||
        delivery !== amend.delivery ||
        lines.some((line) => {
            const was = amend.lines.find((original) => original.id === line.id);

            return was !== undefined && line.unitPrice !== was.unit_price;
        });

    const payload = useMemo(() => {
        const data: Record<string, unknown> = {
            base_revision: revision.number,
            idempotency_key: form.data.idempotency_key,
        };
        const customerChanges = changedFields(revision.form.customer, customer);
        const shippingChanges = changedFields(
            revision.form.shipping_address,
            shipping,
        );
        const billingChanges = changedFields(
            revision.form.billing_address,
            billing,
        );

        if (Object.keys(customerChanges).length > 0) {
            data.customer = customerChanges;
        }

        if (Object.keys(shippingChanges).length > 0) {
            data.shipping_address = shippingChanges;
        }

        if (Object.keys(billingChanges).length > 0) {
            data.billing_address = billingChanges;
        }

        if (note !== (revision.form.customer_note ?? '')) {
            data.customer_note = note;
        }

        if (linesChanged) {
            data.lines = lines
                .filter((line) => !line.removed)
                .map((line) => {
                    const was = amend.lines.find(
                        (original) => original.id === line.id,
                    );

                    return {
                        id: line.id,
                        sku: line.id === null ? line.sku : null,
                        quantity: Number.parseInt(line.quantity, 10) || 0,
                        unit_price:
                            was === undefined ||
                            line.unitPrice !== was.unit_price
                                ? line.unitPrice.trim() || null
                                : null,
                    };
                });
        }

        if (discount !== amend.discount) {
            data.discount = discount.trim() || '0';
        }

        if (delivery !== amend.delivery) {
            data.delivery = delivery.trim() || '0';
        }

        return data;
    }, [
        customer,
        shipping,
        billing,
        note,
        lines,
        linesChanged,
        discount,
        delivery,
        revision,
        amend,
        form.data.idempotency_key,
    ]);

    const dirty = Object.keys(payload).length > 2;

    // Ask the server what saving would do, a moment after the last keystroke.
    useEffect(() => {
        if (!open || !dirty) {
            setPreview(null);

            return;
        }

        const ticket = ++sequence.current;
        const timer = window.setTimeout(() => {
            setPreviewing(true);
            http.transform(() => payload as { base_revision: number });
            http.submit(OrderRevisionController.preview(orderId))
                .then((answer) => {
                    if (ticket === sequence.current) {
                        setPreview(answer);
                    }
                })
                .catch(() => {
                    if (ticket === sequence.current) {
                        setPreview(null);
                    }
                })
                .finally(() => {
                    if (ticket === sequence.current) {
                        setPreviewing(false);
                    }
                });
        }, 450);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [payload, open, dirty, orderId]);

    const close = async () => {
        if (form.processing) {
            return;
        }

        if (dirty || form.data.reason.trim() !== '') {
            const discard = await confirm({
                title: t('orders.amend.discard_title'),
                description: t('orders.amend.discard_body'),
                confirmLabel: t('orders.amend.discard'),
            });

            if (!discard) {
                return;
            }
        }

        setOpen(false);
        reset();
    };

    const save = (event: FormEvent) => {
        event.preventDefault();

        if (submitting.current || form.processing || !dirty) {
            return;
        }

        submitting.current = true;
        form.transform((data) => ({
            ...payload,
            reason: data.reason.trim(),
            internal_note: data.internal_note.trim() || null,
            password: data.password || null,
            idempotency_key: data.idempotency_key,
        }));
        form.submit(OrderRevisionController.amend(orderId), {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                reset();
            },
            onError: (failed) => {
                if (failed.password) {
                    setTab('pricing');
                }
            },
            onFinish: () => {
                submitting.current = false;
            },
        });
    };

    const updateLine = (key: string, changes: Partial<Line>) =>
        setLines((current) =>
            current.map((line) =>
                line.key === key ? { ...line, ...changes } : line,
            ),
        );

    const addLine = () => {
        const product = amend.products.find((option) => option.sku === adding);

        if (!product) {
            return;
        }

        setLines((current) => [
            ...current,
            {
                key: newKey(),
                id: null,
                sku: product.sku,
                name: product.name,
                quantity: '1',
                unitPrice: product.price,
                removed: false,
            },
        ]);
        setAdding('');
    };

    const fieldList = (
        values: Fields,
        setValues: (next: Fields) => void,
        prefix: string,
        editable: boolean,
    ) => (
        <div className="grid gap-4 sm:grid-cols-2">
            {Object.entries(values).map(([field, value]) => (
                <div key={field} className="grid min-w-0 gap-1.5">
                    <Label htmlFor={`${prefix}-${field}`}>
                        {t(`orders.revision.fields.${field}`)}
                    </Label>
                    <Input
                        id={`${prefix}-${field}`}
                        value={value ?? ''}
                        disabled={!editable || form.processing}
                        onChange={(event) =>
                            setValues({
                                ...values,
                                [field]: event.target.value,
                            })
                        }
                        aria-invalid={
                            errors[`${prefix}.${field}`] ? true : undefined
                        }
                        autoComplete="off"
                    />
                    <InputError message={errors[`${prefix}.${field}`]} />
                </div>
            ))}
        </div>
    );

    const tabs: { key: Tab; label: string; hasError?: boolean }[] = [
        {
            key: 'customer',
            label: t('orders.amend.tabs.customer'),
            hasError: Object.keys(errors).some((key) =>
                key.startsWith('customer'),
            ),
        },
        {
            key: 'address',
            label: t('orders.amend.tabs.address'),
            hasError: Object.keys(errors).some((key) =>
                key.includes('address'),
            ),
        },
        {
            key: 'items',
            label: t('orders.amend.tabs.items'),
            hasError: Boolean(errors.lines),
        },
        {
            key: 'pricing',
            label: t('orders.amend.tabs.pricing'),
            hasError: Boolean(
                errors.discount || errors.delivery || errors.password,
            ),
        },
        { key: 'payment', label: t('orders.amend.tabs.payment') },
        { key: 'fulfilment', label: t('orders.amend.tabs.fulfilment') },
    ];

    const refusal = preview && !preview.ok ? preview.message : null;

    return (
        <>
            <Button variant="outline" onClick={() => setOpen(true)}>
                <Pencil className="size-4" aria-hidden="true" />
                {t('orders.amend.open')}
            </Button>

            <Dialog
                open={open}
                onOpenChange={(next) => {
                    if (next) {
                        setOpen(true);
                    } else {
                        void close();
                    }
                }}
            >
                <DialogContent className="flex max-h-[94vh] w-[calc(100vw-1rem)] flex-col gap-0 overflow-hidden p-0 sm:max-w-5xl">
                    <DialogHeader className="border-b px-4 py-4 sm:px-6">
                        <DialogTitle className="text-lg break-words">
                            {t('orders.amend.title', {
                                reference: amend.reference,
                            })}
                        </DialogTitle>
                        <DialogDescription>
                            {t('orders.amend.description')}
                        </DialogDescription>
                    </DialogHeader>

                    <form
                        onSubmit={save}
                        className="flex min-h-0 flex-1 flex-col"
                    >
                        <div className="px-4 sm:px-6">
                            <SectionTabs
                                items={tabs}
                                active={tab}
                                onChange={(key) => setTab(key as Tab)}
                            />
                        </div>

                        <div className="grid min-h-0 flex-1 overflow-y-auto lg:grid-cols-[minmax(0,1fr)_19rem] lg:overflow-hidden">
                            <div className="min-w-0 space-y-6 p-4 sm:p-6 lg:overflow-y-auto">
                                <SectionTabPanel tab="customer" active={tab}>
                                    {fieldList(
                                        customer,
                                        setCustomer,
                                        'customer',
                                        revision.can.edit_customer,
                                    )}
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="amend-note">
                                            {t('orders.revision.customer_note')}
                                        </Label>
                                        <Textarea
                                            id="amend-note"
                                            value={note}
                                            maxLength={2000}
                                            disabled={
                                                !revision.can.edit_customer ||
                                                form.processing
                                            }
                                            onChange={(event) =>
                                                setNote(event.target.value)
                                            }
                                        />
                                        <InputError
                                            message={errors.customer_note}
                                        />
                                    </div>
                                </SectionTabPanel>

                                <SectionTabPanel tab="address" active={tab}>
                                    <section className="space-y-3">
                                        <h3 className="text-sm font-medium">
                                            {t(
                                                'orders.revision.shipping_address',
                                            )}
                                        </h3>
                                        {fieldList(
                                            shipping,
                                            setShipping,
                                            'shipping_address',
                                            revision.can.edit_address,
                                        )}
                                    </section>
                                    {Object.keys(billing).length > 0 && (
                                        <section className="space-y-3">
                                            <h3 className="text-sm font-medium">
                                                {t(
                                                    'orders.revision.billing_address',
                                                )}
                                            </h3>
                                            {fieldList(
                                                billing,
                                                setBilling,
                                                'billing_address',
                                                revision.can.edit_address,
                                            )}
                                        </section>
                                    )}
                                </SectionTabPanel>

                                <SectionTabPanel tab="items" active={tab}>
                                    {!amend.lines_editable && (
                                        <p className="text-muted-foreground text-sm">
                                            {t('orders.amend.items_read_only')}
                                        </p>
                                    )}
                                    <ul className="divide-y rounded-lg border">
                                        {lines.map((line) => (
                                            <li
                                                key={line.key}
                                                className={`flex flex-wrap items-end gap-3 p-3 ${line.removed ? 'opacity-60' : ''}`}
                                            >
                                                <div className="min-w-0 flex-1 basis-48">
                                                    <div className="text-sm font-medium break-words">
                                                        {line.name}
                                                        {line.id === null && (
                                                            <span className="text-muted-foreground ms-2 text-xs">
                                                                (
                                                                {t(
                                                                    'orders.amend.new_line',
                                                                )}
                                                                )
                                                            </span>
                                                        )}
                                                    </div>
                                                    <div className="text-muted-foreground text-xs break-all">
                                                        {line.sku}
                                                    </div>
                                                    {line.removed && (
                                                        <div className="text-muted-foreground text-xs">
                                                            {t(
                                                                'orders.amend.removed',
                                                            )}
                                                        </div>
                                                    )}
                                                </div>
                                                <div className="grid w-20 gap-1">
                                                    <Label
                                                        htmlFor={`qty-${line.key}`}
                                                        className="text-xs"
                                                    >
                                                        {t(
                                                            'orders.amend.fields.quantity',
                                                        )}
                                                    </Label>
                                                    <Input
                                                        id={`qty-${line.key}`}
                                                        inputMode="numeric"
                                                        value={line.quantity}
                                                        disabled={
                                                            !canItems ||
                                                            line.removed ||
                                                            form.processing
                                                        }
                                                        onChange={(event) =>
                                                            updateLine(
                                                                line.key,
                                                                {
                                                                    quantity:
                                                                        event.target.value.replace(
                                                                            /[^0-9]/g,
                                                                            '',
                                                                        ),
                                                                },
                                                            )
                                                        }
                                                    />
                                                </div>
                                                <MoneyInput
                                                    id={`price-${line.key}`}
                                                    name={`price-${line.key}`}
                                                    label={t(
                                                        'orders.amend.fields.unit_price',
                                                    )}
                                                    value={line.unitPrice}
                                                    onChange={(value) =>
                                                        updateLine(line.key, {
                                                            unitPrice: value,
                                                        })
                                                    }
                                                    disabled={
                                                        !canMoney ||
                                                        line.removed ||
                                                        form.processing
                                                    }
                                                    className="w-36"
                                                />
                                                {canItems && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-9 shrink-0"
                                                        disabled={
                                                            form.processing
                                                        }
                                                        onClick={() =>
                                                            line.id === null
                                                                ? setLines(
                                                                      (
                                                                          current,
                                                                      ) =>
                                                                          current.filter(
                                                                              (
                                                                                  other,
                                                                              ) =>
                                                                                  other.key !==
                                                                                  line.key,
                                                                          ),
                                                                  )
                                                                : updateLine(
                                                                      line.key,
                                                                      {
                                                                          removed:
                                                                              !line.removed,
                                                                      },
                                                                  )
                                                        }
                                                        aria-label={t(
                                                            'orders.amend.remove',
                                                            {
                                                                product:
                                                                    line.name,
                                                            },
                                                        )}
                                                        title={t(
                                                            'orders.amend.remove',
                                                            {
                                                                product:
                                                                    line.name,
                                                            },
                                                        )}
                                                    >
                                                        {line.removed ? (
                                                            <Undo2
                                                                className="size-4"
                                                                aria-hidden="true"
                                                            />
                                                        ) : (
                                                            <Trash2
                                                                className="size-4"
                                                                aria-hidden="true"
                                                            />
                                                        )}
                                                    </Button>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                    <InputError message={errors.lines} />
                                    {canItems && amend.products.length > 0 && (
                                        <div className="flex flex-wrap items-end gap-2">
                                            <div className="grid min-w-0 flex-1 basis-56 gap-1.5">
                                                <Label htmlFor="amend-add">
                                                    {t(
                                                        'orders.amend.add_product',
                                                    )}
                                                </Label>
                                                <select
                                                    id="amend-add"
                                                    value={adding}
                                                    onChange={(event) =>
                                                        setAdding(
                                                            event.target.value,
                                                        )
                                                    }
                                                    className="border-input bg-background focus-visible:ring-ring h-9 w-full min-w-0 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                                >
                                                    <option value="">
                                                        {t(
                                                            'orders.amend.choose_product',
                                                        )}
                                                    </option>
                                                    {amend.products.map(
                                                        (product) => (
                                                            <option
                                                                key={
                                                                    product.sku
                                                                }
                                                                value={
                                                                    product.sku
                                                                }
                                                            >
                                                                {product.name} (
                                                                {product.sku})
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            </div>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                disabled={adding === ''}
                                                onClick={addLine}
                                            >
                                                <Plus
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                                {t('orders.amend.add')}
                                            </Button>
                                        </div>
                                    )}
                                </SectionTabPanel>

                                <SectionTabPanel tab="pricing" active={tab}>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <MoneyInput
                                            id="amend-discount"
                                            name="discount"
                                            label={t(
                                                'orders.amend.fields.discount',
                                            )}
                                            value={discount}
                                            onChange={setDiscount}
                                            disabled={
                                                !canMoney || form.processing
                                            }
                                            error={errors.discount}
                                        />
                                        <MoneyInput
                                            id="amend-delivery"
                                            name="delivery"
                                            label={t(
                                                'orders.amend.fields.delivery',
                                            )}
                                            value={delivery}
                                            onChange={setDelivery}
                                            disabled={
                                                !canMoney || form.processing
                                            }
                                            error={errors.delivery}
                                        />
                                    </div>
                                    <Totals
                                        totals={
                                            preview?.ok
                                                ? preview.totals
                                                : amend.totals
                                        }
                                    />
                                </SectionTabPanel>

                                <SectionTabPanel tab="payment" active={tab}>
                                    {amend.payment && (
                                        <dl className="grid gap-3 text-sm sm:grid-cols-2">
                                            <Fact
                                                label={t(
                                                    'orders.amend.payment.status',
                                                )}
                                            >
                                                {amend.payment.status}
                                            </Fact>
                                            <Fact
                                                label={t(
                                                    'orders.amend.payment.method',
                                                )}
                                            >
                                                {t(
                                                    `orders.amend.payment.${amend.payment.method}`,
                                                )}
                                            </Fact>
                                            <Fact
                                                label={t(
                                                    'orders.amend.payment.amount',
                                                )}
                                            >
                                                <MoneyAmount
                                                    amount={
                                                        amend.payment.amount
                                                    }
                                                />
                                            </Fact>
                                            {preview?.ok && (
                                                <Fact
                                                    label={t(
                                                        'orders.amend.payment.consequence',
                                                    )}
                                                >
                                                    {
                                                        preview.payment_consequence_label
                                                    }
                                                </Fact>
                                            )}
                                        </dl>
                                    )}
                                </SectionTabPanel>

                                <SectionTabPanel tab="fulfilment" active={tab}>
                                    <dl className="grid gap-3 text-sm sm:grid-cols-2">
                                        <Fact
                                            label={t(
                                                'orders.amend.fulfilment.courier',
                                            )}
                                        >
                                            {amend.fulfilment.courier}
                                        </Fact>
                                        <Fact
                                            label={t(
                                                'orders.amend.fulfilment.delivery',
                                            )}
                                        >
                                            {amend.fulfilment.delivery}
                                        </Fact>
                                    </dl>
                                    <p className="text-muted-foreground text-sm">
                                        {t('orders.amend.fulfilment.no_status')}
                                    </p>
                                </SectionTabPanel>
                            </div>

                            <aside
                                aria-live="polite"
                                className="bg-muted/40 min-w-0 space-y-4 border-t p-4 text-sm sm:p-6 lg:overflow-y-auto lg:border-t-0 lg:border-l"
                            >
                                <h3 className="font-medium">
                                    {t('orders.amend.preview.title')}
                                </h3>
                                {!dirty && (
                                    <p className="text-muted-foreground">
                                        {t('orders.amend.preview.unchanged')}
                                    </p>
                                )}
                                {dirty && previewing && (
                                    <p className="text-muted-foreground flex items-center gap-2">
                                        <Spinner />{' '}
                                        {t('orders.amend.preview.calculating')}
                                    </p>
                                )}
                                {refusal && (
                                    <p className="text-danger" role="alert">
                                        {refusal}
                                    </p>
                                )}
                                {dirty && preview?.ok && (
                                    <>
                                        <dl className="space-y-1.5">
                                            <Fact
                                                label={t(
                                                    'orders.amend.fields.total_before',
                                                )}
                                                inline
                                            >
                                                <MoneyAmount
                                                    amount={
                                                        preview.totals
                                                            .total_before
                                                    }
                                                />
                                            </Fact>
                                            <Fact
                                                label={t(
                                                    'orders.amend.fields.total',
                                                )}
                                                inline
                                            >
                                                <MoneyAmount
                                                    amount={
                                                        preview.totals.total
                                                    }
                                                />
                                            </Fact>
                                            <Fact
                                                label={t(
                                                    'orders.amend.fields.difference',
                                                )}
                                                inline
                                            >
                                                <MoneyAmount
                                                    amount={
                                                        preview.totals
                                                            .difference
                                                    }
                                                />
                                            </Fact>
                                            <Fact
                                                label={t(
                                                    'orders.amend.payment.consequence',
                                                )}
                                                inline
                                            >
                                                {
                                                    preview.payment_consequence_label
                                                }
                                            </Fact>
                                        </dl>
                                        {preview.stock.length > 0 && (
                                            <ul className="text-muted-foreground space-y-1 text-xs">
                                                {preview.stock.map((change) => (
                                                    <li
                                                        key={change.sku}
                                                        className="break-all"
                                                    >
                                                        {t(
                                                            'orders.amend.preview.stock',
                                                            {
                                                                sku: change.sku,
                                                                before: change.before,
                                                                after: change.after,
                                                            },
                                                        )}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                        {preview.profit && (
                                            <div className="space-y-1.5 border-t pt-3">
                                                <h4 className="font-medium">
                                                    {t(
                                                        'orders.amend.profit.title',
                                                    )}
                                                </h4>
                                                <dl className="space-y-1.5">
                                                    <Fact
                                                        label={t(
                                                            'orders.amend.profit.net_selling',
                                                        )}
                                                        inline
                                                    >
                                                        <MoneyAmount
                                                            amount={
                                                                preview.profit
                                                                    .net_selling
                                                            }
                                                        />
                                                    </Fact>
                                                    {preview.profit
                                                        .product_base_price && (
                                                        <Fact
                                                            label={t(
                                                                'orders.amend.profit.product_base_price',
                                                            )}
                                                            inline
                                                        >
                                                            <MoneyAmount
                                                                amount={
                                                                    preview
                                                                        .profit
                                                                        .product_base_price
                                                                }
                                                            />
                                                        </Fact>
                                                    )}
                                                    <Fact
                                                        label={t(
                                                            'orders.amend.profit.delivery_charge',
                                                        )}
                                                        inline
                                                    >
                                                        <MoneyAmount
                                                            amount={
                                                                preview.profit
                                                                    .delivery_charge
                                                            }
                                                        />
                                                    </Fact>
                                                    {preview.profit
                                                        .partner_profit && (
                                                        <Fact
                                                            label={t(
                                                                'orders.amend.profit.partner_profit',
                                                            )}
                                                            inline
                                                        >
                                                            <MoneyAmount
                                                                amount={
                                                                    preview
                                                                        .profit
                                                                        .partner_profit
                                                                }
                                                            />
                                                        </Fact>
                                                    )}
                                                    {preview.profit
                                                        .banij_receivable && (
                                                        <Fact
                                                            label={t(
                                                                'orders.amend.profit.banij_receivable',
                                                            )}
                                                            inline
                                                        >
                                                            <MoneyAmount
                                                                amount={
                                                                    preview
                                                                        .profit
                                                                        .banij_receivable
                                                                }
                                                            />
                                                        </Fact>
                                                    )}
                                                </dl>
                                                {preview.profit.unproven && (
                                                    <p className="text-muted-foreground text-xs">
                                                        {t(
                                                            'orders.amend.profit.unproven',
                                                        )}
                                                    </p>
                                                )}
                                                {preview.profit.negative && (
                                                    <p className="text-danger text-xs">
                                                        {t(
                                                            'orders.amend.profit.negative',
                                                        )}
                                                    </p>
                                                )}
                                            </div>
                                        )}
                                    </>
                                )}
                            </aside>
                        </div>

                        <div className="space-y-3 border-t px-4 py-4 sm:px-6">
                            <div className="grid gap-3 md:grid-cols-2">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="amend-reason">
                                        {t('orders.amend.reason')}
                                    </Label>
                                    <Input
                                        id="amend-reason"
                                        value={form.data.reason}
                                        maxLength={1000}
                                        placeholder={t(
                                            'orders.revision.reason_placeholder',
                                        )}
                                        disabled={form.processing}
                                        onChange={(event) =>
                                            form.setData(
                                                'reason',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={
                                            errors.reason ? true : undefined
                                        }
                                    />
                                    <InputError
                                        message={
                                            errors.reason ?? errors.revision
                                        }
                                    />
                                </div>
                                {(pricesChanged || errors.password) && (
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="amend-password">
                                            {t('orders.amend.password')}
                                        </Label>
                                        <PasswordInput
                                            id="amend-password"
                                            value={form.data.password}
                                            autoComplete="current-password"
                                            disabled={form.processing}
                                            onChange={(event) =>
                                                form.setData(
                                                    'password',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            {t('orders.amend.password_help')}
                                        </p>
                                        <InputError message={errors.password} />
                                    </div>
                                )}
                            </div>
                            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => void close()}
                                    disabled={form.processing}
                                >
                                    {t('orders.amend.cancel')}
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={
                                        form.processing ||
                                        !dirty ||
                                        form.data.reason.trim().length < 10 ||
                                        (preview !== null && !preview.ok)
                                    }
                                >
                                    {form.processing && <Spinner />}
                                    {form.processing
                                        ? t('orders.amend.saving')
                                        : t('orders.amend.save')}
                                </Button>
                            </div>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

function Totals({
    totals,
}: {
    totals: Record<
        'subtotal' | 'discount' | 'delivery' | 'tax' | 'total',
        Money
    >;
}) {
    const { t } = useTranslation();

    return (
        <dl className="max-w-sm space-y-1.5 text-sm">
            {(['subtotal', 'discount', 'delivery', 'tax'] as const).map(
                (key) => (
                    <Fact
                        key={key}
                        label={t(`orders.amend.fields.${key}`)}
                        inline
                    >
                        <MoneyAmount amount={totals[key]} />
                    </Fact>
                ),
            )}
            <Fact label={t('orders.amend.fields.total')} inline>
                <span className="font-semibold">
                    <MoneyAmount amount={totals.total} />
                </span>
            </Fact>
        </dl>
    );
}

function Fact({
    label,
    children,
    inline = false,
}: {
    label: string;
    children: ReactNode;
    inline?: boolean;
}) {
    return inline ? (
        <div className="flex items-baseline justify-between gap-3">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-end">{children}</dd>
        </div>
    ) : (
        <div className="min-w-0">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="break-words">{children}</dd>
        </div>
    );
}
