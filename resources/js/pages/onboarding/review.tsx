import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import OnboardingApplicationController from '@/actions/App/Http/Controllers/Erp/OnboardingApplicationController';
import ConfirmButton from '@/components/confirm/confirm-button';
import DetailList, { DetailItem } from '@/components/detail-list';
import Notice from '@/components/notice';
import PageContainer from '@/components/page-container';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';

type Application = {
    personal: {
        legal_name: string | null;
        email: string | null;
        mobile: string | null;
        mobile_verified: boolean;
        country: string | null;
        nationality: string | null;
        date_of_birth: string | null;
        address: string[];
        complete: boolean;
    };
    business: {
        name: string;
        mobile: string | null;
        mobile_is_personal: boolean;
        email: string | null;
        email_is_personal: boolean;
        address: string[];
        complete: boolean;
    };
    domain: {
        name: string | null;
        extension: string | null;
        address: string | null;
    };
    kyc_submitted: boolean;
    kyc_approved: boolean;
    paid: boolean;
    correction: { sections: string[]; feedback: string | null } | null;
};

/**
 * Onboarding step 7 (D39): everything the partner gave, on one page, and the
 * submission that sends it to Banij. Each part links back to its step; once
 * submitted, the account waits in Pending Verification.
 */
export default function OnboardingReview({
    application,
}: {
    application: Application;
}) {
    const { t } = useTranslation();
    const form = useForm({ confirm: false });
    const errors = form.errors as Record<string, string>;
    const ready =
        application.paid &&
        application.kyc_submitted &&
        application.personal.complete &&
        application.business.complete &&
        application.domain.address !== null;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(OnboardingApplicationController.submit(), {
            preserveScroll: true,
        });
    };

    const lines = (values: string[]): ReactNode =>
        values.length === 0 ? (
            <span className="text-muted-foreground">
                {t('onboarding.review.no_address')}
            </span>
        ) : (
            <address className="not-italic">
                {values.map((line) => (
                    <span key={line} className="block">
                        {line}
                    </span>
                ))}
            </address>
        );

    const editLink = (href: string) => (
        <Button variant="outline" size="sm" asChild>
            <Link href={href}>{t('onboarding.review.edit')}</Link>
        </Button>
    );

    return (
        <>
            <Head title={t('onboarding.review.title')} />

            <PageContainer width="narrow">
                <header className="space-y-1.5">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('onboarding.review.heading')}
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        {t('onboarding.review.description')}
                    </p>
                </header>

                {application.correction && (
                    <Notice
                        tone="warning"
                        title={t('onboarding.review.correction')}
                    >
                        {application.correction.feedback}
                    </Notice>
                )}

                <SectionCard
                    title={t('onboarding.review.personal')}
                    actions={editLink(
                        OnboardingApplicationController.details.url(),
                    )}
                >
                    <DetailList columns={1}>
                        <DetailItem label={t('onboarding.review.name')}>
                            {application.personal.legal_name}
                        </DetailItem>
                        <DetailItem label={t('onboarding.review.email')}>
                            {application.personal.email}
                        </DetailItem>
                        <DetailItem label={t('onboarding.review.mobile')}>
                            <span className="font-mono">
                                {application.personal.mobile}
                            </span>
                        </DetailItem>
                        <DetailItem label={t('onboarding.review.country')}>
                            {application.personal.country}
                        </DetailItem>
                        <DetailItem label={t('onboarding.review.nationality')}>
                            {application.personal.nationality}
                        </DetailItem>
                        <DetailItem
                            label={t('onboarding.review.date_of_birth')}
                        >
                            {application.personal.date_of_birth}
                        </DetailItem>
                        <DetailItem label={t('onboarding.review.address')}>
                            {lines(application.personal.address)}
                        </DetailItem>
                    </DetailList>
                </SectionCard>

                <SectionCard
                    title={t('onboarding.review.business')}
                    actions={editLink(
                        OnboardingApplicationController.business.url(),
                    )}
                >
                    <DetailList columns={1}>
                        <DetailItem label={t('onboarding.review.name')}>
                            {application.business.name}
                        </DetailItem>
                        <DetailItem label={t('onboarding.review.domain')}>
                            <span className="font-mono break-all">
                                {application.domain.address}
                            </span>
                        </DetailItem>
                        <DetailItem label={t('onboarding.review.mobile')}>
                            <span className="font-mono">
                                {application.business.mobile}
                            </span>{' '}
                            {application.business.mobile_is_personal && (
                                <span className="text-muted-foreground text-xs">
                                    {t('onboarding.review.personal_mobile')}
                                </span>
                            )}
                        </DetailItem>
                        <DetailItem label={t('onboarding.review.email')}>
                            {application.business.email}{' '}
                            {application.business.email_is_personal && (
                                <span className="text-muted-foreground text-xs">
                                    {t('onboarding.review.personal_email')}
                                </span>
                            )}
                        </DetailItem>
                        <DetailItem label={t('onboarding.review.address')}>
                            {lines(application.business.address)}
                        </DetailItem>
                    </DetailList>
                </SectionCard>

                <SectionCard title={t('onboarding.review.kyc')}>
                    <div className="flex flex-wrap gap-2">
                        <StatusPill
                            tone={application.paid ? 'success' : 'warning'}
                            label={`${t('onboarding.review.payment')}: ${application.paid ? t('onboarding.review.paid') : '—'}`}
                        />
                        <StatusPill
                            tone={
                                application.kyc_approved
                                    ? 'success'
                                    : application.kyc_submitted
                                      ? 'info'
                                      : 'warning'
                            }
                            label={`${t('onboarding.review.kyc')}: ${
                                application.kyc_approved
                                    ? t('onboarding.review.kyc_approved')
                                    : application.kyc_submitted
                                      ? t('onboarding.review.kyc_submitted')
                                      : t('onboarding.review.kyc_missing')
                            }`}
                        />
                    </div>
                </SectionCard>

                <form onSubmit={submit} className="space-y-4">
                    <div className="flex items-start gap-2">
                        <Checkbox
                            id="confirm"
                            checked={form.data.confirm}
                            onCheckedChange={(checked) =>
                                form.setData('confirm', checked === true)
                            }
                        />
                        <Label
                            htmlFor="confirm"
                            className="text-sm leading-snug font-normal"
                        >
                            {t('onboarding.review.confirm')}
                        </Label>
                    </div>
                    {(errors.confirm || errors.onboarding) && (
                        <Notice tone="danger">
                            {errors.onboarding ?? errors.confirm}
                        </Notice>
                    )}
                    <div className="flex justify-end">
                        <ConfirmButton
                            type="submit"
                            disabled={
                                !ready || !form.data.confirm || form.processing
                            }
                            aria-busy={form.processing || undefined}
                            className="gap-2"
                            confirm={{
                                title: t('onboarding.review.submit'),
                                description: t('onboarding.review.description'),
                                confirmLabel: t('onboarding.review.submit'),
                                destructive: false,
                            }}
                        >
                            {form.processing && (
                                <Spinner className="size-3.5" />
                            )}
                            {t('onboarding.review.submit')}
                        </ConfirmButton>
                    </div>
                </form>
            </PageContainer>
        </>
    );
}
