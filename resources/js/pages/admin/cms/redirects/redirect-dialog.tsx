import { Form } from '@inertiajs/react';
import RedirectController from '@/actions/App/Http/Controllers/Admin/Cms/RedirectController';
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
import type { CmsAdminRedirect } from '@/types';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Null creates; a row edits it. */
    row: CmsAdminRedirect | null;
};

/**
 * Create or edit a public-site redirect (§34.1, Stage 7). Loop and chain
 * refusal is ManageRedirect's own job — a rejected save shows up as a field
 * error on `to_path`, naming what it would chain into.
 */
export default function RedirectDialog({ open, onOpenChange, row }: Props) {
    const { t } = useTranslation();

    const submit = row
        ? RedirectController.update.form(row.id)
        : RedirectController.store.form();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {row
                            ? t('cms.redirect_dialog.edit_title')
                            : t('cms.redirect_dialog.create_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('cms.redirect_dialog.description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    key={row?.id ?? 'new'}
                    {...submit}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-1.5">
                                <Label htmlFor="from_path">
                                    {t('cms.redirect_dialog.from_path')}
                                </Label>
                                <Input
                                    id="from_path"
                                    name="from_path"
                                    defaultValue={row?.from_path ?? ''}
                                    disabled={row !== null}
                                    required
                                    placeholder="/old-page"
                                />
                                <InputError message={errors.from_path} />
                            </div>

                            <div className="grid gap-1.5">
                                <Label htmlFor="to_path">
                                    {t('cms.redirect_dialog.to_path')}
                                </Label>
                                <Input
                                    id="to_path"
                                    name="to_path"
                                    defaultValue={row?.to_path ?? ''}
                                    required
                                    placeholder="/new-page"
                                />
                                <InputError message={errors.to_path} />
                            </div>

                            <div className="grid gap-1.5">
                                <Label htmlFor="status_code">
                                    {t('cms.redirect_dialog.status_code')}
                                </Label>
                                <select
                                    id="status_code"
                                    name="status_code"
                                    defaultValue={row?.status_code ?? 301}
                                    className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                >
                                    <option value={301}>
                                        301 —{' '}
                                        {t('cms.redirect_dialog.permanent')}
                                    </option>
                                    <option value={302}>
                                        302 —{' '}
                                        {t('cms.redirect_dialog.temporary')}
                                    </option>
                                    <option value={307}>307</option>
                                    <option value={308}>308</option>
                                </select>
                                <InputError message={errors.status_code} />
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => onOpenChange(false)}
                                >
                                    {t('cms.actions.cancel')}
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {t('cms.actions.save')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
