import { Form, Head } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import DeliverySettingsController from '@/actions/App/Http/Controllers/Admin/DeliverySettingsController';
import FormField from '@/components/forms/form-field';
import MoneyField from '@/components/forms/money-field';
import SubmitButton from '@/components/forms/submit-button';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';

type Settings = {
    volumetric_divisor: number;
    use_greater_of_actual_and_volumetric: boolean;
    additional_per_kg_charge: Money;
    per_box_charge: Money;
    fragile_handling_charge: Money;
    minimum_charge: Money;
    maximum_charge: Money | null;
    free_delivery_threshold: Money | null;
    delivery_success_fee_percent: string;
};

type RuleRow = {
    id: string;
    weight_from_grams: number;
    weight_to_grams: number | null;
    base_charge: Money;
    per_kg_charge: Money | null;
    area: string | null;
    courier_provider: string | null;
    priority: number;
    effective_from: string;
    effective_until: string | null;
    is_active: boolean;
    note: string | null;
};

type Props = {
    settings: Settings;
    rules: RuleRow[];
    courier_providers: { id: number; label: string }[];
    can: { edit: boolean; manage_settings: boolean };
};

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none disabled:opacity-60';

/**
 * Delivery-charge configuration (beta-critical batch, Commit 2): the global
 * knobs the calculator applies, and the dated weight-tier rules it resolves
 * against. A rule is never edited here, only created or closed -- the same
 * append-only discipline every dated rule in this application follows.
 */
export default function DeliverySettingsIndex({
    settings,
    rules,
    courier_providers: courierProviders,
    can,
}: Props) {
    const { t, locale } = useTranslation();
    const [creating, setCreating] = useState(false);

    return (
        <>
            <Head title={t('delivery_settings.admin.title')} />

            <PageContainer>
                <PageHeader
                    title={t('delivery_settings.admin.title')}
                    description={t('delivery_settings.admin.description')}
                />

                <SectionCard
                    title={t('delivery_settings.admin.settings_title')}
                >
                    <Form
                        {...DeliverySettingsController.update.form()}
                        options={{ preserveScroll: true }}
                        className="space-y-4"
                    >
                        {({ errors, processing }) => (
                            <fieldset
                                disabled={!can.manage_settings}
                                className="space-y-4"
                            >
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <FormField
                                        label={t(
                                            'delivery_settings.admin.volumetric_divisor',
                                        )}
                                        hint={t(
                                            'delivery_settings.admin.volumetric_divisor_help',
                                        )}
                                        error={errors.volumetric_divisor}
                                        required
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="volumetric_divisor"
                                                type="number"
                                                inputMode="numeric"
                                                min={1}
                                                step={1}
                                                className="tabular-nums"
                                                defaultValue={
                                                    settings.volumetric_divisor
                                                }
                                            />
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t(
                                            'delivery_settings.admin.use_greater',
                                        )}
                                        error={
                                            errors.use_greater_of_actual_and_volumetric
                                        }
                                    >
                                        {(field) => (
                                            <select
                                                {...field}
                                                name="use_greater_of_actual_and_volumetric"
                                                className={selectClass}
                                                defaultValue={
                                                    settings.use_greater_of_actual_and_volumetric
                                                        ? '1'
                                                        : '0'
                                                }
                                            >
                                                <option value="1">
                                                    {t('common.yes')}
                                                </option>
                                                <option value="0">
                                                    {t('common.no')}
                                                </option>
                                            </select>
                                        )}
                                    </FormField>
                                </div>

                                <div className="grid gap-4 sm:grid-cols-3">
                                    <FormField
                                        label={t(
                                            'delivery_settings.admin.additional_per_kg_charge',
                                        )}
                                        error={errors.additional_per_kg_charge}
                                        required
                                    >
                                        {(field) => (
                                            <MoneyField
                                                {...field}
                                                name="additional_per_kg_charge"
                                                defaultValue={
                                                    settings
                                                        .additional_per_kg_charge
                                                        .amount
                                                }
                                            />
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t(
                                            'delivery_settings.admin.per_box_charge',
                                        )}
                                        error={errors.per_box_charge}
                                        required
                                    >
                                        {(field) => (
                                            <MoneyField
                                                {...field}
                                                name="per_box_charge"
                                                defaultValue={
                                                    settings.per_box_charge
                                                        .amount
                                                }
                                            />
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t(
                                            'delivery_settings.admin.fragile_handling_charge',
                                        )}
                                        error={errors.fragile_handling_charge}
                                        required
                                    >
                                        {(field) => (
                                            <MoneyField
                                                {...field}
                                                name="fragile_handling_charge"
                                                defaultValue={
                                                    settings
                                                        .fragile_handling_charge
                                                        .amount
                                                }
                                            />
                                        )}
                                    </FormField>
                                </div>

                                <div className="grid gap-4 sm:grid-cols-3">
                                    <FormField
                                        label={t(
                                            'delivery_settings.admin.minimum_charge',
                                        )}
                                        error={errors.minimum_charge}
                                        required
                                    >
                                        {(field) => (
                                            <MoneyField
                                                {...field}
                                                name="minimum_charge"
                                                defaultValue={
                                                    settings.minimum_charge
                                                        .amount
                                                }
                                            />
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t(
                                            'delivery_settings.admin.maximum_charge',
                                        )}
                                        hint={t(
                                            'delivery_settings.admin.maximum_charge_help',
                                        )}
                                        error={errors.maximum_charge}
                                    >
                                        {(field) => (
                                            <MoneyField
                                                {...field}
                                                name="maximum_charge"
                                                defaultValue={
                                                    settings.maximum_charge
                                                        ?.amount
                                                }
                                            />
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t(
                                            'delivery_settings.admin.free_delivery_threshold',
                                        )}
                                        hint={t(
                                            'delivery_settings.admin.free_delivery_threshold_help',
                                        )}
                                        error={errors.free_delivery_threshold}
                                    >
                                        {(field) => (
                                            <MoneyField
                                                {...field}
                                                name="free_delivery_threshold"
                                                defaultValue={
                                                    settings
                                                        .free_delivery_threshold
                                                        ?.amount
                                                }
                                            />
                                        )}
                                    </FormField>
                                </div>

                                <div className="grid gap-4 sm:grid-cols-3">
                                    <FormField
                                        label={t(
                                            'delivery_settings.admin.delivery_success_fee_percent',
                                        )}
                                        hint={t(
                                            'delivery_settings.admin.delivery_success_fee_percent_help',
                                        )}
                                        error={
                                            errors.delivery_success_fee_percent
                                        }
                                        required
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="delivery_success_fee_percent"
                                                type="number"
                                                inputMode="decimal"
                                                min={0}
                                                max={100}
                                                step="0.01"
                                                className="tabular-nums"
                                                defaultValue={
                                                    settings.delivery_success_fee_percent
                                                }
                                            />
                                        )}
                                    </FormField>
                                </div>

                                {can.manage_settings && (
                                    <div className="flex justify-end">
                                        <SubmitButton processing={processing}>
                                            {t('common.actions.save')}
                                        </SubmitButton>
                                    </div>
                                )}
                            </fieldset>
                        )}
                    </Form>
                </SectionCard>

                <SectionCard
                    title={t('delivery_settings.admin.rules_title')}
                    description={t('delivery_settings.admin.rules_description')}
                    actions={
                        can.edit ? (
                            <Button size="sm" onClick={() => setCreating(true)}>
                                <Plus className="size-4" aria-hidden="true" />
                                {t('delivery_settings.admin.add_rule')}
                            </Button>
                        ) : undefined
                    }
                    contentClassName="p-0"
                >
                    {rules.length === 0 ? (
                        <p className="text-muted-foreground p-5 text-sm">
                            {t('delivery_settings.admin.no_rules')}
                        </p>
                    ) : (
                        <ul className="divide-border divide-y">
                            {rules.map((rule) => (
                                <li
                                    key={rule.id}
                                    className="flex flex-wrap items-center justify-between gap-3 px-5 py-3"
                                >
                                    <div className="min-w-0 space-y-0.5">
                                        <p className="text-sm font-medium tabular-nums">
                                            {rule.weight_from_grams}
                                            {' – '}
                                            {rule.weight_to_grams ?? '∞'}
                                            {' g'}
                                        </p>
                                        <p className="text-muted-foreground text-xs">
                                            {rule.area || rule.courier_provider
                                                ? [
                                                      rule.area,
                                                      rule.courier_provider,
                                                  ]
                                                      .filter(Boolean)
                                                      .join(' · ')
                                                : t(
                                                      'delivery_settings.admin.general_scope',
                                                  )}
                                            {' · '}
                                            {new Date(
                                                rule.effective_from,
                                            ).toLocaleDateString(locale)}
                                            {rule.effective_until &&
                                                ` – ${new Date(rule.effective_until).toLocaleDateString(locale)}`}
                                        </p>
                                    </div>

                                    <div className="flex items-center gap-3">
                                        <div className="text-right">
                                            <MoneyAmount
                                                amount={rule.base_charge}
                                            />
                                            {rule.per_kg_charge && (
                                                <p className="text-muted-foreground text-xs">
                                                    +
                                                    {
                                                        rule.per_kg_charge
                                                            .formatted
                                                    }
                                                    /kg
                                                </p>
                                            )}
                                        </div>

                                        <StatusPill
                                            tone={
                                                rule.is_active
                                                    ? 'success'
                                                    : 'neutral'
                                            }
                                            label={t(
                                                rule.is_active
                                                    ? 'delivery_settings.admin.active'
                                                    : 'delivery_settings.admin.closed',
                                            )}
                                        />

                                        {can.edit && rule.is_active && (
                                            <Form
                                                {...DeliverySettingsController.closeRule.form(
                                                    rule.id,
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                            >
                                                {({ processing }) => (
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        variant="ghost"
                                                        disabled={processing}
                                                    >
                                                        {t(
                                                            'delivery_settings.admin.close',
                                                        )}
                                                    </Button>
                                                )}
                                            </Form>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </PageContainer>

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {t('delivery_settings.admin.add_rule')}
                        </DialogTitle>
                    </DialogHeader>

                    <Form
                        {...DeliverySettingsController.storeRule.form()}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setCreating(false)}
                        className="space-y-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid grid-cols-2 gap-4">
                                    <FormField
                                        label={t(
                                            'delivery_settings.admin.weight_from_grams',
                                        )}
                                        error={errors.weight_from_grams}
                                        required
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="weight_from_grams"
                                                type="number"
                                                inputMode="numeric"
                                                min={0}
                                                step={1}
                                                defaultValue={0}
                                            />
                                        )}
                                    </FormField>
                                    <FormField
                                        label={t(
                                            'delivery_settings.admin.weight_to_grams',
                                        )}
                                        hint={t(
                                            'delivery_settings.admin.weight_to_grams_help',
                                        )}
                                        error={errors.weight_to_grams}
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="weight_to_grams"
                                                type="number"
                                                inputMode="numeric"
                                                min={1}
                                                step={1}
                                            />
                                        )}
                                    </FormField>
                                </div>

                                <div className="grid grid-cols-2 gap-4">
                                    <FormField
                                        label={t(
                                            'delivery_settings.admin.base_charge',
                                        )}
                                        error={errors.base_charge}
                                        required
                                    >
                                        {(field) => (
                                            <MoneyField
                                                {...field}
                                                name="base_charge"
                                            />
                                        )}
                                    </FormField>
                                    <FormField
                                        label={t(
                                            'delivery_settings.admin.per_kg_charge_override',
                                        )}
                                        hint={t(
                                            'delivery_settings.admin.per_kg_charge_override_help',
                                        )}
                                        error={errors.per_kg_charge}
                                    >
                                        {(field) => (
                                            <MoneyField
                                                {...field}
                                                name="per_kg_charge"
                                            />
                                        )}
                                    </FormField>
                                </div>

                                <div className="grid grid-cols-2 gap-4">
                                    <FormField
                                        label={t(
                                            'delivery_settings.admin.area',
                                        )}
                                        hint={t(
                                            'delivery_settings.admin.area_help',
                                        )}
                                        error={errors.area}
                                    >
                                        {(field) => (
                                            <Input {...field} name="area" />
                                        )}
                                    </FormField>
                                    <FormField
                                        label={t(
                                            'delivery_settings.admin.courier_provider',
                                        )}
                                        error={errors.courier_provider_id}
                                    >
                                        {(field) => (
                                            <select
                                                {...field}
                                                name="courier_provider_id"
                                                className={selectClass}
                                                defaultValue=""
                                            >
                                                <option value="">
                                                    {t(
                                                        'delivery_settings.admin.general_scope',
                                                    )}
                                                </option>
                                                {courierProviders.map(
                                                    (provider) => (
                                                        <option
                                                            key={provider.id}
                                                            value={provider.id}
                                                        >
                                                            {provider.label}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        )}
                                    </FormField>
                                </div>

                                <FormField
                                    label={t(
                                        'delivery_settings.admin.effective_from',
                                    )}
                                    error={errors.effective_from}
                                    required
                                >
                                    {(field) => (
                                        <Input
                                            {...field}
                                            name="effective_from"
                                            type="datetime-local"
                                            defaultValue={new Date()
                                                .toISOString()
                                                .slice(0, 16)}
                                        />
                                    )}
                                </FormField>

                                <FormField
                                    label={t('delivery_settings.admin.note')}
                                    error={errors.note}
                                >
                                    {(field) => (
                                        <Input {...field} name="note" />
                                    )}
                                </FormField>

                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => setCreating(false)}
                                    >
                                        {t('common.actions.cancel')}
                                    </Button>
                                    <SubmitButton processing={processing}>
                                        {t('delivery_settings.admin.add_rule')}
                                    </SubmitButton>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </>
    );
}
