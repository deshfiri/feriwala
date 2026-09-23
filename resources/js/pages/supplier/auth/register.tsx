import { Form, Head, Link } from '@inertiajs/react';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import PasswordInput from '@/components/password-input';
import { Input } from '@/components/ui/input';
import TextArea from '@/components/forms/text-area';
import { useTranslation } from '@/hooks/use-translation';
import { login } from '@/routes/supplier';
import { store } from '@/routes/supplier/register';

export default function SupplierRegister() {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('supplier.auth.register_title')} />

            <Form
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                className="flex flex-col gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        <FormField
                            label={t('supplier.fields.business_name')}
                            error={errors.business_name}
                            required
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    name="business_name"
                                    autoComplete="organization"
                                    required
                                />
                            )}
                        </FormField>

                        <FormField
                            label={t('supplier.fields.contact_person_name')}
                            error={errors.contact_person_name}
                            required
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    name="contact_person_name"
                                    autoComplete="name"
                                    required
                                />
                            )}
                        </FormField>

                        <FormField
                            label={t('supplier.fields.business_address')}
                            error={errors.business_address}
                            required
                        >
                            {(field) => (
                                <TextArea
                                    {...field}
                                    name="business_address"
                                    required
                                />
                            )}
                        </FormField>

                        <FormField
                            label={t('supplier.fields.email')}
                            error={errors.email}
                            required
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    type="email"
                                    name="email"
                                    autoComplete="email"
                                    required
                                />
                            )}
                        </FormField>

                        <FormField
                            label={t('supplier.fields.mobile')}
                            error={errors.mobile}
                            required
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    type="tel"
                                    name="mobile"
                                    autoComplete="tel"
                                    placeholder="+8801XXXXXXXXX"
                                    required
                                />
                            )}
                        </FormField>

                        <FormField
                            label={`${t('supplier.fields.trade_licence_number')} (${t('supplier.fields.optional')})`}
                            error={errors.trade_licence_number}
                        >
                            {(field) => (
                                <Input {...field} name="trade_licence_number" />
                            )}
                        </FormField>

                        <FormField
                            label={`${t('supplier.fields.tax_identification_number')} (${t('supplier.fields.optional')})`}
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
                            label={t('supplier.fields.password_confirmation')}
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

                        <SubmitButton
                            processing={processing}
                            className="w-full"
                        >
                            {t('supplier.auth.register_submit')}
                        </SubmitButton>

                        <p className="text-muted-foreground text-center text-sm">
                            {t('supplier.auth.have_account')}{' '}
                            <Link href={login()} className="underline">
                                {t('supplier.auth.login_submit')}
                            </Link>
                        </p>
                    </>
                )}
            </Form>
        </>
    );
}

SupplierRegister.layout = {
    titleKey: 'supplier.auth.register_title',
    descriptionKey: 'supplier.auth.register_description',
};
