import { Form, Head } from '@inertiajs/react';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import { send, verify } from '@/routes/supplier/verification/mobile';

export default function SupplierMobileVerification({
    mobile,
    mobileVerified,
}: {
    mobile: string;
    mobileVerified: boolean;
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('supplier.verification.mobile')} />

            <div className="mx-auto max-w-md space-y-6">
                <PageHeader
                    title={t('supplier.verification.mobile')}
                    description={mobile}
                />

                <SectionCard title={t('supplier.verification.mobile')}>
                    {mobileVerified ? (
                        <p className="text-sm font-medium text-green-600">
                            {t('supplier.verification.verified')}
                        </p>
                    ) : (
                        <div className="space-y-5">
                            <Form
                                {...send.form()}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing, errors }) => (
                                    <div className="space-y-2">
                                        <SubmitButton
                                            processing={processing}
                                            variant="outline"
                                        >
                                            {t(
                                                'supplier.verification.send_code',
                                            )}
                                        </SubmitButton>
                                        {errors.mobile && (
                                            <p
                                                role="alert"
                                                className="text-danger text-xs font-medium"
                                            >
                                                {errors.mobile}
                                            </p>
                                        )}
                                    </div>
                                )}
                            </Form>

                            <Form
                                {...verify.form()}
                                resetOnSuccess={['code']}
                                className="space-y-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <FormField
                                            label={t(
                                                'supplier.verification.code',
                                            )}
                                            error={errors.code}
                                            required
                                        >
                                            {(field) => (
                                                <Input
                                                    {...field}
                                                    name="code"
                                                    inputMode="numeric"
                                                    autoComplete="one-time-code"
                                                    maxLength={6}
                                                    required
                                                />
                                            )}
                                        </FormField>
                                        <SubmitButton processing={processing}>
                                            {t('supplier.verification.verify')}
                                        </SubmitButton>
                                    </>
                                )}
                            </Form>
                        </div>
                    )}
                </SectionCard>
            </div>
        </>
    );
}
