import { Form, router, useHttp } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import WebsiteCredentialController from '@/actions/App/Http/Controllers/Admin/WebsiteCredentialController';
import FormField from '@/components/forms/form-field';
import InputError from '@/components/input-error';
import Notice from '@/components/notice';
import SectionCard from '@/components/section-card';
import WebsiteCredentialList, {
    type CredentialRow,
} from '@/components/website-credential-list';
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

export type CredentialScopeOption = { value: string; label: string };

export type CredentialPresetOption = {
    value: string;
    label: string;
    scopes: string[];
};

export type StorefrontConnection = {
    erp_origin: string;
    origin_accepted: boolean;
    website_id: string;
    primary_host: string;
};

/** What the server answers, once, to issuing or rotating a credential. */
export type IssuedCredential = {
    key_id: string;
    secret: string;
    scopes: string[];
    env: string;
    origin_accepted: boolean;
    previous_secret_expires_at: string | null;
};

type IssueFields = { name: string; scopes: string[] };

/**
 * A storefront's API credentials, for the platform staff explicitly allowed to
 * manage them (D32 §2, P3 ER-1).
 *
 * Issuing and rotating are plain JSON requests, not page visits: the secret
 * comes back once in that response and is held only in this page's memory,
 * shown in a dialog until it is closed. It is never in the session, a flash
 * message, browser storage or a page prop, so a reload cannot bring it back.
 *
 * Nothing is sent to the storefront. The operator copies the environment block
 * into that installation's server-side configuration by hand.
 */
export default function StorefrontCredentials({
    websiteId,
    credentials,
    scopes,
    presets,
    connection,
}: {
    websiteId: string;
    credentials: CredentialRow[];
    scopes: CredentialScopeOption[];
    presets: CredentialPresetOption[];
    connection: StorefrontConnection;
}) {
    const { t } = useTranslation();
    const [issued, setIssued] = useState<IssuedCredential | null>(null);
    const [copied, setCopied] = useState(false);

    const issue = useHttp<IssueFields, IssuedCredential>({
        name: '',
        scopes: [],
    });
    const rotation = useHttp<Record<string, never>, IssuedCredential>({});
    const [rotating, setRotating] = useState<string | null>(null);

    const refreshList = () => router.reload({ only: ['credentials'] });

    async function submitIssue(event: FormEvent) {
        event.preventDefault();

        try {
            const answer = await issue.post(
                WebsiteCredentialController.store.url(websiteId),
            );
            setIssued(answer);
            issue.reset();
            refreshList();
        } catch {
            // Validation errors are on issue.errors; nothing else to keep.
        }
    }

    async function rotate(credential: CredentialRow) {
        if (!window.confirm(t('website.credentials.rotate_confirm'))) {
            return;
        }

        setRotating(credential.id);

        try {
            const answer = await rotation.post(
                WebsiteCredentialController.rotate.url({
                    website: websiteId,
                    credential: credential.id,
                }),
            );
            setIssued(answer);
            refreshList();
        } catch {
            // The refusal, if any, is on rotation.errors.
        } finally {
            setRotating(null);
        }
    }

    const toggleScope = (value: string, checked: boolean) =>
        issue.setData(
            'scopes',
            checked
                ? [...issue.data.scopes, value]
                : issue.data.scopes.filter((scope) => scope !== value),
        );

    const close = () => {
        setIssued(null);
        setCopied(false);
    };

    async function copyEnvironment(block: string) {
        try {
            await navigator.clipboard.writeText(block);
            setCopied(true);
        } catch {
            setCopied(false);
        }
    }

    const errors = issue.errors as Record<string, string | undefined>;
    const rotationErrors = rotation.errors as Record<
        string,
        string | undefined
    >;

    return (
        <>
            <SectionCard
                title={t('website.credentials.title')}
                description={t('website.credentials.hint')}
            >
                <div className="space-y-4">
                    {!connection.origin_accepted && (
                        <Notice
                            tone="warning"
                            inset
                            title={t('website.credentials.origin_not_https')}
                        >
                            {connection.erp_origin}
                        </Notice>
                    )}

                    <div className="grid gap-1 text-sm">
                        <span className="text-muted-foreground text-xs">
                            {t('website.credentials.site_config')}
                        </span>
                        <code className="bg-surface-subtle rounded-lg p-3 font-mono text-xs break-all whitespace-pre-wrap">
                            {`"websiteId": "${connection.website_id}",\n"primaryHost": "${connection.primary_host}"`}
                        </code>
                    </div>

                    <WebsiteCredentialList
                        credentials={credentials}
                        emptyHelp={t('website.credentials.none_help')}
                        actions={(credential) => (
                            <div className="flex flex-wrap items-end gap-3">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    disabled={rotating !== null}
                                    onClick={() => rotate(credential)}
                                >
                                    {rotating === credential.id && <Spinner />}
                                    {t('website.integration.rotate')}
                                </Button>

                                <Form
                                    {...WebsiteCredentialController.revoke.form(
                                        {
                                            website: websiteId,
                                            credential: credential.id,
                                        },
                                    )}
                                    options={{ preserveScroll: true }}
                                    className="flex flex-wrap items-end gap-2"
                                >
                                    {({ processing, errors: revokeErrors }) => (
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
                                                        revokeErrors.reason
                                                    }
                                                />
                                            </div>
                                            <Button
                                                type="submit"
                                                size="sm"
                                                variant="destructive"
                                                disabled={processing}
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
                    />
                    <InputError message={rotationErrors.credential} />
                </div>
            </SectionCard>

            <SectionCard
                title={t('website.integration.issue_title')}
                description={t('website.credentials.issue_hint')}
            >
                <form onSubmit={submitIssue} className="space-y-4">
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
                                value={issue.data.name}
                                onChange={(event) =>
                                    issue.setData('name', event.target.value)
                                }
                                required
                            />
                        )}
                    </FormField>

                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-muted-foreground text-xs">
                            {t('website.credentials.presets_title')}
                        </span>
                        {presets.map((preset) => (
                            <Button
                                key={preset.value}
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    issue.setData('scopes', [...preset.scopes])
                                }
                            >
                                {preset.label}
                            </Button>
                        ))}
                    </div>

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
                                    value={scope.value}
                                    checked={issue.data.scopes.includes(
                                        scope.value,
                                    )}
                                    onChange={(event) =>
                                        toggleScope(
                                            scope.value,
                                            event.target.checked,
                                        )
                                    }
                                />
                                <span>{scope.label}</span>
                                <code className="text-muted-foreground text-xs">
                                    {scope.value}
                                </code>
                            </label>
                        ))}
                        <InputError
                            message={errors.scopes ?? errors['scopes.0']}
                        />
                    </fieldset>

                    <InputError message={errors.website} />

                    <Button
                        type="submit"
                        disabled={
                            issue.processing || issue.data.scopes.length === 0
                        }
                    >
                        {issue.processing && <Spinner />}
                        {t('website.integration.issue')}
                    </Button>
                </form>
            </SectionCard>

            <Dialog
                open={issued !== null}
                onOpenChange={(open) => !open && close()}
            >
                {issued && (
                    <DialogContent className="sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>
                                {t('website.credentials.once_title')}
                            </DialogTitle>
                            <DialogDescription>
                                {t('website.integration.secret_once')}
                            </DialogDescription>
                        </DialogHeader>

                        {!issued.origin_accepted && (
                            <Notice
                                tone="warning"
                                inset
                                title={t(
                                    'website.credentials.origin_not_https',
                                )}
                            >
                                {connection.erp_origin}
                            </Notice>
                        )}

                        <div className="grid gap-1">
                            <span className="text-muted-foreground text-xs">
                                {t('website.credentials.env_title')}
                            </span>
                            <pre className="bg-surface-subtle overflow-x-auto rounded-lg p-3 font-mono text-xs break-all whitespace-pre-wrap">
                                {issued.env}
                            </pre>
                        </div>

                        {issued.previous_secret_expires_at && (
                            <p className="text-muted-foreground text-xs">
                                {t('website.integration.grace_until', {
                                    date: new Date(
                                        issued.previous_secret_expires_at,
                                    ).toLocaleString(),
                                })}
                            </p>
                        )}

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => copyEnvironment(issued.env)}
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
