import { Head, Link } from '@inertiajs/react';
import { CalendarClock, Globe, Plus } from 'lucide-react';
import DetailList, { DetailItem } from '@/components/detail-list';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { websiteHealthTone, websiteStatusTone } from '@/lib/website';
import { cn } from '@/lib/utils';
import { create, index, show } from '@/routes/websites';
import type {
    WebsiteAllocationCard,
    WebsiteAllocationTerm,
    WebsiteEntitlement,
    WebsiteOwnerSummary,
} from '@/types/website';

type Props = {
    allocations: WebsiteAllocationCard[];
    websites: WebsiteOwnerSummary[];
    entitlement: WebsiteEntitlement;
    can: { create: boolean };
};

/**
 * The account's own storefronts (§16, P5-8).
 *
 * Websites a package purchase entitles the account to lead the page, one card
 * each, with the domain and hosting period counted from the confirmed purchase
 * (§16.2). Every figure is the server's — including the days remaining — so
 * this page formats dates and nothing more.
 *
 * What the package allows is said in words before the button rather than by the
 * button being missing: "your package allows one website and you have one" is
 * an answer somebody can act on; a door that is simply not there is not.
 */
export default function Websites({
    allocations,
    websites,
    entitlement,
    can,
}: Props) {
    const { t, locale } = useTranslation();

    const atLimit =
        entitlement.remaining !== null && entitlement.remaining <= 0;
    const mayRequest =
        can.create && entitlement.allowed && !entitlement.expired && !atLimit;

    // Counts in the reader's own digits, as the dates are.
    const count = (value: number) => value.toLocaleString(locale);

    const date = (value: string | null, fallback = '—') =>
        value
            ? new Date(value).toLocaleDateString(locale, {
                  day: 'numeric',
                  month: 'long',
                  year: 'numeric',
              })
            : fallback;
    const expiry = (value: string | null) =>
        date(value, t('website.allocation.no_expiry'));

    const allowance =
        entitlement.limit === null
            ? t('website.index.allowance_unlimited', {
                  used: count(entitlement.used),
              })
            : t('website.index.allowance', {
                  used: count(entitlement.used),
                  limit: count(entitlement.limit),
              });

    // Websites already shown on an allocation card are not listed twice.
    const allocated = new Set(
        allocations.flatMap((card) =>
            card.website === null ? [] : [card.website.id],
        ),
    );
    const otherWebsites = websites.filter(
        (website) => !allocated.has(website.id),
    );

    const hasAnything = allocations.length > 0 || websites.length > 0;

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

                {entitlement.allowed && entitlement.expired && (
                    <div
                        className="border-warning bg-warning-subtle rounded-xl border p-4 text-sm"
                        role="status"
                    >
                        {t('website.index.entitlement_expired')}
                    </div>
                )}

                {entitlement.allowed && !entitlement.expired && atLimit && (
                    <div
                        className="border-warning bg-warning-subtle rounded-xl border p-4 text-sm"
                        role="status"
                    >
                        {t('website.index.limit_reached', {
                            limit: count(entitlement.limit ?? 0),
                        })}
                    </div>
                )}

                {!hasAnything && (
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
                )}

                {allocations.length > 0 && (
                    <section
                        aria-labelledby="package-websites-title"
                        className="space-y-3"
                    >
                        <div className="space-y-1">
                            <h2
                                id="package-websites-title"
                                className="text-base font-semibold tracking-tight"
                            >
                                {t('website.allocation.section_title')}
                            </h2>
                            <p className="text-muted-foreground max-w-3xl text-sm">
                                {t('website.allocation.section_hint')}
                            </p>
                        </div>

                        <div className="grid gap-4 lg:grid-cols-2">
                            {allocations.map((card) => (
                                <AllocationCard
                                    key={card.id}
                                    card={card}
                                    locale={locale}
                                    maySetUp={mayRequest}
                                />
                            ))}
                        </div>
                    </section>
                )}

                {otherWebsites.length > 0 && (
                    <SectionCard
                        title={
                            allocations.length > 0
                                ? t('website.index.other_websites')
                                : t('website.title')
                        }
                        description={
                            allocations.length > 0
                                ? t('website.index.other_websites_hint')
                                : allowance
                        }
                    >
                        <ul className="divide-border divide-y">
                            {otherWebsites.map((website) => (
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
                                        {/* What staff recorded, or that they have not yet. */}
                                        <div
                                            className="text-muted-foreground tabular text-xs"
                                            data-test="domain-record"
                                        >
                                            {website.domain_record
                                                ? t(
                                                      'website.index.domain_line',
                                                      {
                                                          domain: website
                                                              .domain_record
                                                              .domain,
                                                          registered: date(
                                                              website
                                                                  .domain_record
                                                                  .registered_at,
                                                          ),
                                                          expires: expiry(
                                                              website
                                                                  .domain_record
                                                                  .expires_at,
                                                          ),
                                                      },
                                                  )
                                                : t(
                                                      'website.index.domain_pending',
                                                  )}
                                        </div>
                                        <div
                                            className="text-muted-foreground tabular text-xs"
                                            data-test="hosting-record"
                                        >
                                            {website.hosting_record
                                                ? t(
                                                      'website.index.hosting_line',
                                                      {
                                                          plan: website
                                                              .hosting_record
                                                              .plan,
                                                          started: date(
                                                              website
                                                                  .hosting_record
                                                                  .started_at,
                                                          ),
                                                          expires: expiry(
                                                              website
                                                                  .hosting_record
                                                                  .expires_at,
                                                          ),
                                                      },
                                                  )
                                                : t(
                                                      'website.index.hosting_pending',
                                                  )}
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

/**
 * One package-allocated website: who it belongs to, where it lives, and how
 * long its domain and hosting run.
 */
function AllocationCard({
    card,
    locale,
    maySetUp,
}: {
    card: WebsiteAllocationCard;
    locale: string;
    maySetUp: boolean;
}) {
    const { t } = useTranslation();

    return (
        <article
            data-test={`allocation-${card.id}`}
            className="bg-card text-card-foreground flex flex-col gap-4 rounded-xl border p-5 shadow-sm"
        >
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 space-y-1">
                    <h3 className="truncate text-lg font-semibold tracking-tight">
                        {card.name}
                    </h3>
                    {card.package_name && (
                        <p className="text-muted-foreground text-xs">
                            {t('website.allocation.package')}:{' '}
                            {card.package_name}
                        </p>
                    )}
                </div>
                {/* Label carries the state, never colour alone (§33.9). */}
                <StatusPill tone={card.status_tone} label={card.status_label} />
            </header>

            <DetailList>
                <DetailItem label={t('website.allocation.website_address')}>
                    {card.website ? (
                        <a
                            href={card.website.url}
                            target="_blank"
                            rel="noreferrer"
                            className="font-mono text-xs underline-offset-4 hover:underline"
                        >
                            {card.website.host}
                        </a>
                    ) : (
                        <span className="text-muted-foreground font-normal">
                            {t('website.allocation.not_set_up')}
                        </span>
                    )}
                </DetailItem>
                <DetailItem label={t('website.allocation.domain_name')}>
                    {card.domain_name ?? (
                        <span className="text-muted-foreground font-normal">
                            {t('website.allocation.not_registered')}
                        </span>
                    )}
                </DetailItem>
                <DetailItem label={t('website.allocation.partner_name')}>
                    {card.partner_name}
                </DetailItem>
            </DetailList>

            <div className="grid gap-3 sm:grid-cols-2">
                <TermPanel
                    title={t('website.allocation.domain')}
                    term={card.domain}
                    locale={locale}
                />
                <TermPanel
                    title={t('website.allocation.hosting')}
                    term={card.hosting}
                    locale={locale}
                />
            </div>

            <footer className="mt-auto flex flex-wrap gap-2">
                {card.website ? (
                    <Button asChild size="sm" variant="outline">
                        <Link href={show(card.website.id)}>
                            {t('website.allocation.open')}
                        </Link>
                    </Button>
                ) : (
                    // An expired term is renewed, not set up.
                    maySetUp &&
                    card.status !== 'expired' && (
                        <Button asChild size="sm">
                            <Link href={create()}>
                                {t('website.allocation.set_up')}
                            </Link>
                        </Button>
                    )
                )}
            </footer>
        </article>
    );
}

/**
 * The domain or the hosting side of a website's term.
 */
function TermPanel({
    title,
    term,
    locale,
}: {
    title: string;
    term: WebsiteAllocationTerm;
    locale: string;
}) {
    const { t } = useTranslation();

    const date = (value: string) =>
        new Date(value).toLocaleDateString(locale, {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        });

    const remaining = (() => {
        if (term.days_remaining === null) {
            return t('website.allocation.no_expiry');
        }

        if (term.days_remaining > 0) {
            return t('website.allocation.days_left', {
                days: term.days_remaining.toLocaleString(locale),
            });
        }

        if (term.days_remaining === 0) {
            return t('website.allocation.expires_today');
        }

        return t('website.allocation.expired_days_ago', {
            days: Math.abs(term.days_remaining).toLocaleString(locale),
        });
    })();

    const lapsed = term.days_remaining !== null && term.days_remaining < 0;

    return (
        <div className="bg-surface-subtle space-y-2 rounded-lg border p-3">
            <div className="flex items-center justify-between gap-2">
                <span className="text-sm font-semibold">{title}</span>
                <span
                    className={cn(
                        'tabular text-xs font-medium',
                        lapsed ? 'text-danger' : 'text-muted-foreground',
                    )}
                >
                    {remaining}
                </span>
            </div>

            {!term.included && (
                <p className="text-muted-foreground text-xs">
                    {t('website.allocation.not_included')}
                </p>
            )}

            <p className="text-muted-foreground text-2xs font-semibold tracking-wide uppercase">
                {t('website.allocation.package_period')}
            </p>

            <dl className="grid grid-cols-2 gap-2 text-xs">
                <div>
                    <dt className="text-muted-foreground">
                        {t('website.allocation.purchased')}
                    </dt>
                    <dd className="tabular font-medium">
                        {date(term.starts_at)}
                    </dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">
                        {t('website.allocation.expires')}
                    </dt>
                    <dd className="tabular font-medium">
                        {term.expires_at
                            ? date(term.expires_at)
                            : t('website.allocation.no_expiry')}
                    </dd>
                </div>
            </dl>

            {/*
             * The provider's own record, when there is one — shown as its own
             * line and never in place of the package's period above.
             */}
            {term.provider && (
                <div
                    className="space-y-1 border-t pt-2"
                    data-test="provider-record"
                >
                    <p className="text-muted-foreground text-2xs font-semibold tracking-wide uppercase">
                        {t('website.allocation.provider_record')}
                    </p>
                    <dl className="grid grid-cols-2 gap-2 text-xs">
                        {term.provider.plan && (
                            <div className="col-span-2">
                                <dt className="text-muted-foreground">
                                    {t('website.allocation.provider_plan')}
                                </dt>
                                <dd className="font-medium">
                                    {term.provider.plan}
                                </dd>
                            </div>
                        )}
                        <div>
                            <dt className="text-muted-foreground">
                                {t('website.allocation.provider_started')}
                            </dt>
                            <dd className="tabular font-medium">
                                {term.provider.started_at
                                    ? date(term.provider.started_at)
                                    : '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">
                                {t('website.allocation.provider_expires')}
                            </dt>
                            <dd className="tabular font-medium">
                                {term.provider.expires_at
                                    ? date(term.provider.expires_at)
                                    : t('website.allocation.no_expiry')}
                            </dd>
                        </div>
                    </dl>
                </div>
            )}

            {term.package_based && (
                <p
                    className="text-muted-foreground flex items-start gap-1.5 text-xs"
                    title={t('website.allocation.package_based_hint')}
                >
                    <CalendarClock
                        aria-hidden="true"
                        className="mt-0.5 size-3.5 shrink-0"
                    />
                    <span>
                        <span className="text-foreground font-medium">
                            {t('website.allocation.package_based')}
                        </span>
                        {' · '}
                        {t('website.allocation.package_based_hint')}
                    </span>
                </p>
            )}
        </div>
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
