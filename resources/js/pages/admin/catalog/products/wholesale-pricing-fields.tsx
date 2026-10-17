import { Calculator } from 'lucide-react';
import { useState } from 'react';
import FormField from '@/components/forms/form-field';
import MoneyField from '@/components/forms/money-field';
import NativeSelect from '@/components/forms/native-select';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { WholesalePricingMethod } from '@/types';
import useWholesalePricePreview from './use-wholesale-price-preview';

type Props = {
    /**
     * A product always has both figures; a variation's are optional overrides
     * of its product's, and a percentage one is worked out from its own cost.
     */
    scope: 'product' | 'variant';
    initial: {
        method: WholesalePricingMethod;
        baseCost: string;
        markup: string;
        wholesale: string;
        /** The saved wholesale price as the server rendered it, if any. */
        saved: Money | null;
    };
    errors: Partial<
        Record<
            | 'base_cost'
            | 'wholesale_pricing_method'
            | 'wholesale_markup_percentage'
            | 'wholesale_price',
            string
        >
    >;
    /** Labels and notes that differ between a product and a variation. */
    labels: {
        baseCost: string;
        baseCostHelp?: string;
        baseCostHint?: string;
        wholesale: string;
        wholesaleHelp?: string;
        wholesaleHint?: string;
    };
};

/**
 * Base cost, pricing method, markup and wholesale price, in that order.
 *
 * Renders as children of the caller's grid. Fixed keeps the price a person
 * types. Percentage hides that input behind a calculated figure the server
 * supplies — the browser holds strings and never does the sum — and posts no
 * `wholesale_price` at all; the server calculates its own regardless.
 *
 * Moving Percentage → Fixed keeps the last calculated price as the starting
 * value to edit, and the markup stops being posted so the server clears it.
 */
export default function WholesalePricingFields({
    scope,
    initial,
    errors,
    labels,
}: Props) {
    const { t } = useTranslation();

    const [method, setMethod] = useState<WholesalePricingMethod>(
        initial.method,
    );
    const [baseCost, setBaseCost] = useState(initial.baseCost);
    const [markup, setMarkup] = useState(initial.markup);
    const [wholesale, setWholesale] = useState(initial.wholesale);

    // The markup is calculated when its field loses focus, not on every
    // keystroke; the base cost still recalculates as it is typed.
    const [appliedMarkup, setAppliedMarkup] = useState(initial.markup);

    const percentage = method === 'percentage';
    const { preview, calculating, failed } = useWholesalePricePreview(
        percentage,
        baseCost,
        appliedMarkup,
    );

    // The latest calculated price becomes the editable one the moment the
    // method is switched back to Fixed.
    const calculated =
        preview?.wholesale_price ??
        (percentage &&
        appliedMarkup === initial.markup &&
        baseCost === initial.baseCost
            ? initial.saved
            : null);

    const changeMethod = (next: WholesalePricingMethod) => {
        if (next === 'fixed' && percentage && calculated) {
            setWholesale(calculated.amount);
        }

        setMethod(next);
    };

    const baseCostError =
        errors.base_cost ??
        (percentage && baseCost.trim() === ''
            ? t('catalog.pricing.base_cost_first')
            : undefined);

    return (
        <>
            <FormField
                label={labels.baseCost}
                description={labels.baseCostHelp}
                hint={
                    scope === 'variant' && percentage
                        ? t('catalog.pricing.variant_base_cost_help')
                        : labels.baseCostHint
                }
                error={baseCostError}
                required={scope === 'product' || percentage}
            >
                {(field) => (
                    <MoneyField
                        {...field}
                        name="base_cost"
                        value={baseCost}
                        onChange={setBaseCost}
                    />
                )}
            </FormField>

            <FormField
                label={t('catalog.pricing.method')}
                description={t('catalog.pricing.method_help')}
                error={errors.wholesale_pricing_method}
            >
                {(field) => (
                    <NativeSelect
                        {...field}
                        name="wholesale_pricing_method"
                        value={method}
                        onChange={(event) =>
                            changeMethod(
                                event.target.value as WholesalePricingMethod,
                            )
                        }
                    >
                        <option value="fixed">
                            {t('catalog.pricing.method_fixed')}
                        </option>
                        <option value="percentage">
                            {t('catalog.pricing.method_percentage')}
                        </option>
                    </NativeSelect>
                )}
            </FormField>

            {percentage && (
                <FormField
                    label={t('catalog.pricing.markup')}
                    description={t('catalog.pricing.markup_help', {
                        max: '1000',
                    })}
                    error={errors.wholesale_markup_percentage}
                    required
                >
                    {(field) => (
                        <div className="relative">
                            <Input
                                {...field}
                                name="wholesale_markup_percentage"
                                type="text"
                                inputMode="decimal"
                                autoComplete="off"
                                placeholder="0"
                                value={markup}
                                onChange={(event) =>
                                    setMarkup(event.target.value)
                                }
                                onBlur={() => setAppliedMarkup(markup)}
                                className="pr-8 tabular-nums"
                            />
                            <span
                                aria-hidden="true"
                                className="text-muted-foreground pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-sm"
                            >
                                %
                            </span>
                        </div>
                    )}
                </FormField>
            )}

            {percentage ? (
                <FormField
                    label={t('catalog.pricing.calculated')}
                    description={t('catalog.pricing.calculated_help')}
                    error={errors.wholesale_price}
                >
                    {(field) => (
                        <output
                            id={field.id}
                            aria-describedby={field['aria-describedby']}
                            aria-live="polite"
                            aria-busy={calculating}
                            className={cn(
                                'bg-muted/50 text-foreground flex h-9 w-full min-w-0 items-center justify-between gap-2 rounded-md border border-dashed px-3 text-sm tabular-nums',
                                calculating && 'opacity-70',
                            )}
                        >
                            <span className="truncate">
                                {calculated?.formatted ??
                                    (calculating
                                        ? t('catalog.pricing.calculating')
                                        : failed
                                          ? t('catalog.pricing.preview_failed')
                                          : markup.trim() === ''
                                            ? t('catalog.pricing.markup_prompt')
                                            : markup.trim() !==
                                                appliedMarkup.trim()
                                              ? t('catalog.pricing.leave_field')
                                              : '—')}
                            </span>
                            <span className="text-muted-foreground flex shrink-0 items-center gap-1 text-xs">
                                <Calculator
                                    className="size-3.5"
                                    aria-hidden="true"
                                />
                                {t('catalog.pricing.calculated_badge')}
                            </span>
                        </output>
                    )}
                </FormField>
            ) : (
                <FormField
                    label={labels.wholesale}
                    description={labels.wholesaleHelp}
                    hint={labels.wholesaleHint}
                    error={errors.wholesale_price}
                    required={scope === 'product'}
                >
                    {(field) => (
                        <MoneyField
                            {...field}
                            name="wholesale_price"
                            value={wholesale}
                            onChange={setWholesale}
                        />
                    )}
                </FormField>
            )}

            {percentage && preview && (
                <p className="text-muted-foreground text-sm tabular-nums sm:col-span-2">
                    <span className="sr-only">
                        {t('catalog.pricing.preview_label')}:{' '}
                    </span>
                    {t('catalog.pricing.preview', {
                        base: preview.base_cost.formatted,
                        percent: preview.markup_percentage,
                        result: preview.wholesale_price.formatted,
                    })}
                </p>
            )}
        </>
    );
}
