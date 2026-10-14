import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import WebsiteWebhookSummary, {
    type WebhookRow,
} from '@/components/website-webhook-summary';
import WebsiteCredentialList, {
    type CredentialRow,
} from '@/components/website-credential-list';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import WebsiteIntegrationController from '@/actions/App/Http/Controllers/Erp/WebsiteIntegrationController';
import { websiteHealthTone } from '@/lib/website';
import { index as websitesIndex, show as websiteShow } from '@/routes/websites';
import type { WebsiteDetail } from '@/types/website';

export type { CredentialRow };

export type ApiCallRow = {
    request_id: string;
    method: string;
    path: string;
    status: number;
    error_code: string | null;
    duration_ms: number;
    created_at: string;
};

export type { WebhookRow };

export type DeliveryRow = {
    event_id: string;
    event_type: string;
    state: string;
    attempt: number;
    response_status: number | null;
    last_error: string | null;
    next_retry_at: string | null;
    delivered_at: string | null;
    created_at: string;
};

export type FailureRow = {
    id: string;
    event_id: string | null;
    event_type: string | null;
    error: string;
    attempts: number;
    retry_count: number;
    failed_at: string;
};

type Props = {
    website: WebsiteDetail;
    credentials: CredentialRow[];
    recent_calls: ApiCallRow[];
    api_base: string;
    webhook?: WebhookRow | null;
    deliveries?: DeliveryRow[];
    failures?: FailureRow[];
};

const deliveryTones: Record<string, 'success' | 'warning' | 'danger' | 'info'> =
    {
        delivered: 'success',
        pending: 'info',
        retrying: 'warning',
        failed: 'danger',
    };

/**
 * Connecting a storefront to the ERP (§17.3, contract §3, P5-17, P5-27, P5-28).
 *
 * The storefront's API credentials are shown, never managed, here: Banij
 * administration issues, rotates and revokes them and configures the storefront
 * by hand (D32 §2), so no credential secret ever reaches this screen.
 *
 * The webhook is shown the same way: its address and the last four characters
 * of its signing secret. Setting, rotating and switching it off are Banij
 * administration's (Option P1). Retrying a failed delivery and synchronising
 * stay here.
 */
export default function WebsiteIntegration({
    website,
    credentials,
    recent_calls: recentCalls,
    api_base: apiBase,
    webhook = null,
    deliveries = [],
    failures = [],
}: Props) {
    const { t, locale } = useTranslation();
    const when = (value: string | null) =>
        value
            ? new Date(value).toLocaleString(locale)
            : t('website.integration.never');

    return (
        <>
            <Head title={t('website.integration.title')} />

            <PageContainer>
                <PageHeader
                    title={t('website.integration.title')}
                    description={`${website.name} · ${apiBase}`}
                    actions={
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={websiteShow(website.id)}>
                                {website.name}
                            </Link>
                        </Button>
                    }
                />

                <div className="flex flex-wrap items-center gap-2">
                    <StatusPill
                        tone={websiteHealthTone(website.connection.health)}
                        label={website.connection.health_label}
                    />
                    <span className="text-muted-foreground text-sm">
                        {t('website.integration.connected_at')}:{' '}
                        {when(website.connection.api_connected_at)}
                    </span>
                </div>

                <SectionCard
                    title={t('website.integration.credentials')}
                    description={t('website.integration.managed_by_admin')}
                >
                    <WebsiteCredentialList
                        credentials={credentials}
                        emptyHelp={t(
                            'website.integration.no_credentials_owner',
                        )}
                    />
                </SectionCard>

                <SectionCard
                    title={t('website.integration.webhook_title')}
                    description={t('website.integration.webhook_hint')}
                >
                    <div className="space-y-4">
                        <WebsiteWebhookSummary webhook={webhook} />

                        <p className="text-muted-foreground text-xs">
                            {t('website.integration.webhook_managed_by_admin')}
                        </p>

                        {webhook && (
                            <Form
                                {...WebsiteIntegrationController.syncNow.form(
                                    website.id,
                                )}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing, errors }) => (
                                    <div className="space-y-1">
                                        <Button
                                            type="submit"
                                            size="sm"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            {t('website.integration.sync_now')}
                                        </Button>
                                        <InputError message={errors.sync} />
                                    </div>
                                )}
                            </Form>
                        )}
                    </div>
                </SectionCard>

                <SectionCard
                    title={t('website.integration.failures_title')}
                    description={t('website.integration.failures_hint')}
                >
                    {failures.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            {t('website.integration.no_failures')}
                        </p>
                    ) : (
                        <ul className="divide-border divide-y">
                            {failures.map((failure) => (
                                <li
                                    key={failure.id}
                                    className="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0"
                                >
                                    <div className="min-w-0 space-y-1 text-xs">
                                        <div className="font-mono text-sm">
                                            {failure.event_type}
                                        </div>
                                        <div className="text-muted-foreground break-all">
                                            {failure.error}
                                        </div>
                                        <div className="text-muted-foreground">
                                            {t(
                                                'website.integration.failed_after',
                                                {
                                                    attempts: String(
                                                        failure.attempts,
                                                    ),
                                                    date: when(
                                                        failure.failed_at,
                                                    ),
                                                },
                                            )}
                                        </div>
                                    </div>

                                    {failure.event_id && (
                                        <Form
                                            {...WebsiteIntegrationController.retryDelivery.form(
                                                {
                                                    website: website.id,
                                                    delivery: failure.event_id,
                                                },
                                            )}
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing, errors }) => (
                                                <div className="space-y-1">
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        disabled={processing}
                                                    >
                                                        {processing && (
                                                            <Spinner />
                                                        )}
                                                        {t(
                                                            'website.integration.retry',
                                                        )}
                                                    </Button>
                                                    <InputError
                                                        message={
                                                            errors.delivery
                                                        }
                                                    />
                                                </div>
                                            )}
                                        </Form>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>

                <SectionCard title={t('website.integration.deliveries_title')}>
                    {deliveries.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            {t('website.integration.no_deliveries')}
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-xs">
                                <thead className="text-muted-foreground">
                                    <tr>
                                        <th className="py-2 pr-3 font-medium">
                                            {t('website.integration.when')}
                                        </th>
                                        <th className="py-2 pr-3 font-medium">
                                            {t('website.integration.event')}
                                        </th>
                                        <th className="py-2 pr-3 font-medium">
                                            {t('website.integration.status')}
                                        </th>
                                        <th className="py-2 font-medium">
                                            {t('website.integration.attempts')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-border divide-y">
                                    {deliveries.map((delivery) => (
                                        <tr key={delivery.event_id}>
                                            <td className="py-2 pr-3 whitespace-nowrap">
                                                {when(delivery.created_at)}
                                            </td>
                                            <td className="py-2 pr-3 font-mono">
                                                {delivery.event_type}
                                            </td>
                                            <td className="py-2 pr-3">
                                                <StatusPill
                                                    tone={
                                                        deliveryTones[
                                                            delivery.state
                                                        ] ?? 'neutral'
                                                    }
                                                    label={t(
                                                        `website.integration.delivery_states.${delivery.state}`,
                                                    )}
                                                />
                                                {delivery.next_retry_at && (
                                                    <div className="text-muted-foreground mt-1">
                                                        {t(
                                                            'website.integration.next_retry',
                                                            {
                                                                date: when(
                                                                    delivery.next_retry_at,
                                                                ),
                                                            },
                                                        )}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="py-2">
                                                {delivery.attempt}
                                                {delivery.response_status
                                                    ? ` · ${delivery.response_status}`
                                                    : ''}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </SectionCard>

                <SectionCard
                    title={t('website.integration.recent_calls')}
                    description={t('website.integration.recent_calls_hint')}
                >
                    {recentCalls.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            {t('website.integration.no_calls')}
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-xs">
                                <thead className="text-muted-foreground">
                                    <tr>
                                        <th className="py-2 pr-3 font-medium">
                                            {t('website.integration.when')}
                                        </th>
                                        <th className="py-2 pr-3 font-medium">
                                            {t('website.integration.call')}
                                        </th>
                                        <th className="py-2 pr-3 font-medium">
                                            {t('website.integration.status')}
                                        </th>
                                        <th className="py-2 font-medium">
                                            {t(
                                                'website.integration.request_id',
                                            )}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-border divide-y">
                                    {recentCalls.map((call) => (
                                        <tr key={call.request_id}>
                                            <td className="py-2 pr-3 whitespace-nowrap">
                                                {when(call.created_at)}
                                            </td>
                                            <td className="py-2 pr-3 font-mono">
                                                {call.method} {call.path}
                                            </td>
                                            <td className="py-2 pr-3">
                                                <StatusPill
                                                    tone={
                                                        call.status < 400
                                                            ? 'success'
                                                            : call.status < 500
                                                              ? 'warning'
                                                              : 'danger'
                                                    }
                                                    label={`${call.status}${call.error_code ? ` · ${call.error_code}` : ''}`}
                                                />
                                            </td>
                                            <td className="py-2 font-mono">
                                                {call.request_id}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </SectionCard>
            </PageContainer>
        </>
    );
}

WebsiteIntegration.layout = {
    breadcrumbs: [
        {
            title: 'nav.websites',
            href: websitesIndex(),
        },
    ],
};
