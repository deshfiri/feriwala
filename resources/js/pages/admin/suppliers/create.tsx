import { Form, Head } from '@inertiajs/react';
import ManagedSupplierController from '@/actions/App/Http/Controllers/Admin/ManagedSupplierController';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import TextArea from '@/components/forms/text-area';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Staff opening a Supplier account on a Supplier's behalf. No password field:
 * the Supplier sets their own from an emailed, expiring, single-use link.
 */
export default function CreateSupplier() {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('managed_accounts.supplier.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('managed_accounts.supplier.title')}
                    description={t('managed_accounts.supplier.description')}
                />

                <SectionCard title={t('managed_accounts.supplier.title')}>
                    <Form
                        {...ManagedSupplierController.store.form()}
                        className="grid gap-4 sm:grid-cols-2"
                    >
                        {({ processing, errors }) => (
                            <>
                                <FormField
                                    label={t(
                                        'managed_accounts.fields.business_name',
                                    )}
                                    error={errors.business_name}
                                    required
                                >
                                    {(field) => (
                                        <Input
                                            {...field}
                                            name="business_name"
                                            required
                                        />
                                    )}
                                </FormField>
                                <FormField
                                    label={t(
                                        'managed_accounts.fields.contact_person_name',
                                    )}
                                    error={errors.contact_person_name}
                                    required
                                >
                                    {(field) => (
                                        <Input
                                            {...field}
                                            name="contact_person_name"
                                            required
                                        />
                                    )}
                                </FormField>
                                <FormField
                                    label={t('managed_accounts.fields.email')}
                                    error={errors.email}
                                    required
                                >
                                    {(field) => (
                                        <Input
                                            {...field}
                                            type="email"
                                            name="email"
                                            autoComplete="off"
                                            required
                                        />
                                    )}
                                </FormField>
                                <FormField
                                    label={t('managed_accounts.fields.mobile')}
                                    error={errors.mobile}
                                    required
                                >
                                    {(field) => (
                                        <Input
                                            {...field}
                                            type="tel"
                                            name="mobile"
                                            autoComplete="off"
                                            required
                                        />
                                    )}
                                </FormField>
                                <FormField
                                    label={t(
                                        'managed_accounts.fields.business_address',
                                    )}
                                    error={errors.business_address}
                                    className="sm:col-span-2"
                                    required
                                >
                                    {(field) => (
                                        <Input
                                            {...field}
                                            name="business_address"
                                            required
                                        />
                                    )}
                                </FormField>
                                <FormField
                                    label={t(
                                        'managed_accounts.fields.trade_licence_number',
                                    )}
                                    error={errors.trade_licence_number}
                                >
                                    {(field) => (
                                        <Input
                                            {...field}
                                            name="trade_licence_number"
                                        />
                                    )}
                                </FormField>
                                <FormField
                                    label={t(
                                        'managed_accounts.fields.tax_identification_number',
                                    )}
                                    error={errors.tax_identification_number}
                                >
                                    {(field) => (
                                        <Input
                                            {...field}
                                            name="tax_identification_number"
                                        />
                                    )}
                                </FormField>
                                <FormField
                                    label={t('managed_accounts.fields.reason')}
                                    description={t(
                                        'managed_accounts.fields.reason_help',
                                    )}
                                    error={errors.reason}
                                    className="sm:col-span-2"
                                    required
                                >
                                    {(field) => (
                                        <TextArea
                                            {...field}
                                            name="reason"
                                            required
                                        />
                                    )}
                                </FormField>

                                <p className="text-muted-foreground text-sm sm:col-span-2">
                                    {t('managed_accounts.notice')}
                                </p>

                                <div className="sm:col-span-2">
                                    <SubmitButton processing={processing}>
                                        {t('managed_accounts.submit')}
                                    </SubmitButton>
                                </div>
                            </>
                        )}
                    </Form>
                </SectionCard>
            </PageContainer>
        </>
    );
}
