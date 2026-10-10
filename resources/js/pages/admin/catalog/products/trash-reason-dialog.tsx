import { router } from '@inertiajs/react';
import { useState } from 'react';
import AlertError from '@/components/alert-error';
import FormField from '@/components/forms/form-field';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useTranslation } from '@/hooks/use-translation';
import ReasonTextarea from '@/components/forms/reason-textarea';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    productName: string;
    url: string;
    onSuccess?: () => void;
};

/**
 * "Move to Trash", asking for the reason the Trash screen will show later.
 *
 * A plain `router.delete` with the reason in its body — not the `<Form>`
 * component, since there is nothing else on this dialog to bind to a request;
 * the dialog itself is all the state there is.
 */
export default function TrashReasonDialog({
    open,
    onOpenChange,
    productName,
    url,
    onSuccess,
}: Props) {
    const { t } = useTranslation();
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);

    const submit = () => {
        setProcessing(true);

        router.delete(url, {
            data: { reason },
            preserveScroll: true,
            onSuccess: () => {
                setReason('');
                setError(undefined);
                onOpenChange(false);
                onSuccess?.();
            },
            onError: (errors) => setError(errors.reason ?? errors.product),
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!processing) {
                    onOpenChange(next);
                }
            }}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {t('catalog.products.trash.confirm_title', {
                            name: productName,
                        })}
                    </DialogTitle>
                    <DialogDescription>
                        {t('catalog.products.trash.confirm_description', {
                            name: productName,
                        })}
                    </DialogDescription>
                </DialogHeader>

                {error && <AlertError errors={[error]} />}

                <FormField
                    label={t('catalog.products.trash.reason_label')}
                    hint={t('catalog.products.trash.reason_help')}
                    required
                >
                    {(field) => (
                        <ReasonTextarea
                            context="product"
                            {...field}
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            rows={3}
                            maxLength={2000}
                            required
                            className="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        />
                    )}
                </FormField>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                        disabled={processing}
                    >
                        {t('common.actions.cancel')}
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={processing || reason.trim() === ''}
                        onClick={submit}
                    >
                        {t('catalog.products.delete')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
