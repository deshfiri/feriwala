import { router, usePage } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import ProductPriceTierController from '@/actions/App/Http/Controllers/Admin/ProductPriceTierController';
import AlertError from '@/components/alert-error';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import type { PriceTierScope, ProductDetail } from '@/types';

type Props = {
    product: ProductDetail;
    scopes: PriceTierScope[];
    canEdit: boolean;
};

type Row = { key: number; min_quantity: string; unit_price_minor: string };

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none sm:w-72';

/**
 * Quantity pricing for a product or one of its variations (§11.1).
 *
 * The table is saved whole, never row by row: each band only means something
 * against the band before it. Rows here are typed figures awaiting the server —
 * nothing on this screen works out what a quantity costs. The saved bands below
 * the editor are the server's own rendering, including the ones it no longer
 * charges because the base price was cut beneath them.
 */
export default function PriceTiersSection({ product, scopes, canEdit }: Props) {
    const { t } = useTranslation();
    const page = usePage<{ errors: Record<string, string> }>();
    const errors = page.props.errors ?? {};

    const [scopeId, setScopeId] = useState('');
    const scope =
        scopes.find((candidate) => (candidate.variant_id ?? '') === scopeId) ??
        scopes[0];

    const nextKey = useRef(0);
    const toRows = (source: PriceTierScope | undefined): Row[] =>
        (source?.tiers ?? []).map((tier) => ({
            key: nextKey.current++,
            min_quantity: String(tier.min_quantity),
            unit_price_minor: tier.unit_price.decimal,
        }));

    const [rows, setRows] = useState<Row[]>(() => toRows(scope));
    const [processing, setProcessing] = useState(false);

    // Re-seeded from the server whenever the scope changes or a save returns.
    useEffect(() => {
        setRows(toRows(scope));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [scope]);

    if (!scope) {
        return null;
    }

    const productScope = scopes[0];
    const inheritsProduct =
        scope.variant_id !== null &&
        scope.tiers.length === 0 &&
        productScope.tiers.length > 0;

    const update = (
        key: number,
        field: keyof Omit<Row, 'key'>,
        value: string,
    ) =>
        setRows((current) =>
            current.map((row) =>
                row.key === key ? { ...row, [field]: value } : row,
            ),
        );

    const save = () =>
        router.put(
            ProductPriceTierController.update.url(product.id),
            {
                variant_id: scope.variant_id,
                tiers: rows.map((row) => ({
                    min_quantity: row.min_quantity,
                    unit_price_minor: row.unit_price_minor,
                })),
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );

    return (
        <SectionCard
            title={t('catalog.tiers.title')}
            description={t('catalog.tiers.description')}
        >
            <div className="space-y-4">
                {scopes.length > 1 && (
                    <div className="grid gap-1.5">
                        <Label htmlFor="tier-scope">
                            {t('catalog.tiers.scope')}
                        </Label>
                        <select
                            id="tier-scope"
                            value={scopeId}
                            onChange={(event) => setScopeId(event.target.value)}
                            className={selectClass}
                        >
                            {scopes.map((candidate) => (
                                <option
                                    key={candidate.variant_id ?? 'product'}
                                    value={candidate.variant_id ?? ''}
                                >
                                    {candidate.label ??
                                        t('catalog.tiers.whole_product')}
                                </option>
                            ))}
                        </select>
                    </div>
                )}

                <p className="text-sm">
                    {t('catalog.tiers.base', {
                        amount: scope.base_price.formatted,
                    })}
                </p>

                {scope.tiers.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        {inheritsProduct
                            ? t('catalog.tiers.uses_product')
                            : t('catalog.tiers.empty')}
                    </p>
                ) : (
                    <ul className="divide-border divide-y rounded-md border">
                        {scope.tiers.map((tier) => (
                            <li
                                key={tier.min_quantity}
                                className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm"
                            >
                                <span>
                                    {t('catalog.tiers.row', {
                                        quantity: tier.min_quantity,
                                    })}
                                </span>
                                <span className="flex flex-wrap items-center gap-2">
                                    <MoneyAmount amount={tier.unit_price} />
                                    {!tier.applies && (
                                        <StatusPill
                                            tone="warning"
                                            label={t(
                                                'catalog.tiers.no_longer_applies',
                                            )}
                                        />
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}

                {canEdit && (
                    <div className="space-y-3 rounded-lg border border-dashed p-4">
                        {errors.tiers && <AlertError errors={[errors.tiers]} />}

                        {rows.map((row, index) => (
                            <div
                                key={row.key}
                                className="grid items-start gap-2 sm:grid-cols-[1fr_1fr_auto]"
                            >
                                <div className="grid gap-1">
                                    <Label htmlFor={`tier-${row.key}-quantity`}>
                                        {t('catalog.tiers.from')}
                                    </Label>
                                    <Input
                                        id={`tier-${row.key}-quantity`}
                                        type="number"
                                        inputMode="numeric"
                                        min={2}
                                        step={1}
                                        className="tabular-nums"
                                        value={row.min_quantity}
                                        onChange={(event) =>
                                            update(
                                                row.key,
                                                'min_quantity',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={
                                            errors[
                                                `tiers.${index}.min_quantity`
                                            ]
                                        }
                                    />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor={`tier-${row.key}-price`}>
                                        {t('catalog.tiers.unit_price')}
                                    </Label>
                                    <div className="relative">
                                        <span
                                            aria-hidden="true"
                                            className="text-muted-foreground pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm"
                                        >
                                            ৳
                                        </span>
                                        <Input
                                            id={`tier-${row.key}-price`}
                                            type="text"
                                            inputMode="decimal"
                                            pattern="^\d+(\.\d{1,2})?$"
                                            placeholder="0.00"
                                            className="pl-7 tabular-nums"
                                            value={row.unit_price_minor}
                                            onChange={(event) =>
                                                update(
                                                    row.key,
                                                    'unit_price_minor',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <InputError
                                        message={
                                            errors[
                                                `tiers.${index}.unit_price_minor`
                                            ]
                                        }
                                    />
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="mt-6"
                                    aria-label={t('catalog.tiers.remove')}
                                    onClick={() =>
                                        setRows((current) =>
                                            current.filter(
                                                (candidate) =>
                                                    candidate.key !== row.key,
                                            ),
                                        )
                                    }
                                >
                                    <Trash2 className="size-4" />
                                </Button>
                            </div>
                        ))}

                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    setRows((current) => [
                                        ...current,
                                        {
                                            key: nextKey.current++,
                                            min_quantity: '',
                                            unit_price_minor: '',
                                        },
                                    ])
                                }
                            >
                                <Plus className="size-4" aria-hidden="true" />
                                {t('catalog.tiers.add')}
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                disabled={processing}
                                onClick={save}
                            >
                                {t('catalog.tiers.save')}
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </SectionCard>
    );
}
