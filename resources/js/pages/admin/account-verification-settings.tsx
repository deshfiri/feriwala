import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import AccountVerificationSettingsController from '@/actions/App/Http/Controllers/Admin/AccountVerificationSettingsController';
import FormField from '@/components/forms/form-field';
import NativeSelect from '@/components/forms/native-select';
import SubmitButton from '@/components/forms/submit-button';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';

type Props = {
    settings: {
        mobile_verification_required: boolean;
        kyc_selfie_mode?: string;
        domain_extensions?: string[];
    };
    selfie_modes?: { value: string; label: string }[];
    can: { manage: boolean };
};

/** `.com, shop .store` → ['.com', '.shop', '.store']; the server is the judge. */
const parseExtensions = (text: string): string[] =>
    text
        .split(/[\s,]+/)
        .map((part) => part.trim().toLowerCase())
        .filter((part) => part !== '')
        .map((part) => (part.startsWith('.') ? part : `.${part}`));

/**
 * Onboarding settings (§5.1, D39): whether mobile verification is required,
 * whether KYC asks for a selfie for every partner (or leaves it to each KYC
 * batch), and which domain extensions a partner may choose their preferred
 * domain from. A partner can be given their own selfie setting from their
 * activation review.
 */
export default function AccountVerificationSettingsIndex({
    settings,
    selfie_modes = [],
    can,
}: Props) {
    const { t } = useTranslation();
    const [extensions, setExtensions] = useState(
        (settings.domain_extensions ?? []).join(', '),
    );

    return (
        <>
            <Head title={t('account_verification_settings.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('account_verification_settings.title')}
                    description={t('account_verification_settings.description')}
                />

                <Form
                    {...AccountVerificationSettingsController.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <SectionCard
                                title={t(
                                    'account_verification_settings.mobile.title',
                                )}
                                description={t(
                                    'account_verification_settings.mobile.description',
                                )}
                            >
                                <div className="space-y-3">
                                    <div className="flex items-center gap-3">
                                        {/* A hidden false ahead of the checkbox:
                                            an unchecked box sends nothing, and
                                            "nothing" is not "off". */}
                                        <input
                                            type="hidden"
                                            name="mobile_verification_required"
                                            value="0"
                                        />
                                        <input
                                            id="mobile-verification-required"
                                            type="checkbox"
                                            name="mobile_verification_required"
                                            value="1"
                                            disabled={!can.manage}
                                            defaultChecked={
                                                settings.mobile_verification_required
                                            }
                                            className="accent-brand size-4"
                                        />
                                        <Label htmlFor="mobile-verification-required">
                                            {t(
                                                'account_verification_settings.mobile.enabled',
                                            )}
                                        </Label>
                                    </div>

                                    {!settings.mobile_verification_required && (
                                        <p className="text-warning text-sm font-medium">
                                            {t(
                                                'account_verification_settings.mobile.off_notice',
                                            )}
                                        </p>
                                    )}
                                </div>
                            </SectionCard>

                            <SectionCard
                                title={t(
                                    'account_verification_settings.selfie.title',
                                )}
                                description={t(
                                    'account_verification_settings.selfie.description',
                                )}
                            >
                                <FormField
                                    label={t(
                                        'account_verification_settings.selfie.label',
                                    )}
                                    error={errors.kyc_selfie_mode}
                                >
                                    {(props) => (
                                        <NativeSelect
                                            {...props}
                                            name="kyc_selfie_mode"
                                            defaultValue={
                                                settings.kyc_selfie_mode ?? ''
                                            }
                                            disabled={!can.manage}
                                        >
                                            <option value="">
                                                {t(
                                                    'account_verification_settings.selfie.per_batch',
                                                )}
                                            </option>
                                            {selfie_modes.map((mode) => (
                                                <option
                                                    key={mode.value}
                                                    value={mode.value}
                                                >
                                                    {mode.label}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    )}
                                </FormField>
                            </SectionCard>

                            <SectionCard
                                title={t(
                                    'account_verification_settings.domains.title',
                                )}
                                description={t(
                                    'account_verification_settings.domains.description',
                                )}
                            >
                                <FormField
                                    label={t(
                                        'account_verification_settings.domains.label',
                                    )}
                                    description={t(
                                        'account_verification_settings.domains.help',
                                    )}
                                    error={errors.domain_extensions}
                                >
                                    {(props) => (
                                        <Input
                                            {...props}
                                            value={extensions}
                                            disabled={!can.manage}
                                            onChange={(event) =>
                                                setExtensions(
                                                    event.target.value,
                                                )
                                            }
                                            placeholder=".com, .shop, .store"
                                        />
                                    )}
                                </FormField>
                                {parseExtensions(extensions).map(
                                    (extension) => (
                                        <input
                                            key={extension}
                                            type="hidden"
                                            name="domain_extensions[]"
                                            value={extension}
                                        />
                                    ),
                                )}
                            </SectionCard>

                            {can.manage && (
                                <SubmitButton processing={processing}>
                                    {t('common.actions.save')}
                                </SubmitButton>
                            )}
                        </>
                    )}
                </Form>
            </PageContainer>
        </>
    );
}
