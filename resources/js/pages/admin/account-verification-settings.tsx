import { Form, Head } from '@inertiajs/react';
import AccountVerificationSettingsController from '@/actions/App/Http/Controllers/Admin/AccountVerificationSettingsController';
import SubmitButton from '@/components/forms/submit-button';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';

type Props = {
    settings: { mobile_verification_required: boolean };
    can: { manage: boolean };
};

/**
 * Whether mobile number verification is a required onboarding step (§5.1).
 *
 * Switching this off does not just stop sending the code -- it removes the
 * requirement itself, so nobody registering while it is off is left stuck
 * on a step nobody can complete differently.
 */
export default function AccountVerificationSettingsIndex({
    settings,
    can,
}: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('account_verification_settings.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('account_verification_settings.title')}
                    description={t('account_verification_settings.description')}
                />

                <SectionCard
                    title={t('account_verification_settings.mobile.title')}
                    description={t(
                        'account_verification_settings.mobile.description',
                    )}
                >
                    {can.manage ? (
                        <Form
                            {...AccountVerificationSettingsController.update.form()}
                            options={{ preserveScroll: true }}
                            className="space-y-4"
                        >
                            {({ processing }) => (
                                <>
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

                                    <SubmitButton processing={processing}>
                                        {t('common.actions.save')}
                                    </SubmitButton>
                                </>
                            )}
                        </Form>
                    ) : (
                        <p className="text-sm">
                            {t(
                                settings.mobile_verification_required
                                    ? 'account_verification_settings.mobile.enabled'
                                    : 'account_verification_settings.mobile.off_notice',
                            )}
                        </p>
                    )}
                </SectionCard>
            </PageContainer>
        </>
    );
}
