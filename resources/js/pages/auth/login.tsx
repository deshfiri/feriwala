import { Form, Head, Link, setLayoutProps } from '@inertiajs/react';
import InputError from '@/components/input-error';
import LanguageSwitcher from '@/components/language-switcher';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import StaffInvitationAlert from '@/components/staff-invitation-alert';
import type { StaffInvitationContext } from '@/components/staff-invitation-alert';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import { register as supplierRegister } from '@/routes/supplier';

type Props = {
    status?: string;
    canResetPassword: boolean;
    staffInvitation?: StaffInvitationContext | null;
};

/**
 * The standard sign-in form: email, password, remember me, a forgot-password
 * link and a link to registration, inside the default auth layout. Passkey
 * sign-in, a staff invitation notice and the Supplier entry point sit alongside
 * it as before.
 */
export default function Login({
    status,
    canResetPassword,
    staffInvitation,
}: Props) {
    const { t } = useTranslation();

    setLayoutProps({
        title: t('auth.login.heading'),
        description: t('auth.login.intro'),
    });

    return (
        <>
            <Head title={t('auth.login.title')} />

            {staffInvitation && (
                <StaffInvitationAlert invitation={staffInvitation} />
            )}

            {status && (
                <div className="text-center text-sm font-medium text-green-600">
                    {status}
                </div>
            )}

            <PasskeyVerify />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {t('auth.login.email')}
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="email"
                                    placeholder={t(
                                        'auth.login.email_placeholder',
                                    )}
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center">
                                    <Label htmlFor="password">
                                        {t('auth.login.password')}
                                    </Label>
                                    {canResetPassword && (
                                        <TextLink
                                            href={request()}
                                            className="ml-auto text-sm"
                                            tabIndex={5}
                                        >
                                            {t('auth.login.forgot')}
                                        </TextLink>
                                    )}
                                </div>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="current-password"
                                    placeholder={t(
                                        'auth.login.password_placeholder',
                                    )}
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    tabIndex={3}
                                />
                                <Label htmlFor="remember">
                                    {t('auth.login.remember')}
                                </Label>
                            </div>

                            <Button
                                type="submit"
                                className="mt-4 w-full"
                                tabIndex={4}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                {processing
                                    ? t('auth.login.submitting')
                                    : t('auth.login.submit')}
                            </Button>
                        </div>

                        <div className="text-muted-foreground text-center text-sm">
                            {t('auth.login.new_here')}{' '}
                            <TextLink
                                href={register({
                                    query: {
                                        invitation: staffInvitation?.token,
                                    },
                                })}
                                tabIndex={6}
                                data-test="register-link"
                            >
                                {t('auth.login.create_account')}
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>

            <div className="text-muted-foreground flex flex-col items-center gap-3 border-t pt-6 text-center text-sm">
                <p>
                    {t('auth.login.supplier_prompt')}{' '}
                    <Link
                        href={supplierRegister()}
                        className="text-foreground underline underline-offset-4"
                    >
                        {t('auth.login.supplier_cta')}
                    </Link>
                </p>
                <LanguageSwitcher />
            </div>
        </>
    );
}
