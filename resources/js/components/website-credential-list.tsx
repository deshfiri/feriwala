import { KeyRound } from 'lucide-react';
import type { ReactNode } from 'react';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { useTranslation } from '@/hooks/use-translation';

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

/**
 * A storefront's API credentials, each named by its key identifier and the last
 * four characters of its secret — never the secret itself (D32 §2).
 *
 * The owner's screen shows the list as it is; the administration screen passes
 * `actions` to offer rotation and revocation on a credential that still works.
 */
export default function WebsiteCredentialList({
    credentials,
    emptyHelp,
    actions,
}: {
    credentials: CredentialRow[];
    emptyHelp: string;
    actions?: (credential: CredentialRow) => ReactNode;
}) {
    const { t, locale } = useTranslation();

    const when = (value: string | null) =>
        value
            ? new Date(value).toLocaleString(locale)
            : t('website.integration.never');

    if (credentials.length === 0) {
        return (
            <EmptyState
                icon={KeyRound}
                title={t('website.integration.no_credentials')}
                description={emptyHelp}
            />
        );
    }

    return (
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
                                {credential.key_id} · …{credential.secret_hint}
                            </div>
                            <div className="text-muted-foreground text-xs">
                                {credential.scopes.join(', ')}
                            </div>
                            <div className="text-muted-foreground text-xs">
                                {t('website.integration.last_used')}:{' '}
                                {when(credential.last_used_at)}
                            </div>
                            {credential.previous_secret_expires_at && (
                                <div className="text-muted-foreground text-xs">
                                    {t('website.integration.grace_until', {
                                        date: when(
                                            credential.previous_secret_expires_at,
                                        ),
                                    })}
                                </div>
                            )}
                        </div>

                        <StatusPill
                            tone={credential.revoked_at ? 'danger' : 'success'}
                            label={
                                credential.revoked_at
                                    ? t('website.integration.revoked')
                                    : t('website.integration.active')
                            }
                        />
                    </div>

                    {credential.revoked_at ? (
                        <p className="text-muted-foreground text-xs">
                            {credential.revoked_reason}
                        </p>
                    ) : (
                        actions?.(credential)
                    )}
                </li>
            ))}
        </ul>
    );
}
