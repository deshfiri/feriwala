import { Form, Head } from '@inertiajs/react';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import { email } from '@/routes/supplier/password';

export default function SupplierForgotPassword({
    status,
}: {
    status?: string;
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('supplier.auth.forgot_title')} />

            {status && (
                <p
                    className="text-success text-center text-sm font-medium"
                    role="status"
                >
                    {status}
                </p>
            )}

            <Form {...email.form()} className="flex flex-col gap-5">
                {({ processing, errors }) => (
                    <>
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
                                    autoFocus
                                    required
                                />
                            )}
                        </FormField>

                        <SubmitButton
                            processing={processing}
                            className="w-full"
                        >
                            {t('supplier.auth.forgot_submit')}
                        </SubmitButton>
                    </>
                )}
            </Form>
        </>
    );
}

SupplierForgotPassword.layout = {
    titleKey: 'supplier.auth.forgot_title',
    descriptionKey: 'supplier.auth.forgot_description',
};
