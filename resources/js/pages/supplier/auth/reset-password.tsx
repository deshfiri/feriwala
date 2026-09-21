import { Form, Head } from '@inertiajs/react';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import PasswordInput from '@/components/password-input';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import { update } from '@/routes/supplier/password';

export default function SupplierResetPassword({
    token,
    email,
}: {
    token: string;
    email: string;
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('supplier.auth.reset_title')} />

            <Form
                {...update.form()}
                transform={(data) => ({ ...data, token, email })}
                resetOnSuccess={['password', 'password_confirmation']}
                className="flex flex-col gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        <FormField
                            label={t('supplier.fields.email')}
                            error={errors.email}
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    type="email"
                                    value={email}
                                    readOnly
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
                                    autoFocus
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
                            {t('supplier.auth.reset_submit')}
                        </SubmitButton>
                    </>
                )}
            </Form>
        </>
    );
}

SupplierResetPassword.layout = {
    titleKey: 'supplier.auth.reset_title',
};
