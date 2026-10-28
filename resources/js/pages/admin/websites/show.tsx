import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import FormField from '@/components/forms/form-field';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import WebsiteImageCard from '@/components/website-image-card';
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
import type { StatusTone } from '@/lib/status';
import type { WebsiteDetail, WebsiteDomainRow } from '@/types/website';
import ReasonTextarea from '@/components/forms/reason-textarea';
import type { CredentialRow } from '@/components/website-credential-list';
import type { WebhookRow } from '@/components/website-webhook-summary';
import ConnectStore from './connect-store';
import StorefrontWebhook from './storefront-webhook';
import StorefrontCredentials, {
    type CredentialPresetOption,
    type CredentialScopeOption,
    type StorefrontConnection,
} from './storefront-credentials';

type Props = {
    website: WebsiteDetail;
    can: { administer: boolean; manage_integration?: boolean };
    transitions: { value: string; label: string }[];
    credentials?: CredentialRow[];
    webhook?: WebhookRow | null;
    credential_scopes?: CredentialScopeOption[];
    credential_presets?: CredentialPresetOption[];
    storefront_connection?: StorefrontConnection | null;
    image_limits: { max_bytes: number; accepted: string[] };
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
export default function AdminWebsiteShow({
    website,
    can,
    transitions,
    credentials = [],
    webhook = null,
    credential_scopes: credentialScopes = [],
    credential_presets: credentialPresets = [],
    storefront_connection: storefrontConnection = null,
    image_limits: imageLimits,
}: Props) {
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

                {can.administer &&
                    (['logo', 'banner'] as const).map((asset) => (
                        <WebsiteImageCard
                            key={asset}
                            asset={asset}
                            websiteName={website.name}
                            url={
                                asset === 'logo'
                                    ? website.logo_url
                                    : website.banner_url
                            }
                            limits={imageLimits}
                            uploadForm={WebsiteController.updateImage.form({
                                website: website.id,
                                asset,
                            })}
                            removeForm={WebsiteController.destroyImage.form({
                                website: website.id,
                                asset,
                            })}
                        />
                    ))}

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

                {can.manage_integration && storefrontConnection && (
                    <ConnectStore
                        websiteId={website.id}
                        address={website.host}
                        credentials={credentials}
                        webhook={webhook}
                    />
                )}

                {can.manage_integration && storefrontConnection && (
                    <details className="group rounded-xl border">
                        <summary className="cursor-pointer px-5 py-3 text-sm font-medium">
                            {t('website.connect.advanced')}
                            <span className="text-muted-foreground ml-2 font-normal">
                                {t('website.connect.advanced_hint')}
                            </span>
                        </summary>
                        <div className="space-y-6 p-5 pt-2">
                            <StorefrontCredentials
                                websiteId={website.id}
                                credentials={credentials}
                                scopes={credentialScopes}
                                presets={credentialPresets}
                                connection={storefrontConnection}
                            />

                            <StorefrontWebhook
                                websiteId={website.id}
                                webhook={webhook}
                            />
                        </div>
                    </details>
                )}

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
                                            <ReasonTextarea
                                                context="generic"
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

                {can.administer &&
                    transitions.some((option) => option.value === 'closed') && (
                        <SectionCard
                            tone="destructive"
                            title={t('website.admin.delete_title')}
                            description={t('website.admin.delete_hint')}
                        >
                            <Form
                                {...WebsiteController.destroy.form(website.id)}
                                onBefore={() =>
                                    window.confirm(
                                        t('website.admin.delete_confirm'),
                                    )
                                }
                                className="space-y-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="website-delete-reason">
                                                {t(
                                                    'website.admin.delete_reason',
                                                )}
                                            </Label>
                                            <textarea
                                                id="website-delete-reason"
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

                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            {t('website.admin.delete_submit')}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </SectionCard>
                    )}

                <SectionCard
                    title={t('website.show.domains')}
                    description={t('website.admin.domain_hint')}
                >
                    {website.requested_address && (
                        <p className="text-muted-foreground mb-3 text-sm">
                            {t('website.admin.requested_address')}:{' '}
                            <span className="text-foreground font-mono">
                                {website.requested_address}
                            </span>
                        </p>
                    )}
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
                                {domain.routing_status && (
                                    <DomainRouting
                                        websiteId={website.id}
                                        domain={domain}
                                        canAdminister={can.administer}
                                    />
                                )}
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

const routingTones: Record<string, StatusTone> = {
    unverified: 'neutral',
    verified: 'info',
    active: 'success',
    suspended: 'warning',
};

/**
 * Whether the shared storefront may answer on a domain, and the controls that
 * move it (D30 §3): the DNS record to publish, a check that looks for it, and
 * activation or suspension. Only the moves legal from where it stands are
 * offered; the server refuses the rest regardless.
 */
function DomainRouting({
    websiteId,
    domain,
    canAdminister,
}: {
    websiteId: string;
    domain: WebsiteDomainRow;
    canAdminister: boolean;
}) {
    const { t, locale } = useTranslation();
    const status = domain.routing_status ?? 'unverified';
    const params = { website: websiteId, domain: domain.id };
    const when = (value: string | null | undefined) =>
        value ? new Date(value).toLocaleString(locale) : '—';

    return (
        <div
            className="bg-surface-subtle mt-2 space-y-2 rounded-lg border p-3 text-xs"
            data-test={`domain-routing-${domain.id}`}
        >
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">
                    {t('website.admin.routing_title')}
                </span>
                <StatusPill
                    tone={routingTones[status] ?? 'neutral'}
                    label={domain.routing_status_label ?? status}
                />
            </div>

            {domain.verification && status === 'unverified' && (
                <div className="space-y-1">
                    <p className="text-muted-foreground">
                        {t('website.admin.routing_record_hint')}
                    </p>
                    <div className="font-mono break-all">
                        TXT {domain.verification.record_name}
                    </div>
                    <div className="font-mono break-all">
                        {domain.verification.record_value}
                    </div>
                    {domain.verification.checked_at && (
                        <p className="text-muted-foreground">
                            {t('website.admin.routing_checked', {
                                date: when(domain.verification.checked_at),
                            })}
                            {domain.verification.error
                                ? ` · ${domain.verification.error}`
                                : ''}
                        </p>
                    )}
                </div>
            )}

            {domain.verified_at && (
                <p className="text-muted-foreground">
                    {t('website.admin.routing_verified', {
                        date: when(domain.verified_at),
                    })}
                </p>
            )}

            {canAdminister && (
                <div className="flex flex-wrap items-start gap-2">
                    <Form
                        {...WebsiteController.issueDomainVerification.form(
                            params,
                        )}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                size="sm"
                                variant="outline"
                                disabled={processing}
                            >
                                {domain.verification
                                    ? t('website.admin.routing_reissue')
                                    : t('website.admin.routing_issue')}
                            </Button>
                        )}
                    </Form>

                    {status === 'unverified' && domain.verification && (
                        <Form
                            {...WebsiteController.checkDomainVerification.form(
                                params,
                            )}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={processing}
                                >
                                    {t('website.admin.routing_check')}
                                </Button>
                            )}
                        </Form>
                    )}

                    {(status === 'verified' || status === 'suspended') && (
                        <Form
                            {...WebsiteController.activateDomain.form(params)}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing, errors }) => (
                                <>
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        {t('website.admin.routing_activate')}
                                    </Button>
                                    <InputError message={errors.domain} />
                                </>
                            )}
                        </Form>
                    )}

                    {status === 'active' && (
                        <Form
                            {...WebsiteController.suspendDomain.form(params)}
                            options={{ preserveScroll: true }}
                            className="flex w-full flex-col gap-2"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <textarea
                                        name="reason"
                                        required
                                        minLength={10}
                                        maxLength={500}
                                        rows={2}
                                        className={controlClass}
                                        aria-label={t(
                                            'website.admin.routing_suspend_reason',
                                        )}
                                        placeholder={t(
                                            'website.admin.routing_suspend_reason',
                                        )}
                                    />
                                    <InputError message={errors.reason} />
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="destructive"
                                        disabled={processing}
                                        className="self-start"
                                    >
                                        {t('website.admin.routing_suspend')}
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}
                </div>
            )}
        </div>
    );
}

AdminWebsiteShow.layout = {
    breadcrumbs: [
        {
            title: 'nav.partner_websites',
            href: index(),
        },
    ],
};
