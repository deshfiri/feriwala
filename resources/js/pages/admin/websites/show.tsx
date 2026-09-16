import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import FormField from '@/components/forms/form-field';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import WebsiteController from '@/actions/App/Http/Controllers/Admin/WebsiteController';
import {
    websiteChargeTone,
    websiteHealthTone,
    websiteServiceTone,
    websiteStatusTone,
} from '@/lib/website';
import { index } from '@/routes/admin/websites';
import type { WebsiteDetail } from '@/types/website';

type Props = {
    website: WebsiteDetail;
    can: { administer: boolean };
    transitions: { value: string; label: string }[];
};

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * One partner storefront, for the staff who administer it (§16.3, §16.4).
 *
 * Only the moves the status map allows from where it stands are offered, so the
 * screen cannot put up a button the action would refuse. Every move needs a
 * reason: a storefront going dark is something its owner will ask about.
 */
export default function AdminWebsiteShow({ website, can, transitions }: Props) {
    const { t, locale } = useTranslation();

    const date = (value: string | null) =>
        value ? new Date(value).toLocaleDateString(locale) : '—';

    return (
        <>
            <Head title={website.name} />

            <PageContainer>
                <PageHeader
                    title={website.name}
                    description={website.host}
                    actions={
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={index()}>
                                <ArrowLeft aria-hidden="true" />
                                {t('website.admin_title')}
                            </Link>
                        </Button>
                    }
                />

                <div className="flex flex-wrap items-center gap-2">
                    <StatusPill
                        tone={websiteStatusTone(website.status)}
                        label={website.status_label}
                    />
                    <StatusPill
                        tone={websiteHealthTone(website.connection.health)}
                        label={website.connection.health_label}
                    />
                    {website.account && (
                        <span className="text-muted-foreground text-sm">
                            {t('website.admin.account')}: {website.account.name}
                        </span>
                    )}
                </div>

                <SectionCard title={t('website.show.charges')}>
                    {website.charges.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            {t('website.show.charges_empty')}
                        </p>
                    ) : (
                        <ul className="divide-border divide-y">
                            {website.charges.map((charge) => (
                                <li
                                    key={charge.id}
                                    className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                                >
                                    <div className="space-y-1">
                                        <div className="text-sm font-medium">
                                            {charge.type_label}
                                        </div>
                                        <StatusPill
                                            tone={websiteChargeTone(
                                                charge.status,
                                            )}
                                            label={charge.status_label}
                                        />
                                    </div>
                                    <MoneyAmount
                                        amount={charge.amount}
                                        direction="debit"
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>

                {can.administer && (
                    <SectionCard
                        title={t('website.admin.move_title')}
                        description={t('website.admin.move_hint')}
                    >
                        {transitions.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                {t('website.admin.no_moves')}
                            </p>
                        ) : (
                            <Form
                                {...WebsiteController.updateStatus.form(
                                    website.id,
                                )}
                                options={{ preserveScroll: true }}
                                className="space-y-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="website-status">
                                                {t('website.admin.move_status')}
                                            </Label>
                                            <select
                                                id="website-status"
                                                name="status"
                                                required
                                                className={controlClass}
                                            >
                                                {transitions.map((option) => (
                                                    <option
                                                        key={option.value}
                                                        value={option.value}
                                                    >
                                                        {option.label}
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError
                                                message={errors.status}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="website-reason">
                                                {t('website.admin.move_reason')}
                                            </Label>
                                            <textarea
                                                id="website-reason"
                                                name="reason"
                                                rows={2}
                                                required
                                                minLength={10}
                                                maxLength={500}
                                                className={controlClass}
                                            />
                                            <InputError
                                                message={errors.reason}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="website-internal-note">
                                                {t(
                                                    'website.admin.move_internal_note',
                                                )}
                                            </Label>
                                            <textarea
                                                id="website-internal-note"
                                                name="internal_note"
                                                rows={2}
                                                maxLength={1000}
                                                className={controlClass}
                                            />
                                            <InputError
                                                message={errors.internal_note}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="website-public-note">
                                                {t(
                                                    'website.admin.move_public_note',
                                                )}
                                            </Label>
                                            <textarea
                                                id="website-public-note"
                                                name="public_note"
                                                rows={2}
                                                maxLength={1000}
                                                className={controlClass}
                                            />
                                            <InputError
                                                message={errors.public_note}
                                            />
                                        </div>

                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            {t('website.admin.move_submit')}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        )}
                    </SectionCard>
                )}

                <SectionCard
                    title={t('website.show.domains')}
                    description={t('website.admin.domain_hint')}
                >
                    <ul className="divide-border divide-y">
                        {website.domains.map((domain) => (
                            <li
                                key={domain.id}
                                className="space-y-1 py-3 first:pt-0"
                            >
                                <div className="font-mono text-sm">
                                    {domain.domain}
                                </div>
                                <StatusPill
                                    tone={websiteServiceTone(domain.status)}
                                    label={domain.status_label}
                                />
                                <div className="text-muted-foreground text-xs">
                                    {t('website.show.expires', {
                                        date: date(domain.expires_at),
                                    })}
                                    {domain.registrar
                                        ? ` · ${domain.registrar}`
                                        : ''}
                                </div>
                            </li>
                        ))}
                    </ul>

                    {can.administer && (
                        <Form
                            {...WebsiteController.storeDomain.form(website.id)}
                            options={{ preserveScroll: true }}
                            className="mt-4 space-y-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <FormField
                                        label={t('website.admin.domain_name')}
                                        error={errors.domain}
                                        required
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="domain"
                                                maxLength={253}
                                                required
                                            />
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t(
                                            'website.admin.domain_registrar',
                                        )}
                                        error={errors.registrar}
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="registrar"
                                                maxLength={120}
                                            />
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t('website.admin.term_months')}
                                        error={errors.term_months}
                                        required
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="term_months"
                                                type="number"
                                                min={1}
                                                max={120}
                                                defaultValue={12}
                                                required
                                            />
                                        )}
                                    </FormField>

                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            name="make_primary"
                                            value="1"
                                            defaultChecked
                                        />
                                        {t('website.admin.domain_primary')}
                                    </label>

                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        {t('website.admin.domain_submit')}
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}
                </SectionCard>

                <SectionCard title={t('website.show.hostings')}>
                    <ul className="divide-border divide-y">
                        {website.hostings.map((hosting) => (
                            <li
                                key={hosting.id}
                                className="space-y-1 py-3 first:pt-0"
                            >
                                <div className="text-sm">{hosting.plan}</div>
                                <StatusPill
                                    tone={websiteServiceTone(hosting.status)}
                                    label={hosting.status_label}
                                />
                                <div className="text-muted-foreground text-xs">
                                    {t('website.show.expires', {
                                        date: date(hosting.expires_at),
                                    })}
                                    {hosting.provider
                                        ? ` · ${hosting.provider}`
                                        : ''}
                                </div>
                            </li>
                        ))}
                    </ul>

                    {can.administer && (
                        <Form
                            {...WebsiteController.storeHosting.form(website.id)}
                            options={{ preserveScroll: true }}
                            className="mt-4 space-y-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <FormField
                                        label={t('website.admin.hosting_plan')}
                                        error={errors.plan}
                                        required
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="plan"
                                                maxLength={120}
                                                required
                                            />
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t(
                                            'website.admin.hosting_provider',
                                        )}
                                        error={errors.provider}
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="provider"
                                                maxLength={120}
                                            />
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t('website.admin.term_months')}
                                        error={errors.term_months}
                                        required
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                name="term_months"
                                                type="number"
                                                min={1}
                                                max={120}
                                                defaultValue={12}
                                                required
                                            />
                                        )}
                                    </FormField>

                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        {t('website.admin.hosting_submit')}
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}
                </SectionCard>

                <SectionCard title={t('website.show.history')}>
                    {website.history.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            {t('website.show.no_history')}
                        </p>
                    ) : (
                        <ol className="space-y-3">
                            {[...website.history].reverse().map((entry) => (
                                <li key={entry.id} className="space-y-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <StatusPill
                                            tone={websiteStatusTone(
                                                entry.new_status,
                                            )}
                                            label={entry.new_status_label}
                                        />
                                        <span className="text-muted-foreground text-xs">
                                            {new Date(
                                                entry.changed_at,
                                            ).toLocaleString(locale)}
                                            {entry.changed_by
                                                ? ` · ${entry.changed_by}`
                                                : ''}
                                        </span>
                                    </div>
                                    {entry.reason && (
                                        <p className="text-sm">
                                            {entry.reason}
                                        </p>
                                    )}
                                    {entry.internal_note && (
                                        <p className="text-muted-foreground text-xs">
                                            {entry.internal_note}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ol>
                    )}
                </SectionCard>
            </PageContainer>
        </>
    );
}

AdminWebsiteShow.layout = {
    breadcrumbs: [
        {
            title: 'Partner websites',
            href: index(),
        },
    ],
};
