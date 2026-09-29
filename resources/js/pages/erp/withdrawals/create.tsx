import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AccountWithdrawalController from '@/actions/App/Http/Controllers/Erp/AccountWithdrawalController';
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
import type { Money } from '@/lib/money';
import { index as payoutMethods } from '@/routes/payout-methods';
import { index } from '@/routes/withdrawals';

const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

type Method = {
    id: string;
    label: string;
    type_label: string;
    masked_number: string;
    is_default: boolean;
};

type Props = {
    available_balance: Money;
    minimum: Money;
    maximum: Money | null;
    currency: string;
    methods: Method[];
    idempotency_key: string;
};

/**
 * A Client/Partner `BusinessAccount`'s own withdrawal request (§27, mirrors
 * `resources/js/pages/supplier/withdrawals/create.tsx` for a different owner).
 *
 * The server remains authoritative for balance, limits and eligibility — the
 * figures shown here are informational, and `RequestAccountWithdrawal`
 * re-checks all of them fresh, under a row lock, at submit time. The hidden
 * `idempotency_key` is generated once when this page loads and stays the
 * same across a retried submission of this form, so a double-click or a
 * network retry lands on the server's own idempotency guard rather than
 * creating a second request — the button is also disabled while the request
 * is in flight, as a first line of defence.
 */
export default function AccountWithdrawalCreate({
    available_balance: availableBalance,
    minimum,
    maximum,
    methods,
    idempotency_key: idempotencyKey,
}: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('withdrawal.erp.request')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('withdrawal.erp.request')}
                    description={t('withdrawal.erp.description')}
                    actions={
                        <Button variant="outline" size="sm" asChild>
                            <Link href={index()}>
                                <ArrowLeft
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                {t('withdrawal.erp.back')}
                            </Link>
                        </Button>
                    }
                />

                <SectionCard title={t('withdrawal.erp.available_balance')}>
                    <MoneyAmount
                        amount={availableBalance}
                        size="large"
                        direction="credit"
                    />
                    <p className="text-muted-foreground mt-2 text-xs">
                        {t('withdrawal.erp.minimum')}:{' '}
                        <MoneyAmount amount={minimum} size="small" />
                        {' · '}
                        {t('withdrawal.erp.maximum')}:{' '}
                        {maximum ? (
                            <MoneyAmount amount={maximum} size="small" />
                        ) : (
                            t('withdrawal.erp.no_maximum')
                        )}
                    </p>
                </SectionCard>

                {methods.length === 0 ? (
                    <SectionCard title={t('withdrawal.erp.select_method')}>
                        <p className="text-muted-foreground text-sm">
                            {t('withdrawal.erp.no_methods')}
                        </p>
                        <Button size="sm" className="mt-3" asChild>
                            <Link href={payoutMethods()}>
                                {t('withdrawal.erp.add_method')}
                            </Link>
                        </Button>
                    </SectionCard>
                ) : (
                    <SectionCard title={t('withdrawal.erp.request')}>
                        <Form
                            {...AccountWithdrawalController.store.form()}
                            options={{ preserveScroll: true }}
                            className="space-y-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="idempotency_key"
                                        value={idempotencyKey}
                                    />

                                    <div className="grid gap-2">
                                        <Label htmlFor="withdrawal-method">
                                            {t('withdrawal.erp.payout_method')}
                                        </Label>
                                        <select
                                            id="withdrawal-method"
                                            name="payout_method_id"
                                            required
                                            className={selectClass}
                                            defaultValue={
                                                methods.find(
                                                    (method) =>
                                                        method.is_default,
                                                )?.id ?? methods[0]?.id
                                            }
                                        >
                                            {methods.map((method) => (
                                                <option
                                                    key={method.id}
                                                    value={method.id}
                                                >
                                                    {method.label} (
                                                    {method.type_label} ·{' '}
                                                    {method.masked_number})
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={errors.payout_method_id}
                                        />
                                    </div>

                                    <MoneyInput
                                        id="withdrawal-amount"
                                        name="amount"
                                        label={t('withdrawal.erp.amount')}
                                        required
                                        error={errors.amount}
                                    />

                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        {t('withdrawal.erp.submit')}
                                    </Button>
                                </>
                            )}
                        </Form>
                    </SectionCard>
                )}
            </PageContainer>
        </>
    );
}
