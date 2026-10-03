import { Form } from '@inertiajs/react';
import { useState } from 'react';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import TextArea from '@/components/forms/text-area';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';

export type ContactChannelState = {
    verified: boolean;
    verified_at: string | null;
    by_staff: boolean;
    verified_by: string | null;
    reason: string | null;
};

export type ContactState = {
    email: ContactChannelState;
    mobile: ContactChannelState;
};

type FormTarget = {
    action: string;
    method: 'get' | 'post' | 'put' | 'patch' | 'delete';
};

type Props = {
    contact: ContactState;
    /** Wayfinder `.form()` results for this identity's endpoints. */
    verifyForm: FormTarget;
    sendLinkForm: FormTarget;
    revokeLinkForm: FormTarget;
    canVerify: boolean;
    canManageSetup: boolean;
};

/**
 * Where an identity's email and mobile stand, with the two things staff may do
 * about it: confirm a channel by hand, and send or revoke the password-setup
 * link. Staff-only. Every action asks for a reason, which is recorded; nothing
 * here shows or sets a password.
 */
export default function ContactVerificationCard({
    contact,
    verifyForm,
    sendLinkForm,
    revokeLinkForm,
    canVerify,
    canManageSetup,
}: Props) {
    const { t, locale } = useTranslation();
    const [confirming, setConfirming] = useState<'email' | 'mobile' | null>(
        null,
    );

    const channels = [
        ['email', contact.email],
        ['mobile', contact.mobile],
    ] as const;

    return (
        <>
            <SectionCard title={t('managed_accounts.contact.title')}>
                <ul className="divide-border divide-y text-sm">
                    {channels.map(([key, state]) => (
                        <li
                            key={key}
                            className="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0"
                        >
                            <span className="flex-1 font-medium">
                                {t(`managed_accounts.fields.${key}`)}
                            </span>

                            <StatusPill
                                tone={
                                    state.verified
                                        ? state.by_staff
                                            ? 'info'
                                            : 'success'
                                        : 'warning'
                                }
                                label={
                                    state.verified
                                        ? state.by_staff
                                            ? t(
                                                  'managed_accounts.contact.by_staff',
                                              )
                                            : t(
                                                  'managed_accounts.contact.verified',
                                              )
                                        : t(
                                              'managed_accounts.contact.not_verified',
                                          )
                                }
                            />

                            {state.verified && state.verified_at && (
                                <span className="text-muted-foreground text-xs">
                                    {new Date(
                                        state.verified_at,
                                    ).toLocaleDateString(locale)}
                                    {state.by_staff && state.verified_by
                                        ? ` · ${state.verified_by}`
                                        : ''}
                                </span>
                            )}

                            {!state.verified && canVerify && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setConfirming(key)}
                                >
                                    {t('managed_accounts.contact.confirm')}
                                </Button>
                            )}

                            {state.by_staff && state.reason && (
                                <p className="text-muted-foreground w-full text-xs">
                                    {state.reason}
                                </p>
                            )}
                        </li>
                    ))}
                </ul>

                {canManageSetup && (
                    <div className="mt-4 space-y-3 border-t pt-4">
                        <p className="text-sm font-medium">
                            {t('managed_accounts.contact.setup_title')}
                        </p>
                        <p className="text-muted-foreground text-xs">
                            {t('managed_accounts.contact.setup_help')}
                        </p>
                        <ReasonForm
                            target={sendLinkForm}
                            label={t('managed_accounts.send_link')}
                        />
                        <ReasonForm
                            target={revokeLinkForm}
                            label={t('managed_accounts.revoke_link')}
                            variant="outline"
                        />
                    </div>
                )}
            </SectionCard>

            <Dialog
                open={confirming !== null}
                onOpenChange={(open) => !open && setConfirming(null)}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {t('managed_accounts.contact.confirm_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('managed_accounts.contact.confirm_help')}
                        </DialogDescription>
                    </DialogHeader>

                    <Form
                        {...verifyForm}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setConfirming(null)}
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="channel"
                                    value={confirming ?? ''}
                                />
                                <FormField
                                    label={t('managed_accounts.fields.reason')}
                                    description={t(
                                        'managed_accounts.fields.reason_help',
                                    )}
                                    error={errors.reason ?? errors.channel}
                                    required
                                >
                                    {(field) => (
                                        <TextArea
                                            {...field}
                                            name="reason"
                                            required
                                        />
                                    )}
                                </FormField>
                                <SubmitButton processing={processing}>
                                    {t('managed_accounts.contact.confirm')}
                                </SubmitButton>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </>
    );
}

function ReasonForm({
    target,
    label,
    variant = 'default',
}: {
    target: FormTarget;
    label: string;
    variant?: 'default' | 'outline';
}) {
    const { t } = useTranslation();

    return (
        <Form
            {...target}
            options={{ preserveScroll: true }}
            className="flex flex-wrap items-end gap-2"
        >
            {({ processing, errors }) => (
                <>
                    <FormField
                        label={t('managed_accounts.fields.reason')}
                        error={errors.reason}
                        className="min-w-48 flex-1"
                        required
                    >
                        {(field) => <Input {...field} name="reason" required />}
                    </FormField>
                    <SubmitButton processing={processing} variant={variant}>
                        {label}
                    </SubmitButton>
                </>
            )}
        </Form>
    );
}
