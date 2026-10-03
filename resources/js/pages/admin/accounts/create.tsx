import { Form, Head } from '@inertiajs/react';
import ManagedAccountController from '@/actions/App/Http/Controllers/Admin/ManagedAccountController';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import TextArea from '@/components/forms/text-area';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import type { SelectOption } from '@/types';

/**
 * Staff opening a Client/Partner account on someone's behalf. There is no
 * password field by design: the owner sets their own from an emailed,
 * expiring, single-use link.
 */
export default function CreateAccount({
    countries,
    default_country,
}: {
    countries: SelectOption[];
    default_country: string;
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('managed_accounts.partner.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('managed_accounts.partner.title')}
                    description={t('managed_accounts.partner.description')}
                />

                <SectionCard title={t('managed_accounts.partner.title')}>
                    <Form
                        {...ManagedAccountController.store.form()}
                        className="grid gap-4 sm:grid-cols-2"
                    >
                        {({ processing, errors }) => (
                            <>
                                <FormField
                                    label={t('managed_accounts.fields.name')}
                                    error={errors.name}
                                    required
                                >
                                    {(field) => (
                                        <Input
                                            {...field}
                                            name="name"
                                            required
                                        />
                                    )}
                                </FormField>
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
                                    label={t('managed_accounts.fields.country')}
                                    error={errors.country}
                                >
                                    {(field) => (
                                        <select
                                            {...field}
                                            name="country"
                                            defaultValue={default_country}
                                            className="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                                        >
                                            {countries.map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                    )}
                                </FormField>
                                <FormField
                                    label={t(
                                        'managed_accounts.fields.referral_code',
                                    )}
                                    description={t(
                                        'managed_accounts.fields.referral_help',
                                    )}
                                    error={errors.referral_code}
                                >
                                    {(field) => (
                                        <Input
                                            {...field}
                                            name="referral_code"
                                            autoComplete="off"
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
