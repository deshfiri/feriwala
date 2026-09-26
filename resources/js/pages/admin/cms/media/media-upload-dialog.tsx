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

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * Upload a new image into the CMS media library (§34's media-safety
 * requirements, Stage 7). The real MIME/size check is byte-sniffed
 * server-side by UploadCmsMedia — a disguised file comes back as a field
 * error here, not a crash.
 */
export default function MediaUploadDialog({ open, onOpenChange }: Props) {
    const { t } = useTranslation();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {t('cms.media_upload_dialog.title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('cms.media_upload_dialog.description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...MediaController.store.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-1.5">
                                <Label htmlFor="file">
                                    {t('cms.media_upload_dialog.file')}
                                </Label>
                                <Input
                                    id="file"
                                    name="file"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    required
                                />
                                <InputError message={errors.file} />
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
                                    {t('cms.actions.upload')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
