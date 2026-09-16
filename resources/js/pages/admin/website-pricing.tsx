import { Form, Head } from '@inertiajs/react';
import { Scale } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import FormField from '@/components/forms/form-field';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
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
import WebsitePricingController from '@/actions/App/Http/Controllers/Admin/WebsitePricingController';
import type { Money } from '@/lib/money';
import { index } from '@/routes/admin/website-pricing';
import type { Column, Paginator } from '@/types';

type Rule = {
    id: string;
    product: { name: string; sku: string } | null;
    package: string | null;
    allows_user_pricing: boolean;
    minimum: Money | null;
    maximum: Money | null;
    suggested: Money | null;
    max_margin_percent: number | null;
    locked_fields: string[];
    effective_from: string;
    effective_to: string | null;
};

type Props = {
    rules: Paginator<Rule>;
    lockable_fields: { value: string; label: string }[];
    packages: { value: string; label: string }[];
};

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 w-full rounded-lg border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * What partners may charge for what they sell (§15.1, P5-5).
 *
 * Rules are opened and closed, never edited: a bound that changed in place
 * would rewrite what a partner was allowed to charge last month.
 */
export default function WebsitePricing({
    rules,
    lockable_fields: lockableFields,
    packages,
}: Props) {
    const { t, locale } = useTranslation();

    const columns: Column<Rule>[] = [
        {
            key: 'scope',
            header: t('website.pricing.columns.scope'),
            cell: (row) => (
                <div className="min-w-0 space-y-1">
                    <div className="truncate text-sm">
                        {row.product
                            ? `${row.product.name} (${row.product.sku})`
                            : t('website.pricing.every_product')}
                    </div>
                    <div className="text-muted-foreground text-xs">
                        {row.package ?? t('website.pricing.every_package')}
                    </div>
                </div>
            ),
        },
        {
            key: 'bounds',
            header: t('website.pricing.columns.bounds'),
            cell: (row) => (
                <div className="space-y-1 text-xs">
                    {row.minimum && (
                        <div>
                            {t('website.pricing.minimum')}:{' '}
                            <MoneyAmount amount={row.minimum} />
                        </div>
                    )}
                    {row.maximum && (
                        <div>
                            {t('website.pricing.maximum')}:{' '}
                            <MoneyAmount amount={row.maximum} />
                        </div>
                    )}
                    {row.max_margin_percent !== null && (
                        <div className="text-muted-foreground">
                            {t('website.pricing.margin', {
                                percent: String(row.max_margin_percent),
                            })}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'pricing',
            header: t('website.pricing.columns.pricing'),
            cell: (row) => (
                <div className="space-y-1">
                    <StatusPill
                        tone={row.allows_user_pricing ? 'success' : 'warning'}
                        label={
                            row.allows_user_pricing
                                ? t('website.pricing.partner_prices')
                                : t('website.pricing.fixed')
                        }
                    />
                    {row.locked_fields.length > 0 && (
                        <div className="text-muted-foreground text-xs">
                            {t('website.pricing.locked', {
                                fields: row.locked_fields.join(', '),
                            })}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'window',
            header: t('website.pricing.columns.window'),
            priority: 'secondary',
            cell: (row) => (
                <div className="text-muted-foreground space-y-1 text-xs">
                    <div>
                        {new Date(row.effective_from).toLocaleDateString(
                            locale,
                        )}
                    </div>
                    <div>
                        {row.effective_to
                            ? new Date(row.effective_to).toLocaleDateString(
                                  locale,
                              )
                            : t('website.pricing.open_ended')}
                    </div>
                </div>
            ),
        },
        {
            key: 'actions',
            header: t('website.pricing.columns.actions'),
            cell: (row) =>
                row.effective_to ? null : (
                    <Form
                        {...WebsitePricingController.close.form(row.id)}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                size="sm"
                                variant="outline"
                                disabled={processing}
                            >
                                {t('website.pricing.close')}
                            </Button>
                        )}
                    </Form>
                ),
        },
    ];

    return (
        <>
            <Head title={t('website.pricing.title')} />

            <PageContainer>
                <PageHeader
                    title={t('website.pricing.title')}
                    description={t('website.pricing.description')}
                />

                <SectionCard
                    title={t('website.pricing.open_title')}
                    description={t('website.pricing.open_hint')}
                >
                    <Form
                        {...WebsitePricingController.store.form()}
                        options={{ preserveScroll: true }}
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <FormField
                                        label={t('website.pricing.product')}
                                        description={t(
                                            'website.pricing.product_hint',
                                        )}
                                        error={errors.product}
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="product"
                                                maxLength={26}
                                            />
                                        )}
                                    </FormField>

                                    <div className="grid gap-1.5">
                                        <Label htmlFor="rule-package">
                                            {t('website.pricing.package')}
                                        </Label>
                                        <select
                                            id="rule-package"
                                            name="package"
                                            className={selectClass}
                                        >
                                            <option value="">
                                                {t(
                                                    'website.pricing.every_package',
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
                                        <InputError message={errors.package} />
                                    </div>

                                    <FormField
                                        label={t('website.pricing.minimum')}
                                        error={errors.min_price_minor}
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="min_price_minor"
                                                type="number"
                                                min={0}
                                            />
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t('website.pricing.maximum')}
                                        error={errors.max_price_minor}
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="max_price_minor"
                                                type="number"
                                                min={0}
                                            />
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t('website.pricing.suggested')}
                                        error={errors.suggested_price_minor}
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="suggested_price_minor"
                                                type="number"
                                                min={0}
                                            />
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t(
                                            'website.pricing.margin_percent',
                                        )}
                                        error={errors.max_margin_percent}
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="max_margin_percent"
                                                type="number"
                                                min={0}
                                                max={1000}
                                            />
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t('website.pricing.from')}
                                        error={errors.effective_from}
                                        required
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="effective_from"
                                                type="date"
                                                required
                                            />
                                        )}
                                    </FormField>
                                </div>

                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        name="allows_user_pricing"
                                        value="1"
                                        defaultChecked
                                    />
                                    {t('website.pricing.allows_user_pricing')}
                                </label>

                                <fieldset className="space-y-2">
                                    <legend className="text-sm font-medium">
                                        {t('website.pricing.lock_fields')}
                                    </legend>
                                    <div className="flex flex-wrap gap-3">
                                        {lockableFields.map((field) => (
                                            <label
                                                key={field.value}
                                                className="flex items-center gap-2 text-sm"
                                            >
                                                <input
                                                    type="checkbox"
                                                    name="locked_fields[]"
                                                    value={field.value}
                                                />
                                                {field.label}
                                            </label>
                                        ))}
                                    </div>
                                </fieldset>

                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {t('website.pricing.open_rule')}
                                </Button>
                            </>
                        )}
                    </Form>
                </SectionCard>

                <DataTable
                    columns={columns}
                    paginator={rules}
                    rowKey={(row) => row.id}
                    caption={t('website.pricing.title')}
                    searchable={false}
                    onlyReload={['rules']}
                    renderCard={(row) => (
                        <div className="space-y-2">
                            <div className="text-sm font-medium">
                                {row.product
                                    ? row.product.name
                                    : t('website.pricing.every_product')}
                            </div>
                            <StatusPill
                                tone={
                                    row.allows_user_pricing
                                        ? 'success'
                                        : 'warning'
                                }
                                label={
                                    row.allows_user_pricing
                                        ? t('website.pricing.partner_prices')
                                        : t('website.pricing.fixed')
                                }
                            />
                        </div>
                    )}
                    emptyState={
                        <EmptyState
                            icon={Scale}
                            title={t('website.pricing.empty')}
                            description={t('website.pricing.empty_help')}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

WebsitePricing.layout = {
    breadcrumbs: [
        {
            title: 'Website pricing',
            href: index(),
        },
    ],
};
