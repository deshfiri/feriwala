import { Form } from '@inertiajs/react';
import type {
    AddressFormAction,
    AddressRow,
} from '@/components/address-book/types';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Confirm archiving an address. Shared by the Client/Partner and the
 * Supplier address screens — see {@link AddressDialog} for why.
 */
export default function ArchiveAddressDialog({
    open,
    onOpenChange,
    address,
    form,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    address: AddressRow | null;
    form: AddressFormAction | null;
}) {
    const { t } = useTranslation();

    if (address === null || form === null) {
        return null;
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{address.contact_name}</DialogTitle>
                    <DialogDescription>
                        {t('address.archive_confirm')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...form}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ processing }) => (
                        <DialogFooter className="gap-2 sm:gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => onOpenChange(false)}
                            >
                                {t('address.actions.cancel')}
                            </Button>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                {t('address.actions.archive')}
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
