import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import FormField from '@/components/forms/form-field';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import WebsiteController from '@/actions/App/Http/Controllers/Erp/WebsiteController';
import type { Money } from '@/lib/money';
import { index } from '@/routes/websites';
import type { WebsiteEntitlement, WebsiteWallet } from '@/types/website';

type Props = {
    entitlement: WebsiteEntitlement;
    storefront_domain: string;
    wallet: WebsiteWallet;
    charges: Record<string, Money>;
};

/**
 * Asking for a dedicated website (§16.2, P5-8).
 *
 * Every figure here came from the server and is the same call that raises the
 * charges once the request goes through, so the price on this screen is the
 * price that gets charged (§9, §36.1). Nothing is charged by opening it.
 */
export default function RequestWebsite({
    entitlement,
    storefront_domain: storefrontDomain,
    wallet,
    charges,
}: Props) {
    const { t } = useTranslation();
    const [subdomain, setSubdomain] = useState('');

    const chargeRows = Object.entries(charges).filter(
        ([, amount]) => amount.minor_units > 0,
    );

    return (
        <>
            <Head title={t('website.create.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('website.create.title')}
                    description={t('website.create.subtitle')}
                    actions={
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={index()}>
                                <ArrowLeft aria-hidden="true" />
                                {t('website.title')}
                            </Link>
                        </Button>
                    }
                />

                <SectionCard title={t('website.create.title')}>
                    <Form
                        {...WebsiteController.store.form()}
                        className="space-y-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <FormField
                                    label={t('website.create.name')}
                                    description={t('website.create.name_hint')}
                                    error={errors.name}
                                    required
                                >
                                    {(field) => (
                                        <Input
                                            {...field}
                                            name="name"
                                            maxLength={120}
                                            required
                                        />
                                    )}
                                </FormField>

                                <FormField
                                    label={t('website.create.subdomain')}
                                    description={t(
                                        'website.create.subdomain_hint',
                                    )}
                                    hint={`${subdomain || 'your-shop'}.${storefrontDomain}`}
                                    error={errors.subdomain}
                                    required
                                >
                                    {(field) => (
                                        <Input
                                            {...field}
                                            name="subdomain"
                                            value={subdomain}
                                            onChange={(event) =>
                                                setSubdomain(
                                                    event.target.value.toLowerCase(),
                                                )
                                            }
                                            maxLength={63}
                                            required
                                        />
                                    )}
                                </FormField>

                                <Button
                                    type="submit"
                                    disabled={
                                        processing || !entitlement.allowed
                                    }
                                >
                                    {processing && <Spinner />}
                                    {t('website.create.submit')}
                                </Button>
                            </>
                        )}
                    </Form>
                </SectionCard>

                <SectionCard
                    title={t('website.create.charges_title')}
                    description={t('website.create.charges_hint')}
                >
                    {chargeRows.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            {t('website.show.charges_empty')}
                        </p>
                    ) : (
                        <dl className="grid gap-3 sm:grid-cols-2">
                            {chargeRows.map(([type, amount]) => (
                                <div key={type} className="space-y-1">
                                    <dt className="text-muted-foreground text-xs font-medium">
                                        {t(`website.charge_types.${type}`)}
                                    </dt>
                                    <dd>
                                        <MoneyAmount
                                            amount={amount}
                                            direction="debit"
                                        />
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    )}

                    {wallet.available && (
                        <p className="text-muted-foreground mt-4 text-xs">
                            {t('website.show.wallet_available', {
                                amount: wallet.available.formatted,
                            })}
                        </p>
                    )}
                </SectionCard>
            </PageContainer>
        </>
    );
}

RequestWebsite.layout = {
    breadcrumbs: [
        {
            title: 'Websites',
            href: index(),
        },
    ],
};
