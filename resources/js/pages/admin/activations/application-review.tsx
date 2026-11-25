import { Form } from '@inertiajs/react';
import ConfirmButton from '@/components/confirm/confirm-button';
import DetailList, { DetailItem } from '@/components/detail-list';
import FormField from '@/components/forms/form-field';
import TextArea from '@/components/forms/text-area';
import Notice from '@/components/notice';
import SectionCard from '@/components/section-card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import NativeSelect from '@/components/forms/native-select';
import {
    domainCheck,
    requestCorrection,
    selfie as activationSelfie,
} from '@/routes/admin/activations';

export type SubmittedApplication = {
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
        check?: {
            result: 'available' | 'unavailable';
            at: string | null;
            by: string | null;
            note: string | null;
        } | null;
    };
    kyc_submitted: boolean;
    kyc_issuing_country?: string | null;
    kyc_approved: boolean;
    paid: boolean;
    submitted_at: string | null;
    correction: { sections: string[]; feedback: string | null } | null;
};

export type PartnerSelfie = {
    mode: string;
    effective: string | null;
    modes: { value: string; label: string }[];
    can_set: boolean;
};

/**
 * Whether this one partner's identity verification asks for a selfie (D39),
 * overriding the platform setting. Needs a reason; applies to a round not yet
 * submitted.
 */
export function PartnerSelfieSetting({
    accountId,
    selfie,
}: {
    accountId: string;
    selfie: PartnerSelfie;
}) {
    const { t } = useTranslation();

    return (
        <SectionCard
            title={t('account_verification_settings.selfie.partner_label')}
            description={
                selfie.effective ??
                t('account_verification_settings.selfie.per_batch')
            }
        >
            {selfie.can_set ? (
                <Form
                    {...activationSelfie.form(accountId)}
                    options={{ preserveScroll: true }}
                    className="space-y-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <FormField
                                label={t(
                                    'account_verification_settings.selfie.partner_label',
                                )}
                                error={errors.mode}
                            >
                                {(props) => (
                                    <NativeSelect
                                        {...props}
                                        name="mode"
                                        defaultValue={selfie.mode}
                                    >
                                        <option value="">
                                            {t(
                                                'account_verification_settings.selfie.partner_default',
                                            )}
                                        </option>
                                        {selfie.modes.map((mode) => (
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
                            <FormField
                                label={t('activation.application.reason')}
                                error={errors.reason}
                                required
                            >
                                {(props) => (
                                    <TextArea
                                        {...props}
                                        name="reason"
                                        rows={2}
                                        required
                                        maxLength={1000}
                                    />
                                )}
                            </FormField>
                            <ConfirmButton
                                type="submit"
                                variant="outline"
                                disabled={processing}
                                confirm={{ destructive: false }}
                            >
                                {t('common.actions.save')}
                            </ConfirmButton>
                        </>
                    )}
                </Form>
            ) : null}
        </SectionCard>
    );
}

/**
 * What the partner submitted (D39): personal details, business, and the
 * preferred domain — which staff check for availability and buy by hand; it is
 * never bought automatically. A reviewer can send any of the three back with
 * feedback the partner sees.
 */
export default function ApplicationReview({
    accountId,
    application,
    sections,
    canRequestCorrection,
}: {
    accountId: string;
    application: SubmittedApplication;
    sections: string[];
    canRequestCorrection: boolean;
}) {
    const { t, locale } = useTranslation();
    const dash = <span className="text-muted-foreground">—</span>;
    const lines = (values: string[]) =>
        values.length === 0
            ? dash
            : values.map((line) => (
                  <span key={line} className="block">
                      {line}
                  </span>
              ));

    return (
        <SectionCard
            title={t('activation.application.title')}
            description={
                application.submitted_at
                    ? t('activation.application.submitted_at', {
                          at: new Date(application.submitted_at).toLocaleString(
                              locale,
                          ),
                      })
                    : t('activation.application.not_submitted')
            }
        >
            <div className="space-y-5">
                {application.correction && (
                    <Notice
                        tone="warning"
                        title={t('activation.application.correction_open')}
                    >
                        {application.correction.feedback}
                    </Notice>
                )}

                <div className="grid gap-5 md:grid-cols-2">
                    <div className="space-y-2">
                        <h3 className="text-sm font-semibold">
                            {t('onboarding.review.personal')}
                        </h3>
                        <DetailList columns={1}>
                            <DetailItem label={t('onboarding.review.name')}>
                                {application.personal.legal_name ?? dash}
                            </DetailItem>
                            <DetailItem label={t('onboarding.review.mobile')}>
                                <span className="font-mono">
                                    {application.personal.mobile ?? '—'}
                                </span>{' '}
                                <span className="text-muted-foreground text-xs">
                                    {application.personal.mobile_verified
                                        ? t('activation.application.verified')
                                        : t(
                                              'activation.application.unverified',
                                          )}
                                </span>
                            </DetailItem>
                            <DetailItem label={t('onboarding.review.email')}>
                                {application.personal.email ?? dash}
                            </DetailItem>
                            <DetailItem label={t('onboarding.review.country')}>
                                {application.personal.country ?? dash}
                            </DetailItem>
                            <DetailItem
                                label={t('onboarding.review.nationality')}
                            >
                                {application.personal.nationality ?? dash}
                            </DetailItem>
                            <DetailItem
                                label={t('onboarding.review.date_of_birth')}
                            >
                                {application.personal.date_of_birth ?? dash}
                            </DetailItem>
                            <DetailItem
                                label={t('kyc.submission.issuing_country')}
                            >
                                {application.kyc_issuing_country ?? dash}
                            </DetailItem>
                            <DetailItem label={t('onboarding.review.address')}>
                                {lines(application.personal.address)}
                            </DetailItem>
                        </DetailList>
                    </div>

                    <div className="space-y-2">
                        <h3 className="text-sm font-semibold">
                            {t('onboarding.review.business')}
                        </h3>
                        <DetailList columns={1}>
                            <DetailItem label={t('onboarding.review.name')}>
                                {application.business.name}
                            </DetailItem>
                            <DetailItem
                                label={t('activation.application.domain')}
                            >
                                <span className="font-mono break-all">
                                    {application.domain.address ?? '—'}
                                </span>
                                <span className="text-muted-foreground block text-xs">
                                    {t('activation.application.domain_manual')}
                                </span>
                                <span className="block text-xs">
                                    {application.domain.check
                                        ? t(
                                              `activation.application.domain_check_${application.domain.check.result}`,
                                              {
                                                  by:
                                                      application.domain.check
                                                          .by ?? '—',
                                              },
                                          )
                                        : t(
                                              'activation.application.domain_not_checked',
                                          )}
                                </span>
                                {canRequestCorrection &&
                                    application.domain.address && (
                                        <Form
                                            {...domainCheck.form(accountId)}
                                            options={{ preserveScroll: true }}
                                            className="mt-2 flex flex-wrap items-center gap-2"
                                        >
                                            {({ processing }) => (
                                                <>
                                                    <NativeSelect
                                                        name="result"
                                                        size="sm"
                                                        className="w-auto"
                                                        defaultValue="available"
                                                    >
                                                        <option value="available">
                                                            {t(
                                                                'activation.application.mark_available',
                                                            )}
                                                        </option>
                                                        <option value="unavailable">
                                                            {t(
                                                                'activation.application.mark_unavailable',
                                                            )}
                                                        </option>
                                                    </NativeSelect>
                                                    <ConfirmButton
                                                        type="submit"
                                                        size="sm"
                                                        variant="outline"
                                                        disabled={processing}
                                                        confirm={{
                                                            destructive: false,
                                                        }}
                                                    >
                                                        {t(
                                                            'activation.application.record_check',
                                                        )}
                                                    </ConfirmButton>
                                                </>
                                            )}
                                        </Form>
                                    )}
                            </DetailItem>
                            <DetailItem label={t('onboarding.review.mobile')}>
                                <span className="font-mono">
                                    {application.business.mobile ?? '—'}
                                </span>{' '}
                                {application.business.mobile_is_personal && (
                                    <span className="text-muted-foreground text-xs">
                                        {t('onboarding.review.personal_mobile')}
                                    </span>
                                )}
                            </DetailItem>
                            <DetailItem label={t('onboarding.review.email')}>
                                {application.business.email ?? '—'}{' '}
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
                    </div>
                </div>

                {canRequestCorrection && application.submitted_at && (
                    <Form
                        {...requestCorrection.form(accountId)}
                        options={{ preserveScroll: true }}
                        className="space-y-3 rounded-lg border p-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <p className="text-sm font-semibold">
                                    {t(
                                        'activation.application.request_correction',
                                    )}
                                </p>
                                <fieldset className="flex flex-wrap gap-4">
                                    <legend className="text-muted-foreground mb-2 text-xs">
                                        {t(
                                            'activation.application.sections_label',
                                        )}
                                    </legend>
                                    {sections.map((section) => (
                                        <div
                                            key={section}
                                            className="flex items-center gap-2"
                                        >
                                            <Checkbox
                                                id={`section-${section}`}
                                                name="sections[]"
                                                value={section}
                                            />
                                            <Label
                                                htmlFor={`section-${section}`}
                                                className="text-sm font-normal"
                                            >
                                                {t(
                                                    `activation.application.sections.${section}`,
                                                )}
                                            </Label>
                                        </div>
                                    ))}
                                </fieldset>
                                {errors.sections && (
                                    <p className="text-danger text-xs">
                                        {errors.sections}
                                    </p>
                                )}

                                <FormField
                                    label={t('activation.application.feedback')}
                                    description={t(
                                        'activation.application.feedback_help',
                                    )}
                                    error={errors.feedback}
                                    required
                                >
                                    {(props) => (
                                        <TextArea
                                            {...props}
                                            name="feedback"
                                            required
                                            maxLength={1000}
                                        />
                                    )}
                                </FormField>
                                <FormField
                                    label={t('activation.application.reason')}
                                    error={errors.reason}
                                    required
                                >
                                    {(props) => (
                                        <TextArea
                                            {...props}
                                            name="reason"
                                            rows={2}
                                            required
                                            maxLength={1000}
                                        />
                                    )}
                                </FormField>
                                <FormField
                                    label={t(
                                        'activation.application.internal_note',
                                    )}
                                    error={errors.internal_note}
                                >
                                    {(props) => (
                                        <TextArea
                                            {...props}
                                            name="internal_note"
                                            rows={2}
                                            maxLength={2000}
                                        />
                                    )}
                                </FormField>

                                <ConfirmButton
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                    className="gap-2"
                                >
                                    {processing && (
                                        <Spinner className="size-3.5" />
                                    )}
                                    {t(
                                        'activation.application.send_correction',
                                    )}
                                </ConfirmButton>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </SectionCard>
    );
}
