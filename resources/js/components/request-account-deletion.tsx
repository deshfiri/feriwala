import { Form } from '@inertiajs/react';
import { useRef } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import type { OwnDeletionRequest } from '@/types';

/** Where a form posts, as Wayfinder's `.form()` hands it over. */
type FormTarget = {
    action: string;
    method: 'get' | 'post' | 'put' | 'patch' | 'delete';
};

/**
 * Asking to have your own account deleted.
 *
 * There is no "delete my account" button, on purpose: an account holds records
 * other people rely on — orders, payments, a wallet — so a person asks, confirms
 * with their password, and an authorised member of staff decides. This shows
 * where that request stands, and lets them change their mind while it is open.
 *
 * Used by partners, their staff and Suppliers alike; only the two addresses it
 * posts to differ.
 */
export default function RequestAccountDeletion({
    request,
    requestForm,
    withdrawForm,
}: {
    request: OwnDeletionRequest | null;
    requestForm: FormTarget;
    withdrawForm: FormTarget;
}) {
    const { t, locale } = useTranslation();
    const reasonInput = useRef<HTMLTextAreaElement>(null);

    const requestedOn =
        request === null
            ? ''
            : new Date(request.requested_at).toLocaleDateString(locale, {
                  day: 'numeric',
                  month: 'short',
                  year: 'numeric',
              });

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title={t('deletion.title')}
                description={t('deletion.description')}
            />

            <div className="border-danger/25 bg-danger-subtle space-y-4 rounded-lg border p-4">
                <div className="text-danger space-y-0.5">
                    <p className="font-medium">{t('deletion.warning_title')}</p>
                    <p className="text-sm">
                        {t('deletion.warning_description')}
                    </p>
                </div>

                {request?.status === 'pending' ? (
                    <div className="space-y-3" data-test="deletion-pending">
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusPill
                                tone="warning"
                                label={t('deletion.status.pending')}
                            />
                            <span className="text-muted-foreground text-xs">
                                {t('deletion.requested_on', {
                                    date: requestedOn,
                                })}
                            </span>
                        </div>

                        <p className="text-sm">
                            <span className="font-medium">
                                {t('deletion.your_reason')}
                            </span>{' '}
                            {request.reason}
                        </p>

                        <Form
                            {...withdrawForm}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                    data-test="withdraw-deletion-button"
                                >
                                    {t('deletion.withdraw')}
                                </Button>
                            )}
                        </Form>
                    </div>
                ) : (
                    <>
                        {request?.status === 'rejected' && (
                            <div
                                role="status"
                                className="bg-background space-y-1 rounded-md border p-3 text-sm"
                            >
                                <p className="font-medium">
                                    {t('deletion.rejected_title')}
                                </p>
                                {request.decision_note && (
                                    <p className="text-muted-foreground">
                                        {request.decision_note}
                                    </p>
                                )}
                            </div>
                        )}

                        <Dialog>
                            <DialogTrigger asChild>
                                <Button
                                    variant="destructive"
                                    data-test="request-deletion-button"
                                >
                                    {t('deletion.request')}
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>
                                    {t('deletion.confirm_title')}
                                </DialogTitle>
                                <DialogDescription>
                                    {t('deletion.confirm_description')}
                                </DialogDescription>

                                <Form
                                    {...requestForm}
                                    options={{ preserveScroll: true }}
                                    onError={() => reasonInput.current?.focus()}
                                    resetOnSuccess
                                    className="space-y-5"
                                >
                                    {({
                                        resetAndClearErrors,
                                        processing,
                                        errors,
                                    }) => (
                                        <>
                                            <div className="grid gap-2">
                                                <Label htmlFor="deletion-reason">
                                                    {t('deletion.reason')}
                                                </Label>
                                                <textarea
                                                    id="deletion-reason"
                                                    ref={reasonInput}
                                                    name="reason"
                                                    rows={3}
                                                    required
                                                    maxLength={1000}
                                                    className="border-input bg-background focus-visible:ring-ring rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                                />
                                                <InputError
                                                    message={errors.reason}
                                                />
                                            </div>

                                            <div className="grid gap-2">
                                                <Label htmlFor="deletion-password">
                                                    {t('deletion.password')}
                                                </Label>
                                                <PasswordInput
                                                    id="deletion-password"
                                                    name="current_password"
                                                    required
                                                    autoComplete="current-password"
                                                />
                                                <InputError
                                                    message={
                                                        errors.current_password
                                                    }
                                                />
                                            </div>

                                            <DialogFooter className="gap-2">
                                                <DialogClose asChild>
                                                    <Button
                                                        type="button"
                                                        variant="secondary"
                                                        onClick={() =>
                                                            resetAndClearErrors()
                                                        }
                                                    >
                                                        {t(
                                                            'common.actions.cancel',
                                                        )}
                                                    </Button>
                                                </DialogClose>
                                                <Button
                                                    type="submit"
                                                    variant="destructive"
                                                    disabled={processing}
                                                    data-test="confirm-request-deletion-button"
                                                >
                                                    {t('deletion.submit')}
                                                </Button>
                                            </DialogFooter>
                                        </>
                                    )}
                                </Form>
                            </DialogContent>
                        </Dialog>
                    </>
                )}
            </div>
        </div>
    );
}
