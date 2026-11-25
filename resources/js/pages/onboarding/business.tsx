import { Head, Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
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
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import { children, divisions } from '@/routes/locations';

type Props = {
    business: {
        name: string;
        domain_name: string;
        domain_extension: string;
        use_personal_mobile: boolean;
        mobile_country: string;
        mobile_number: string;
        use_personal_email: boolean;
        email: string;
        has_address: boolean;
        address: {
            detailed_address: string;
            postcode: string | null;
            location: LocationChain;
        } | null;
    };
    personal: { mobile: string | null; email: string };
    extensions: string[];
    dialling_codes: DiallingCode[];
    correction: { feedback: string | null } | null;
};

const divisionsUrl = divisions.url();
const childrenUrl = (
    parentType: 'division' | 'district' | 'upazila',
    parentSourceId: string,
) => children.url([parentType, parentSourceId]);

/**
 * Onboarding step 6 (D39): only what is needed to establish the online store.
 *
 * The domain is a preference — a name and one of the extensions Banij offers —
 * saved for our team to check and buy by hand. There is no live availability
 * check, and the page says so rather than implying one.
 */
export default function OnboardingBusiness({
    business,
    personal,
    extensions,
    dialling_codes,
    correction,
}: Props) {
    const { t } = useTranslation();
    const form = useForm({
        name: business.name,
        domain_name: business.domain_name,
        domain_extension: business.domain_extension,
        use_personal_mobile: business.use_personal_mobile,
        mobile_country: business.mobile_country,
        mobile_number: business.mobile_number,
        use_personal_email: business.use_personal_email,
        email: business.email,
        has_address: business.has_address,
        division_id: business.address?.location.division?.id ?? '',
        district_id: business.address?.location.district?.id ?? '',
        upazila_id: business.address?.location.upazila?.id ?? '',
        union_id: business.address?.location.union?.id ?? '',
        detailed_address: business.address?.detailed_address ?? '',
        postcode: business.address?.postcode ?? '',
    });
    const errors = form.errors as Record<string, string>;
    const domainPreview =
        form.data.domain_name.trim() === ''
            ? null
            : `${form.data.domain_name.trim().toLowerCase()}${form.data.domain_extension}`;

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
        form.submit(OnboardingApplicationController.updateBusiness(), {
            preserveScroll: true,
        });
    };

    const toggle = (
        name: 'use_personal_mobile' | 'use_personal_email' | 'has_address',
        label: string,
    ) => (
        <div className="flex items-start gap-2">
            <Checkbox
                id={name}
                checked={form.data[name]}
                onCheckedChange={(checked) =>
                    form.setData(name, checked === true)
                }
            />
            <Label htmlFor={name} className="text-sm leading-snug font-normal">
                {label}
            </Label>
        </div>
    );

    return (
        <>
            <Head title={t('onboarding.business.title')} />

            <PageContainer width="narrow">
                <form onSubmit={save} className="space-y-6">
                    <SectionCard
                        title={t('onboarding.business.heading')}
                        description={t('onboarding.business.description')}
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

                            <FormField
                                label={t('onboarding.business.name')}
                                error={errors.name}
                                required
                            >
                                {(props) => (
                                    <Input
                                        {...props}
                                        name="name"
                                        value={form.data.name}
                                        onChange={(event) =>
                                            form.setData(
                                                'name',
                                                event.target.value,
                                            )
                                        }
                                    />
                                )}
                            </FormField>
                        </div>
                    </SectionCard>

                    <SectionCard
                        title={t('onboarding.business.domain')}
                        description={t('onboarding.business.domain_help')}
                    >
                        <div className="space-y-3">
                            <div className="grid grid-cols-[minmax(0,1fr)_minmax(0,8rem)] gap-2">
                                <FormField
                                    label={t('onboarding.business.domain_name')}
                                    error={errors.domain_name}
                                    required
                                >
                                    {(props) => (
                                        <Input
                                            {...props}
                                            name="domain_name"
                                            value={form.data.domain_name}
                                            autoComplete="off"
                                            spellCheck={false}
                                            onChange={(event) =>
                                                form.setData(
                                                    'domain_name',
                                                    event.target.value
                                                        .toLowerCase()
                                                        .replace(/\s+/g, ''),
                                                )
                                            }
                                        />
                                    )}
                                </FormField>
                                <FormField
                                    label={t(
                                        'onboarding.business.domain_extension',
                                    )}
                                    error={errors.domain_extension}
                                    required
                                >
                                    {(props) => (
                                        <NativeSelect
                                            {...props}
                                            name="domain_extension"
                                            value={form.data.domain_extension}
                                            onChange={(event) =>
                                                form.setData(
                                                    'domain_extension',
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            {extensions.map((extension) => (
                                                <option
                                                    key={extension}
                                                    value={extension}
                                                >
                                                    {extension}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    )}
                                </FormField>
                            </div>
                            {domainPreview && (
                                <p className="text-muted-foreground text-sm break-all">
                                    {t('onboarding.business.domain_preview', {
                                        domain: domainPreview,
                                    })}
                                </p>
                            )}

                            <DomainCheck
                                name={form.data.domain_name}
                                extension={form.data.domain_extension}
                                onUse={(name, extension) =>
                                    form.setData({
                                        ...form.data,
                                        domain_name: name,
                                        domain_extension: extension,
                                    })
                                }
                            />
                        </div>
                    </SectionCard>

                    <SectionCard title={t('onboarding.business.mobile')}>
                        <div className="space-y-4">
                            {toggle(
                                'use_personal_mobile',
                                t('onboarding.business.use_personal_mobile', {
                                    mobile: personal.mobile ?? '',
                                }),
                            )}
                            {!form.data.use_personal_mobile && (
                                <MobileField
                                    label={t('onboarding.business.mobile')}
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
                                        errors.mobile_country
                                    }
                                />
                            )}

                            {toggle(
                                'use_personal_email',
                                t('onboarding.business.use_personal_email', {
                                    email: personal.email,
                                }),
                            )}
                            {!form.data.use_personal_email && (
                                <FormField
                                    label={t('onboarding.business.email')}
                                    error={errors.email}
                                    required
                                >
                                    {(props) => (
                                        <Input
                                            {...props}
                                            type="email"
                                            name="email"
                                            value={form.data.email}
                                            onChange={(event) =>
                                                form.setData(
                                                    'email',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    )}
                                </FormField>
                            )}
                        </div>
                    </SectionCard>

                    <SectionCard title={t('onboarding.business.address')}>
                        <div className="space-y-4">
                            {toggle(
                                'has_address',
                                t('onboarding.business.has_address'),
                            )}
                            {form.data.has_address && (
                                <>
                                    <LocationPicker
                                        initial={business.address?.location}
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
                                    <FormField
                                        label={t(
                                            'onboarding.details.detailed_address',
                                        )}
                                        error={errors.detailed_address}
                                        required
                                    >
                                        {(props) => (
                                            <Input
                                                {...props}
                                                name="detailed_address"
                                                value={
                                                    form.data.detailed_address
                                                }
                                                onChange={(event) =>
                                                    form.setData(
                                                        'detailed_address',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        )}
                                    </FormField>
                                    <FormField
                                        label={t('onboarding.details.postcode')}
                                        error={errors.postcode}
                                    >
                                        {(props) => (
                                            <Input
                                                {...props}
                                                name="postcode"
                                                value={form.data.postcode}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'postcode',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        )}
                                    </FormField>
                                </>
                            )}
                            {errors.onboarding && (
                                <Notice tone="danger">
                                    {errors.onboarding}
                                </Notice>
                            )}
                        </div>
                    </SectionCard>

                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Button variant="outline" asChild>
                            <Link
                                href={OnboardingApplicationController.details.url()}
                            >
                                {t('onboarding.business.back')}
                            </Link>
                        </Button>
                        <SubmitButton processing={form.processing}>
                            {t('onboarding.business.save')}
                        </SubmitButton>
                    </div>
                </form>
            </PageContainer>
        </>
    );
}

type DomainCheckResult = {
    domain: string | null;
    status: 'invalid' | 'taken_on_banij' | 'unverified';
    reason: string | null;
    disclaimer: string;
    suggestions: {
        name: string;
        extension: string;
        domain: string;
        status: 'taken_on_banij' | 'unverified';
    }[];
};

/**
 * "Check name" (D39): what Banij can honestly say about a preferred domain.
 * Never "available" — there is no registrar check. It says whether the name is
 * valid, whether another partner on Banij already asked for it, offers other
 * names, and states plainly that availability is checked by hand.
 */
function DomainCheck({
    name,
    extension,
    onUse,
}: {
    name: string;
    extension: string;
    onUse: (name: string, extension: string) => void;
}) {
    const { t } = useTranslation();
    const [checking, setChecking] = useState(false);
    const [result, setResult] = useState<DomainCheckResult | null>(null);

    const check = async () => {
        if (name.trim() === '') {
            return;
        }

        setChecking(true);

        try {
            const response = await fetch(
                OnboardingApplicationController.checkDomain.url({
                    query: { name: name.trim().toLowerCase(), extension },
                }),
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                },
            );

            setResult(
                response.ok
                    ? ((await response.json()) as DomainCheckResult)
                    : null,
            );
        } finally {
            setChecking(false);
        }
    };

    return (
        <div className="space-y-3">
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => void check()}
                disabled={checking || name.trim() === ''}
            >
                {checking
                    ? t('onboarding.business.domain_checking')
                    : t('onboarding.business.domain_check')}
            </Button>

            {result && (
                <div
                    className="space-y-2 rounded-lg border p-3 text-sm"
                    role="status"
                >
                    <p className="font-medium break-all">
                        {result.domain ?? name}
                        {' — '}
                        <span
                            className={
                                result.status === 'unverified'
                                    ? 'text-warning'
                                    : 'text-danger'
                            }
                        >
                            {result.reason ??
                                t(
                                    `onboarding.business.domain_status.${result.status}`,
                                )}
                        </span>
                    </p>
                    <p className="text-muted-foreground text-xs">
                        {result.disclaimer}
                    </p>

                    {result.suggestions.length > 0 && (
                        <div className="space-y-1">
                            <p className="text-muted-foreground text-xs font-medium">
                                {t('onboarding.business.domain_alternatives')}
                            </p>
                            <ul className="space-y-1">
                                {result.suggestions.map((suggestion) => (
                                    <li
                                        key={suggestion.domain}
                                        className="flex flex-wrap items-center justify-between gap-2"
                                    >
                                        <span className="font-mono text-xs break-all">
                                            {suggestion.domain}{' '}
                                            <span className="text-muted-foreground">
                                                (
                                                {t(
                                                    `onboarding.business.domain_status.${suggestion.status}`,
                                                )}
                                                )
                                            </span>
                                        </span>
                                        {suggestion.status === 'unverified' && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => {
                                                    onUse(
                                                        suggestion.name,
                                                        suggestion.extension,
                                                    );
                                                    setResult(null);
                                                }}
                                            >
                                                {t(
                                                    'onboarding.business.domain_use',
                                                )}
                                            </Button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
