import { Form } from '@inertiajs/react';
import { useState } from 'react';
import BillingController from '@/actions/App/Http/Controllers/Admin/BillingController';
import InputError from '@/components/input-error';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';

export type TaxRateRow = {
    id: string;
    code: string;
    name: string;
    percent: string;
    effective_from: string;
    effective_until: string | null;
    is_active: boolean;
    in_force: boolean;
};

export type TaxRuleRow = {
    id: string;
    scope: string;
    scope_label: string;
    scope_value: string | null;
    tax_code: string;
    mode: string;
    mode_label: string;
    priority: number;
    /** What the rule charges today, or null when its code has no rate in force. */
    rate: string | null;
    effective_from: string;
    effective_until: string | null;
    is_active: boolean;
    in_force: boolean;
    note: string | null;
};

type Option = { value: string; label: string };

const selectClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Tax rates and the rules that point at them (D19).
 *
 * Two panels because they are two decisions. A rate is what a code is worth on
 * a date; a rule is what that code applies to. Merging them into one row would
 * make "VAT went from 15% to 12%" look like an edit to every rule that names it.
 *
 * A rule whose code has no rate in force is called out rather than left looking
 * healthy. It charges nothing, which is the right answer under D19 — no rate is
 * ever assumed — but an administrator has to be able to tell that apart from a
 * rule that is working.
 */
export default function BillingTax({
    rates,
    rules,
    scopes,
    modes,
    taxableFees,
    canManage,
}: {
    rates: TaxRateRow[];
    rules: TaxRuleRow[];
    scopes: (Option & { requires_value: boolean })[];
    modes: Option[];
    taxableFees: Option[];
    canManage: boolean;
}) {
    const { t, locale } = useTranslation();
    const [addingRate, setAddingRate] = useState(false);
    const [addingRule, setAddingRule] = useState(false);

    const date = (value: string | null) =>
        value === null ? '—' : new Date(value).toLocaleDateString(locale);

    const state = (row: { is_active: boolean; in_force: boolean }) => {
        if (row.in_force) {
            return {
                tone: 'success' as const,
                label: t('billing.tax.in_force'),
            };
        }

        return row.is_active
            ? { tone: 'info' as const, label: t('billing.tax.scheduled') }
            : { tone: 'neutral' as const, label: t('billing.tax.ended') };
    };

    return (
        <>
            <SectionCard
                title={t('billing.tax.rates_title')}
                description={t('billing.tax.rates_description')}
                actions={
                    canManage ? (
                        <Button
                            variant="secondary"
                            size="sm"
                            onClick={() => setAddingRate((open) => !open)}
                        >
                            {t('billing.tax.add_rate')}
                        </Button>
                    ) : undefined
                }
                contentClassName="p-0"
            >
                {rates.length === 0 ? (
                    <p className="text-muted-foreground px-5 py-4 text-sm">
                        {t('billing.tax.rates_empty')}
                    </p>
                ) : (
                    <ul className="divide-border divide-y text-sm">
                        {rates.map((rate) => (
                            <li
                                key={rate.id}
                                className="flex flex-wrap items-center justify-between gap-3 px-5 py-3"
                            >
                                <div className="min-w-0">
                                    <p className="font-medium">
                                        <span className="font-mono">
                                            {rate.code}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {' · '}
                                            {rate.name}
                                        </span>
                                    </p>
                                    <p className="text-muted-foreground text-xs">
                                        {date(rate.effective_from)} —{' '}
                                        {date(rate.effective_until)}
                                    </p>
                                </div>

                                <div className="flex items-center gap-3">
                                    <span className="font-medium tabular-nums">
                                        {rate.percent}
                                    </span>

                                    <StatusPill
                                        tone={state(rate).tone}
                                        label={state(rate).label}
                                    />

                                    {canManage && rate.is_active && (
                                        <Form
                                            {...BillingController.closeTaxRate.form(
                                                rate.id,
                                            )}
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={processing}
                                                >
                                                    {t('billing.tax.close')}
                                                </Button>
                                            )}
                                        </Form>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                {canManage && addingRate && (
                    <Form
                        {...BillingController.storeTaxRate.form()}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setAddingRate(false)}
                        resetOnSuccess
                        className="border-border space-y-4 border-t p-5"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="tax-rate-code">
                                            {t('billing.tax.code')}
                                        </Label>
                                        <Input
                                            id="tax-rate-code"
                                            name="code"
                                            required
                                            maxLength={64}
                                            className="font-mono"
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            {t('billing.tax.code_help')}
                                        </p>
                                        <InputError message={errors.code} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="tax-rate-name">
                                            {t('billing.tax.name')}
                                        </Label>
                                        <Input
                                            id="tax-rate-name"
                                            name="name"
                                            required
                                        />
                                        <InputError message={errors.name} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="tax-rate-bp">
                                            {t('billing.tax.basis_points')}
                                        </Label>
                                        <Input
                                            id="tax-rate-bp"
                                            name="rate_basis_points"
                                            type="number"
                                            min={0}
                                            max={10000}
                                            required
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            {t('billing.tax.basis_points_help')}
                                        </p>
                                        <InputError
                                            message={errors.rate_basis_points}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="tax-rate-from">
                                            {t('billing.tax.effective_from')}
                                        </Label>
                                        <Input
                                            id="tax-rate-from"
                                            name="effective_from"
                                            type="date"
                                            required
                                        />
                                        <InputError
                                            message={errors.effective_from}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="tax-rate-until">
                                            {t('billing.tax.effective_until')}
                                        </Label>
                                        <Input
                                            id="tax-rate-until"
                                            name="effective_until"
                                            type="date"
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            {t(
                                                'billing.tax.effective_until_help',
                                            )}
                                        </p>
                                        <InputError
                                            message={errors.effective_until}
                                        />
                                    </div>
                                </div>

                                <Button type="submit" disabled={processing}>
                                    {t('billing.tax.submit_rate')}
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </SectionCard>

            <SectionCard
                title={t('billing.tax.rules_title')}
                description={t('billing.tax.rules_description')}
                actions={
                    canManage ? (
                        <Button
                            variant="secondary"
                            size="sm"
                            onClick={() => setAddingRule((open) => !open)}
                        >
                            {t('billing.tax.add_rule')}
                        </Button>
                    ) : undefined
                }
                contentClassName="p-0"
            >
                {rules.length === 0 ? (
                    <p className="text-muted-foreground px-5 py-4 text-sm">
                        {t('billing.tax.rules_empty')}
                    </p>
                ) : (
                    <ul className="divide-border divide-y text-sm">
                        {rules.map((rule) => (
                            <li
                                key={rule.id}
                                className="flex flex-wrap items-center justify-between gap-3 px-5 py-3"
                            >
                                <div className="min-w-0">
                                    <p className="font-medium">
                                        {rule.scope_label}
                                        {rule.scope_value !== null && (
                                            <span className="text-muted-foreground">
                                                {' · '}
                                                {rule.scope_value}
                                            </span>
                                        )}
                                    </p>
                                    <p className="text-muted-foreground text-xs">
                                        <span className="font-mono">
                                            {rule.tax_code}
                                        </span>
                                        {' · '}
                                        {rule.mode_label}
                                        {' · '}
                                        {t('billing.tax.priority')}{' '}
                                        {rule.priority}
                                    </p>
                                    <p className="text-muted-foreground text-xs">
                                        {date(rule.effective_from)} —{' '}
                                        {date(rule.effective_until)}
                                    </p>
                                    {rule.note !== null && (
                                        <p className="text-muted-foreground text-xs">
                                            {rule.note}
                                        </p>
                                    )}
                                    {/* A rule taxing nothing looks exactly like
                                        one that works unless it is said. */}
                                    {rule.rate === null && rule.in_force && (
                                        <p className="text-danger text-xs font-medium">
                                            {t('billing.tax.no_rate_help')}
                                        </p>
                                    )}
                                </div>

                                <div className="flex items-center gap-3">
                                    <span className="font-medium tabular-nums">
                                        {rule.rate ?? t('billing.tax.no_rate')}
                                    </span>

                                    <StatusPill
                                        tone={state(rule).tone}
                                        label={state(rule).label}
                                    />

                                    {canManage && rule.is_active && (
                                        <Form
                                            {...BillingController.closeTaxRule.form(
                                                rule.id,
                                            )}
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={processing}
                                                >
                                                    {t('billing.tax.close')}
                                                </Button>
                                            )}
                                        </Form>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                {canManage && addingRule && (
                    <Form
                        {...BillingController.storeTaxRule.form()}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setAddingRule(false)}
                        resetOnSuccess
                        className="border-border space-y-4 border-t p-5"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="tax-rule-scope">
                                            {t('billing.tax.scope')}
                                        </Label>
                                        <select
                                            id="tax-rule-scope"
                                            name="scope"
                                            required
                                            className={selectClass}
                                        >
                                            {scopes.map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError message={errors.scope} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="tax-rule-value">
                                            {t('billing.tax.scope_value')}
                                        </Label>
                                        <select
                                            id="tax-rule-value"
                                            name="scope_value"
                                            className={selectClass}
                                        >
                                            <option value="">—</option>
                                            {taxableFees.map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                        <p className="text-muted-foreground text-xs">
                                            {t('billing.tax.scope_value_help')}
                                        </p>
                                        <InputError
                                            message={errors.scope_value}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="tax-rule-code">
                                            {t('billing.tax.code')}
                                        </Label>
                                        <select
                                            id="tax-rule-code"
                                            name="tax_code"
                                            required
                                            className={selectClass}
                                        >
                                            {[
                                                ...new Set(
                                                    rates.map(
                                                        (rate) => rate.code,
                                                    ),
                                                ),
                                            ].map((code) => (
                                                <option key={code} value={code}>
                                                    {code}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError message={errors.tax_code} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="tax-rule-mode">
                                            {t('billing.tax.mode')}
                                        </Label>
                                        <select
                                            id="tax-rule-mode"
                                            name="mode"
                                            required
                                            className={selectClass}
                                        >
                                            {modes.map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="tax-rule-priority">
                                            {t('billing.tax.priority')}
                                        </Label>
                                        <Input
                                            id="tax-rule-priority"
                                            name="priority"
                                            type="number"
                                            min={0}
                                            defaultValue={0}
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            {t('billing.tax.priority_help')}
                                        </p>
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="tax-rule-from">
                                            {t('billing.tax.effective_from')}
                                        </Label>
                                        <Input
                                            id="tax-rule-from"
                                            name="effective_from"
                                            type="date"
                                            required
                                        />
                                        <InputError
                                            message={errors.effective_from}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="tax-rule-until">
                                            {t('billing.tax.effective_until')}
                                        </Label>
                                        <Input
                                            id="tax-rule-until"
                                            name="effective_until"
                                            type="date"
                                        />
                                        <InputError
                                            message={errors.effective_until}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="tax-rule-note">
                                            {t('billing.tax.note')}
                                        </Label>
                                        <Input
                                            id="tax-rule-note"
                                            name="note"
                                            maxLength={191}
                                        />
                                    </div>
                                </div>

                                <Button
                                    type="submit"
                                    disabled={processing || rates.length === 0}
                                >
                                    {t('billing.tax.submit_rule')}
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </SectionCard>
        </>
    );
}
