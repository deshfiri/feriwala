import { Form, Head, Link } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import MobileVerificationController from '@/actions/App/Http/Controllers/Erp/MobileVerificationController';
import SubmitButton from '@/components/forms/submit-button';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import SectionCard from '@/components/section-card';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import { status as onboardingStatus } from '@/routes/onboarding';

type Props = {
    /** Masked server-side — enough to spot a typo, not to read over a shoulder. */
    mobile: string;
    /** Whether a code is out and still usable. Never the code itself. */
    code_pending: boolean;
    code_length: number;
    expires_in_minutes: number;
};

/**
 * Confirming the mobile number an account registered with (§5.1).
 *
 * Two steps on one screen, in the order they happen: ask for a code, then type
 * it. Opening the page sends nothing — a text goes out only from the button, so
 * a reload or a prefetch cannot send somebody a stream of codes.
 */
export default function VerifyMobile({
    mobile,
    code_pending,
    code_length,
    expires_in_minutes,
}: Props) {
    const { t } = useTranslation();
    const [code, setCode] = useState('');

    return (
        <>
            <Head title={t('common.verify_mobile.title')} />

            <PageContainer width="narrow">
                <SectionCard
                    title={t('common.verify_mobile.title')}
                    description={t('common.verify_mobile.description', {
                        mobile,
                        length: code_length,
                    })}
                >
                    <div className="space-y-6">
                        <p className="text-muted-foreground text-sm">
                            {t('common.verify_mobile.why')}
                        </p>

                        <Form
                            {...MobileVerificationController.send.form()}
                            disableWhileProcessing
                        >
                            {({ processing, errors }) => (
                                <div className="space-y-2">
                                    <SubmitButton
                                        processing={processing}
                                        processingLabel={t(
                                            'common.verify_mobile.sending',
                                        )}
                                        variant={
                                            code_pending ? 'outline' : 'default'
                                        }
                                        data-test="send-mobile-code-button"
                                    >
                                        {code_pending
                                            ? t(
                                                  'common.verify_mobile.send_again',
                                              )
                                            : t('common.verify_mobile.send')}
                                    </SubmitButton>

                                    {/* A wait, not a failure — the cooldown
                                        answers on its own key. */}
                                    <InputError message={errors.resend} />
                                </div>
                            )}
                        </Form>

                        <Form
                            {...MobileVerificationController.verify.form()}
                            onError={() => setCode('')}
                            className="space-y-3"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="space-y-1">
                                        <Label htmlFor="mobile-code">
                                            {t('common.verify_mobile.code')}
                                        </Label>
                                        <p
                                            id="mobile-code-help"
                                            className="text-muted-foreground text-xs"
                                        >
                                            {code_pending
                                                ? t(
                                                      'common.verify_mobile.pending',
                                                      {
                                                          minutes:
                                                              expires_in_minutes,
                                                      },
                                                  )
                                                : t(
                                                      'common.verify_mobile.not_sent',
                                                  )}
                                        </p>
                                    </div>

                                    <InputOTP
                                        id="mobile-code"
                                        name="code"
                                        maxLength={code_length}
                                        value={code}
                                        onChange={(value) => setCode(value)}
                                        disabled={processing}
                                        pattern={REGEXP_ONLY_DIGITS}
                                        inputMode="numeric"
                                        autoComplete="one-time-code"
                                        aria-describedby="mobile-code-help"
                                        aria-invalid={
                                            errors.code ? true : undefined
                                        }
                                    >
                                        <InputOTPGroup>
                                            {Array.from(
                                                { length: code_length },
                                                (_, index) => (
                                                    <InputOTPSlot
                                                        key={index}
                                                        index={index}
                                                    />
                                                ),
                                            )}
                                        </InputOTPGroup>
                                    </InputOTP>

                                    <InputError message={errors.code} />

                                    <SubmitButton
                                        processing={processing}
                                        processingLabel={t(
                                            'common.verify_mobile.verifying',
                                        )}
                                        data-test="verify-mobile-button"
                                    >
                                        {t('common.verify_mobile.verify')}
                                    </SubmitButton>
                                </>
                            )}
                        </Form>
                    </div>
                </SectionCard>

                <Link
                    href={onboardingStatus()}
                    className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1.5 text-sm"
                >
                    <ArrowLeft className="size-4" aria-hidden="true" />
                    {t('common.verify_mobile.back')}
                </Link>
            </PageContainer>
        </>
    );
}
