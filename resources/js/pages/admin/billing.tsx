import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import BillingController from '@/actions/App/Http/Controllers/Admin/BillingController';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';

export type FeeRuleRow = {
    id: string;
    fee_type: string;
    fee_type_label: string;
    package: string | null;
    amount: Money;
    effective_from: string;
    effective_until: string | null;
    is_active: boolean;
    in_force: boolean;
    note: string | null;
};

type Props = {
    fee_rules: FeeRuleRow[];
    packages: { id: string; name: string }[];
    fee_types: { value: string; label: string }[];
    can: { manage: boolean };
};

/**
 * What Feriwala charges (§9).
 *
 * Fee rules are dated rather than edited, and the list says so by showing the
 * window each price applied to. A screen that offered "change the fee" would be
 * offering to rewrite what past quotes were built from.
 *
 * Every amount is entered and displayed in minor units server-side; nothing
 * here computes a total (§36.1).
 */
export default function Billing({
    fee_rules: feeRules,
    packages,
    fee_types: feeTypes,
    can,
}: Props) {
    const { t, locale } = useTranslation();
    const [adding, setAdding] = useState(false);

    const date = (value: string | null) =>
        value === null ? '—' : new Date(value).toLocaleDateString(locale);

    const state = (rule: FeeRuleRow) => {
        if (rule.in_force) {
            return {
                tone: 'success' as const,
                label: t('billing.fees.in_force'),
            };
        }

        return rule.is_active
            ? { tone: 'info' as const, label: t('billing.fees.scheduled') }
            : { tone: 'neutral' as const, label: t('billing.fees.ended') };
    };

    return (
        <>
            <Head title={t('billing.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('billing.title')}
                    description={t('billing.description')}
                />

                <SectionCard
                    title={t('billing.fees.title')}
                    description={t('billing.fees.description')}
                    actions={
                        can.manage ? (
                            <Button
                                variant="secondary"
                                size="sm"
                                onClick={() => setAdding((open) => !open)}
                            >
                                {t('billing.fees.add')}
                            </Button>
                        ) : undefined
                    }
                    contentClassName="p-0"
                >
                    {feeRules.length === 0 ? (
                        <p className="text-muted-foreground px-5 py-4 text-sm">
                            {t('billing.fees.empty')}
                        </p>
                    ) : (
                        <ul className="divide-border divide-y text-sm">
                            {feeRules.map((rule) => (
                                <li
                                    key={rule.id}
                                    className="flex flex-wrap items-center justify-between gap-3 px-5 py-3"
                                >
                                    <div className="min-w-0">
                                        <p className="font-medium">
                                            {rule.fee_type_label}
                                            {rule.package !== null && (
                                                <span className="text-muted-foreground">
                                                    {' · '}
                                                    {rule.package}
                                                </span>
                                            )}
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
                                    </div>

                                    <div className="flex items-center gap-3">
                                        <MoneyAmount amount={rule.amount} />

                                        <StatusPill
                                            tone={state(rule).tone}
                                            label={state(rule).label}
                                        />

                                        {can.manage && rule.is_active && (
                                            <Form
                                                {...BillingController.closeFeeRule.form(
                                                    rule.id,
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                            >
                                                {({ processing }) => (
                                                    <Button
                                                        type="submit"
                                                        variant="ghost"
                                                        size="sm"
                                                        disabled={processing}
                                                    >
                                                        {t(
                                                            'billing.fees.close',
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

                    {can.manage && adding && (
                        <Form
                            {...BillingController.storeFeeRule.form()}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setAdding(false)}
                            resetOnSuccess
                            className="border-border space-y-4 border-t p-5"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="fee-type">
                                                {t('billing.fees.type')}
                                            </Label>
                                            <select
                                                id="fee-type"
                                                name="fee_type"
                                                required
                                                className="border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                            >
                                                {feeTypes.map((type) => (
                                                    <option
                                                        key={type.value}
                                                        value={type.value}
                                                    >
                                                        {type.label}
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError
                                                message={errors.fee_type}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="fee-package">
                                                {t('billing.fees.package')}
                                            </Label>
                                            <select
                                                id="fee-package"
                                                name="package"
                                                className="border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                            >
                                                <option value="">
                                                    {t(
                                                        'billing.fees.package_any',
                                                    )}
                                                </option>
                                                {packages.map((option) => (
                                                    <option
                                                        key={option.id}
                                                        value={option.id}
                                                    >
                                                        {option.name}
                                                    </option>
                                                ))}
                                            </select>
                                            <p className="text-muted-foreground text-xs">
                                                {t('billing.fees.package_help')}
                                            </p>
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="fee-amount">
                                                {t('billing.fees.amount')}
                                            </Label>
                                            <Input
                                                id="fee-amount"
                                                name="amount_minor"
                                                type="number"
                                                min={0}
                                                required
                                            />
                                            <p className="text-muted-foreground text-xs">
                                                {t('billing.fees.amount_help')}
                                            </p>
                                            <InputError
                                                message={errors.amount_minor}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="fee-from">
                                                {t(
                                                    'billing.fees.effective_from',
                                                )}
                                            </Label>
                                            <Input
                                                id="fee-from"
                                                name="effective_from"
                                                type="date"
                                                required
                                            />
                                            <InputError
                                                message={errors.effective_from}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="fee-until">
                                                {t(
                                                    'billing.fees.effective_until',
                                                )}
                                            </Label>
                                            <Input
                                                id="fee-until"
                                                name="effective_until"
                                                type="date"
                                            />
                                            <p className="text-muted-foreground text-xs">
                                                {t(
                                                    'billing.fees.effective_until_help',
                                                )}
                                            </p>
                                            <InputError
                                                message={errors.effective_until}
                                            />
                                        </div>
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="fee-note">
                                            {t('billing.fees.note')}
                                        </Label>
                                        <textarea
                                            id="fee-note"
                                            name="note"
                                            rows={2}
                                            className="border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            {t('billing.fees.note_help')}
                                        </p>
                                    </div>

                                    <Button type="submit" disabled={processing}>
                                        {t('billing.fees.submit')}
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}
                </SectionCard>
            </PageContainer>
        </>
    );
}
