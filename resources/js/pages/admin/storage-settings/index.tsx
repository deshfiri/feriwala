import { Form, Head, useForm, useHttp } from '@inertiajs/react';
import { useState } from 'react';
import StorageSettingsController from '@/actions/App/Http/Controllers/Admin/StorageSettingsController';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import ReasonTextarea from '@/components/forms/reason-textarea';

type Settings = {
    enabled: boolean;
    is_configured: boolean;
    account_id: string | null;
    access_key_id_masked: string | null;
    is_access_key_configured: boolean;
    is_secret_configured: boolean;
    bucket: string | null;
    endpoint: string | null;
    region: string;
    public_domain: string | null;
    default_visibility: 'public' | 'private';
    signed_url_expiry_minutes: number;
};

type Props = {
    settings: Settings;
    can: { manage: boolean };
};

type CredentialFields = {
    account_id: string;
    access_key_id: string;
    secret_access_key: string;
    bucket: string;
    endpoint: string;
    region: string;
    public_domain: string;
    default_visibility: 'public' | 'private';
    signed_url_expiry_minutes: number;
    reason: string;
};

type ConnectionTestResponse = { success: boolean; message: string };

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none disabled:opacity-60';

/**
 * Settings -> Storage: Cloudflare R2 (beta-critical batch, Commit 3).
 *
 * No stored secret is ever sent to this page -- `settings.access_key_id_masked`
 * is the only thing standing in for the access key, and the secret key has
 * nothing standing in for it at all beyond `is_secret_configured`. Leaving a
 * credential field blank and saving keeps what is already stored; there is no
 * way to read it back in order to edit part of it.
 *
 * Saving and switching R2 on both require a freshly confirmed password (the
 * route's `RequirePassword` middleware) and a reason, same as a wallet
 * reversal -- an R2 secret is standing access, not a one-time transaction.
 */
export default function StorageSettingsIndex({ settings, can }: Props) {
    const { t } = useTranslation();
    const [testResult, setTestResult] = useState<ConnectionTestResponse | null>(
        null,
    );

    const { data, setData } = useForm<CredentialFields>({
        account_id: settings.account_id ?? '',
        access_key_id: '',
        secret_access_key: '',
        bucket: settings.bucket ?? '',
        endpoint: settings.endpoint ?? '',
        region: settings.region,
        public_domain: settings.public_domain ?? '',
        default_visibility: settings.default_visibility,
        signed_url_expiry_minutes: settings.signed_url_expiry_minutes,
        reason: '',
    });

    const { submit: runTest, processing: testing } = useHttp<
        Partial<CredentialFields>,
        ConnectionTestResponse
    >(
        () => StorageSettingsController.testConnection(),
        () => ({
            account_id: data.account_id,
            access_key_id: data.access_key_id,
            secret_access_key: data.secret_access_key,
            bucket: data.bucket,
            endpoint: data.endpoint,
            region: data.region,
        }),
    );

    async function testConnection() {
        setTestResult(null);
        try {
            const result = await runTest();
            setTestResult(result);
        } catch {
            setTestResult({
                success: false,
                message: t('storage_settings.test_failed_generic'),
            });
        }
    }

    return (
        <>
            <Head title={t('storage_settings.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('storage_settings.title')}
                    description={t('storage_settings.description')}
                />

                <SectionCard
                    title={t('storage_settings.status_title')}
                    actions={
                        <StatusPill
                            tone={
                                settings.enabled
                                    ? 'success'
                                    : settings.is_configured
                                      ? 'warning'
                                      : 'neutral'
                            }
                            label={t(
                                settings.enabled
                                    ? 'storage_settings.state.enabled'
                                    : settings.is_configured
                                      ? 'storage_settings.state.configured'
                                      : 'storage_settings.state.not_configured',
                            )}
                        />
                    }
                >
                    <dl className="grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t('storage_settings.fields.access_key_id')}
                            </dt>
                            <dd className="font-medium tabular-nums">
                                {settings.access_key_id_masked ??
                                    t('storage_settings.not_set')}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t('storage_settings.fields.secret_access_key')}
                            </dt>
                            <dd className="font-medium">
                                {t(
                                    settings.is_secret_configured
                                        ? 'storage_settings.set'
                                        : 'storage_settings.not_set',
                                )}
                            </dd>
                        </div>
                    </dl>

                    {can.manage && (
                        <Form
                            {...StorageSettingsController.enable.form()}
                            options={{ preserveScroll: true }}
                            className="mt-5 border-t pt-4"
                        >
                            {({
                                errors: enableErrors,
                                processing: toggling,
                            }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="enabled"
                                        value={settings.enabled ? 0 : 1}
                                    />

                                    <FormField
                                        label={t('storage_settings.reason')}
                                        error={enableErrors.reason}
                                        required
                                        className="mb-3 max-w-md"
                                    >
                                        {(f) => (
                                            <ReasonTextarea
                                                context="generic"
                                                {...f}
                                                name="reason"
                                                placeholder={t(
                                                    'storage_settings.reason_placeholder',
                                                )}
                                            />
                                        )}
                                    </FormField>

                                    <SubmitButton
                                        processing={toggling}
                                        variant={
                                            settings.enabled
                                                ? 'outline'
                                                : 'default'
                                        }
                                        disabled={
                                            !settings.enabled &&
                                            !settings.is_configured
                                        }
                                    >
                                        {t(
                                            settings.enabled
                                                ? 'storage_settings.disable'
                                                : 'storage_settings.enable',
                                        )}
                                    </SubmitButton>
                                </>
                            )}
                        </Form>
                    )}
                </SectionCard>

                {can.manage && (
                    <SectionCard
                        title={t('storage_settings.credentials_title')}
                        description={t(
                            'storage_settings.credentials_description',
                        )}
                    >
                        <Form
                            {...StorageSettingsController.update.form()}
                            options={{ preserveScroll: true }}
                            transform={() => ({ ...data })}
                            onSuccess={() => {
                                setData('access_key_id', '');
                                setData('secret_access_key', '');
                                setData('reason', '');
                                setTestResult(null);
                            }}
                            className="space-y-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <FormField
                                            label={t(
                                                'storage_settings.fields.account_id',
                                            )}
                                            error={errors.account_id}
                                            required
                                        >
                                            {(f) => (
                                                <Input
                                                    {...f}
                                                    name="account_id"
                                                    value={data.account_id}
                                                    onChange={(e) =>
                                                        setData(
                                                            'account_id',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            )}
                                        </FormField>

                                        <FormField
                                            label={t(
                                                'storage_settings.fields.bucket',
                                            )}
                                            error={errors.bucket}
                                            required
                                        >
                                            {(f) => (
                                                <Input
                                                    {...f}
                                                    name="bucket"
                                                    value={data.bucket}
                                                    onChange={(e) =>
                                                        setData(
                                                            'bucket',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            )}
                                        </FormField>
                                    </div>

                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <FormField
                                            label={t(
                                                'storage_settings.fields.access_key_id',
                                            )}
                                            hint={t(
                                                'storage_settings.blank_keeps_existing',
                                            )}
                                            error={errors.access_key_id}
                                        >
                                            {(f) => (
                                                <Input
                                                    {...f}
                                                    name="access_key_id"
                                                    type="password"
                                                    autoComplete="new-password"
                                                    placeholder={
                                                        settings.access_key_id_masked ??
                                                        undefined
                                                    }
                                                    value={data.access_key_id}
                                                    onChange={(e) =>
                                                        setData(
                                                            'access_key_id',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            )}
                                        </FormField>

                                        <FormField
                                            label={t(
                                                'storage_settings.fields.secret_access_key',
                                            )}
                                            hint={t(
                                                'storage_settings.blank_keeps_existing',
                                            )}
                                            error={errors.secret_access_key}
                                        >
                                            {(f) => (
                                                <Input
                                                    {...f}
                                                    name="secret_access_key"
                                                    type="password"
                                                    autoComplete="new-password"
                                                    placeholder={t(
                                                        settings.is_secret_configured
                                                            ? 'storage_settings.set'
                                                            : 'storage_settings.not_set',
                                                    )}
                                                    value={
                                                        data.secret_access_key
                                                    }
                                                    onChange={(e) =>
                                                        setData(
                                                            'secret_access_key',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            )}
                                        </FormField>
                                    </div>

                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <FormField
                                            label={t(
                                                'storage_settings.fields.endpoint',
                                            )}
                                            error={errors.endpoint}
                                            required
                                        >
                                            {(f) => (
                                                <Input
                                                    {...f}
                                                    name="endpoint"
                                                    placeholder="https://<account>.r2.cloudflarestorage.com"
                                                    value={data.endpoint}
                                                    onChange={(e) =>
                                                        setData(
                                                            'endpoint',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            )}
                                        </FormField>

                                        <FormField
                                            label={t(
                                                'storage_settings.fields.region',
                                            )}
                                            error={errors.region}
                                        >
                                            {(f) => (
                                                <Input
                                                    {...f}
                                                    name="region"
                                                    value={data.region}
                                                    onChange={(e) =>
                                                        setData(
                                                            'region',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            )}
                                        </FormField>
                                    </div>

                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <FormField
                                            label={t(
                                                'storage_settings.fields.public_domain',
                                            )}
                                            hint={t(
                                                'storage_settings.public_domain_help',
                                            )}
                                            error={errors.public_domain}
                                        >
                                            {(f) => (
                                                <Input
                                                    {...f}
                                                    name="public_domain"
                                                    value={data.public_domain}
                                                    onChange={(e) =>
                                                        setData(
                                                            'public_domain',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            )}
                                        </FormField>

                                        <FormField
                                            label={t(
                                                'storage_settings.fields.default_visibility',
                                            )}
                                            error={errors.default_visibility}
                                        >
                                            {(f) => (
                                                <select
                                                    {...f}
                                                    name="default_visibility"
                                                    className={selectClass}
                                                    value={
                                                        data.default_visibility
                                                    }
                                                    onChange={(e) =>
                                                        setData(
                                                            'default_visibility',
                                                            e.target.value as
                                                                | 'public'
                                                                | 'private',
                                                        )
                                                    }
                                                >
                                                    <option value="private">
                                                        {t(
                                                            'storage_settings.visibility.private',
                                                        )}
                                                    </option>
                                                    <option value="public">
                                                        {t(
                                                            'storage_settings.visibility.public',
                                                        )}
                                                    </option>
                                                </select>
                                            )}
                                        </FormField>
                                    </div>

                                    <FormField
                                        label={t(
                                            'storage_settings.fields.signed_url_expiry_minutes',
                                        )}
                                        hint={t(
                                            'storage_settings.signed_url_expiry_help',
                                        )}
                                        error={errors.signed_url_expiry_minutes}
                                        className="max-w-xs"
                                    >
                                        {(f) => (
                                            <Input
                                                {...f}
                                                name="signed_url_expiry_minutes"
                                                type="number"
                                                inputMode="numeric"
                                                min={1}
                                                max={10080}
                                                className="tabular-nums"
                                                value={
                                                    data.signed_url_expiry_minutes
                                                }
                                                onChange={(e) =>
                                                    setData(
                                                        'signed_url_expiry_minutes',
                                                        Number(e.target.value),
                                                    )
                                                }
                                            />
                                        )}
                                    </FormField>

                                    <FormField
                                        label={t('storage_settings.reason')}
                                        error={errors.reason}
                                        required
                                        className="max-w-md"
                                    >
                                        {(f) => (
                                            <ReasonTextarea
                                                context="generic"
                                                {...f}
                                                name="reason"
                                                placeholder={t(
                                                    'storage_settings.reason_placeholder',
                                                )}
                                                value={data.reason}
                                                onChange={(e) =>
                                                    setData(
                                                        'reason',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        )}
                                    </FormField>

                                    {testResult && (
                                        <div
                                            className={
                                                testResult.success
                                                    ? 'text-success text-sm'
                                                    : 'text-danger text-sm'
                                            }
                                            role="status"
                                        >
                                            {testResult.success
                                                ? t(
                                                      'storage_settings.test_succeeded',
                                                  )
                                                : testResult.message}
                                        </div>
                                    )}

                                    <div className="flex flex-wrap items-center justify-end gap-3">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={testing}
                                            onClick={testConnection}
                                        >
                                            {testing
                                                ? t('storage_settings.testing')
                                                : t(
                                                      'storage_settings.test_connection',
                                                  )}
                                        </Button>

                                        <SubmitButton processing={processing}>
                                            {t('common.actions.save')}
                                        </SubmitButton>
                                    </div>
                                </>
                            )}
                        </Form>
                    </SectionCard>
                )}
            </PageContainer>
        </>
    );
}
