import { Form, router, useHttp } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import WebsiteWebhookController from '@/actions/App/Http/Controllers/Admin/WebsiteWebhookController';
import InputError from '@/components/input-error';
import SectionCard from '@/components/section-card';
import WebsiteWebhookSummary, {
    type WebhookRow,
} from '@/components/website-webhook-summary';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';

/** What the server answers to setting or rotating the webhook. */
export type WebhookAnswer = {
    url: string;
    secret: string | null;
    secret_hint: string;
    previous_secret_expires_at: string | null;
};

/**
 * A storefront's webhook endpoint and signing secret, for the platform staff
 * explicitly allowed to manage them (contract §7.2, D32 §2, Option P1).
 *
 * Setting the address the first time and rotating are plain JSON requests: the
 * signing secret comes back once in that response and is held only in this
 * page's memory, shown in a dialog until it is closed. It is never in the
 * session, a flash message, browser storage or a page prop.
 */
export default function StorefrontWebhook({
    websiteId,
    webhook,
}: {
    websiteId: string;
    webhook: WebhookRow | null;
}) {
    const { t } = useTranslation();
    const [secret, setSecret] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);

    const address = useHttp<{ url: string }, WebhookAnswer>({
        url: webhook?.url ?? '',
    });
    const rotation = useHttp<Record<string, never>, WebhookAnswer>({});

    const refresh = () => router.reload({ only: ['webhook'] });

    const reveal = (answer: WebhookAnswer) => {
        if (answer.secret) {
            setSecret(answer.secret);
        }

        refresh();
    };

    async function saveAddress(event: FormEvent) {
        event.preventDefault();

        try {
            reveal(
                await address.put(
                    WebsiteWebhookController.store.url(websiteId),
                ),
            );
        } catch {
            // Validation errors are on address.errors.
        }
    }

    async function rotate() {
        if (!window.confirm(t('website.credentials.rotate_confirm'))) {
            return;
        }

        try {
            reveal(
                await rotation.post(
                    WebsiteWebhookController.rotate.url(websiteId),
                ),
            );
        } catch {
            // Nothing to keep; the endpoint is unchanged.
        }
    }

    const close = () => {
        setSecret(null);
        setCopied(false);
    };

    async function copySecret(value: string) {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
        } catch {
            setCopied(false);
        }
    }

    const errors = address.errors as Record<string, string | undefined>;

    return (
        <>
            <SectionCard
                title={t('website.integration.webhook_title')}
                description={t('website.integration.webhook_hint')}
            >
                <div className="space-y-4">
                    <WebsiteWebhookSummary webhook={webhook} />

                    <form
                        onSubmit={saveAddress}
                        className="flex flex-wrap items-end gap-2"
                    >
                        <div className="grid min-w-0 flex-1 gap-1">
                            <Label htmlFor="admin-webhook-url">
                                {t('website.integration.webhook_url')}
                            </Label>
                            <Input
                                id="admin-webhook-url"
                                type="url"
                                placeholder="https://"
                                value={address.data.url}
                                onChange={(event) =>
                                    address.setData('url', event.target.value)
                                }
                                maxLength={500}
                                required
                            />
                            <InputError
                                message={errors.url ?? errors.website}
                            />
                        </div>
                        <Button
                            type="submit"
                            size="sm"
                            disabled={address.processing}
                        >
                            {address.processing && <Spinner />}
                            {t('website.integration.webhook_save')}
                        </Button>
                    </form>

                    {webhook && (
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={rotation.processing}
                                onClick={rotate}
                            >
                                {rotation.processing && <Spinner />}
                                {t('website.integration.rotate')}
                            </Button>

                            {webhook.is_active && (
                                <Form
                                    {...WebsiteWebhookController.disable.form(
                                        websiteId,
                                    )}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant="ghost"
                                            disabled={processing}
                                        >
                                            {t('website.integration.disable')}
                                        </Button>
                                    )}
                                </Form>
                            )}
                        </div>
                    )}
                </div>
            </SectionCard>

            <Dialog
                open={secret !== null}
                onOpenChange={(open) => !open && close()}
            >
                {secret && (
                    <DialogContent className="sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>
                                {t('website.credentials.webhook_once_title')}
                            </DialogTitle>
                            <DialogDescription>
                                {t('website.integration.secret_once')}
                            </DialogDescription>
                        </DialogHeader>

                        <pre className="bg-surface-subtle overflow-x-auto rounded-lg p-3 font-mono text-xs break-all whitespace-pre-wrap">
                            {secret}
                        </pre>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => copySecret(secret)}
                            >
                                {copied
                                    ? t('website.credentials.copied')
                                    : t('website.credentials.copy')}
                            </Button>
                            <Button type="button" onClick={close}>
                                {t('website.integration.stored_it')}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                )}
            </Dialog>
        </>
    );
}
