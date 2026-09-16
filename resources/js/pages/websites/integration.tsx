import { Form, Head, Link, router } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { useEffect, useState } from 'react';
import FormField from '@/components/forms/form-field';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import WebsiteIntegrationController from '@/actions/App/Http/Controllers/Erp/WebsiteIntegrationController';
import { websiteHealthTone } from '@/lib/website';
import { index as websitesIndex, show as websiteShow } from '@/routes/websites';
import type { WebsiteDetail } from '@/types/website';

export type CredentialRow = {
    id: string;
    name: string;
    key_id: string;
    secret_hint: string;
    scopes: string[];
    last_used_at: string | null;
    rotated_at: string | null;
    previous_secret_expires_at: string | null;
    revoked_at: string | null;
    revoked_reason: string | null;
    created_at: string;
};

export type ApiCallRow = {
    request_id: string;
    method: string;
    path: string;
    status: number;
    error_code: string | null;
    duration_ms: number;
    created_at: string;
};

type Props = {
    website: WebsiteDetail;
    credentials: CredentialRow[];
    scopes: { value: string; label: string; default: boolean }[];
    recent_calls: ApiCallRow[];
    api_base: string;
};

type Issued = { key_id: string; secret: string };

/**
 * Connecting a storefront to the ERP (§17.3, contract §3, P5-17, P5-27, P5-28).
 *
 * A secret arrives exactly once, as flash data on the response that made it,
 * and lives only in this component's state until the page is left. It is
 * never a page prop that a reload could bring back.
 */
export default function WebsiteIntegration({
    website,
    credentials,
    scopes,
    recent_calls: recentCalls,
    api_base: apiBase,
}: Props) {
    const { t, locale } = useTranslation();
    const [issued, setIssued] = useState<Issued | null>(null);

    useEffect(
        () =>
            router.on('flash', (event) => {
                const flash = (event as CustomEvent).detail?.flash;
                const credential = flash?.credential as Issued | undefined;

                if (credential?.secret) {
                    setIssued(credential);
                }
            }),
        [],
    );

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

                {issued && (
                    <div
                        className="border-warning bg-warning-subtle space-y-2 rounded-xl border p-4"
                        role="alert"
                    >
                        <p className="text-sm font-medium">
                            {t('website.integration.secret_once')}
                        </p>
                        <dl className="grid gap-2 text-sm">
                            <div>
                                <dt className="text-muted-foreground text-xs">
                                    {t('website.integration.key_id')}
                                </dt>
                                <dd className="font-mono break-all">
                                    {issued.key_id}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">
                                    {t('website.integration.secret')}
                                </dt>
                                <dd className="font-mono break-all">
                                    {issued.secret}
                                </dd>
                            </div>
                        </dl>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setIssued(null)}
                        >
                            {t('website.integration.stored_it')}
                        </Button>
                    </div>
                )}

                <SectionCard
                    title={t('website.integration.issue_title')}
                    description={t('website.integration.issue_hint')}
                >
                    <Form
                        {...WebsiteIntegrationController.storeCredential.form(
                            website.id,
                        )}
                        options={{ preserveScroll: true }}
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <FormField
                                    label={t('website.integration.name')}
                                    error={errors.name}
                                    required
                                >
                                    {(field) => (
                                        <Input
                                            {...field}
                                            name="name"
                                            maxLength={80}
                                            required
                                        />
                                    )}
                                </FormField>

                                <fieldset className="space-y-2">
                                    <legend className="text-sm font-medium">
                                        {t('website.integration.scopes_title')}
                                    </legend>
                                    {scopes.map((scope) => (
                                        <label
                                            key={scope.value}
                                            className="flex items-center gap-2 text-sm"
                                        >
                                            <input
                                                type="checkbox"
                                                name="scopes[]"
                                                value={scope.value}
                                                defaultChecked={scope.default}
                                            />
                                            <span>{scope.label}</span>
                                            <code className="text-muted-foreground text-xs">
                                                {scope.value}
                                            </code>
                                        </label>
                                    ))}
                                    <InputError message={errors.scopes} />
                                </fieldset>

                                <InputError message={errors.website} />

                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {t('website.integration.issue')}
                                </Button>
                            </>
                        )}
                    </Form>
                </SectionCard>

                <SectionCard title={t('website.integration.credentials')}>
                    {credentials.length === 0 ? (
                        <EmptyState
                            icon={KeyRound}
                            title={t('website.integration.no_credentials')}
                            description={t(
                                'website.integration.no_credentials_help',
                            )}
                        />
                    ) : (
                        <ul className="divide-border divide-y">
                            {credentials.map((credential) => (
                                <li
                                    key={credential.id}
                                    className="space-y-3 py-4 first:pt-0 last:pb-0"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="min-w-0 space-y-1">
                                            <div className="text-sm font-medium">
                                                {credential.name}
                                            </div>
                                            <div className="text-muted-foreground font-mono text-xs break-all">
                                                {credential.key_id} · …
                                                {credential.secret_hint}
                                            </div>
                                            <div className="text-muted-foreground text-xs">
                                                {credential.scopes.join(', ')}
                                            </div>
                                            <div className="text-muted-foreground text-xs">
                                                {t(
                                                    'website.integration.last_used',
                                                )}
                                                :{' '}
                                                {when(credential.last_used_at)}
                                            </div>
                                            {credential.previous_secret_expires_at && (
                                                <div className="text-muted-foreground text-xs">
                                                    {t(
                                                        'website.integration.grace_until',
                                                        {
                                                            date: when(
                                                                credential.previous_secret_expires_at,
                                                            ),
                                                        },
                                                    )}
                                                </div>
                                            )}
                                        </div>

                                        <StatusPill
                                            tone={
                                                credential.revoked_at
                                                    ? 'danger'
                                                    : 'success'
                                            }
                                            label={
                                                credential.revoked_at
                                                    ? t(
                                                          'website.integration.revoked',
                                                      )
                                                    : t(
                                                          'website.integration.active',
                                                      )
                                            }
                                        />
                                    </div>

                                    {credential.revoked_at ? (
                                        <p className="text-muted-foreground text-xs">
                                            {credential.revoked_reason}
                                        </p>
                                    ) : (
                                        <div className="flex flex-wrap items-end gap-3">
                                            <Form
                                                {...WebsiteIntegrationController.rotateCredential.form(
                                                    {
                                                        website: website.id,
                                                        credential:
                                                            credential.id,
                                                    },
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                            >
                                                {({ processing }) => (
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        variant="outline"
                                                        disabled={processing}
                                                    >
                                                        {t(
                                                            'website.integration.rotate',
                                                        )}
                                                    </Button>
                                                )}
                                            </Form>

                                            <Form
                                                {...WebsiteIntegrationController.revokeCredential.form(
                                                    {
                                                        website: website.id,
                                                        credential:
                                                            credential.id,
                                                    },
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                                className="flex flex-wrap items-end gap-2"
                                            >
                                                {({ processing, errors }) => (
                                                    <>
                                                        <div className="grid gap-1">
                                                            <Label
                                                                htmlFor={`revoke-${credential.id}`}
                                                            >
                                                                {t(
                                                                    'website.integration.revoke_reason',
                                                                )}
                                                            </Label>
                                                            <Input
                                                                id={`revoke-${credential.id}`}
                                                                name="reason"
                                                                minLength={5}
                                                                maxLength={500}
                                                                required
                                                            />
                                                            <InputError
                                                                message={
                                                                    errors.reason
                                                                }
                                                            />
                                                        </div>
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            variant="destructive"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            {t(
                                                                'website.integration.revoke',
                                                            )}
                                                        </Button>
                                                    </>
                                                )}
                                            </Form>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
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
            title: 'Websites',
            href: websitesIndex(),
        },
    ],
};
