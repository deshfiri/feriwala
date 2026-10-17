import { Form } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import FormField from '@/components/forms/form-field';
import NativeSelect from '@/components/forms/native-select';
import ReasonTextarea from '@/components/forms/reason-textarea';
import SubmitButton from '@/components/forms/submit-button';
import Notice from '@/components/notice';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';

type Target = { value: string; label: string };

const textareaClasses =
    'border-input focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:border-destructive w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]';

/**
 * Staff uploading a KYC document on an applicant's behalf, for a partner or a
 * Supplier alike.
 *
 * What the round accepts depends on the round the applicant would be filling
 * in, so the choices are asked of the server when the dialog opens rather than
 * guessed here. Opening it is what opens the round, exactly as the applicant's
 * own KYC screen does on first arrival.
 */
export default function StaffKycUploadDialog({
    targetsUrl,
    storeForm,
    open,
    onOpenChange,
}: {
    targetsUrl: string;
    /** The wayfinder `.form()` for the store route: `{ action, method }`. */
    storeForm: { action: string; method: 'post' };
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();
    const [targets, setTargets] = useState<Target[] | null>(null);
    const [message, setMessage] = useState<string | null>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        const controller = new AbortController();

        setTargets(null);
        setMessage(null);

        fetch(targetsUrl, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then((response) => response.json())
            .then((body: { targets: Target[]; message?: string }) => {
                setTargets(body.targets);
                setMessage(body.message ?? null);
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setTargets([]);
                }
            });

        return () => controller.abort();
    }, [open, targetsUrl]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('kyc.upload_on_behalf.title')}</DialogTitle>
                    <DialogDescription>
                        {t('kyc.upload_on_behalf.description')}
                    </DialogDescription>
                </DialogHeader>

                {targets === null ? (
                    <p className="text-muted-foreground text-sm">
                        {t('kyc.upload_on_behalf.loading')}
                    </p>
                ) : targets.length === 0 ? (
                    <Notice tone="warning" inset>
                        {message ?? t('kyc.upload_on_behalf.none')}
                    </Notice>
                ) : (
                    <Form
                        {...storeForm}
                        onSuccess={() => onOpenChange(false)}
                        className="space-y-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <FormField
                                    label={t('kyc.upload_on_behalf.document')}
                                    error={errors.target}
                                    required
                                >
                                    {(field) => (
                                        <NativeSelect
                                            {...field}
                                            name="target"
                                            defaultValue=""
                                            required
                                        >
                                            <option value="" disabled>
                                                {t(
                                                    'kyc.upload_on_behalf.choose',
                                                )}
                                            </option>
                                            {targets.map((target) => (
                                                <option
                                                    key={target.value}
                                                    value={target.value}
                                                >
                                                    {target.label}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    )}
                                </FormField>

                                <FormField
                                    label={t('kyc.upload_on_behalf.file')}
                                    error={errors.file}
                                    required
                                >
                                    {(field) => (
                                        <Input
                                            {...field}
                                            type="file"
                                            name="file"
                                            required
                                        />
                                    )}
                                </FormField>

                                <FormField
                                    label={t('kyc.upload_on_behalf.reason')}
                                    description={t(
                                        'kyc.upload_on_behalf.reason_help',
                                    )}
                                    error={errors.reason}
                                    required
                                >
                                    {(field) => (
                                        <ReasonTextarea
                                            context="account"
                                            {...field}
                                            name="reason"
                                            rows={2}
                                            className={textareaClasses}
                                            required
                                        />
                                    )}
                                </FormField>

                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        name="submit"
                                        value="1"
                                    />
                                    {t('kyc.upload_on_behalf.submit_after')}
                                </label>

                                <SubmitButton processing={processing}>
                                    {t('kyc.upload_on_behalf.submit')}
                                </SubmitButton>
                            </>
                        )}
                    </Form>
                )}
            </DialogContent>
        </Dialog>
    );
}
