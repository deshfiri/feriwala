import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { FormEvent } from 'react';
import FormField from '@/components/forms/form-field';
import NativeSelect from '@/components/forms/native-select';
import InputError from '@/components/input-error';
import Notice from '@/components/notice';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import ExternalStoreController from '@/actions/App/Http/Controllers/Erp/ExternalStoreController';
import { index } from '@/routes/stores';
import type { StoreAllowance } from '@/types/stores';

type Props = {
    allowance: StoreAllowance;
    platforms: { value: string; label: string }[];
};

/**
 * Connecting one of the partner's own stores (D29).
 *
 * "Check connection" asks the store with these credentials and saves nothing;
 * "Connect store" checks again and saves. The credentials go to the server and
 * are never sent back to this page.
 */
export default function ConnectStore({ allowance, platforms }: Props) {
    const { t } = useTranslation();
    const mayConnect =
        allowance.allowed &&
        (allowance.remaining === null || allowance.remaining > 0);

    const form = useForm({
        platform: platforms[0]?.value ?? 'dfcommerce',
        name: '',
        base_url: '',
        api_key: '',
        api_secret: '',
        webhook_secret: '',
    });

    // The server also reports on the connection and the package, beside the fields.
    const errors = form.errors as Record<string, string | undefined>;

    const check = () =>
        form.post(ExternalStoreController.check.url(), {
            preserveScroll: true,
        });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(ExternalStoreController.store.url());
    };

    return (
        <>
            <Head title={t('stores.form.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('stores.form.title')}
                    description={t('stores.form.description')}
                    actions={
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={index()}>
                                <ArrowLeft aria-hidden="true" />
                                {t('stores.title')}
                            </Link>
                        </Button>
                    }
                />

                {!allowance.allowed && (
                    <Notice tone="warning">{t('stores.not_entitled')}</Notice>
                )}

                <SectionCard>
                    <form onSubmit={submit} className="space-y-4" noValidate>
                        <FormField
                            label={t('stores.form.platform')}
                            error={form.errors.platform}
                            required
                        >
                            {(field) => (
                                <NativeSelect
                                    {...field}
                                    value={form.data.platform}
                                    onChange={(event) =>
                                        form.setData(
                                            'platform',
                                            event.target.value,
                                        )
                                    }
                                >
                                    {platforms.map((platform) => (
                                        <option
                                            key={platform.value}
                                            value={platform.value}
                                        >
                                            {platform.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            )}
                        </FormField>

                        <FormField
                            label={t('stores.form.name')}
                            description={t('stores.form.name_hint')}
                            error={form.errors.name}
                            required
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                    maxLength={120}
                                    autoComplete="off"
                                />
                            )}
                        </FormField>

                        <FormField
                            label={t('stores.form.base_url')}
                            description={t('stores.form.base_url_hint')}
                            error={form.errors.base_url}
                            required
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    type="url"
                                    inputMode="url"
                                    placeholder="https://"
                                    value={form.data.base_url}
                                    onChange={(event) =>
                                        form.setData(
                                            'base_url',
                                            event.target.value,
                                        )
                                    }
                                    autoComplete="off"
                                />
                            )}
                        </FormField>

                        <p className="text-muted-foreground text-sm">
                            {t('stores.form.credentials_hint')}
                        </p>

                        <FormField
                            label={t('stores.form.api_key')}
                            error={form.errors.api_key}
                            required
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    value={form.data.api_key}
                                    onChange={(event) =>
                                        form.setData(
                                            'api_key',
                                            event.target.value,
                                        )
                                    }
                                    autoComplete="off"
                                    spellCheck={false}
                                />
                            )}
                        </FormField>

                        <FormField
                            label={t('stores.form.api_secret')}
                            error={form.errors.api_secret}
                            required
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    type="password"
                                    value={form.data.api_secret}
                                    onChange={(event) =>
                                        form.setData(
                                            'api_secret',
                                            event.target.value,
                                        )
                                    }
                                    autoComplete="new-password"
                                />
                            )}
                        </FormField>

                        <FormField
                            label={t('stores.form.webhook_secret')}
                            description={t('stores.form.webhook_secret_hint')}
                            error={form.errors.webhook_secret}
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    type="password"
                                    value={form.data.webhook_secret}
                                    onChange={(event) =>
                                        form.setData(
                                            'webhook_secret',
                                            event.target.value,
                                        )
                                    }
                                    autoComplete="new-password"
                                    spellCheck={false}
                                />
                            )}
                        </FormField>

                        <InputError message={errors.connection} />
                        <InputError message={errors.store} />

                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                disabled={form.processing || !mayConnect}
                                onClick={check}
                            >
                                {t('stores.form.check')}
                            </Button>
                            <Button
                                type="submit"
                                disabled={form.processing || !mayConnect}
                            >
                                {form.processing && <Spinner />}
                                {t('stores.form.submit')}
                            </Button>
                        </div>
                    </form>
                </SectionCard>
            </PageContainer>
        </>
    );
}

ConnectStore.layout = {
    breadcrumbs: [{ title: 'nav.stores', href: index() }],
};
