import { Form } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { useEffect, useState } from 'react';
import KycUpdateRequestController from '@/actions/App/Http/Controllers/Admin/KycUpdateRequestController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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

export type DocumentTypeOption = { id: string; name: string };

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    accountId: string;
    documentTypes: DocumentTypeOption[];
};

/**
 * Ask a trading business for fresh KYC (§7.2, FD-1).
 *
 * Two texts, because they go to different readers and only one of them is ever
 * shown to the account holder. The internal reason is for the audit trail; the
 * instructions travel with the notification, and a request that says only
 * "update your verification" comes back with the same documents attached.
 *
 * Every applicable document starts selected, which is the common case — this is
 * a periodic re-verification more often than a single expired licence. Clearing
 * the lot is refused rather than read as "everything", because a request for
 * nothing is not something an administrator means.
 */
export default function RequestKycUpdateDialog({
    open,
    onOpenChange,
    accountId,
    documentTypes,
}: Props) {
    const { t } = useTranslation();
    const [selected, setSelected] = useState<string[]>([]);

    // Re-seeded each time it opens, so a cancelled edit does not carry into the
    // next request.
    useEffect(() => {
        if (open) {
            setSelected(documentTypes.map((type) => type.id));
        }
    }, [open, documentTypes]);

    const toggle = (id: string) =>
        setSelected((current) =>
            current.includes(id)
                ? current.filter((one) => one !== id)
                : [...current, id],
        );

    const allSelected =
        documentTypes.length > 0 && selected.length === documentTypes.length;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('account.kyc_update.title')}</DialogTitle>
                    <DialogDescription>
                        {t('account.kyc_update.description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...KycUpdateRequestController.form(accountId)}
                    onSuccess={() => onOpenChange(false)}
                    className="space-y-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="space-y-1.5">
                                <Label htmlFor="reason">
                                    {t('account.kyc_update.reason')}
                                </Label>
                                <textarea
                                    id="reason"
                                    name="reason"
                                    rows={2}
                                    required
                                    maxLength={1000}
                                    className="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                />
                                <p className="text-muted-foreground text-xs">
                                    {t('account.kyc_update.reason_help')}
                                </p>
                                <InputError message={errors.reason} />
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="instructions">
                                    {t('account.kyc_update.instructions')}
                                </Label>
                                <textarea
                                    id="instructions"
                                    name="instructions"
                                    rows={3}
                                    required
                                    maxLength={1000}
                                    className="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                />
                                <p className="text-muted-foreground text-xs">
                                    {t('account.kyc_update.instructions_help')}
                                </p>
                                <InputError message={errors.instructions} />
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="deadline">
                                    {t('account.kyc_update.deadline')}
                                </Label>
                                <Input
                                    id="deadline"
                                    name="deadline"
                                    type="date"
                                    // The server refuses a past date too; this
                                    // stops the calendar offering one at all.
                                    min={new Date(Date.now() + 86400000)
                                        .toISOString()
                                        .slice(0, 10)}
                                />
                                <p className="text-muted-foreground text-xs">
                                    {t('account.kyc_update.deadline_help')}
                                </p>
                                <InputError message={errors.deadline} />
                            </div>

                            <fieldset className="space-y-2">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <legend className="text-sm font-medium">
                                        {t('account.kyc_update.documents')}
                                    </legend>

                                    {documentTypes.length > 0 && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setSelected(
                                                    allSelected
                                                        ? []
                                                        : documentTypes.map(
                                                              (type) => type.id,
                                                          ),
                                                )
                                            }
                                        >
                                            {allSelected
                                                ? t(
                                                      'account.kyc_update.clear_all',
                                                  )
                                                : t(
                                                      'account.kyc_update.select_all',
                                                  )}
                                        </Button>
                                    )}
                                </div>

                                {documentTypes.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">
                                        {t('account.kyc_update.documents_none')}
                                    </p>
                                ) : (
                                    <>
                                        <ul className="divide-border divide-y rounded-lg border">
                                            {documentTypes.map((type) => (
                                                <li
                                                    key={type.id}
                                                    className="flex items-center gap-3 px-3 py-2"
                                                >
                                                    <Checkbox
                                                        id={`document-${type.id}`}
                                                        checked={selected.includes(
                                                            type.id,
                                                        )}
                                                        onCheckedChange={() =>
                                                            toggle(type.id)
                                                        }
                                                    />
                                                    <Label
                                                        htmlFor={`document-${type.id}`}
                                                        className="flex-1 font-normal"
                                                    >
                                                        {type.name}
                                                    </Label>
                                                </li>
                                            ))}
                                        </ul>

                                        {/* Posted as the real field; the
                                            checkboxes above drive this. */}
                                        {selected.map((id) => (
                                            <input
                                                key={id}
                                                type="hidden"
                                                name="document_type_ids[]"
                                                value={id}
                                            />
                                        ))}

                                        <p className="text-muted-foreground text-xs">
                                            {t(
                                                'account.kyc_update.documents_help',
                                            )}
                                        </p>
                                    </>
                                )}
                            </fieldset>

                            {/*
                             * The confirmation is a statement of consequence
                             * rather than a second "are you sure": what makes
                             * this hard to undo is that somebody is told.
                             */}
                            <p
                                className="bg-warning-subtle border-warning/30 flex items-start gap-2 rounded-lg border p-3 text-sm"
                                role="status"
                            >
                                <AlertTriangle
                                    aria-hidden="true"
                                    className="text-warning mt-0.5 size-4 shrink-0"
                                />
                                {t('account.kyc_update.confirm')}
                            </p>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => onOpenChange(false)}
                                >
                                    {t('account.kyc_update.cancel')}
                                </Button>

                                <Button
                                    type="submit"
                                    disabled={
                                        processing ||
                                        (documentTypes.length > 0 &&
                                            selected.length === 0)
                                    }
                                >
                                    {processing && <Spinner />}
                                    {processing
                                        ? t('account.kyc_update.sending')
                                        : t('account.kyc_update.submit')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
