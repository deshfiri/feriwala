import { Head, Link } from '@inertiajs/react';
import DetailList, { DetailItem } from '@/components/detail-list';
import Notice from '@/components/notice';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { index as ordersIndex } from '@/routes/orders';
import { index } from '@/routes/stores';
import type { StatusTone } from '@/lib/status';
import type { ExternalStoreDetail } from '@/types/stores';

type Props = { store: ExternalStoreDetail };

const webhookTones: Record<string, StatusTone> = {
    received: 'info',
    processed: 'success',
    ignored: 'neutral',
    failed: 'danger',
    deferred: 'warning',
};

/**
 * One previously connected store, read-only (D29, as amended by D30): how it
 * stood, what it sent, and the orders it brought in.
 *
 * Owners no longer connect, test, sync, re-key or disconnect stores
 * themselves, so nothing here changes the connection. No credential or
 * secret is ever on this page.
 */
export default function ShowStore({ store }: Props) {
    const { t, locale } = useTranslation();

    const when = (value: string | null) =>
        value ? new Date(value).toLocaleString(locale) : t('stores.never');

    return (
        <>
            <Head title={store.name} />

            <PageContainer>
                <PageHeader
                    title={store.name}
                    description={`${store.platform_label} · ${store.base_url}`}
                    back={{ href: index(), label: t('stores.title') }}
                    meta={
                        <StatusPill
                            tone={store.status_tone}
                            label={store.status_label}
                        />
                    }
                    actions={
                        <Button variant="outline" size="sm" asChild>
                            <Link
                                href={ordersIndex({
                                    query: { store: `external:${store.id}` },
                                })}
                            >
                                {t('stores.actions.orders')}
                            </Link>
                        </Button>
                    }
                />

                <Notice tone="neutral">{t('stores.retired_notice')}</Notice>

                {store.last_error && (
                    <Notice
                        tone={store.status === 'failing' ? 'danger' : 'warning'}
                    >
                        {store.last_error}
                    </Notice>
                )}

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="space-y-6 lg:col-span-2">
                        <SectionCard title={t('stores.show.details')}>
                            <DetailList columns={2}>
                                <DetailItem
                                    label={t('stores.columns.platform')}
                                >
                                    {store.platform_label}
                                </DetailItem>
                                <DetailItem
                                    label={t('stores.show.connected_at')}
                                >
                                    {when(store.connected_at)}
                                </DetailItem>
                                <DetailItem
                                    label={t('stores.columns.last_success')}
                                >
                                    {when(store.last_success_at)}
                                </DetailItem>
                                <DetailItem
                                    label={t('stores.show.last_webhook')}
                                >
                                    {when(store.last_webhook_at)}
                                </DetailItem>
                                <DetailItem label={t('stores.columns.orders')}>
                                    <span className="tabular">
                                        {store.orders_count.toLocaleString(
                                            locale,
                                        )}
                                    </span>
                                </DetailItem>
                            </DetailList>
                        </SectionCard>

                        <SectionCard title={t('stores.show.notifications')}>
                            {store.webhook_events.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    {t('stores.show.notifications_empty')}
                                </p>
                            ) : (
                                <ul className="divide-y">
                                    {store.webhook_events.map(
                                        (event, position) => (
                                            <li
                                                key={`${event.event}-${event.received_at}-${position}`}
                                                className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm"
                                            >
                                                <div className="min-w-0">
                                                    <code className="font-mono text-xs">
                                                        {event.event}
                                                    </code>
                                                    <div className="text-muted-foreground text-xs">
                                                        {when(
                                                            event.received_at,
                                                        )}
                                                        {event.duplicates > 0 &&
                                                            ` · ×${event.duplicates + 1}`}
                                                    </div>
                                                    {event.error && (
                                                        <div className="text-danger text-xs">
                                                            {event.error}
                                                        </div>
                                                    )}
                                                </div>
                                                <StatusPill
                                                    tone={
                                                        webhookTones[
                                                            event.state
                                                        ] ?? 'neutral'
                                                    }
                                                    label={t(
                                                        `stores.show.webhook_states.${event.state}`,
                                                    )}
                                                />
                                            </li>
                                        ),
                                    )}
                                </ul>
                            )}
                        </SectionCard>
                    </div>

                    <div className="space-y-6">
                        <SectionCard title={t('stores.show.history')}>
                            <ol className="space-y-3">
                                {store.history.map((change, position) => (
                                    <li
                                        key={`${change.at}-${position}`}
                                        className="text-sm"
                                    >
                                        <div className="font-medium">
                                            {change.status_label}
                                        </div>
                                        <div className="text-muted-foreground text-xs">
                                            {when(change.at)} ·{' '}
                                            {t(
                                                `stores.show.sources.${change.source}`,
                                            )}
                                        </div>
                                        {change.reason && (
                                            <div className="text-muted-foreground text-xs">
                                                {change.reason}
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ol>
                        </SectionCard>
                    </div>
                </div>
            </PageContainer>
        </>
    );
}

ShowStore.layout = {
    breadcrumbs: [{ title: 'nav.stores', href: index() }],
};
