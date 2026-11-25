import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import OnboardingApplicationController from '@/actions/App/Http/Controllers/Erp/OnboardingApplicationController';
import LocationPicker, {
    type LocationChain,
} from '@/components/address-book/location-picker';
import FormField from '@/components/forms/form-field';
import NativeSelect from '@/components/forms/native-select';
import SubmitButton from '@/components/forms/submit-button';
import Notice from '@/components/notice';
import MobileField, {
    type DiallingCode,
} from '@/components/onboarding/mobile-field';
import PageContainer from '@/components/page-container';
import SectionCard from '@/components/section-card';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import { children, divisions } from '@/routes/locations';

type Option = { value: string; label: string };

type BdAddress = {
    detailed_address: string;
    postcode: string | null;
    location: LocationChain;
};

type Props = {
    details: {
        legal_name: string;
        email: string;
        mobile_country: string;
        mobile_number: string;
        mobile_verified: boolean;
        country: string;
        nationality: string | null;
        date_of_birth: string | null;
        bd_address: BdAddress | null;
        foreign_address: {
            line_1: string;
            line_2: string | null;
            city: string;
            postcode: string | null;
        } | null;
    };
    countries: Option[];
    nationalities: Option[];
    dialling_codes: DiallingCode[];
    correction: { feedback: string | null } | null;
};

const divisionsUrl = divisions.url();
const childrenUrl = (
    parentType: 'division' | 'district' | 'upazila',
    parentSourceId: string,
) => children.url([parentType, parentSourceId]);

/**
 * Onboarding step 5 (D39): who the owner is, as on their ID, and where they live.
 *
 * Name, mobile, email and country come filled in from registration. A changed
 * mobile is verified again before anything else. A Bangladeshi residence is
 * picked from the structured location list; anywhere else is typed.
 */
export default function OnboardingDetails({
    details,
    countries,
    nationalities,
    dialling_codes,
    correction,
}: Props) {
    const { t } = useTranslation();
    const form = useForm({
        legal_name: details.legal_name,
        mobile_country: details.mobile_country,
        mobile_number: details.mobile_number,
        country: details.country,
        nationality: details.nationality ?? details.country,
        date_of_birth: details.date_of_birth ?? '',
        division_id: details.bd_address?.location.division?.id ?? '',
        district_id: details.bd_address?.location.district?.id ?? '',
        upazila_id: details.bd_address?.location.upazila?.id ?? '',
        union_id: details.bd_address?.location.union?.id ?? '',
        detailed_address: details.bd_address?.detailed_address ?? '',
        postcode:
            details.bd_address?.postcode ??
            details.foreign_address?.postcode ??
            '',
        line_1: details.foreign_address?.line_1 ?? '',
        line_2: details.foreign_address?.line_2 ?? '',
        city: details.foreign_address?.city ?? '',
    });
    const errors = form.errors as Record<string, string>;
    const inBangladesh = form.data.country === 'BD';

    const setPlace = (chain: LocationChain) =>
        form.setData({
            ...form.data,
            division_id: chain.division?.id ?? '',
            district_id: chain.district?.id ?? '',
            upazila_id: chain.upazila?.id ?? '',
            union_id: chain.union?.id ?? '',
        });

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.submit(OnboardingApplicationController.updateDetails(), {
            preserveScroll: true,
        });
    };

    const text = (
        name:
            | 'legal_name'
            | 'detailed_address'
            | 'postcode'
            | 'line_1'
            | 'line_2'
            | 'city',
        label: string,
        required = true,
    ) => (
        <FormField label={label} error={errors[name]} required={required}>
            {(props) => (
                <Input
                    {...props}
                    name={name}
                    value={form.data[name]}
                    onChange={(event) => form.setData(name, event.target.value)}
                />
            )}
        </FormField>
    );

    return (
        <>
            <Head title={t('onboarding.details.title')} />

            <PageContainer width="narrow">
                <form onSubmit={save} className="space-y-6">
                    <SectionCard
                        title={t('onboarding.details.heading')}
                        description={t('onboarding.details.description')}
                    >
                        <div className="space-y-4">
                            {correction && (
                                <Notice
                                    tone="warning"
                                    title={t('onboarding.review.correction')}
                                >
                                    {correction.feedback}
                                </Notice>
                            )}

                            {text(
                                'legal_name',
                                t('onboarding.details.legal_name'),
                            )}

                            <FormField
                                label={t('onboarding.details.email')}
                                description={t('onboarding.details.email_help')}
                            >
                                {(props) => (
                                    <Input
                                        {...props}
                                        value={details.email}
                                        readOnly
                                        disabled
                                    />
                                )}
                            </FormField>

                            <MobileField
                                label={t('onboarding.mobile.number')}
                                help={t('onboarding.details.mobile_help')}
                                countries={dialling_codes}
                                country={form.data.mobile_country}
                                number={form.data.mobile_number}
                                onCountryChange={(value) =>
                                    form.setData('mobile_country', value)
                                }
                                onNumberChange={(value) =>
                                    form.setData('mobile_number', value)
                                }
                                error={
                                    errors.mobile_number ??
                                    errors.mobile_country ??
                                    errors.mobile
                                }
                            />

                            <div className="grid gap-4 sm:grid-cols-2">
                                <FormField
                                    label={t('onboarding.details.country')}
                                    error={errors.country}
                                    required
                                >
                                    {(props) => (
                                        <NativeSelect
                                            {...props}
                                            name="country"
                                            value={form.data.country}
                                            onChange={(event) =>
                                                form.setData(
                                                    'country',
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            {countries.map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    )}
                                </FormField>

                                <FormField
                                    label={t('onboarding.details.nationality')}
                                    error={errors.nationality}
                                    required
                                >
                                    {(props) => (
                                        <NativeSelect
                                            {...props}
                                            name="nationality"
                                            value={form.data.nationality}
                                            onChange={(event) =>
                                                form.setData(
                                                    'nationality',
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            {nationalities.map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    )}
                                </FormField>
                            </div>

                            <FormField
                                label={t('onboarding.details.date_of_birth')}
                                error={errors.date_of_birth}
                                required
                            >
                                {(props) => (
                                    <Input
                                        {...props}
                                        type="date"
                                        name="date_of_birth"
                                        value={form.data.date_of_birth}
                                        onChange={(event) =>
                                            form.setData(
                                                'date_of_birth',
                                                event.target.value,
                                            )
                                        }
                                    />
                                )}
                            </FormField>
                        </div>
                    </SectionCard>

                    <SectionCard title={t('onboarding.details.address')}>
                        <div className="space-y-4">
                            {inBangladesh ? (
                                <>
                                    <LocationPicker
                                        initial={details.bd_address?.location}
                                        divisionsUrl={divisionsUrl}
                                        childrenUrl={childrenUrl}
                                        onChange={setPlace}
                                        errors={{
                                            division_id: errors.division_id,
                                            district_id: errors.district_id,
                                            upazila_id: errors.upazila_id,
                                            union_id: errors.union_id,
                                        }}
                                    />
                                    {text(
                                        'detailed_address',
                                        t(
                                            'onboarding.details.detailed_address',
                                        ),
                                    )}
                                    {text(
                                        'postcode',
                                        t('onboarding.details.postcode'),
                                        false,
                                    )}
                                </>
                            ) : (
                                <>
                                    {text(
                                        'line_1',
                                        t('onboarding.details.line_1'),
                                    )}
                                    {text(
                                        'line_2',
                                        t('onboarding.details.line_2'),
                                        false,
                                    )}
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        {text(
                                            'city',
                                            t('onboarding.details.city'),
                                        )}
                                        {text(
                                            'postcode',
                                            t('onboarding.details.postcode'),
                                            false,
                                        )}
                                    </div>
                                </>
                            )}
                            {errors.address && (
                                <p className="text-danger text-sm">
                                    {errors.address}
                                </p>
                            )}
                            {errors.onboarding && (
                                <Notice tone="danger">
                                    {errors.onboarding}
                                </Notice>
                            )}
                        </div>
                    </SectionCard>

                    <div className="flex justify-end">
                        <SubmitButton processing={form.processing}>
                            {t('onboarding.details.save')}
                        </SubmitButton>
                    </div>
                </form>
            </PageContainer>
        </>
    );
}
