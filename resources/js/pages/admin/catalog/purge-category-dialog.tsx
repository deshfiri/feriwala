import { router } from '@inertiajs/react';
import { useState } from 'react';
import CategoryController from '@/actions/App/Http/Controllers/Admin/CategoryController';
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
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';

type Props = {
    category: { id: string; name: string } | null;
    onClose: () => void;
};

/**
 * Super Admin purge of a category and every subcategory under it.
 *
 * The server refuses while any Product, trashed ones included, still sits in the
 * branch, so this can only ever remove empty categories.
 */
export default function PurgeCategoryDialog({ category, onClose }: Props) {
    const { t } = useTranslation();
    const [password, setPassword] = useState('');
    const [errors, setErrors] = useState<string[]>([]);
    const [processing, setProcessing] = useState(false);

    if (category === null) {
        return null;
    }

    const submit = () => {
        setProcessing(true);
        setErrors([]);

        router.delete(CategoryController.purge.url(category.id), {
            data: { password },
            preserveScroll: true,
            onSuccess: () => {
                setPassword('');
                onClose();
            },
            onError: (bag) => setErrors(Object.values(bag)),
            onFinish: () => {
                setPassword('');
                setProcessing(false);
            },
        });
    };

    return (
        <Dialog
            open
            onOpenChange={(next) => {
                if (!next && !processing) {
                    onClose();
                }
            }}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {t('catalog.categories.purge.title', {
                            name: category.name,
                        })}
                    </DialogTitle>
                    <DialogDescription>
                        {t('catalog.categories.purge.description')}
                    </DialogDescription>
                </DialogHeader>

                {errors.length > 0 && <AlertError errors={errors} />}

                <FormField
                    label={t('catalog.products.force_delete.password_label')}
                    required
                >
                    {(field) => (
                        <Input
                            {...field}
                            type="password"
                            value={password}
                            onChange={(event) =>
                                setPassword(event.target.value)
                            }
                            autoComplete="current-password"
                        />
                    )}
                </FormField>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={onClose}
                        disabled={processing}
                    >
                        {t('common.actions.cancel')}
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={processing || password === ''}
                        onClick={submit}
                    >
                        {t('catalog.categories.purge.action')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
