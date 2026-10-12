import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import DetailList, { DetailItem } from '@/components/detail-list';
import ConfirmDialog from '@/components/forms/confirm-dialog';
import FormField from '@/components/forms/form-field';
import Notice from '@/components/notice';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import ExternalStoreController from '@/actions/App/Http/Controllers/Erp/ExternalStoreController';
import { index as ordersIndex } from '@/routes/orders';
import { edit, index } from '@/routes/stores';
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
 * One store connection: how it stands, how to wire its webhook, and what it
 * has sent (D29).
 *
 * The signing secret is shown once — read from the flash on the response that
 * made it, kept only in this component's state — and never comes back with a
 * reload. A secret the store generated can be entered instead; it goes one
 * way, and the field is cleared once it is saved. Nothing else secret is ever
 * on this page.
 */
export default function ShowStore({ store }: Props) {
    const { t, locale } = useTranslation();
    const page = usePage();
    const initial = (page.flash as Record<string, unknown> | undefined)
        ?.webhook_secret;
    const [secret, setSecret] = useState<string | null>(
        typeof initial === 'string' ? initial : null,
    );
    const [disconnecting, setDisconnecting] = useState(false);
    const ownSecret = useForm({ webhook_secret: '' });

    useEffect(
        () =>
            router.on('flash', (event) => {
                const flash = (event as CustomEvent).detail?.flash;

                if (typeof flash?.webhook_secret === 'string') {
                    setSecret(flash.webhook_secret);
                }
            }),
        [],
    );

    const live = store.status !== 'disconnected';
    const when = (value: string | null) =>
        value ? new Date(value).toLocaleString(locale) : t('stores.never');
    const post = (url: string) =>
        router.post(url, {}, { preserveScroll: true });
    const saveOwnSecret = (event: FormEvent) => {
        event.preventDefault();
        ownSecret.put(
            ExternalStoreController.provideWebhookSecret.url(store.id),
            { preserveScroll: true, onFinish: () => ownSecret.reset() },
        );
    };

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
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <Link
                                    href={ordersIndex({
                                        query: {
                                            store: `external:${store.id}`,
                                        },
                                    })}
                                >
                                    {t('stores.actions.orders')}
                                </Link>
                            </Button>
                            {live ? (
                                <>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            post(
                                                ExternalStoreController.test.url(
                                                    store.id,
                                                ),
                                            )
                                        }
                                    >
                                        {t('stores.actions.test')}
                                    </Button>
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            post(
                                                ExternalStoreController.sync.url(
                                                    store.id,
                                                ),
                                            )
                                        }
                                    >
                                        {t('stores.actions.sync')}
                                    </Button>
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={edit(store.id)}>
                                            {t('stores.actions.edit')}
                                        </Link>
                                    </Button>
                                </>
                            ) : (
                                <Button size="sm" asChild>
                                    <Link href={edit(store.id)}>
                                        {t('stores.actions.reconnect')}
                                    </Link>
                                </Button>
                            )}
                        </div>
                    }
                />

                {!live && (
                    <Notice tone="neutral">
                        {t('stores.show.disconnected_notice')}
                    </Notice>
                )}

                {store.last_error && (
                    <Notice
                        tone={store.status === 'failing' ? 'danger' : 'warning'}
                    >
                        {store.last_error}
                    </Notice>
                )}

                {secret && (
                    <Notice
                        tone="warning"
                        role="alert"
                        title={t('stores.show.webhook_secret_once')}
                        action={
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setSecret(null)}
                            >
                                {t('stores.show.webhook_stored')}
                            </Button>
                        }
                    >
                        <code className="font-mono break-all">{secret}</code>
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
                                <DetailItem label={t('stores.show.api_key')}>
                                    {store.api_key_hint
                                        ? t('stores.show.api_key_hint', {
                                              hint: store.api_key_hint,
                                          })
                                        : '—'}
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
                                <DetailItem label={t('stores.show.failures')}>
                                    <span className="tabular">
                                        {store.consecutive_failures.toLocaleString(
                                            locale,
                                        )}
                                    </span>
                                </DetailItem>
                            </DetailList>
                        </SectionCard>

                        {live && (
                            <SectionCard
                                title={t('stores.show.webhook_title')}
                                description={t(
                                    'stores.show.webhook_description',
                                )}
                                actions={
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            post(
                                                ExternalStoreController.rotateWebhookSecret.url(
                                                    store.id,
                                                ),
                                            )
                                        }
                                    >
                                        {t('stores.actions.rotate_secret')}
                                    </Button>
                                }
                            >
                                <DetailList>
                                    <DetailItem
                                        label={t('stores.show.webhook_url')}
                                    >
                                        <code className="font-mono text-xs break-all">
                                            {store.webhook.url}
                                        </code>
                                    </DetailItem>
                                    <DetailItem
                                        label={t('stores.show.webhook_secret')}
                                    >
                                        <span className="text-muted-foreground text-sm font-normal">
                                            {t(
                                                'stores.show.webhook_secret_hidden',
                                            )}
                                        </span>
                                    </DetailItem>
                                    <DetailItem
                                        label={t('stores.show.webhook_events')}
                                    >
                                        <div className="flex flex-wrap gap-1">
                                            {store.webhook.events.map(
                                                (event) => (
                                                    <code
                                                        key={event}
                                                        className="bg-surface-subtle rounded px-1.5 py-0.5 font-mono text-xs"
                                                    >
                                                        {event}
                                                    </code>
                                                ),
                                            )}
                                        </div>
                                    </DetailItem>
                                </DetailList>
                                <p className="text-muted-foreground mt-3 text-xs">
                                    {t('stores.show.webhook_headers', {
                                        signature:
                                            store.webhook.signature_header,
                                        event_id: store.webhook.event_id_header,
                                    })}
                                </p>
                                <form
                                    onSubmit={saveOwnSecret}
                                    className="mt-6 space-y-3 border-t pt-4"
                                >
                                    <div>
                                        <h3 className="text-sm font-medium">
                                            {t('stores.show.own_secret_title')}
                                        </h3>
                                        <p className="text-muted-foreground text-xs">
                                            {t(
                                                'stores.show.own_secret_description',
                                            )}
                                        </p>
                                    </div>
                                    <FormField
                                        label={t('stores.show.webhook_secret')}
                                        error={ownSecret.errors.webhook_secret}
                                        required
                                    >
                                        {(field) => (
                                            <Input
                                                {...field}
                                                type="password"
                                                value={
                                                    ownSecret.data
                                                        .webhook_secret
                                                }
                                                onChange={(event) =>
                                                    ownSecret.setData(
                                                        'webhook_secret',
                                                        event.target.value,
                                                    )
                                                }
                                                autoComplete="new-password"
                                                spellCheck={false}
                                            />
                                        )}
                                    </FormField>
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="outline"
                                        disabled={
                                            ownSecret.processing ||
                                            ownSecret.data.webhook_secret === ''
                                        }
                                    >
                                        {t('stores.show.own_secret_save')}
                                    </Button>
                                </form>
                            </SectionCard>
                        )}

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

                        {live && (
                            <SectionCard
                                title={t('stores.show.disconnect_title')}
                                description={t(
                                    'stores.show.disconnect_description',
                                )}
                                tone="destructive"
                            >
                                <ConfirmDialog
                                    trigger={
                                        <Button variant="destructive" size="sm">
                                            {t('stores.actions.disconnect')}
                                        </Button>
                                    }
                                    title={t('stores.actions.disconnect')}
                                    description={t(
                                        'stores.show.disconnect_confirm',
                                        { name: store.name },
                                    )}
                                    confirmLabel={t(
                                        'stores.actions.disconnect',
                                    )}
                                    destructive
                                    processing={disconnecting}
                                    onConfirm={() =>
                                        router.delete(
                                            ExternalStoreController.destroy.url(
                                                store.id,
                                            ),
                                            {
                                                onStart: () =>
                                                    setDisconnecting(true),
                                                onFinish: () =>
                                                    setDisconnecting(false),
                                            },
                                        )
                                    }
                                />
                            </SectionCard>
                        )}
                    </div>
                </div>
            </PageContainer>
        </>
    );
}

ShowStore.layout = {
    breadcrumbs: [{ title: 'nav.stores', href: index() }],
};
