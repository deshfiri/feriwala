import { router, useHttp } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import WebsiteConnectionController from '@/actions/App/Http/Controllers/Admin/WebsiteConnectionController';
import FormField from '@/components/forms/form-field';
import Notice from '@/components/notice';
import SectionCard from '@/components/section-card';
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
import { useTranslation } from '@/hooks/use-translation';

/** What the server answers, once, to connecting a store. */
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
 * Connect a partner's storefront in one step, on their behalf.
 *
 * One address in; a Store ID and a token out, with the whole `.env` block the
 * storefront needs. The answer comes back once in the JSON response and is held
 * only in this page's memory until the dialog is closed — never in the
 * session, a flash message or a prop, so a reload cannot bring it back.
 */
export default function ConnectStore({
    websiteId,
    connected,
}: {
    websiteId: string;
    /** The website already has a live connection: its credentials are listed. */
    connected: boolean;
}) {
    const { t } = useTranslation();
    const [done, setDone] = useState<ConnectedStore | null>(null);
    const [copied, setCopied] = useState(false);
    const connect = useHttp<{ address: string }, ConnectedStore>({
        address: '',
    });

    const errors = connect.errors as Record<string, string | undefined>;

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
                    connected
                        ? t('website.connect.connected')
                        : t('website.connect.hint')
                }
            >
                {!connected && (
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
