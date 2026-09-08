import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import StaffInvitationAlert from '@/components/staff-invitation-alert';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
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
 * Mobile is required because §5.2 makes it the second identity factor and OTP
 * verification depends on it. Date of birth, gender, country and nationality are
 * optional: an account can trade without them, and demanding them at the door
 * costs registrations for data that can be filled in later.
 */
export default function Register({
    passwordRules,
    staffInvitation,
    countries,
    defaultCountry,
    genders,
    referralCode,
}: Props) {
    return (
        <>
            <Head title="Register" />
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
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    type="text"
                                    required
                                    autoFocus
                                    autoComplete="name"
                                    name="name"
                                    placeholder="Full name"
                                />
                                <InputError message={errors.name} />
                            </div>

                            {/* `items-start`, or a column with a helper line
                                under it stretches its neighbour and the two
                                inputs stop lining up. */}
                            <div className="grid items-start gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="email">Email address</Label>
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
                                        Mobile number
                                    </Label>
                                    <Input
                                        id="mobile"
                                        type="tel"
                                        required
                                        autoComplete="tel"
                                        name="mobile"
                                        placeholder="+8801XXXXXXXXX"
                                    />
                                    {/* §5.2 makes this the second identity
                                        factor, and OTP verification depends on
                                        reaching it. */}
                                    <p className="text-muted-foreground text-xs">
                                        We send a verification code to this
                                        number.
                                    </p>
                                    <InputError message={errors.mobile} />
                                </div>
                            </div>

                            {/* `items-start`, or a column with a helper line
                                under it stretches its neighbour and the two
                                inputs stop lining up. */}
                            <div className="grid items-start gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="password">Password</Label>
                                    <PasswordInput
                                        id="password"
                                        required
                                        autoComplete="new-password"
                                        name="password"
                                        placeholder="Password"
                                        passwordrules={passwordRules}
                                    />
                                    <InputError message={errors.password} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password_confirmation">
                                        Confirm password
                                    </Label>
                                    <PasswordInput
                                        id="password_confirmation"
                                        required
                                        autoComplete="new-password"
                                        name="password_confirmation"
                                        placeholder="Confirm password"
                                        passwordrules={passwordRules}
                                    />
                                    <InputError
                                        message={errors.password_confirmation}
                                    />
                                </div>
                            </div>

                            {/*
                             * Optional throughout. An account can trade without
                             * any of it, and asking for it as a condition of
                             * entry costs registrations for data that is just
                             * as useful filled in later.
                             */}
                            <fieldset className="grid items-start gap-4 sm:grid-cols-2">
                                <legend className="text-muted-foreground mb-2 w-full text-xs">
                                    Optional — you can add these later
                                </legend>

                                <div className="grid gap-2">
                                    <Label htmlFor="date_of_birth">
                                        Date of birth
                                    </Label>
                                    <Input
                                        id="date_of_birth"
                                        type="date"
                                        name="date_of_birth"
                                        autoComplete="bday"
                                        max={new Date()
                                            .toISOString()
                                            .slice(0, 10)}
                                    />
                                    <InputError
                                        message={errors.date_of_birth}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="gender">Gender</Label>
                                    <select
                                        id="gender"
                                        name="gender"
                                        defaultValue=""
                                        className="border-input bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                    >
                                        <option value="">Not specified</option>
                                        {genders.map((gender) => (
                                            <option
                                                key={gender.value}
                                                value={gender.value}
                                            >
                                                {gender.label}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.gender} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="country">Country</Label>
                                    {/* From the supported registry, never free
                                        text — a country nobody can resolve is a
                                        scope rule that matches nobody. */}
                                    <select
                                        id="country"
                                        name="country"
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

                                <div className="grid gap-2">
                                    <Label htmlFor="nationality">
                                        Nationality
                                    </Label>
                                    <Input
                                        id="nationality"
                                        type="text"
                                        name="nationality"
                                        placeholder="Bangladeshi"
                                    />
                                    <InputError message={errors.nationality} />
                                </div>
                            </fieldset>

                            <div className="grid gap-2">
                                <Label htmlFor="referral_code">
                                    Referral code
                                </Label>
                                <Input
                                    id="referral_code"
                                    type="text"
                                    name="referral_code"
                                    defaultValue={referralCode ?? ''}
                                    placeholder="Optional"
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
                                        I accept the terms and conditions
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
                                        I accept the privacy policy
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
                                Create account
                            </Button>
                        </div>

                        <div className="text-muted-foreground text-center text-sm">
                            Already have an account?{' '}
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
                                Log in
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

Register.layout = {
    title: 'Create an account',
    description: 'Enter your details below to create your account',
};
