import { Form, Head, Link } from '@inertiajs/react';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import PasswordInput from '@/components/password-input';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import { register } from '@/routes/supplier';
import { store } from '@/routes/supplier/login';
import { request } from '@/routes/supplier/password';

export default function SupplierLogin({ status }: { status?: string }) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('supplier.auth.login_title')} />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-5"
            >
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

                        <FormField
                            label={t('supplier.fields.password')}
                            error={errors.password}
                            required
                        >
                            {(field) => (
                                <PasswordInput
                                    {...field}
                                    name="password"
                                    autoComplete="current-password"
                                    required
                                />
                            )}
                        </FormField>

                        <div className="flex items-center justify-between gap-3">
                            <div className="flex items-center gap-2">
                                <Checkbox id="remember" name="remember" />
                                <Label htmlFor="remember">
                                    {t('supplier.fields.remember')}
                                </Label>
                            </div>
                            <Link
                                href={request()}
                                className="text-sm underline"
                            >
                                {t('supplier.auth.forgot')}
                            </Link>
                        </div>

                        <SubmitButton
                            processing={processing}
                            className="w-full"
                            data-test="supplier-login-button"
                        >
                            {t('supplier.auth.login_submit')}
                        </SubmitButton>

                        <p className="text-muted-foreground text-center text-sm">
                            {t('supplier.auth.no_account')}{' '}
                            <Link href={register()} className="underline">
                                {t('supplier.auth.register_title')}
                            </Link>
                        </p>
                    </>
                )}
            </Form>

            {status && (
                <p
                    className="text-success text-center text-sm font-medium"
                    role="status"
                >
                    {status}
                </p>
            )}
        </>
    );
}

SupplierLogin.layout = {
    titleKey: 'supplier.auth.login_title',
    descriptionKey: 'supplier.auth.login_description',
};
