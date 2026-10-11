import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import FormField from '@/components/forms/form-field';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import ExternalStoreController from '@/actions/App/Http/Controllers/Erp/ExternalStoreController';
import { index, show } from '@/routes/stores';
import type { ExternalStoreDetail } from '@/types/stores';

type Props = { store: ExternalStoreDetail };

/**
 * Rename a connection, replace its credentials, or reconnect it (D29).
 *
 * The current credentials are never shown: leaving both fields empty keeps
 * them. A disconnected store needs both to reconnect.
 */
export default function EditStore({ store }: Props) {
    const { t } = useTranslation();
    const reconnecting = store.status === 'disconnected';

    const form = useForm({ name: store.name, api_key: '', api_secret: '' });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(ExternalStoreController.update.url(store.id));
    };

    return (
        <>
            <Head
                title={
                    reconnecting
                        ? t('stores.form.reconnect_title')
                        : t('stores.form.edit_title')
                }
            />

            <PageContainer width="narrow">
                <PageHeader
                    title={
                        reconnecting
                            ? t('stores.form.reconnect_title')
                            : t('stores.form.edit_title')
                    }
                    description={
                        reconnecting
                            ? t('stores.form.reconnect_description')
                            : t('stores.form.edit_description')
                    }
                    back={{ href: show(store.id), label: store.name }}
                />

                <SectionCard description={store.base_url}>
                    <form onSubmit={submit} className="space-y-4" noValidate>
                        <FormField
                            label={t('stores.form.name')}
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
                                />
                            )}
                        </FormField>

                        {!reconnecting && store.api_key_hint && (
                            <p className="text-muted-foreground text-sm">
                                {t('stores.form.new_credentials_hint', {
                                    hint: store.api_key_hint,
                                })}
                            </p>
                        )}

                        <FormField
                            label={t('stores.form.api_key')}
                            error={form.errors.api_key}
                            required={reconnecting}
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
                            required={reconnecting}
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

                        <InputError message={errors.connection} />
                        <InputError message={errors.store} />
                        <InputError message={errors.base_url} />

                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {reconnecting
                                ? t('stores.actions.reconnect')
                                : t('stores.form.save')}
                        </Button>
                    </form>
                </SectionCard>
            </PageContainer>
        </>
    );
}

EditStore.layout = {
    breadcrumbs: [{ title: 'nav.stores', href: index() }],
};
