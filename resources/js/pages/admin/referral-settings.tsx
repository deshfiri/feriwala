import { Form, Head, Link } from '@inertiajs/react';
import { Network } from 'lucide-react';
import { useState } from 'react';
import FormField from '@/components/forms/form-field';
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
import ReferralSettingsController from '@/actions/App/Http/Controllers/Admin/ReferralSettingsController';
import type { Money } from '@/lib/money';
import type { StatusTone } from '@/lib/status';
import { index } from '@/routes/admin/referral-settings';
import type { Paginator } from '@/types';
import ReasonTextarea from '@/components/forms/reason-textarea';

export type RewardView = {
    type: 'fixed' | 'percentage';
    amount: Money | null;
    percent: string | null;
    cap: Money | null;
};

export type PlanVersion = {
    id: string;
    package: string | null;
    trigger: string;
    trigger_label: string;
    base: string;
    base_label: string;
    max_depth: number;
    levels: {
        level: number;
        reward: RewardView;
        enabled: boolean;
        required_packages: string[];
        min_active_direct_referrals: number;
    }[];
    joining_reward: RewardView | null;
    holding_days: number;
    minimum_qualifying_payment: Money;
    qualifies: Record<
        'suspended' | 'restricted' | 'package_lapsed' | 'not_active',
        boolean
    >;
    state: 'in_force' | 'scheduled' | 'ended' | 'closed';
    effective_from: string;
    effective_to: string | null;
    reason: string;
    opened_by: string;
    close_reason: string | null;
    closed_by: string | null;
};

type Option = { value: string; label: string };

type Props = {
    enabled: boolean;
    can_manage: boolean;
    plans: Paginator<PlanVersion>;
    packages: Option[];
    triggers: Option[];
    bases: Option[];
};

const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

const stateTones: Record<PlanVersion['state'], StatusTone> = {
    in_force: 'success',
    scheduled: 'info',
    ended: 'neutral',
    closed: 'neutral',
};

const qualifyingKeys = [
    'suspended',
    'restricted',
    'package_lapsed',
    'not_active',
] as const;

/** Local wall-clock time for a datetime-local input, a minute from now. */
function soon(): string {
    const date = new Date(Date.now() + 60_000);
    const pad = (value: number) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/**
 * The multi-level referral configuration (§25.4.1, D24, P7-12, P7-44).
 *
 * Seen with `referral.view_settings`; the switch and both forms appear only
 * with `referral.manage_settings`. A plan version is opened with a rule for
 * every level up to its depth and closed with a reason — never edited.
 */
export default function ReferralSettings({
    enabled,
    can_manage: canManage,
    plans,
    packages,
    triggers,
    bases,
}: Props) {
    const { t, locale } = useTranslation();
    const [depth, setDepth] = useState(3);
    const [types, setTypes] = useState<string[]>([
        'percentage',
        'percentage',
        'fixed',
    ]);
    const [joiningType, setJoiningType] = useState('');

    const when = (value: string) => new Date(value).toLocaleString(locale);

    const changeDepth = (value: number) => {
        const next = Math.max(
            1,
            Math.min(100, Number.isFinite(value) ? value : 1),
        );

        setDepth(next);
        setTypes((current) =>
            Array.from({ length: next }, (_, i) => current[i] ?? 'percentage'),
        );
    };

    const reward = (view: RewardView) => (
        <span className="inline-flex flex-wrap items-center gap-1">
            {view.type === 'percentage' ? (
                <span className="tabular-nums">{view.percent}%</span>
            ) : (
                view.amount && <MoneyAmount amount={view.amount} />
            )}
            {view.cap && (
                <span className="text-muted-foreground text-xs">
                    (
                    {t('referral.settings.cap', { amount: view.cap.formatted })}
                    )
                </span>
            )}
        </span>
    );

    return (
        <>
            <Head title={t('referral.settings.title')} />

            <PageContainer>
                <PageHeader
                    title={t('referral.settings.title')}
                    description={t('referral.settings.description')}
                />

                {!canManage && (
                    <p className="text-muted-foreground text-sm" role="note">
                        {t('referral.settings.read_only')}
                    </p>
                )}

                <SectionCard
                    title={t('referral.settings.switch_title')}
                    description={t('referral.settings.switch_hint')}
                >
                    <div className="space-y-4">
                        <StatusPill
                            tone={enabled ? 'success' : 'neutral'}
                            label={
                                enabled
                                    ? t('referral.settings.on')
                                    : t('referral.settings.off')
                            }
                        />
                        <p className="text-muted-foreground text-sm">
                            {t('referral.settings.compliance')}
                        </p>

                        {canManage && (
                            <Form
                                {...ReferralSettingsController.toggle.form()}
                                options={{ preserveScroll: true }}
                                resetOnSuccess
                                className="space-y-3"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="enabled"
                                            value={enabled ? '0' : '1'}
                                        />
                                        <FormField
                                            label={t(
                                                'referral.settings.reason',
                                            )}
                                            description={t(
                                                'referral.settings.reason_hint',
                                            )}
                                            error={errors.reason}
                                            required
                                        >
                                            {(field) => (
                                                <ReasonTextarea
                                                    context="referral"
                                                    {...field}
                                                    name="reason"
                                                    minLength={10}
                                                    maxLength={500}
                                                    required
                                                />
                                            )}
                                        </FormField>
                                        <Button
                                            type="submit"
                                            variant={
                                                enabled ? 'outline' : 'default'
                                            }
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            {enabled
                                                ? t(
                                                      'referral.settings.switch_off',
                                                  )
                                                : t(
                                                      'referral.settings.switch_on',
                                                  )}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        )}
                    </div>
                </SectionCard>

                {canManage && (
                    <SectionCard
                        title={t('referral.settings.open_title')}
                        description={t('referral.settings.open_hint')}
                    >
                        <Form
                            {...ReferralSettingsController.store.form()}
                            options={{ preserveScroll: true }}
                            className="space-y-6"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="plan-package">
                                                {t('referral.settings.package')}
                                            </Label>
                                            <select
                                                id="plan-package"
                                                name="package"
                                                className={selectClass}
                                            >
                                                <option value="">
                                                    {t(
                                                        'referral.settings.every_package',
                                                    )}
                                                </option>
                                                {packages.map((option) => (
                                                    <option
                                                        key={option.value}
                                                        value={option.value}
                                                    >
                                                        {option.label}
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError
                                                message={errors.package}
                                            />
                                        </div>

                                        <div className="grid gap-1.5">
                                            <Label htmlFor="plan-trigger">
                                                {t('referral.settings.trigger')}
                                            </Label>
                                            <select
                                                id="plan-trigger"
                                                name="trigger"
                                                className={selectClass}
                                            >
                                                {triggers.map((option) => (
                                                    <option
                                                        key={option.value}
                                                        value={option.value}
                                                    >
                                                        {option.label}
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError
                                                message={errors.trigger}
                                            />
                                        </div>

                                        <div className="grid gap-1.5">
                                            <Label htmlFor="plan-base">
                                                {t('referral.settings.base')}
                                            </Label>
                                            <select
                                                id="plan-base"
                                                name="commission_base"
                                                className={selectClass}
                                            >
                                                {bases.map((option) => (
                                                    <option
                                                        key={option.value}
                                                        value={option.value}
                                                    >
                                                        {option.label}
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError
                                                message={errors.commission_base}
                                            />
                                        </div>

                                        <FormField
                                            label={t(
                                                'referral.settings.max_depth',
                                            )}
                                            description={t(
                                                'referral.settings.max_depth_hint',
                                            )}
                                            error={errors.max_depth}
                                            required
                                        >
                                            {(field) => (
                                                <Input
                                                    {...field}
                                                    name="max_depth"
                                                    type="number"
                                                    min={1}
                                                    max={100}
                                                    value={depth}
                                                    onChange={(event) =>
                                                        changeDepth(
                                                            Number(
                                                                event.target
                                                                    .value,
                                                            ),
                                                        )
                                                    }
                                                    required
                                                />
                                            )}
                                        </FormField>
                                    </div>

                                    <fieldset className="space-y-3">
                                        <legend className="text-sm font-medium">
                                            {t('referral.settings.levels')}
                                        </legend>
                                        <InputError message={errors.levels} />

                                        {types.map((type, i) => (
                                            <div
                                                key={i}
                                                className="border-border space-y-3 rounded-lg border p-3"
                                                data-testid="level-row"
                                            >
                                                <p className="text-sm font-medium">
                                                    {t(
                                                        'referral.settings.level',
                                                        {
                                                            level: String(
                                                                i + 1,
                                                            ),
                                                        },
                                                    )}
                                                </p>
                                                <div className="grid gap-3 sm:grid-cols-3">
                                                    <div className="grid gap-1.5">
                                                        <Label
                                                            htmlFor={`level-${i}-type`}
                                                        >
                                                            {t(
                                                                'referral.settings.type',
                                                            )}
                                                        </Label>
                                                        <select
                                                            id={`level-${i}-type`}
                                                            name={`levels[${i}][type]`}
                                                            className={
                                                                selectClass
                                                            }
                                                            value={type}
                                                            onChange={(event) =>
                                                                setTypes(
                                                                    (current) =>
                                                                        current.map(
                                                                            (
                                                                                value,
                                                                                j,
                                                                            ) =>
                                                                                j ===
                                                                                i
                                                                                    ? event
                                                                                          .target
                                                                                          .value
                                                                                    : value,
                                                                        ),
                                                                )
                                                            }
                                                        >
                                                            <option value="percentage">
                                                                {t(
                                                                    'referral.reward_types.percentage',
                                                                )}
                                                            </option>
                                                            <option value="fixed">
                                                                {t(
                                                                    'referral.reward_types.fixed',
                                                                )}
                                                            </option>
                                                        </select>
                                                    </div>

                                                    {type === 'percentage' ? (
                                                        <div className="grid gap-1.5">
                                                            <Label
                                                                htmlFor={`level-${i}-rate`}
                                                            >
                                                                {t(
                                                                    'referral.settings.rate_percent',
                                                                )}
                                                            </Label>
                                                            <Input
                                                                id={`level-${i}-rate`}
                                                                name={`levels[${i}][rate_percent]`}
                                                                inputMode="decimal"
                                                                placeholder="10"
                                                                required
                                                            />
                                                        </div>
                                                    ) : (
                                                        <MoneyInput
                                                            id={`level-${i}-amount`}
                                                            name={`levels[${i}][amount]`}
                                                            label={t(
                                                                'referral.settings.amount_label',
                                                            )}
                                                            required
                                                        />
                                                    )}

                                                    <MoneyInput
                                                        id={`level-${i}-cap`}
                                                        name={`levels[${i}][cap]`}
                                                        label={t(
                                                            'referral.settings.cap_label',
                                                        )}
                                                    />

                                                    <div className="grid gap-1.5">
                                                        <Label
                                                            htmlFor={`level-${i}-direct`}
                                                        >
                                                            {t(
                                                                'referral.settings.min_direct',
                                                            )}
                                                        </Label>
                                                        <Input
                                                            id={`level-${i}-direct`}
                                                            name={`levels[${i}][min_active_direct_referrals]`}
                                                            type="number"
                                                            min={0}
                                                            defaultValue={0}
                                                        />
                                                    </div>

                                                    <div className="grid gap-1.5 sm:col-span-2">
                                                        <Label
                                                            htmlFor={`level-${i}-packages`}
                                                        >
                                                            {t(
                                                                'referral.settings.required_packages',
                                                            )}
                                                        </Label>
                                                        <select
                                                            id={`level-${i}-packages`}
                                                            name={`levels[${i}][required_packages][]`}
                                                            multiple
                                                            className="border-input bg-background min-h-9 w-full rounded-md border px-3 py-1 text-sm"
                                                        >
                                                            {packages.map(
                                                                (option) => (
                                                                    <option
                                                                        key={
                                                                            option.value
                                                                        }
                                                                        value={
                                                                            option.value
                                                                        }
                                                                    >
                                                                        {
                                                                            option.label
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                    </div>
                                                </div>
                                                <label className="flex items-center gap-2 text-sm">
                                                    <input
                                                        type="checkbox"
                                                        name={`levels[${i}][enabled]`}
                                                        value="1"
                                                        defaultChecked
                                                    />
                                                    {t(
                                                        'referral.settings.enabled',
                                                    )}
                                                </label>
                                                <InputError
                                                    message={
                                                        errors[`levels.${i}`] ??
                                                        errors[
                                                            `levels.${i}.rate_percent`
                                                        ] ??
                                                        errors[
                                                            `levels.${i}.amount`
                                                        ]
                                                    }
                                                />
                                            </div>
                                        ))}
                                    </fieldset>

                                    <fieldset className="space-y-3">
                                        <legend className="text-sm font-medium">
                                            {t(
                                                'referral.settings.joining_title',
                                            )}
                                        </legend>
                                        <p className="text-muted-foreground text-sm">
                                            {t(
                                                'referral.settings.joining_hint',
                                            )}
                                        </p>
                                        <div className="grid gap-3 sm:grid-cols-3">
                                            <select
                                                aria-label={t(
                                                    'referral.settings.joining_title',
                                                )}
                                                name="joining_type"
                                                className={selectClass}
                                                value={joiningType}
                                                onChange={(event) =>
                                                    setJoiningType(
                                                        event.target.value,
                                                    )
                                                }
                                            >
                                                <option value="">
                                                    {t(
                                                        'referral.settings.joining_none',
                                                    )}
                                                </option>
                                                <option value="percentage">
                                                    {t(
                                                        'referral.reward_types.percentage',
                                                    )}
                                                </option>
                                                <option value="fixed">
                                                    {t(
                                                        'referral.reward_types.fixed',
                                                    )}
                                                </option>
                                            </select>
                                            {joiningType === 'percentage' && (
                                                <Input
                                                    aria-label={t(
                                                        'referral.settings.rate_percent',
                                                    )}
                                                    name="joining_rate_percent"
                                                    inputMode="decimal"
                                                    required
                                                />
                                            )}
                                            {joiningType === 'fixed' && (
                                                <MoneyInput
                                                    id="joining-amount"
                                                    name="joining_amount"
                                                    label={t(
                                                        'referral.settings.amount_label',
                                                    )}
                                                    required
                                                    className="gap-0"
                                                />
                                            )}
                                            {joiningType !== '' && (
                                                <MoneyInput
                                                    id="joining-cap"
                                                    name="joining_cap"
                                                    label={t(
                                                        'referral.settings.cap_label',
                                                    )}
                                                    className="gap-0"
                                                />
                                            )}
                                        </div>
                                        <InputError message={errors.joining} />
                                    </fieldset>

                                    <div className="grid gap-4 sm:grid-cols-3">
                                        <FormField
                                            label={t(
                                                'referral.settings.holding_days',
                                            )}
                                            error={errors.holding_days}
                                            required
                                        >
                                            {(field) => (
                                                <Input
                                                    {...field}
                                                    name="holding_days"
                                                    type="number"
                                                    min={0}
                                                    max={365}
                                                    defaultValue={0}
                                                    required
                                                />
                                            )}
                                        </FormField>
                                        <MoneyInput
                                            id="minimum-qualifying-payment"
                                            name="minimum_qualifying_payment"
                                            label={t(
                                                'referral.settings.minimum_payment',
                                            )}
                                            defaultValue="0"
                                            required
                                            error={
                                                errors.minimum_qualifying_payment
                                            }
                                        />
                                        <FormField
                                            label={t(
                                                'referral.settings.effective_from',
                                            )}
                                            error={errors.effective_from}
                                            required
                                        >
                                            {(field) => (
                                                <Input
                                                    {...field}
                                                    name="effective_from"
                                                    type="datetime-local"
                                                    defaultValue={soon()}
                                                    required
                                                />
                                            )}
                                        </FormField>
                                    </div>

                                    <fieldset className="space-y-2">
                                        <legend className="text-sm font-medium">
                                            {t(
                                                'referral.settings.qualifies_title',
                                            )}
                                        </legend>
                                        <p className="text-muted-foreground text-sm">
                                            {t(
                                                'referral.settings.qualifies_hint',
                                            )}
                                        </p>
                                        <div className="flex flex-wrap gap-x-5 gap-y-2">
                                            {qualifyingKeys.map((key) => (
                                                <label
                                                    key={key}
                                                    className="flex items-center gap-2 text-sm"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        name={`qualifies_${key}`}
                                                        value="1"
                                                    />
                                                    {t(
                                                        `referral.settings.qualifies.${key}`,
                                                    )}
                                                </label>
                                            ))}
                                        </div>
                                    </fieldset>

                                    <FormField
                                        label={t('referral.settings.reason')}
                                        description={t(
                                            'referral.settings.reason_hint',
                                        )}
                                        error={errors.reason}
                                        required
                                    >
                                        {(field) => (
                                            <ReasonTextarea
                                                context="referral"
                                                {...field}
                                                name="reason"
                                                minLength={10}
                                                maxLength={500}
                                                required
                                            />
                                        )}
                                    </FormField>

                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        {t('referral.settings.open')}
                                    </Button>
                                </>
                            )}
                        </Form>
                    </SectionCard>
                )}

                <SectionCard title={t('referral.settings.versions')}>
                    {plans.data.length === 0 ? (
                        <EmptyState
                            icon={Network}
                            title={t('referral.settings.no_versions')}
                            description={t(
                                'referral.settings.no_versions_help',
                            )}
                        />
                    ) : (
                        <ul className="divide-border divide-y">
                            {plans.data.map((plan) => (
                                <li
                                    key={plan.id}
                                    className="space-y-3 py-4 first:pt-0 last:pb-0"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">
                                            {plan.package ??
                                                t(
                                                    'referral.settings.every_package',
                                                )}
                                        </span>
                                        <StatusPill
                                            tone={stateTones[plan.state]}
                                            label={t(
                                                `referral.plan_states.${plan.state}`,
                                            )}
                                        />
                                        <span className="text-muted-foreground text-xs">
                                            {t('referral.settings.window', {
                                                from: when(plan.effective_from),
                                                to: plan.effective_to
                                                    ? when(plan.effective_to)
                                                    : t(
                                                          'referral.settings.open_ended',
                                                      ),
                                            })}
                                        </span>
                                    </div>

                                    <p className="text-muted-foreground text-sm">
                                        {plan.trigger_label} · {plan.base_label}{' '}
                                        ·{' '}
                                        {t('referral.settings.depth', {
                                            depth: String(plan.max_depth),
                                        })}
                                    </p>

                                    <ol className="grid gap-1 text-sm sm:grid-cols-2">
                                        {plan.levels.map((level) => (
                                            <li
                                                key={level.level}
                                                className="flex flex-wrap items-center gap-2"
                                            >
                                                <span className="text-muted-foreground">
                                                    {t(
                                                        'referral.settings.level',
                                                        {
                                                            level: String(
                                                                level.level,
                                                            ),
                                                        },
                                                    )}
                                                </span>
                                                {reward(level.reward)}
                                                {!level.enabled && (
                                                    <StatusPill
                                                        tone="neutral"
                                                        label={t(
                                                            'referral.settings.disabled_level',
                                                        )}
                                                    />
                                                )}
                                                {level.min_active_direct_referrals >
                                                    0 && (
                                                    <span className="text-muted-foreground text-xs">
                                                        {t(
                                                            'referral.settings.min_direct_short',
                                                            {
                                                                count: String(
                                                                    level.min_active_direct_referrals,
                                                                ),
                                                            },
                                                        )}
                                                    </span>
                                                )}
                                                {level.required_packages
                                                    .length > 0 && (
                                                    <span className="text-muted-foreground text-xs">
                                                        {t(
                                                            'referral.settings.packages_short',
                                                            {
                                                                packages:
                                                                    level.required_packages.join(
                                                                        ', ',
                                                                    ),
                                                            },
                                                        )}
                                                    </span>
                                                )}
                                            </li>
                                        ))}
                                    </ol>

                                    <div className="text-muted-foreground flex flex-wrap gap-x-4 gap-y-1 text-xs">
                                        {plan.joining_reward && (
                                            <span className="inline-flex items-center gap-1">
                                                {t('referral.settings.joining')}
                                                : {reward(plan.joining_reward)}
                                            </span>
                                        )}
                                        <span>
                                            {plan.holding_days > 0
                                                ? t(
                                                      'referral.settings.holding',
                                                      {
                                                          days: String(
                                                              plan.holding_days,
                                                          ),
                                                      },
                                                  )
                                                : t(
                                                      'referral.settings.paid_immediately',
                                                  )}
                                        </span>
                                        {qualifyingKeys
                                            .filter(
                                                (key) => plan.qualifies[key],
                                            )
                                            .map((key) => (
                                                <span key={key}>
                                                    {t(
                                                        `referral.settings.qualifies.${key}`,
                                                    )}
                                                </span>
                                            ))}
                                    </div>

                                    <p className="text-sm">
                                        <span className="text-muted-foreground">
                                            {t('referral.settings.opened_by', {
                                                name: plan.opened_by,
                                            })}
                                            :{' '}
                                        </span>
                                        {plan.reason}
                                    </p>
                                    {plan.closed_by && (
                                        <p className="text-sm">
                                            <span className="text-muted-foreground">
                                                {t(
                                                    'referral.settings.closed_by',
                                                    {
                                                        name: plan.closed_by,
                                                    },
                                                )}
                                                :{' '}
                                            </span>
                                            {plan.close_reason}
                                        </p>
                                    )}

                                    {canManage && plan.state !== 'closed' && (
                                        <Form
                                            {...ReferralSettingsController.close.form(
                                                plan.id,
                                            )}
                                            options={{ preserveScroll: true }}
                                            className="flex flex-wrap items-end gap-2"
                                        >
                                            {({ processing, errors }) => (
                                                <>
                                                    <div className="grid min-w-0 flex-1 gap-1">
                                                        <Label
                                                            htmlFor={`close-${plan.id}`}
                                                        >
                                                            {t(
                                                                'referral.settings.close_reason',
                                                            )}
                                                        </Label>
                                                        <ReasonTextarea
                                                            context="referral"
                                                            id={`close-${plan.id}`}
                                                            name="reason"
                                                            minLength={10}
                                                            maxLength={500}
                                                            required
                                                        />
                                                        <InputError
                                                            message={
                                                                errors.reason ??
                                                                errors.plan
                                                            }
                                                        />
                                                    </div>
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        variant="outline"
                                                        disabled={processing}
                                                    >
                                                        {t(
                                                            'referral.settings.close',
                                                        )}
                                                    </Button>
                                                </>
                                            )}
                                        </Form>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}

                    {plans.last_page > 1 && (
                        <nav className="mt-4 flex justify-between text-sm">
                            {plans.current_page > 1 ? (
                                <Link
                                    href={index({
                                        query: { page: plans.current_page - 1 },
                                    })}
                                >
                                    {t('common.actions.back')}
                                </Link>
                            ) : (
                                <span />
                            )}
                            {plans.current_page < plans.last_page && (
                                <Link
                                    href={index({
                                        query: { page: plans.current_page + 1 },
                                    })}
                                >
                                    {t('common.actions.next')}
                                </Link>
                            )}
                        </nav>
                    )}
                </SectionCard>
            </PageContainer>
        </>
    );
}
