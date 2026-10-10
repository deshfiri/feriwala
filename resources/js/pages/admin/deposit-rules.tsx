import { Form, Head } from '@inertiajs/react';
import { Scale } from 'lucide-react';
import { useState } from 'react';
import DepositRuleController from '@/actions/App/Http/Controllers/Admin/DepositRuleController';
import DataTable from '@/components/data-table/data-table';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
import MoneyInput from '@/components/money-input';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { index } from '@/routes/admin/deposit-rules';
import type { Money } from '@/lib/money';
import type { Column, Paginator } from '@/types';
import ReasonTextarea from '@/components/forms/reason-textarea';

type Option = { value: string | number; label: string };

type DepositRuleRow = {
    id: string;
    scope: string;
    scope_label: string;
    scope_id: number | null;
    specificity: number;
    required_deposit: Money;
    minimum_balance: Money;
    required_top_up: Money;
    low_threshold: Money | null;
    critical_threshold: Money | null;
    grace_period_days: number | null;
    deposit_deadline_days: number | null;
    frequency_label: string;
    refundability_label: string;
    refundable_percent: number | null;
    reserved_until_cancellation: boolean;
    deposit_usable_for_charges: boolean;
    withdrawable_after_liabilities: boolean;
    actions: Record<string, boolean>;
    priority: number;
    effective_from: string;
    effective_until: string | null;
    is_active: boolean;
    note: string | null;
    created_by: string | null;
};

type HistoryRow = {
    id: number;
    rule_id: number;
    action: string;
    actor: string | null;
    reason: string | null;
    effective_from: string;
    at: string;
};

type Props = {
    rules: Paginator<DepositRuleRow>;
    scopes: (Option & { specificity: number })[];
    frequencies: Option[];
    refundabilities: Option[];
    packages: Option[];
    history: HistoryRow[];
    can: { manage: boolean };
};

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * §24.3's graded actions, in the order they happen.
 *
 * The rule column and the restriction stage are named differently — one is
 * "may this happen", the other is "this has happened — so the pairing is
 * written out rather than derived by string surgery.
 */
const GRADED_ACTIONS = [
    {
        field: 'restricts_chargeable_services',
        stage: 'services_restricted',
    },
    { field: 'pauses_website_setup', stage: 'website_setup_paused' },
    { field: 'disables_website', stage: 'website_disabled' },
    { field: 'restricts_account', stage: 'account_restricted' },
    { field: 'disables_account', stage: 'account_disabled' },
] as const;

/**
 * What accounts are required to deposit and keep (§24.1).
 *
 * The scope is shown on every row with the precedence beside it, because "most
 * specific wins" is easy to say and hard to trust: somebody looking at a
 * package rule needs to see that a rule for one account will beat it.
 *
 * Nothing here edits a figure. Raising a minimum balance closes one rule and
 * opens another, so an account restricted in March stays explainable against
 * the rule of March — and the history below is what makes that visible rather
 * than merely true.
 */
export default function AdminDepositRules({
    rules,
    scopes,
    frequencies,
    refundabilities,
    packages,
    history,
    can,
}: Props) {
    const { t, locale } = useTranslation();
    const [creating, setCreating] = useState(false);

    const date = (value: string | null) =>
        value === null
            ? '—'
            : new Date(value).toLocaleDateString(locale, {
                  day: 'numeric',
                  month: 'short',
                  year: 'numeric',
              });

    const columns: Column<DepositRuleRow>[] = [
        {
            key: 'scope',
            header: t('wallet.rules.columns.scope'),
            cell: (row) => (
                <div className="min-w-0">
                    <div className="font-medium">{row.scope_label}</div>
                    <div className="text-muted-foreground text-xs">
                        {t('wallet.rules.precedence', {
                            value: row.specificity,
                        })}
                    </div>
                </div>
            ),
        },
        {
            key: 'required_deposit',
            header: t('wallet.balances.required_deposit'),
            align: 'end',
            cell: (row) => <MoneyAmount amount={row.required_deposit} />,
        },
        {
            key: 'minimum_balance',
            header: t('wallet.balances.minimum_balance'),
            align: 'end',
            cell: (row) => <MoneyAmount amount={row.minimum_balance} />,
        },
        {
            key: 'grace',
            header: t('wallet.rules.columns.grace'),
            priority: 'secondary',
            cell: (row) =>
                row.grace_period_days === null
                    ? '—'
                    : t('wallet.rules.days', { count: row.grace_period_days }),
        },
        {
            key: 'refundability',
            header: t('wallet.rules.columns.refundability'),
            priority: 'secondary',
            cell: (row) => (
                <div className="min-w-0">
                    <div>{row.refundability_label}</div>
                    {row.refundable_percent !== null && (
                        <div className="text-muted-foreground text-xs">
                            {row.refundable_percent}%
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'window',
            header: t('wallet.rules.columns.window'),
            cell: (row) => (
                <div className="min-w-0">
                    <div>{date(row.effective_from)}</div>
                    <div className="text-muted-foreground text-xs">
                        {row.effective_until === null
                            ? t('wallet.rules.open_ended')
                            : date(row.effective_until)}
                    </div>
                </div>
            ),
        },
        {
            key: 'state',
            header: t('wallet.columns.status'),
            cell: (row) => (
                <StatusPill
                    tone={row.is_active ? 'success' : 'neutral'}
                    label={
                        row.is_active
                            ? t('wallet.rules.active')
                            : t('wallet.rules.inactive')
                    }
                />
            ),
        },
    ];

    return (
        <>
            <Head title={t('wallet.rules.title')} />

            <PageContainer>
                <PageHeader
                    title={t('wallet.rules.title')}
                    description={t('wallet.rules.description')}
                    actions={
                        can.manage && (
                            <Button size="sm" onClick={() => setCreating(true)}>
                                {t('wallet.rules.add')}
                            </Button>
                        )
                    }
                />

                <DataTable
                    columns={columns}
                    paginator={rules}
                    rowKey={(row) => row.id}
                    caption={t('wallet.rules.caption')}
                    searchable={false}
                    onlyReload={['rules']}
                    emptyState={
                        <EmptyState
                            icon={Scale}
                            title={t('wallet.rules.empty_title')}
                            description={t('wallet.rules.empty_description')}
                        />
                    }
                />

                {creating && can.manage && (
                    <SectionCard
                        title={t('wallet.rules.add')}
                        description={t('wallet.rules.add_help')}
                    >
                        <Form
                            {...DepositRuleController.store.form()}
                            onSuccess={() => setCreating(false)}
                            className="space-y-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="rule-scope">
                                                {t(
                                                    'wallet.rules.columns.scope',
                                                )}
                                            </Label>
                                            <select
                                                id="rule-scope"
                                                name="scope"
                                                required
                                                className={controlClass}
                                                defaultValue="global"
                                            >
                                                {scopes.map((scope) => (
                                                    <option
                                                        key={scope.value}
                                                        value={scope.value}
                                                    >
                                                        {scope.label}
                                                    </option>
                                                ))}
                                            </select>
                                            <p className="text-muted-foreground text-xs">
                                                {t('wallet.rules.scope_help')}
                                            </p>
                                            <InputError
                                                message={errors.scope}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="rule-scope-id">
                                                {t('wallet.rules.scope_id')}
                                            </Label>
                                            <Input
                                                id="rule-scope-id"
                                                name="scope_id"
                                                type="number"
                                                min={1}
                                            />
                                            <p className="text-muted-foreground text-xs">
                                                {t(
                                                    'wallet.rules.scope_id_help',
                                                )}
                                                {packages.length > 0 &&
                                                    ` ${packages
                                                        .map(
                                                            (option) =>
                                                                `${option.value}: ${option.label}`,
                                                        )
                                                        .join(', ')}`}
                                            </p>
                                            <InputError
                                                message={errors.scope_id}
                                            />
                                        </div>

                                        <MoneyInput
                                            id="rule-deposit"
                                            name="required_deposit"
                                            label={t(
                                                'wallet.balances.required_deposit',
                                            )}
                                            defaultValue="0"
                                            required
                                            error={errors.required_deposit}
                                        />

                                        <MoneyInput
                                            id="rule-minimum"
                                            name="minimum_balance"
                                            label={t(
                                                'wallet.balances.minimum_balance',
                                            )}
                                            defaultValue="0"
                                            required
                                            error={errors.minimum_balance}
                                        />

                                        <MoneyInput
                                            id="rule-low"
                                            name="low_threshold"
                                            label={t('wallet.rules.low')}
                                            error={errors.low_threshold}
                                        />

                                        <div className="grid gap-2">
                                            <Label htmlFor="rule-grace">
                                                {t(
                                                    'wallet.rules.columns.grace',
                                                )}
                                            </Label>
                                            <Input
                                                id="rule-grace"
                                                name="grace_period_days"
                                                type="number"
                                                min={0}
                                                max={365}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="rule-frequency">
                                                {t('wallet.rules.frequency')}
                                            </Label>
                                            <select
                                                id="rule-frequency"
                                                name="frequency"
                                                className={controlClass}
                                                defaultValue="one_time"
                                                required
                                            >
                                                {frequencies.map((option) => (
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
                                            <Label htmlFor="rule-refundability">
                                                {t(
                                                    'wallet.rules.columns.refundability',
                                                )}
                                            </Label>
                                            <select
                                                id="rule-refundability"
                                                name="refundability"
                                                className={controlClass}
                                                defaultValue="full"
                                                required
                                            >
                                                {refundabilities.map(
                                                    (option) => (
                                                        <option
                                                            key={option.value}
                                                            value={option.value}
                                                        >
                                                            {option.label}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="rule-from">
                                                {t('wallet.rules.from')}
                                            </Label>
                                            <Input
                                                id="rule-from"
                                                name="effective_from"
                                                type="date"
                                                required
                                                defaultValue={new Date()
                                                    .toISOString()
                                                    .slice(0, 10)}
                                            />
                                            <InputError
                                                message={errors.effective_from}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="rule-until">
                                                {t('wallet.rules.until')}
                                            </Label>
                                            <Input
                                                id="rule-until"
                                                name="effective_until"
                                                type="date"
                                            />
                                        </div>
                                    </div>

                                    <fieldset className="grid gap-2">
                                        <legend className="text-xs font-medium">
                                            {t('wallet.rules.actions')}
                                        </legend>

                                        {GRADED_ACTIONS.map((action) => (
                                            <label
                                                key={action.field}
                                                className="flex items-start gap-2 text-sm"
                                            >
                                                <input
                                                    type="checkbox"
                                                    name={action.field}
                                                    value="1"
                                                    className="accent-brand mt-0.5"
                                                />
                                                <span>
                                                    {t(
                                                        `wallet.restrictions.${action.stage}`,
                                                    )}
                                                </span>
                                            </label>
                                        ))}
                                    </fieldset>

                                    <div className="grid gap-2">
                                        <Label htmlFor="rule-reason">
                                            {t('wallet.rules.reason')}
                                        </Label>
                                        <ReasonTextarea
                                            context="generic"
                                            id="rule-reason"
                                            name="reason"
                                            rows={2}
                                            required
                                            minLength={5}
                                            className={controlClass}
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            {t('wallet.rules.reason_help')}
                                        </p>
                                        <InputError message={errors.reason} />
                                    </div>

                                    <div className="flex gap-2">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() => setCreating(false)}
                                        >
                                            {t('common.actions.cancel')}
                                        </Button>

                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            {t('wallet.rules.submit')}
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </SectionCard>
                )}

                <SectionCard
                    title={t('wallet.rules.history')}
                    description={t('wallet.rules.history_help')}
                >
                    {history.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            {t('wallet.rules.history_empty')}
                        </p>
                    ) : (
                        <ul className="divide-border divide-y">
                            {history.map((entry) => (
                                <li
                                    key={entry.id}
                                    className="flex flex-wrap items-baseline justify-between gap-2 py-3 first:pt-0 last:pb-0"
                                >
                                    <div className="min-w-0">
                                        <div className="text-sm">
                                            {t(
                                                `wallet.rules.history_action.${entry.action}`,
                                            )}
                                            {entry.actor !== null &&
                                                ` · ${entry.actor}`}
                                        </div>
                                        {entry.reason !== null && (
                                            <div className="text-muted-foreground text-xs">
                                                {entry.reason}
                                            </div>
                                        )}
                                    </div>

                                    <div className="text-muted-foreground text-end text-xs">
                                        <div>{date(entry.at)}</div>
                                        <div>
                                            {t('wallet.rules.effective', {
                                                date: date(
                                                    entry.effective_from,
                                                ),
                                            })}
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </PageContainer>
        </>
    );
}

AdminDepositRules.layout = {
    breadcrumbs: [
        {
            title: 'nav.deposit_rules',
            href: index(),
        },
    ],
};
