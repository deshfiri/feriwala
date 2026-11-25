import { Form, Head, setLayoutProps } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import StaffInvitationAlert from '@/components/staff-invitation-alert';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { login } from '@/routes';
import { store } from '@/routes/register';
import type { StaffInvitationContext } from '@/components/staff-invitation-alert';

type Option = { value: string; label: string };

type Props = {
    passwordRules: string;
    staffInvitation?: StaffInvitationContext | null;
    countries: Option[];
    defaultCountry: string;
    genders: Option[];
    diallingCodes: (Option & { code: string })[];
    referralCode?: string | null;
};

/**
 * Opening a Feriwala account (§5.1 step one, §5.2).
 *
 * The form asks for exactly what `CreateNewUser` requires and nothing more.
 * It had drifted to four fields while the validator wanted seven, which meant
 * every real registration failed on a mobile number the form never collected —
 * so the fields here are not decoration, they are the contract.
 *
 * D39 keeps it to what the specification asks at the door: legal full name as
 * on the ID, country of residence, a mobile from any country (calling code plus
 * number), email, password, and the two acceptances. Date of birth and
 * nationality are asked at the personal-details step, once the package is paid.
 */
export default function Register({
    passwordRules,
    staffInvitation,
    countries,
    defaultCountry,
    diallingCodes,
    referralCode,
}: Props) {
    const { t } = useTranslation();

    setLayoutProps({
        title: t('auth.register.layout_title'),
        description: t('auth.register.layout_description'),
    });

    return (
        <>
            <Head title={t('auth.register.title')} />
            <Form
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        {staffInvitation && (
                            <>
                                <StaffInvitationAlert
                                    invitation={staffInvitation}
                                />

                                {/*
                                 * Carried through the form so registering does
                                 * not open a business of their own. Someone
                                 * joining an existing account must not leave
                                 * registration owning the one membership D1
                                 * allows them.
                                 */}
                                <input
                                    type="hidden"
                                    name="invitation"
                                    value={staffInvitation.token}
                                />
                            </>
                        )}

                        <div className="grid gap-5">
                            <div className="grid gap-2">
                                <Label htmlFor="name">
                                    {t('auth.register.name')}
                                </Label>
                                <Input
                                    id="name"
                                    type="text"
                                    required
                                    autoFocus
                                    autoComplete="name"
                                    name="name"
                                    placeholder={t(
                                        'auth.register.name_placeholder',
                                    )}
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="country">
                                    {t('auth.register.country')}
                                </Label>
                                {/* From the supported registry, never free
                                    text — a country nobody can resolve is a
                                    scope rule that matches nobody. */}
                                <select
                                    id="country"
                                    name="country"
                                    required
                                    defaultValue={defaultCountry}
                                    className="border-input bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                >
                                    {countries.map((country) => (
                                        <option
                                            key={country.value}
                                            value={country.value}
                                        >
                                            {country.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.country} />
                            </div>

                            {/* `items-start`, or a column with a helper line
                                under it stretches its neighbour and the two
                                inputs stop lining up. */}
                            <div className="grid gap-5">
                                <div className="grid gap-2">
                                    <Label htmlFor="email">
                                        {t('auth.register.email')}
                                    </Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        required
                                        autoComplete="email"
                                        name="email"
                                        placeholder="email@example.com"
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="mobile">
                                        {t('auth.register.mobile')}
                                    </Label>
                                    {/* Any country (D39): the code, then the
                                        number without it. */}
                                    <div className="grid grid-cols-[minmax(0,9rem)_minmax(0,1fr)] gap-2">
                                        <select
                                            name="mobile_country"
                                            aria-label={t(
                                                'onboarding.mobile.country',
                                            )}
                                            defaultValue={defaultCountry}
                                            className="border-input bg-background focus-visible:ring-ring h-9 min-w-0 rounded-md border px-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                        >
                                            {diallingCodes.map((code) => (
                                                <option
                                                    key={code.value}
                                                    value={code.value}
                                                >
                                                    {code.label}
                                                </option>
                                            ))}
                                        </select>
                                        <Input
                                            id="mobile"
                                            type="tel"
                                            required
                                            autoComplete="tel-national"
                                            inputMode="tel"
                                            name="mobile"
                                            placeholder="1XXXXXXXXX"
                                        />
                                    </div>
                                    {/* §5.2 makes this the second identity
                                        factor, and OTP verification depends on
                                        reaching it. */}
                                    <p className="text-muted-foreground text-xs">
                                        {t('auth.register.mobile_hint')}
                                    </p>
                                    <InputError message={errors.mobile} />
                                </div>
                            </div>

                            {/* `items-start`, or a column with a helper line
                                under it stretches its neighbour and the two
                                inputs stop lining up. */}
                            <div className="grid gap-5">
                                <div className="grid gap-2">
                                    <Label htmlFor="password">
                                        {t('auth.register.password')}
                                    </Label>
                                    <PasswordInput
                                        id="password"
                                        required
                                        autoComplete="new-password"
                                        name="password"
                                        placeholder={t(
                                            'auth.register.password_placeholder',
                                        )}
                                        passwordrules={passwordRules}
                                    />
                                    <InputError message={errors.password} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password_confirmation">
                                        {t(
                                            'auth.register.password_confirmation',
                                        )}
                                    </Label>
                                    <PasswordInput
                                        id="password_confirmation"
                                        required
                                        autoComplete="new-password"
                                        name="password_confirmation"
                                        placeholder={t(
                                            'auth.register.password_confirmation_placeholder',
                                        )}
                                        passwordrules={passwordRules}
                                    />
                                    <InputError
                                        message={errors.password_confirmation}
                                    />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="referral_code">
                                    {t('auth.register.referral_code')}
                                </Label>
                                <Input
                                    id="referral_code"
                                    type="text"
                                    name="referral_code"
                                    defaultValue={referralCode ?? ''}
                                    placeholder={t(
                                        'auth.register.referral_placeholder',
                                    )}
                                />
                                {/* A wrong code never blocks registration
                                    (§25.1) — it simply credits nobody. */}
                                <InputError message={errors.referral_code} />
                            </div>

                            <div className="grid gap-3">
                                <div className="flex items-start gap-3">
                                    <Checkbox
                                        id="terms_accepted"
                                        name="terms_accepted"
                                        value="1"
                                        required
                                    />
                                    <Label
                                        htmlFor="terms_accepted"
                                        className="text-sm font-normal"
                                    >
                                        {t('auth.register.terms')}
                                    </Label>
                                </div>
                                <InputError message={errors.terms_accepted} />

                                <div className="flex items-start gap-3">
                                    <Checkbox
                                        id="privacy_accepted"
                                        name="privacy_accepted"
                                        value="1"
                                        required
                                    />
                                    <Label
                                        htmlFor="privacy_accepted"
                                        className="text-sm font-normal"
                                    >
                                        {t('auth.register.privacy')}
                                    </Label>
                                </div>
                                <InputError message={errors.privacy_accepted} />
                            </div>

                            <Button
                                type="submit"
                                className="mt-2 w-full"
                                data-test="register-user-button"
                            >
                                {processing && <Spinner />}
                                {t('auth.register.submit')}
                            </Button>
                        </div>

                        <div className="text-muted-foreground text-center text-sm">
                            {t('auth.register.have_account')}{' '}
                            <TextLink
                                href={
                                    staffInvitation
                                        ? login.url({
                                              query: {
                                                  invitation:
                                                      staffInvitation.token,
                                              },
                                          })
                                        : login()
                                }
                                data-test="staff-invitation-login-link"
                            >
                                {t('auth.register.log_in')}
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}
