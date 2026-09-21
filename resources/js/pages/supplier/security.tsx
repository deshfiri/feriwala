import { Form, Head } from '@inertiajs/react';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import PageHeader from '@/components/page-header';
import PasswordInput from '@/components/password-input';
import SectionCard from '@/components/section-card';
import { useTranslation } from '@/hooks/use-translation';
import { update } from '@/routes/supplier/security/password';

export default function SupplierSecurity() {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('supplier.security.title')} />

            <div className="mx-auto max-w-lg space-y-6">
                <PageHeader
                    title={t('supplier.security.title')}
                    description={t('supplier.security.description')}
                />

                <SectionCard title={t('supplier.fields.password')}>
                    <Form
                        {...update.form()}
                        resetOnSuccess
                        options={{ preserveScroll: true }}
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <FormField
                                    label={t(
                                        'supplier.fields.current_password',
                                    )}
                                    error={errors.current_password}
                                    required
                                >
                                    {(field) => (
                                        <PasswordInput
                                            {...field}
                                            name="current_password"
                                            autoComplete="current-password"
                                            required
                                        />
                                    )}
                                </FormField>
                                <FormField
                                    label={t('supplier.fields.password')}
                                    error={errors.password}
                                    required
                                >
                                    {(field) => (
                                        <PasswordInput
                                            {...field}
                                            name="password"
                                            autoComplete="new-password"
                                            required
                                        />
                                    )}
                                </FormField>
                                <FormField
                                    label={t(
                                        'supplier.fields.password_confirmation',
                                    )}
                                    required
                                >
                                    {(field) => (
                                        <PasswordInput
                                            {...field}
                                            name="password_confirmation"
                                            autoComplete="new-password"
                                            required
                                        />
                                    )}
                                </FormField>
                                <SubmitButton processing={processing}>
                                    {t('supplier.security.save')}
                                </SubmitButton>
                            </>
                        )}
                    </Form>
                </SectionCard>
            </div>
        </>
    );
}
