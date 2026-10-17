import { Form, router, useHttp } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import WebsiteConnectionController from '@/actions/App/Http/Controllers/Admin/WebsiteConnectionController';
import FormField from '@/components/forms/form-field';
import InputError from '@/components/input-error';
import Notice from '@/components/notice';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import type { CredentialRow } from '@/components/website-credential-list';
import type { WebhookRow } from '@/components/website-webhook-summary';
import { useTranslation } from '@/hooks/use-translation';

/** What the server answers, once, to connecting a store or rotating its token. */
export type ConnectedStore = {
    store_id: string;
    token: string;
    address: string;
    local: boolean;
    webhook_url: string | null;
    webhook_secret: string | null;
    erp_base_url: string;
    env: string;
};

/**
 * The partner's storefront connection, in one card.
 *
 * Not connected: one address in, a Store ID and a token out. Connected: who it
 * is, when it was last used, and the two things staff actually do — a new token,
 * or disconnect. Everything finer (scopes, a hand-made credential, the webhook
 * form) sits under "Advanced" on the page.
 *
 * A token comes back once in the JSON response and is held only in this page's
 * memory until the dialog is closed — never in the session, a flash message or a
 * prop, so a reload cannot bring it back.
 */
export default function ConnectStore({
    websiteId,
    address,
    credentials,
    webhook,
}: {
    websiteId: string;
    address: string;
    credentials: CredentialRow[];
    webhook: WebhookRow | null;
}) {
    const { t, locale } = useTranslation();
    const [done, setDone] = useState<ConnectedStore | null>(null);
    const [copied, setCopied] = useState(false);
    const [rotating, setRotating] = useState(false);

    const live = credentials.find((credential) => !credential.revoked_at);
    const connect = useHttp<{ address: string }, ConnectedStore>({
        address: '',
    });
    const rotation = useHttp<Record<string, never>, ConnectedStore>({});

    const errors = connect.errors as Record<string, string | undefined>;
    const rotationErrors = rotation.errors as Record<
        string,
        string | undefined
    >;

    const when = (value: string | null) =>
        value ? new Date(value).toLocaleString(locale) : '—';

    async function submit(event: FormEvent) {
        event.preventDefault();

        try {
            const answer = await connect.post(
                WebsiteConnectionController.store.url(websiteId),
            );
            setDone(answer);
            connect.reset();
            router.reload({ only: ['credentials', 'webhook'] });
        } catch {
            // The refusal is on connect.errors.
        }
    }

    async function rotate() {
        if (!window.confirm(t('website.connect.rotate_confirm'))) {
            return;
        }

        setRotating(true);

        try {
            const answer = await rotation.post(
                WebsiteConnectionController.rotate.url(websiteId),
            );
            setDone(answer);
            router.reload({ only: ['credentials'] });
        } catch {
            // The refusal is on rotation.errors.
        } finally {
            setRotating(false);
        }
    }

    async function copy(block: string) {
        try {
            await navigator.clipboard.writeText(block);
            setCopied(true);
        } catch {
            setCopied(false);
        }
    }

    const close = () => {
        setDone(null);
        setCopied(false);
    };

    return (
        <>
            <SectionCard
                title={t('website.connect.title')}
                description={
                    live
                        ? t('website.connect.connected_hint')
                        : t('website.connect.hint')
                }
                actions={
                    <StatusPill
                        tone={live ? 'success' : 'neutral'}
                        label={t(
                            live
                                ? 'website.connect.state_connected'
                                : 'website.connect.state_not_connected',
                        )}
                    />
                }
            >
                {!live && (
                    <form onSubmit={submit} className="space-y-4">
                        <FormField
                            label={t('website.connect.address')}
                            description={t('website.connect.address_hint')}
                            error={errors.address}
                            required
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    name="address"
                                    value={connect.data.address}
                                    onChange={(event) =>
                                        connect.setData(
                                            'address',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="shop.example.com"
                                    autoComplete="off"
                                    maxLength={253}
                                    required
                                />
                            )}
                        </FormField>

                        <Button
                            type="submit"
                            disabled={
                                connect.processing ||
                                connect.data.address.trim() === ''
                            }
                        >
                            {connect.processing && <Spinner />}
                            {t('website.connect.submit')}
                        </Button>
                    </form>
                )}

                {live && (
                    <div className="space-y-5">
                        <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[10rem_1fr]">
                            <dt className="text-muted-foreground">
                                {t('website.connect.address')}
                            </dt>
                            <dd>{address}</dd>

                            <dt className="text-muted-foreground">
                                {t('website.connect.store_id')}
                            </dt>
                            <dd>
                                <code className="font-mono text-xs break-all">
                                    {live.key_id}
                                </code>
                            </dd>

                            <dt className="text-muted-foreground">
                                {t('website.connect.token')}
                            </dt>
                            <dd>
                                <code className="font-mono text-xs">
                                    ••••{live.secret_hint}
                                </code>
                            </dd>

                            <dt className="text-muted-foreground">
                                {t('website.connect.last_used')}
                            </dt>
                            <dd>{when(live.last_used_at)}</dd>

                            <dt className="text-muted-foreground">
                                {t('website.connect.webhook')}
                            </dt>
                            <dd>
                                {webhook?.is_active
                                    ? webhook.url
                                    : t('website.connect.webhook_none')}
                            </dd>
                        </dl>

                        {live.previous_secret_expires_at && (
                            <Notice tone="info" inset>
                                {t('website.integration.grace_until', {
                                    date: when(live.previous_secret_expires_at),
                                })}
                            </Notice>
                        )}

                        <div className="flex flex-wrap items-center gap-3">
                            <Button
                                type="button"
                                variant="outline"
                                disabled={rotating}
                                onClick={rotate}
                            >
                                {rotating && <Spinner />}
                                {t('website.connect.rotate')}
                            </Button>
                        </div>
                        <InputError message={rotationErrors.address} />

                        <details className="rounded-lg border p-3">
                            <summary className="text-danger cursor-pointer text-sm font-medium">
                                {t('website.connect.disconnect_title')}
                            </summary>

                            <Form
                                {...WebsiteConnectionController.destroy.form(
                                    websiteId,
                                )}
                                options={{ preserveScroll: true }}
                                className="mt-3 flex flex-wrap items-end gap-3"
                            >
                                {({ processing, errors: formErrors }) => (
                                    <>
                                        <div className="grid min-w-64 flex-1 gap-1">
                                            <label
                                                htmlFor="disconnect-reason"
                                                className="text-sm font-medium"
                                            >
                                                {t(
                                                    'website.connect.disconnect_reason',
                                                )}
                                            </label>
                                            <Input
                                                id="disconnect-reason"
                                                name="reason"
                                                minLength={5}
                                                maxLength={500}
                                                required
                                            />
                                            <InputError
                                                message={
                                                    formErrors.reason ??
                                                    formErrors.address
                                                }
                                            />
                                        </div>
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={processing}
                                        >
                                            {t(
                                                'website.connect.disconnect_submit',
                                            )}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </details>
                    </div>
                )}
            </SectionCard>

            <Dialog
                open={done !== null}
                onOpenChange={(open) => !open && close()}
            >
                {done && (
                    <DialogContent className="sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>
                                {t('website.connect.done_title')}
                            </DialogTitle>
                            <DialogDescription>
                                {t('website.connect.done_hint')}
                            </DialogDescription>
                        </DialogHeader>

                        <dl className="grid gap-2 text-sm sm:grid-cols-[8rem_1fr]">
                            <dt className="text-muted-foreground">
                                {t('website.connect.store_id')}
                            </dt>
                            <dd>
                                <code className="font-mono text-xs break-all">
                                    {done.store_id}
                                </code>
                            </dd>
                            <dt className="text-muted-foreground">
                                {t('website.connect.token')}
                            </dt>
                            <dd>
                                <code className="font-mono text-xs break-all">
                                    {done.token}
                                </code>
                            </dd>
                        </dl>

                        <div className="grid gap-1">
                            <span className="text-muted-foreground text-xs">
                                {t('website.connect.env')}
                            </span>
                            <pre className="bg-surface-subtle overflow-x-auto rounded-lg p-3 font-mono text-xs break-all whitespace-pre-wrap">
                                {done.env}
                            </pre>
                        </div>

                        <Notice tone="info" inset>
                            {done.webhook_url
                                ? t('website.connect.webhook_registered', {
                                      url: done.webhook_url,
                                  })
                                : t('website.connect.webhook_skipped')}
                        </Notice>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => copy(done.env)}
                            >
                                {copied
                                    ? t('website.connect.copied')
                                    : t('website.connect.copy')}
                            </Button>
                            <Button type="button" onClick={close}>
                                {t('website.connect.close')}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                )}
            </Dialog>
        </>
    );
}
