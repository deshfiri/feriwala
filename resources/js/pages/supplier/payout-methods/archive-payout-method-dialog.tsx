import { Form } from '@inertiajs/react';
import PayoutMethodController from '@/actions/App/Http/Controllers/Supplier/PayoutMethodController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';

export default function ArchivePayoutMethodDialog({
    open,
    onOpenChange,
    method,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    method: { id: string; label: string } | null;
}) {
    const { t } = useTranslation();

    if (method === null) {
        return null;
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{method.label}</DialogTitle>
                    <DialogDescription>
                        {t('supplier.payout_methods.archive_confirm')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...PayoutMethodController.archive.form(method.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="archive-current-password">
                                    {t(
                                        'supplier.payout_methods.current_password',
                                    )}
                                </Label>
                                <Input
                                    id="archive-current-password"
                                    name="current_password"
                                    type="password"
                                    autoComplete="current-password"
                                    required
                                />
                                <InputError message={errors.current_password} />
                            </div>

                            <DialogFooter className="gap-2 sm:gap-2">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => onOpenChange(false)}
                                >
                                    {t('common.actions.cancel')}
                                </Button>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    {t('supplier.payout_methods.archive')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
