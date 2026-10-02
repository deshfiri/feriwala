import FormField from '@/components/forms/form-field';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import type { ProductLogisticsFields } from '@/types';

type Props = {
    defaultValues: Partial<ProductLogisticsFields>;
    errors: Record<string, string | undefined>;
    /**
     * True on a variant's own form: `ships_by_box`/`is_fragile` get a third
     * "inherit from the product" option, and every field's hint says so
     * rather than claiming a blank value means "none" the way it does on the
     * product's own form.
     */
    allowInherit?: boolean;
};

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none disabled:opacity-60';

/**
 * Physical logistics and packaging, shared between the product form and a
 * variant's own override (beta-critical batch, Commit 1).
 *
 * Weight is entered and shown in whole grams, every dimension in
 * centimetres -- the one canonical unit each is stored in server-side, so
 * what is typed here is exactly what is saved, with no conversion in either
 * direction to silently get wrong.
 */
export default function LogisticsFields({
    defaultValues,
    errors,
    allowInherit = false,
}: Props) {
    const { t } = useTranslation();

    const blankHint = allowInherit
        ? t('catalog.logistics.inherit_hint')
        : undefined;

    const booleanValue = (value: boolean | null | undefined): string =>
        value === true ? '1' : value === false ? '0' : '';

    return (
        <div className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <FormField
                    label={t('catalog.logistics.net_weight_grams')}
                    hint={blankHint}
                    error={errors.net_weight_grams}
                >
                    {(field) => (
                        <Input
                            {...field}
                            name="net_weight_grams"
                            type="number"
                            inputMode="numeric"
                            min={1}
                            step={1}
                            className="tabular-nums"
                            defaultValue={defaultValues.net_weight_grams ?? ''}
                        />
                    )}
                </FormField>

                <FormField
                    label={t('catalog.logistics.shipping_weight_grams')}
                    hint={
                        blankHint ?? t('catalog.logistics.shipping_weight_help')
                    }
                    error={errors.shipping_weight_grams}
                >
                    {(field) => (
                        <Input
                            {...field}
                            name="shipping_weight_grams"
                            type="number"
                            inputMode="numeric"
                            min={1}
                            step={1}
                            className="tabular-nums"
                            defaultValue={
                                defaultValues.shipping_weight_grams ?? ''
                            }
                        />
                    )}
                </FormField>
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
                {(['length_cm', 'width_cm', 'height_cm'] as const).map(
                    (name) => (
                        <FormField
                            key={name}
                            label={t(`catalog.logistics.${name}`)}
                            hint={blankHint}
                            error={errors[name]}
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    name={name}
                                    type="number"
                                    inputMode="decimal"
                                    min={0.01}
                                    step={0.01}
                                    className="tabular-nums"
                                    defaultValue={defaultValues[name] ?? ''}
                                />
                            )}
                        </FormField>
                    ),
                )}
            </div>

            <FormField
                label={t('catalog.logistics.is_fragile')}
                error={errors.is_fragile}
            >
                {(field) => (
                    <select
                        {...field}
                        name="is_fragile"
                        className={selectClass}
                        defaultValue={booleanValue(defaultValues.is_fragile)}
                    >
                        {allowInherit && (
                            <option value="">
                                {t('catalog.logistics.inherit')}
                            </option>
                        )}
                        <option value="1">{t('common.yes')}</option>
                        <option value="0">{t('common.no')}</option>
                    </select>
                )}
            </FormField>

            <fieldset className="space-y-4 rounded-lg border p-3">
                <legend className="px-1 text-sm font-medium">
                    {t('catalog.logistics.box_section')}
                </legend>

                <FormField
                    label={t('catalog.logistics.ships_by_box')}
                    hint={t('catalog.logistics.ships_by_box_help')}
                    error={errors.ships_by_box}
                >
                    {(field) => (
                        <select
                            {...field}
                            name="ships_by_box"
                            className={selectClass}
                            defaultValue={booleanValue(
                                defaultValues.ships_by_box,
                            )}
                        >
                            {allowInherit && (
                                <option value="">
                                    {t('catalog.logistics.inherit')}
                                </option>
                            )}
                            <option value="1">{t('common.yes')}</option>
                            <option value="0">{t('common.no')}</option>
                        </select>
                    )}
                </FormField>

                <div className="grid gap-4 sm:grid-cols-2">
                    <FormField
                        label={t('catalog.logistics.pieces_per_box')}
                        hint={blankHint}
                        error={errors.pieces_per_box}
                    >
                        {(field) => (
                            <Input
                                {...field}
                                name="pieces_per_box"
                                type="number"
                                inputMode="numeric"
                                min={1}
                                step={1}
                                className="tabular-nums"
                                defaultValue={
                                    defaultValues.pieces_per_box ?? ''
                                }
                            />
                        )}
                    </FormField>

                    <FormField
                        label={t('catalog.logistics.box_weight_grams')}
                        hint={blankHint}
                        error={errors.box_weight_grams}
                    >
                        {(field) => (
                            <Input
                                {...field}
                                name="box_weight_grams"
                                type="number"
                                inputMode="numeric"
                                min={1}
                                step={1}
                                className="tabular-nums"
                                defaultValue={
                                    defaultValues.box_weight_grams ?? ''
                                }
                            />
                        )}
                    </FormField>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    {(
                        [
                            'box_length_cm',
                            'box_width_cm',
                            'box_height_cm',
                        ] as const
                    ).map((name) => (
                        <FormField
                            key={name}
                            label={t(`catalog.logistics.${name}`)}
                            hint={blankHint}
                            error={errors[name]}
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    name={name}
                                    type="number"
                                    inputMode="decimal"
                                    min={0.01}
                                    step={0.01}
                                    className="tabular-nums"
                                    defaultValue={defaultValues[name] ?? ''}
                                />
                            )}
                        </FormField>
                    ))}
                </div>
            </fieldset>
        </div>
    );
}
