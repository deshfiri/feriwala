import { Form, Head } from '@inertiajs/react';
import WithdrawalLimitsController from '@/actions/App/Http/Controllers/Admin/WithdrawalLimitsController';
import InputError from '@/components/input-error';
import MoneyInput from '@/components/money-input';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';

type Limits = {
    minimum: string;
    maximum: string | null;
};

type Props = {
    account: Limits;
    supplier: Limits;
    can: { manage: boolean };
};

/**
 * Platform-default withdrawal limits, and a per-owner override on either
 * side (§27, D25).
 *
 * Every figure here is a decimal Taka string, submitted and validated
 * exactly as typed (§36.1) -- nothing here computes or converts an amount.
 */
export default function WithdrawalLimits({ account, supplier, can }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('withdrawal.admin.limits.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('withdrawal.admin.limits.title')}
                    description={t('withdrawal.admin.limits.description')}
                />

                <SectionCard
                    title={t('withdrawal.admin.limits.account_section')}
                >
                    <Form
                        {...WithdrawalLimitsController.updateAccountDefault.form()}
                        options={{ preserveScroll: true }}
                        className="grid gap-4 sm:grid-cols-2"
                    >
                        {({ errors, processing }) => (
                            <>
                                <MoneyInput
                                    id="account-minimum"
                                    name="minimum"
                                    label={t('withdrawal.admin.limits.minimum')}
                                    defaultValue={account.minimum}
                                    disabled={!can.manage}
                                    error={errors.minimum}
                                />
                                <MoneyInput
                                    id="account-maximum"
                                    name="maximum"
                                    label={t('withdrawal.admin.limits.maximum')}
                                    defaultValue={account.maximum ?? ''}
                                    helpText={t(
                                        'withdrawal.admin.limits.no_maximum',
                                    )}
                                    disabled={!can.manage}
                                    error={errors.maximum}
                                />
                                {can.manage && (
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="sm:col-span-2 sm:w-fit"
                                    >
                                        {processing && <Spinner />}
                                        {t('withdrawal.admin.limits.save')}
                                    </Button>
                                )}
                            </>
                        )}
                    </Form>
                </SectionCard>

                <SectionCard
                    title={t('withdrawal.admin.limits.supplier_section')}
                >
                    <Form
                        {...WithdrawalLimitsController.updateSupplierDefault.form()}
                        options={{ preserveScroll: true }}
                        className="grid gap-4 sm:grid-cols-2"
                    >
                        {({ errors, processing }) => (
                            <>
                                <MoneyInput
                                    id="supplier-minimum"
                                    name="minimum"
                                    label={t('withdrawal.admin.limits.minimum')}
                                    defaultValue={supplier.minimum}
                                    disabled={!can.manage}
                                    error={errors.minimum}
                                />
                                <MoneyInput
                                    id="supplier-maximum"
                                    name="maximum"
                                    label={t('withdrawal.admin.limits.maximum')}
                                    defaultValue={supplier.maximum ?? ''}
                                    helpText={t(
                                        'withdrawal.admin.limits.no_maximum',
                                    )}
                                    disabled={!can.manage}
                                    error={errors.maximum}
                                />
                                {can.manage && (
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="sm:col-span-2 sm:w-fit"
                                    >
                                        {processing && <Spinner />}
                                        {t('withdrawal.admin.limits.save')}
                                    </Button>
                                )}
                            </>
                        )}
                    </Form>
                </SectionCard>

                {can.manage && (
                    <>
                        <SectionCard
                            title={`${t('withdrawal.admin.limits.override_section')} — ${t('withdrawal.admin.limits.account_section')}`}
                        >
                            <Form
                                {...WithdrawalLimitsController.updateAccountOverride.form()}
                                options={{ preserveScroll: true }}
                                resetOnSuccess
                                className="grid gap-4 sm:grid-cols-3"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="account-override-owner">
                                                {t(
                                                    'withdrawal.admin.limits.owner_public_id',
                                                )}
                                            </Label>
                                            <Input
                                                id="account-override-owner"
                                                name="owner"
                                                required
                                            />
                                            <InputError
                                                message={errors.owner}
                                            />
                                        </div>
                                        <MoneyInput
                                            id="account-override-minimum"
                                            name="minimum"
                                            label={t(
                                                'withdrawal.admin.limits.minimum',
                                            )}
                                            error={errors.minimum}
                                        />
                                        <MoneyInput
                                            id="account-override-maximum"
                                            name="maximum"
                                            label={t(
                                                'withdrawal.admin.limits.maximum',
                                            )}
                                            error={errors.maximum}
                                        />
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            className="sm:col-span-3 sm:w-fit"
                                        >
                                            {processing && <Spinner />}
                                            {t(
                                                'withdrawal.admin.limits.set_override',
                                            )}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </SectionCard>

                        <SectionCard
                            title={`${t('withdrawal.admin.limits.override_section')} — ${t('withdrawal.admin.limits.supplier_section')}`}
                        >
                            <Form
                                {...WithdrawalLimitsController.updateSupplierOverride.form()}
                                options={{ preserveScroll: true }}
                                resetOnSuccess
                                className="grid gap-4 sm:grid-cols-3"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="supplier-override-owner">
                                                {t(
                                                    'withdrawal.admin.limits.owner_public_id',
                                                )}
                                            </Label>
                                            <Input
                                                id="supplier-override-owner"
                                                name="owner"
                                                required
                                            />
                                            <InputError
                                                message={errors.owner}
                                            />
                                        </div>
                                        <MoneyInput
                                            id="supplier-override-minimum"
                                            name="minimum"
                                            label={t(
                                                'withdrawal.admin.limits.minimum',
                                            )}
                                            error={errors.minimum}
                                        />
                                        <MoneyInput
                                            id="supplier-override-maximum"
                                            name="maximum"
                                            label={t(
                                                'withdrawal.admin.limits.maximum',
                                            )}
                                            error={errors.maximum}
                                        />
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            className="sm:col-span-3 sm:w-fit"
                                        >
                                            {processing && <Spinner />}
                                            {t(
                                                'withdrawal.admin.limits.set_override',
                                            )}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </SectionCard>
                    </>
                )}
            </PageContainer>
        </>
    );
}

WithdrawalLimits.layout = {
    breadcrumbs: [
        {
            title: 'nav.withdrawal_limits',
            href: WithdrawalLimitsController.index(),
        },
    ],
};
