import { Form } from '@inertiajs/react';
import MediaController from '@/actions/App/Http/Controllers/Admin/Cms/MediaController';
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
import type { CmsAdminMedia } from '@/types';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    media: CmsAdminMedia;
};

/**
 * Alt text and attribution for an uploaded image (§34's media-safety
 * requirements, Stage 7) — never the file itself, dimensions or path,
 * which are fixed at upload time.
 */
export default function MediaEditDialog({ open, onOpenChange, media }: Props) {
    const { t } = useTranslation();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {t('cms.media_edit_dialog.title')}
                    </DialogTitle>
                    <DialogDescription>
                        <img
                            src={media.url}
                            alt=""
                            className="mt-2 max-h-40 rounded-md"
                        />
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...MediaController.update.form(media.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-1.5">
                                <Label htmlFor="alt_text_en">
                                    {t('cms.media_edit_dialog.alt_text_en')}
                                </Label>
                                <Input
                                    id="alt_text_en"
                                    name="alt_text_en"
                                    defaultValue={media.alt_text_en ?? ''}
                                />
                                <InputError message={errors.alt_text_en} />
                            </div>

                            <div className="grid gap-1.5">
                                <Label htmlFor="alt_text_bn">
                                    {t('cms.media_edit_dialog.alt_text_bn')}
                                </Label>
                                <Input
                                    id="alt_text_bn"
                                    name="alt_text_bn"
                                    defaultValue={media.alt_text_bn ?? ''}
                                />
                                <InputError message={errors.alt_text_bn} />
                            </div>

                            <div className="grid gap-1.5">
                                <Label htmlFor="attribution">
                                    {t('cms.media_edit_dialog.attribution')}
                                </Label>
                                <Input
                                    id="attribution"
                                    name="attribution"
                                    defaultValue={media.attribution ?? ''}
                                />
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
