import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import WebsiteController from '@/actions/App/Http/Controllers/Erp/WebsiteController';
import {
    websiteChargeTone,
    websiteHealthTone,
    websiteServiceTone,
    websiteStatusTone,
} from '@/lib/website';
import { index } from '@/routes/websites';
import type {
    WebsiteDetail,
    WebsiteDomainRow,
    WebsiteHostingRow,
    WebsiteWallet,
} from '@/types/website';

type Props = {
    website: WebsiteDetail;
    wallet: WebsiteWallet;
    can: { pay: boolean; manage: boolean };
};

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * One of the account's storefronts (§16.2, §16.4, P5-8–P5-11, P5-15).
 *
 * The page leads with where the website stands and what it is waiting for,
 * because that is the only question its owner has. What Feriwala wrote about it
 * internally, who decided, and which registrar or host it sits on are not here
 * and never arrive in the props.
 */
export default function WebsiteShow({ website, wallet, can }: Props) {
    const { t, locale } = useTranslation();
    const [maintenanceMessage, setMaintenanceMessage] = useState(
        website.lifecycle.maintenance_message ?? '',
    );

    const date = (value: string | null) =>
        value ? new Date(value).toLocaleDateString(locale) : '—';

    const outstanding = website.charges.filter(
        (charge) => charge.status === 'due',
    );

    const inMaintenance = website.status === 'maintenance';

    const term = (service: WebsiteDomainRow | WebsiteHostingRow) => (
        <div className="text-muted-foreground space-y-1 text-xs">
            <div>
                {t('website.show.expires', {
                    date: date(service.expires_at),
                })}
            </div>
            {service.days_remaining !== null && (
                <div>
                    {service.days_remaining < 0
                        ? t('website.show.expired')
                        : t('website.show.days_remaining', {
                              days: String(service.days_remaining),
                          })}
                </div>
            )}
        </div>
    );

    return (
        <>
            <Head title={website.name} />

            <PageContainer>
                <PageHeader
                    title={website.name}
                    description={website.host}
                    actions={
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={index()}>
                                <ArrowLeft aria-hidden="true" />
                                {t('website.title')}
                            </Link>
                        </Button>
                    }
                />

                <div className="flex flex-wrap items-center gap-2">
                    <StatusPill
                        tone={websiteStatusTone(website.status)}
                        label={website.status_label}
                    />
                    <StatusPill
                        tone={websiteHealthTone(website.connection.health)}
                        label={website.connection.health_label}
                    />
                </div>

                {website.lifecycle.suspension_reason && (
                    <div
                        className="border-danger bg-danger-subtle rounded-xl border p-4 text-sm"
                        role="status"
                    >
                        {website.lifecycle.suspension_reason}
                    </div>
                )}

                {website.lifecycle.grace_ends_at && (
                    <div
                        className="border-warning bg-warning-subtle rounded-xl border p-4 text-sm"
                        role="status"
                    >
                        {t('website.notes.grace_period')}{' '}
                        {t('website.show.expires', {
                            date: date(website.lifecycle.grace_ends_at),
                        })}
                    </div>
                )}

                <SectionCard
                    title={t('website.show.charges')}
                    description={
                        wallet.available
                            ? t('website.show.wallet_available', {
                                  amount: wallet.available.formatted,
                              })
                            : undefined
                    }
                >
                    {website.charges.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            {t('website.show.charges_empty')}
                        </p>
                    ) : (
                        <ul className="divide-border divide-y">
                            {website.charges.map((charge) => (
                                <li
                                    key={charge.id}
                                    className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                                >
                                    <div className="min-w-0 space-y-1">
                                        <div className="text-sm font-medium">
                                            {charge.type_label}
                                        </div>
                                        <StatusPill
                                            tone={websiteChargeTone(
                                                charge.status,
                                            )}
                                            label={charge.status_label}
                                        />
                                    </div>

                                    <div className="flex items-center gap-3">
                                        <MoneyAmount
                                            amount={charge.amount}
                                            direction="debit"
                                        />

                                        {can.pay && charge.status === 'due' && (
                                            <Form
                                                {...WebsiteController.payCharge.form(
                                                    {
                                                        website: website.id,
                                                        charge: charge.id,
                                                    },
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                            >
                                                {({ processing, errors }) => (
                                                    <div className="space-y-1">
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            {processing && (
                                                                <Spinner />
                                                            )}
                                                            {t(
                                                                'website.show.pay',
                                                            )}
                                                        </Button>
                                                        <InputError
                                                            message={
                                                                errors.wallet ??
                                                                errors.charge
                                                            }
                                                        />
                                                    </div>
                                                )}
                                            </Form>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}

                    {outstanding.length > 0 && (
                        <p className="text-muted-foreground mt-4 text-xs">
                            {t('website.index.outstanding', {
                                count: String(outstanding.length),
                            })}
                        </p>
                    )}
                </SectionCard>

                <SectionCard title={t('website.show.domains')}>
                    {website.domains.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            {t('website.show.domains_empty')}
                        </p>
                    ) : (
                        <ul className="divide-border divide-y">
                            {website.domains.map((domain) => (
                                <li
                                    key={domain.id}
                                    className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                                >
                                    <div className="min-w-0 space-y-1">
                                        <div className="truncate font-mono text-sm">
                                            {domain.domain}
                                        </div>
                                        <StatusPill
                                            tone={websiteServiceTone(
                                                domain.status,
                                            )}
                                            label={domain.status_label}
                                        />
                                        {term(domain)}
                                    </div>

                                    {can.pay && (
                                        <Form
                                            {...WebsiteController.renewDomain.form(
                                                {
                                                    website: website.id,
                                                    domain: domain.id,
                                                },
                                            )}
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={processing}
                                                >
                                                    {processing && <Spinner />}
                                                    {t('website.show.renew')}
                                                </Button>
                                            )}
                                        </Form>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>

                <SectionCard title={t('website.show.hostings')}>
                    {website.hostings.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            {t('website.show.hostings_empty')}
                        </p>
                    ) : (
                        <ul className="divide-border divide-y">
                            {website.hostings.map((hosting) => (
                                <li
                                    key={hosting.id}
                                    className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                                >
                                    <div className="min-w-0 space-y-1">
                                        <div className="truncate text-sm">
                                            {hosting.plan}
                                        </div>
                                        <StatusPill
                                            tone={websiteServiceTone(
                                                hosting.status,
                                            )}
                                            label={hosting.status_label}
                                        />
                                        {term(hosting)}
                                    </div>

                                    {can.pay && (
                                        <Form
                                            {...WebsiteController.renewHosting.form(
                                                {
                                                    website: website.id,
                                                    hosting: hosting.id,
                                                },
                                            )}
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={processing}
                                                >
                                                    {processing && <Spinner />}
                                                    {t('website.show.renew')}
                                                </Button>
                                            )}
                                        </Form>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>

                <SectionCard title={t('website.show.connection')}>
                    <dl className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1">
                            <dt className="text-muted-foreground text-xs font-medium">
                                {t('website.show.last_synced')}
                            </dt>
                            <dd className="text-sm">
                                {website.connection.last_synced_at
                                    ? new Date(
                                          website.connection.last_synced_at,
                                      ).toLocaleString(locale)
                                    : t('website.show.never_synced')}
                            </dd>
                        </div>

                        <div className="space-y-1">
                            <dt className="text-muted-foreground text-xs font-medium">
                                {t('website.show.address')}
                            </dt>
                            <dd className="font-mono text-sm">
                                {website.host}
                            </dd>
                        </div>
                    </dl>
                </SectionCard>

                {can.manage && (
                    <SectionCard
                        title={t('website.show.maintenance_title')}
                        description={t('website.show.maintenance_hint')}
                    >
                        <Form
                            {...WebsiteController.updateMaintenance.form(
                                website.id,
                            )}
                            options={{ preserveScroll: true }}
                            transform={(data) => ({
                                ...data,
                                enabled: inMaintenance ? 0 : 1,
                            })}
                            className="space-y-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    {!inMaintenance && (
                                        <div className="grid gap-2">
                                            <Label htmlFor="maintenance-message">
                                                {t(
                                                    'website.show.maintenance_message',
                                                )}
                                            </Label>
                                            <textarea
                                                id="maintenance-message"
                                                name="message"
                                                rows={3}
                                                maxLength={500}
                                                value={maintenanceMessage}
                                                onChange={(event) =>
                                                    setMaintenanceMessage(
                                                        event.target.value,
                                                    )
                                                }
                                                className={controlClass}
                                            />
                                            <InputError
                                                message={errors.message}
                                            />
                                        </div>
                                    )}

                                    <Button
                                        type="submit"
                                        variant={
                                            inMaintenance
                                                ? 'default'
                                                : 'outline'
                                        }
                                        disabled={processing}
                                    >
                                        {processing && <Spinner />}
                                        {inMaintenance
                                            ? t('website.show.maintenance_off')
                                            : t('website.show.maintenance_on')}
                                    </Button>

                                    <InputError message={errors.status} />
                                </>
                            )}
                        </Form>
                    </SectionCard>
                )}

                <SectionCard title={t('website.show.history')}>
                    {website.history.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            {t('website.show.no_history')}
                        </p>
                    ) : (
                        <ol className="space-y-3">
                            {[...website.history].reverse().map((entry) => (
                                <li key={entry.id} className="space-y-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <StatusPill
                                            tone={websiteStatusTone(
                                                entry.new_status,
                                            )}
                                            label={entry.new_status_label}
                                        />
                                        <span className="text-muted-foreground text-xs">
                                            {new Date(
                                                entry.changed_at,
                                            ).toLocaleString(locale)}
                                        </span>
                                    </div>
                                    {entry.note && (
                                        <p className="text-sm">{entry.note}</p>
                                    )}
                                </li>
                            ))}
                        </ol>
                    )}
                </SectionCard>
            </PageContainer>
        </>
    );
}

WebsiteShow.layout = {
    breadcrumbs: [
        {
            title: 'Websites',
            href: index(),
        },
    ],
};
