import { Head, Link } from '@inertiajs/react';
import { Globe, Plus } from 'lucide-react';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { websiteHealthTone, websiteStatusTone } from '@/lib/website';
import { create, index, show } from '@/routes/websites';
import type { WebsiteEntitlement, WebsiteSummary } from '@/types/website';

type Props = {
    websites: WebsiteSummary[];
    entitlement: WebsiteEntitlement;
    can: { create: boolean };
};

/**
 * The account's own storefronts (§16, P5-8).
 *
 * What the package allows is said in words before the button rather than by the
 * button being missing: "your package allows one website and you have one" is
 * an answer somebody can act on; a door that is simply not there is not.
 */
export default function Websites({ websites, entitlement, can }: Props) {
    const { t, locale } = useTranslation();

    const atLimit =
        entitlement.remaining !== null && entitlement.remaining <= 0;
    const mayRequest = can.create && entitlement.allowed && !atLimit;

    const allowance =
        entitlement.limit === null
            ? t('website.index.allowance_unlimited', {
                  used: String(entitlement.used),
              })
            : t('website.index.allowance', {
                  used: String(entitlement.used),
                  limit: String(entitlement.limit),
              });

    return (
        <>
            <Head title={t('website.title')} />

            <PageContainer>
                <PageHeader
                    title={t('website.title')}
                    description={t('website.subtitle')}
                    actions={
                        mayRequest ? (
                            <Button asChild size="sm">
                                <Link href={create()}>
                                    <Plus aria-hidden="true" />
                                    {t('website.index.request')}
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />

                {!entitlement.allowed && (
                    <div
                        className="border-warning bg-warning-subtle rounded-xl border p-4 text-sm"
                        role="status"
                    >
                        {t('website.index.not_entitled')}
                    </div>
                )}

                {entitlement.allowed && atLimit && (
                    <div
                        className="border-warning bg-warning-subtle rounded-xl border p-4 text-sm"
                        role="status"
                    >
                        {t('website.index.limit_reached', {
                            limit: String(entitlement.limit ?? 0),
                        })}
                    </div>
                )}

                {websites.length === 0 ? (
                    <EmptyState
                        icon={Globe}
                        title={t('website.index.empty_title')}
                        description={t('website.index.empty_body')}
                        action={
                            mayRequest ? (
                                <Button asChild>
                                    <Link href={create()}>
                                        {t('website.index.request')}
                                    </Link>
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <SectionCard
                        title={t('website.title')}
                        description={allowance}
                    >
                        <ul className="divide-border divide-y">
                            {websites.map((website) => (
                                <li
                                    key={website.id}
                                    className="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0"
                                >
                                    <div className="min-w-0 space-y-1">
                                        <Link
                                            href={show(website.id)}
                                            className="block truncate text-sm font-medium underline-offset-4 hover:underline"
                                        >
                                            {website.name}
                                        </Link>
                                        <div className="text-muted-foreground truncate font-mono text-xs">
                                            {website.host}
                                        </div>
                                        {website.outstanding_charges > 0 && (
                                            <div className="text-warning-foreground text-xs font-medium">
                                                {t(
                                                    'website.index.outstanding',
                                                    {
                                                        count: String(
                                                            website.outstanding_charges,
                                                        ),
                                                    },
                                                )}
                                            </div>
                                        )}
                                    </div>

                                    <div className="flex flex-col items-start gap-1 sm:items-end">
                                        <StatusPill
                                            tone={websiteStatusTone(
                                                website.status,
                                            )}
                                            label={website.status_label}
                                        />
                                        <StatusPill
                                            tone={websiteHealthTone(
                                                website.connection_health,
                                            )}
                                            label={t(
                                                `website.health.${website.connection_health}`,
                                            )}
                                        />
                                        <span className="text-muted-foreground text-xs">
                                            {t('website.show.last_synced')}:{' '}
                                            {website.last_synced_at
                                                ? new Date(
                                                      website.last_synced_at,
                                                  ).toLocaleString(locale)
                                                : t(
                                                      'website.show.never_synced',
                                                  )}
                                        </span>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </SectionCard>
                )}
            </PageContainer>
        </>
    );
}

Websites.layout = {
    breadcrumbs: [
        {
            title: 'nav.websites',
            href: index(),
        },
    ],
};
