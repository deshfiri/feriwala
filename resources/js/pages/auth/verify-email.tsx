import { Form, Head } from '@inertiajs/react';
import { CheckCircle2, MailCheck } from 'lucide-react';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

type Props = {
    /** Masked server-side — enough to recognise, not enough to read over a shoulder. */
    email: string | null;
    /** Fortify's flash key. `verification-link-sent` is the only value it sets. */
    status?: string | null;
};

/**
 * Confirming the address an account registered with (§5.1, §6).
 *
 * An identity question, not a commercial one: it says this person reads this
 * mailbox. Nothing here touches the business account, and confirming an address
 * gives a suspended identity nothing back.
 *
 * The page exists because every ERP destination sits behind the `verified`
 * middleware, so an unverified person has nowhere else to be — `HomeRoute`
 * sends them here rather than to a screen that would bounce them straight back.
 */
export default function VerifyEmail({ email, status }: Props) {
    const { t } = useTranslation();

    const justSent = status === 'verification-link-sent';

    return (
        <>
            <Head title={t('common.verify_email.title')} />

            <div className="flex flex-col gap-6">
                <div className="flex justify-center">
                    <span className="bg-brand-subtle text-brand flex size-12 items-center justify-center rounded-full">
                        <MailCheck aria-hidden="true" className="size-6" />
                    </span>
                </div>

                <div className="space-y-2 text-center">
                    <p className="text-sm">
                        {t('common.verify_email.description', {
                            email: email ?? '',
                        })}
                    </p>
                    <p className="text-muted-foreground text-sm">
                        {t('common.verify_email.why')}
                    </p>
                </div>

                {justSent && (
                    /*
                     * `role="status"` so it is announced rather than only seen —
                     * the whole point of the message is that nothing else on the
                     * page changes when the mail goes out.
                     */
                    <p
                        role="status"
                        className="bg-success-subtle text-success flex items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-medium"
                    >
                        <CheckCircle2
                            aria-hidden="true"
                            className="size-4 shrink-0"
                        />
                        {t('common.verify_email.sent')}
                    </p>
                )}

                <Form {...send.form()} disableWhileProcessing>
                    {({ processing, errors }) => (
                        <div className="space-y-3">
                            <Button
                                type="submit"
                                className="w-full"
                                data-test="resend-verification-button"
                            >
                                {processing && <Spinner />}
                                {processing
                                    ? t('common.verify_email.sending')
                                    : t('common.verify_email.resend')}
                            </Button>

                            {/*
                             * The throttle answers with a 429 rather than a
                             * validation error, so the message is shown from
                             * whichever of the two arrives.
                             */}
                            {errors.email && (
                                <p
                                    role="alert"
                                    className="text-warning text-center text-sm"
                                >
                                    {t('common.verify_email.throttled')}
                                </p>
                            )}

                            <p className="text-muted-foreground text-center text-xs">
                                {t('common.verify_email.not_arrived')}
                            </p>
                        </div>
                    )}
                </Form>

                <div className="text-muted-foreground space-y-1 text-center text-sm">
                    <p>{t('common.verify_email.wrong_address')}</p>
                    <TextLink href={logout()} as="button">
                        {t('common.verify_email.sign_out')}
                    </TextLink>
                </div>
            </div>
        </>
    );
}

VerifyEmail.layout = {
    title: 'Confirm your email address',
    description: 'One step left before your account is ready',
};
