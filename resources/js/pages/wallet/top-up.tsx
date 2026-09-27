import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
import MoneyInput from '@/components/money-input';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import WalletTopUpController from '@/actions/App/Http/Controllers/Erp/WalletTopUpController';
import { show } from '@/routes/wallet';
import { isPositive, type Money } from '@/lib/money';
import type { WalletBalances } from '@/types/wallet';

type Props = {
    balances: WalletBalances;
    minimum: Money;
    suggested: Money;
    gateways: { value: string; label: string }[];
};

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Putting money into your own wallet (§24, §26.3).
 *
 * Every figure on this page came from the server, including the smallest amount
 * allowed and the amount that would clear the shortfall. Nothing is worked out
 * here — a total a browser calculated is a total the ledger cannot vouch for
 * (§36.1) — and the amount is the only thing sent back.
 *
 * The split is stated before the button, not after the payment: money into a
 * wallet that is short does two jobs, and "top up ৳5,000, of which ৳3,000 has to
 * stay" is a different sentence from "top up ৳5,000".
 */
export default function WalletTopUp({
    balances,
    minimum,
    suggested,
    gateways,
}: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('wallet.top_up.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('wallet.top_up.title')}
                    description={t('wallet.top_up.description')}
                    actions={
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={show()}>
                                <ArrowLeft aria-hidden="true" />
                                {t('wallet.detail.back')}
                            </Link>
                        </Button>
                    }
                />

                {isPositive(suggested) && (
                    <div
                        className="border-warning bg-warning-subtle rounded-xl border p-4"
                        role="status"
                    >
                        <p className="text-sm font-medium">
                            {t('wallet.shortfall.title')}
                        </p>
                        <p className="text-muted-foreground mt-1 text-sm">
                            {t('wallet.top_up.suggested', {
                                amount: suggested.formatted,
                            })}
                        </p>
                    </div>
                )}

                <SectionCard title={t('wallet.top_up.title')}>
                    <Form
                        {...WalletTopUpController.store.form()}
                        className="space-y-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <MoneyInput
                                    id="top-up-amount"
                                    name="amount"
                                    label={t('wallet.top_up.amount')}
                                    required
                                    defaultValue={
                                        isPositive(suggested)
                                            ? suggested.amount
                                            : undefined
                                    }
                                    helpText={t('wallet.top_up.minimum', {
                                        amount: minimum.formatted,
                                    })}
                                    error={errors.amount}
                                />

                                <div className="grid gap-2">
                                    <Label htmlFor="top-up-gateway">
                                        {t('wallet.top_up.gateway')}
                                    </Label>

                                    <select
                                        id="top-up-gateway"
                                        name="gateway"
                                        required
                                        className={controlClass}
                                    >
                                        {gateways.map((gateway) => (
                                            <option
                                                key={gateway.value}
                                                value={gateway.value}
                                            >
                                                {gateway.label}
                                            </option>
                                        ))}
                                    </select>

                                    <InputError message={errors.gateway} />
                                </div>

                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {t('wallet.top_up.submit')}
                                </Button>
                            </>
                        )}
                    </Form>
                </SectionCard>

                <SectionCard title={t('wallet.statement.title')}>
                    <dl className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1">
                            <dt className="text-muted-foreground text-xs font-medium">
                                {t('wallet.balances.total')}
                            </dt>
                            <dd>
                                <MoneyAmount amount={balances.total} />
                            </dd>
                        </div>

                        <div className="space-y-1">
                            <dt className="text-muted-foreground text-xs font-medium">
                                {t('wallet.balances.usable')}
                            </dt>
                            <dd>
                                <MoneyAmount amount={balances.usable} />
                            </dd>
                        </div>

                        <div className="space-y-1">
                            <dt className="text-muted-foreground text-xs font-medium">
                                {t('wallet.balances.required_deposit')}
                            </dt>
                            <dd>
                                <MoneyAmount
                                    amount={balances.required_deposit}
                                />
                            </dd>
                        </div>

                        <div className="space-y-1">
                            <dt className="text-muted-foreground text-xs font-medium">
                                {t('wallet.balances.minimum_balance')}
                            </dt>
                            <dd>
                                <MoneyAmount
                                    amount={balances.minimum_balance}
                                />
                            </dd>
                        </div>
                    </dl>
                </SectionCard>
            </PageContainer>
        </>
    );
}

WalletTopUp.layout = {
    breadcrumbs: [
        {
            title: 'nav.wallet',
            href: show(),
        },
    ],
};
