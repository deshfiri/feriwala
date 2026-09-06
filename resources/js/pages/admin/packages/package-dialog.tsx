import { Form } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import PackageController from '@/actions/App/Http/Controllers/Admin/PackageController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import type {
    PackageChargeRow,
    PackageFeatureDefinition,
    PackageRow,
} from '@/types';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Null creates; a row edits it. */
    row: PackageRow | null;
    features: PackageFeatureDefinition[];
    chargeTypes: string[];
    frequencies: string[];
};

/**
 * Create or edit one package (§8.1).
 *
 * Prices are entered and posted in **minor units**. A decimal field would be
 * the one place a float could enter a system that has kept money integer
 * everywhere else (D4, §36.1), and the label says so rather than leaving an
 * administrator to discover it by entering 500 and selling a package for ৳5.
 *
 * Entitlements and charges are edited here rather than on screens of their own:
 * what a package grants is what a package *is*, and splitting them lets someone
 * save limits against a plan they have not saved yet.
 */
export default function PackageDialog({
    open,
    onOpenChange,
    row,
    features,
    chargeTypes,
    frequencies,
}: Props) {
    const { t } = useTranslation();
    const [charges, setCharges] = useState<PackageChargeRow[]>([]);

    // Re-seeded whenever the dialog opens on a different row, so editing one
    // package never shows another one's charges.
    useEffect(() => {
        if (open) {
            setCharges(row?.charges ?? []);
        }
    }, [open, row]);

    const submit = row
        ? PackageController.update.form(row.id)
        : PackageController.store.form();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {row
                            ? t('package.form.edit_title')
                            : t('package.form.create_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('package.form.description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...submit}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                    className="space-y-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    name="name"
                                    label={t('package.form.name')}
                                    defaultValue={row?.name ?? ''}
                                    error={errors.name}
                                    required
                                />
                                <Field
                                    name="slug"
                                    label={t('package.form.slug')}
                                    help={t('package.form.slug_help')}
                                    defaultValue={row?.id ?? ''}
                                    error={errors.slug}
                                    required
                                />
                            </div>

                            <Field
                                name="short_description"
                                label={t('package.form.short_description')}
                                defaultValue={row?.short_description ?? ''}
                                error={errors.short_description}
                            />

                            <div className="grid gap-2">
                                <Label htmlFor="description">
                                    {t('package.form.full_description')}
                                </Label>
                                <textarea
                                    id="description"
                                    name="description"
                                    rows={3}
                                    defaultValue={row?.description ?? ''}
                                    className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                />
                                <InputError message={errors.description} />
                            </div>

                            <Section title={t('package.form.pricing')}>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field
                                        name="fee_minor"
                                        type="number"
                                        label={t('package.form.fee')}
                                        defaultValue={String(
                                            row?.fee_minor ?? 0,
                                        )}
                                        error={errors.fee_minor}
                                        required
                                    />
                                    <Field
                                        name="registration_fee_minor"
                                        type="number"
                                        label={t(
                                            'package.form.registration_fee',
                                        )}
                                        help={t(
                                            'package.form.registration_fee_help',
                                        )}
                                        defaultValue={
                                            row?.registration_fee_minor === null
                                                ? ''
                                                : String(
                                                      row?.registration_fee_minor ??
                                                          '',
                                                  )
                                        }
                                        error={errors.registration_fee_minor}
                                    />
                                    <Field
                                        name="renewal_fee_minor"
                                        type="number"
                                        label={t('package.form.renewal_fee')}
                                        defaultValue={
                                            row?.renewal_fee_minor === null
                                                ? ''
                                                : String(
                                                      row?.renewal_fee_minor ??
                                                          '',
                                                  )
                                        }
                                        error={errors.renewal_fee_minor}
                                    />

                                    <div className="grid gap-2">
                                        <Label htmlFor="renewal_frequency">
                                            {t(
                                                'package.form.renewal_frequency',
                                            )}
                                        </Label>
                                        <select
                                            id="renewal_frequency"
                                            name="renewal_frequency"
                                            defaultValue={
                                                row?.renewal_frequency ?? ''
                                            }
                                            className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                        >
                                            <option value="" />
                                            {frequencies.map((value) => (
                                                <option
                                                    key={value}
                                                    value={value}
                                                >
                                                    {t(
                                                        `package.frequency.${value}`,
                                                    )}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={errors.renewal_frequency}
                                        />
                                    </div>

                                    <Field
                                        name="validity_days"
                                        type="number"
                                        label={t('package.form.validity_days')}
                                        defaultValue={
                                            row?.validity_days === null
                                                ? ''
                                                : String(
                                                      row?.validity_days ?? '',
                                                  )
                                        }
                                        error={errors.validity_days}
                                    />
                                    <Field
                                        name="grace_period_days"
                                        type="number"
                                        label={t(
                                            'package.form.grace_period_days',
                                        )}
                                        defaultValue={
                                            row?.grace_period_days === null
                                                ? ''
                                                : String(
                                                      row?.grace_period_days ??
                                                          '',
                                                  )
                                        }
                                        error={errors.grace_period_days}
                                    />
                                    <Field
                                        name="currency_code"
                                        label={t('package.form.currency_code')}
                                        defaultValue={
                                            row?.currency_code ?? 'BDT'
                                        }
                                        error={errors.currency_code}
                                        required
                                    />
                                </div>
                            </Section>

                            <Section title={t('package.form.wallet')}>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field
                                        name="required_deposit_minor"
                                        type="number"
                                        label={t(
                                            'package.form.required_deposit',
                                        )}
                                        defaultValue={String(
                                            row?.required_deposit_minor ?? 0,
                                        )}
                                        error={errors.required_deposit_minor}
                                    />
                                    <Field
                                        name="minimum_balance_minor"
                                        type="number"
                                        label={t(
                                            'package.form.minimum_balance',
                                        )}
                                        defaultValue={String(
                                            row?.minimum_balance_minor ?? 0,
                                        )}
                                        error={errors.minimum_balance_minor}
                                    />
                                </div>
                            </Section>

                            <Section title={t('package.form.availability')}>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field
                                        name="available_from"
                                        type="date"
                                        label={t('package.form.available_from')}
                                        defaultValue={
                                            row?.available_from?.slice(0, 10) ??
                                            ''
                                        }
                                        error={errors.available_from}
                                    />
                                    <Field
                                        name="available_until"
                                        type="date"
                                        label={t(
                                            'package.form.available_until',
                                        )}
                                        defaultValue={
                                            row?.available_until?.slice(
                                                0,
                                                10,
                                            ) ?? ''
                                        }
                                        error={errors.available_until}
                                    />
                                </div>

                                <BooleanField
                                    name="is_active"
                                    label={t('package.form.is_active')}
                                    defaultChecked={row?.is_active ?? true}
                                />
                                <BooleanField
                                    name="is_public"
                                    label={t('package.form.is_public')}
                                    defaultChecked={row?.is_public ?? true}
                                />
                            </Section>

                            <Section
                                title={t('package.form.features')}
                                help={t('package.form.features_help')}
                            >
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {features.map((feature) => (
                                        <FeatureField
                                            key={feature.key}
                                            feature={feature}
                                            value={
                                                row?.features[feature.key] ??
                                                null
                                            }
                                            error={
                                                errors[
                                                    `features.${feature.key}`
                                                ]
                                            }
                                        />
                                    ))}
                                </div>
                            </Section>

                            <Section
                                title={t('package.form.charges')}
                                help={t('package.form.charges_help')}
                            >
                                <ChargeEditor
                                    charges={charges}
                                    onChange={setCharges}
                                    chargeTypes={chargeTypes}
                                    frequencies={frequencies}
                                />
                            </Section>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => onOpenChange(false)}
                                >
                                    {t('package.actions.cancel')}
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {t('package.actions.save')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function Section({
    title,
    help,
    children,
}: {
    title: string;
    help?: string;
    children: React.ReactNode;
}) {
    return (
        <fieldset className="space-y-3 rounded-lg border p-3">
            <legend className="px-1 text-sm font-medium">{title}</legend>
            {help && <p className="text-muted-foreground text-xs">{help}</p>}
            {children}
        </fieldset>
    );
}

function Field({
    name,
    label,
    help,
    error,
    type = 'text',
    defaultValue,
    required,
}: {
    name: string;
    label: string;
    help?: string;
    error?: string;
    type?: string;
    defaultValue: string;
    required?: boolean;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={name}>{label}</Label>
            <Input
                id={name}
                name={name}
                type={type}
                defaultValue={defaultValue}
                required={required}
                autoComplete="off"
            />
            {help && <p className="text-muted-foreground text-xs">{help}</p>}
            <InputError message={error} />
        </div>
    );
}

/**
 * A boolean posts something when unticked, or a `required` rule fails on an
 * intentional "no".
 */
function BooleanField({
    name,
    label,
    defaultChecked,
}: {
    name: string;
    label: string;
    defaultChecked: boolean;
}) {
    const [checked, setChecked] = useState(defaultChecked);

    useEffect(() => setChecked(defaultChecked), [defaultChecked]);

    return (
        <div className="flex items-center gap-2">
            <input type="hidden" name={name} value={checked ? '1' : '0'} />
            <Checkbox
                id={name}
                checked={checked}
                onCheckedChange={(next) => setChecked(next === true)}
            />
            <Label htmlFor={name} className="font-normal">
                {label}
            </Label>
        </div>
    );
}

/**
 * One entitlement, shaped by its type.
 *
 * A limit left blank is **unlimited** and zero is **none at all** — §8.1 keeps
 * those apart, so the field must too. A number input that coerced blank to zero
 * would silently turn an unlimited plan into one that grants nothing.
 */
function FeatureField({
    feature,
    value,
    error,
}: {
    feature: PackageFeatureDefinition;
    value: string | null;
    error?: string;
}) {
    const { t } = useTranslation();
    const name = `features[${feature.key}]`;

    if (feature.type === 'boolean') {
        return (
            <div className="grid gap-1">
                <Label htmlFor={name} className="text-xs">
                    {feature.label}
                </Label>
                <select
                    id={name}
                    name={name}
                    defaultValue={value ?? ''}
                    className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                >
                    <option value="0">{t('package.features.no')}</option>
                    <option value="1">{t('package.features.yes')}</option>
                </select>
                <InputError message={error} />
            </div>
        );
    }

    return (
        <div className="grid gap-1">
            <Label htmlFor={name} className="text-xs">
                {feature.label}
            </Label>
            <Input
                id={name}
                name={name}
                type={feature.type === 'limit' ? 'number' : 'text'}
                min={feature.type === 'limit' ? 0 : undefined}
                defaultValue={value ?? ''}
                placeholder={
                    feature.type === 'limit'
                        ? t('package.features.unlimited')
                        : undefined
                }
            />
            <InputError message={error} />
        </div>
    );
}

function ChargeEditor({
    charges,
    onChange,
    chargeTypes,
    frequencies,
}: {
    charges: PackageChargeRow[];
    onChange: (charges: PackageChargeRow[]) => void;
    chargeTypes: string[];
    frequencies: string[];
}) {
    const { t } = useTranslation();

    const update = (index: number, patch: Partial<PackageChargeRow>) =>
        onChange(
            charges.map((charge, i) =>
                i === index ? { ...charge, ...patch } : charge,
            ),
        );

    return (
        <div className="space-y-2">
            {charges.map((charge, index) => (
                <div
                    key={index}
                    className="grid items-end gap-2 sm:grid-cols-[1fr_1fr_1fr_auto]"
                >
                    <input
                        type="hidden"
                        name={`charges[${index}][charge_type]`}
                        value={charge.charge_type}
                    />
                    <input
                        type="hidden"
                        name={`charges[${index}][amount_minor]`}
                        value={charge.amount_minor}
                    />
                    <input
                        type="hidden"
                        name={`charges[${index}][frequency]`}
                        value={charge.frequency}
                    />

                    <div className="grid gap-1">
                        <Label
                            htmlFor={`charge-type-${index}`}
                            className="text-xs"
                        >
                            {t('package.form.charge_type')}
                        </Label>
                        <select
                            id={`charge-type-${index}`}
                            value={charge.charge_type}
                            onChange={(event) =>
                                update(index, {
                                    charge_type: event.target.value,
                                })
                            }
                            className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        >
                            {chargeTypes.map((value) => (
                                <option key={value} value={value}>
                                    {t(`package.charge_type.${value}`)}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="grid gap-1">
                        <Label
                            htmlFor={`charge-amount-${index}`}
                            className="text-xs"
                        >
                            {t('package.form.charge_amount')}
                        </Label>
                        <Input
                            id={`charge-amount-${index}`}
                            type="number"
                            min={0}
                            value={charge.amount_minor}
                            onChange={(event) =>
                                update(index, {
                                    amount_minor: Number(event.target.value),
                                })
                            }
                        />
                    </div>

                    <div className="grid gap-1">
                        <Label
                            htmlFor={`charge-frequency-${index}`}
                            className="text-xs"
                        >
                            {t('package.form.charge_frequency')}
                        </Label>
                        <select
                            id={`charge-frequency-${index}`}
                            value={charge.frequency}
                            onChange={(event) =>
                                update(index, { frequency: event.target.value })
                            }
                            className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        >
                            {frequencies.map((value) => (
                                <option key={value} value={value}>
                                    {t(`package.frequency.${value}`)}
                                </option>
                            ))}
                        </select>
                    </div>

                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            onChange(charges.filter((_, i) => i !== index))
                        }
                    >
                        <X className="size-4" />
                        {t('package.form.remove_charge')}
                    </Button>
                </div>
            ))}

            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() =>
                    onChange([
                        ...charges,
                        {
                            charge_type: chargeTypes[0] ?? 'website_setup',
                            amount_minor: 0,
                            frequency: 'once',
                        },
                    ])
                }
            >
                <Plus className="size-4" />
                {t('package.form.add_charge')}
            </Button>
        </div>
    );
}
