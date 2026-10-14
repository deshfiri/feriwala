import StatusPill from '@/components/status-pill';
import { useTranslation } from '@/hooks/use-translation';

export type WebhookRow = {
    url: string;
    secret_hint: string;
    is_active: boolean;
    rotated_at: string | null;
    previous_secret_expires_at: string | null;
};

/**
 * A storefront's webhook endpoint by its address and the last four characters
 * of its signing secret — never the secret itself (contract §7.2, D32 §2).
 */
export default function WebsiteWebhookSummary({
    webhook,
}: {
    webhook: WebhookRow | null;
}) {
    const { t, locale } = useTranslation();

    if (webhook === null) {
        return (
            <p className="text-muted-foreground text-sm">
                {t('website.integration.no_webhook')}
            </p>
        );
    }

    return (
        <div className="space-y-1 text-sm">
            <div className="font-mono break-all">{webhook.url}</div>
            <div className="text-muted-foreground text-xs">
                {t('website.integration.secret')}: …{webhook.secret_hint}
            </div>
            <StatusPill
                tone={webhook.is_active ? 'success' : 'neutral'}
                label={
                    webhook.is_active
                        ? t('website.integration.active')
                        : t('website.integration.disabled')
                }
            />
            {webhook.previous_secret_expires_at && (
                <div className="text-muted-foreground text-xs">
                    {t('website.integration.grace_until', {
                        date: new Date(
                            webhook.previous_secret_expires_at,
                        ).toLocaleString(locale),
                    })}
                </div>
            )}
        </div>
    );
}
