import { Form, Head, router, useHttp } from '@inertiajs/react';
import { useState } from 'react';
import SteadfastSettingsController from '@/actions/App/Http/Controllers/Admin/SteadfastSettingsController';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';

type Settings = {
    enabled: boolean;
    configured: boolean;
    missing: string[];
    has_api_key: boolean;
    has_secret_key: boolean;
    has_webhook_token: boolean;
    base_url: string | null;
    default_base_url: string;
    last_test_at: string | null;
    last_test_ok: boolean;
};

const inputClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Steadfast's keys, switch and callback details.
 *
 * No saved credential is ever sent here: the page is told whether each is set.
 * A freshly generated callback token arrives once, in the JSON response that
 * created it, and is held only in this page's memory until the dialog closes.
 */
export default function Steadfast({
    settings,
    callback_url,
}: {
    settings: Settings;
    callback_url: string;
    can: { manage: boolean };
}) {
    const { t } = useTranslation();
    const [token, setToken] = useState<string | null>(null);
    const [copied, setCopied] = useState<'token' | 'url' | null>(null);
    const rotation = useHttp<Record<string, never>, { token: string }>({});

    async function generate() {
        if (
            settings.has_webhook_token &&
            !window.confirm(t('steadfast.ui.regenerate_confirm'))
        ) {
            return;
        }

        try {
            const answer = await rotation.post(
                SteadfastSettingsController.rotateWebhookToken.url(),
            );

            setToken(answer.token);
            router.reload({ only: ['settings'] });
        } catch {
            // Nothing was changed; the old token still stands.
        }
    }

    async function copy(value: string, what: 'token' | 'url') {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(what);
        } catch {
            setCopied(null);
        }
    }

    const close = () => {
        setToken(null);
        setCopied(null);
    };

    const verification = settings.last_test_at
        ? settings.last_test_ok
            ? { tone: 'success' as const, label: 'steadfast.ui.verified' }
            : { tone: 'danger' as const, label: 'steadfast.ui.failed_last' }
        : { tone: 'warning' as const, label: 'steadfast.ui.never_tested' };

    return (
        <>
            <Head title={t('steadfast.ui.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('steadfast.ui.title')}
                    description={t('steadfast.ui.description')}
                />

                <SectionCard title={t('steadfast.ui.connection_title')}>
                    <Form
                        {...SteadfastSettingsController.update.form()}
                        options={{ preserveScroll: true }}
                        resetOnSuccess={['api_key', 'secret_key']}
                        className="space-y-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="flex items-center gap-3">
                                    <input
                                        type="hidden"
                                        name="enabled"
                                        value="0"
                                    />
                                    <input
                                        id="steadfast-enabled"
                                        type="checkbox"
                                        name="enabled"
                                        value="1"
                                        defaultChecked={settings.enabled}
                                        className="accent-brand size-4"
                                    />
                                    <Label htmlFor="steadfast-enabled">
                                        {t('steadfast.ui.enabled')}
                                    </Label>
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="steadfast-base-url">
                                        {t('steadfast.ui.base_url')}
                                    </Label>
                                    <input
                                        id="steadfast-base-url"
                                        name="base_url"
                                        type="url"
                                        defaultValue={settings.base_url ?? ''}
                                        placeholder={settings.default_base_url}
                                        className={inputClass}
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        {t('steadfast.ui.base_url_help')}
                                    </p>
                                    <InputError message={errors.base_url} />
                                </div>

                                <div className="grid gap-3 sm:grid-cols-2">
                                    {(
                                        [
                                            ['api_key', settings.has_api_key],
                                            [
                                                'secret_key',
                                                settings.has_secret_key,
                                            ],
                                        ] as const
                                    ).map(([field, isSet]) => (
                                        <div
                                            key={field}
                                            className="grid gap-1.5"
                                        >
                                            <Label
                                                htmlFor={`steadfast-${field}`}
                                            >
                                                {t(`steadfast.ui.${field}`)}
                                            </Label>
                                            {/* type=password, no autofill: the saved
                                                value is never sent here, so there is
                                                nothing for a browser to put back. */}
                                            <input
                                                id={`steadfast-${field}`}
                                                name={field}
                                                type="password"
                                                autoComplete="new-password"
                                                placeholder={t(
                                                    isSet
                                                        ? 'steadfast.ui.key_set'
                                                        : 'steadfast.ui.key_missing',
                                                )}
                                                className={inputClass}
                                            />
                                            <InputError
                                                message={errors[field]}
                                            />
                                        </div>
                                    ))}
                                </div>

                                <p className="text-muted-foreground text-xs">
                                    {t('steadfast.ui.blank_help')}
                                </p>

                                {settings.missing.length > 0 && (
                                    <p className="text-sm font-medium">
                                        {t('steadfast.ui.missing', {
                                            fields: settings.missing
                                                .map((field) =>
                                                    t(`steadfast.ui.${field}`),
                                                )
                                                .join(', '),
                                        })}
                                    </p>
                                )}

                                <Button type="submit" disabled={processing}>
                                    {t('steadfast.ui.save')}
                                </Button>
                            </>
                        )}
                    </Form>

                    <div className="mt-5 space-y-3 border-t pt-4">
                        <div className="flex flex-wrap items-center gap-3">
                            {/* Labelled, never colour alone (§33.9). */}
                            <StatusPill
                                tone={verification.tone}
                                label={t(verification.label)}
                            />
                            {settings.last_test_at && (
                                <span className="text-muted-foreground text-xs">
                                    {t('steadfast.ui.last_tested', {
                                        time: new Date(
                                            settings.last_test_at,
                                        ).toLocaleString(),
                                    })}
                                </span>
                            )}
                        </div>

                        <Form
                            {...SteadfastSettingsController.test.form()}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    size="sm"
                                    disabled={
                                        processing || !settings.configured
                                    }
                                >
                                    {processing && <Spinner />}
                                    {t('steadfast.ui.test')}
                                </Button>
                            )}
                        </Form>
                        <p className="text-muted-foreground text-xs">
                            {t('steadfast.ui.test_help')}
                        </p>
                    </div>
                </SectionCard>

                <SectionCard
                    title={t('steadfast.ui.webhook_title')}
                    description={t('steadfast.ui.webhook_help')}
                >
                    <div className="space-y-4">
                        <div className="grid gap-1.5">
                            <Label htmlFor="steadfast-callback">
                                {t('steadfast.ui.callback_url')}
                            </Label>
                            <div className="flex gap-2">
                                <input
                                    id="steadfast-callback"
                                    readOnly
                                    value={callback_url}
                                    className={`${inputClass} font-mono`}
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => copy(callback_url, 'url')}
                                >
                                    {copied === 'url'
                                        ? t('steadfast.ui.copied')
                                        : t('steadfast.ui.copy')}
                                </Button>
                            </div>
                        </div>

                        <p className="text-muted-foreground text-sm">
                            {settings.has_webhook_token
                                ? t('steadfast.ui.token_status_set')
                                : t('steadfast.ui.token_status_missing')}
                        </p>

                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={rotation.processing}
                            onClick={generate}
                        >
                            {rotation.processing && <Spinner />}
                            {settings.has_webhook_token
                                ? t('steadfast.ui.regenerate_token')
                                : t('steadfast.ui.generate_token')}
                        </Button>
                    </div>
                </SectionCard>
            </PageContainer>

            <Dialog
                open={token !== null}
                onOpenChange={(open) => !open && close()}
            >
                {token && (
                    <DialogContent className="sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>
                                {t('steadfast.ui.token_once_title')}
                            </DialogTitle>
                            <DialogDescription>
                                {t('steadfast.ui.token_once')}
                            </DialogDescription>
                        </DialogHeader>

                        <pre className="bg-surface-subtle overflow-x-auto rounded-lg p-3 font-mono text-xs break-all whitespace-pre-wrap">
                            {token}
                        </pre>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => copy(token, 'token')}
                            >
                                {copied === 'token'
                                    ? t('steadfast.ui.copied')
                                    : t('steadfast.ui.copy')}
                            </Button>
                            <Button type="button" onClick={close}>
                                {t('steadfast.ui.done')}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                )}
            </Dialog>
        </>
    );
}
