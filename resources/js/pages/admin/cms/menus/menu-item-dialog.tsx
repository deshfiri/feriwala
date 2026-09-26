import { Form } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import MenuItemController from '@/actions/App/Http/Controllers/Admin/Cms/MenuItemController';
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
import type { CmsAdminMenuItem } from '@/types';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    location: string;
    /** Null creates a new item; a row edits it. */
    item: CmsAdminMenuItem | null;
};

/**
 * Create or edit one menu item (§4, §34, Stage 7). Points at exactly one
 * destination — a route name or a safe external URL, never both — the
 * migration's own CHECK constraint and SafeMenuUrl re-check whatever this
 * form does or does not enforce.
 */
export default function MenuItemDialog({
    open,
    onOpenChange,
    location,
    item,
}: Props) {
    const { t } = useTranslation();

    const submit = item
        ? MenuItemController.update.form([location, item.id])
        : MenuItemController.store.form(location);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {item
                            ? t('cms.menu_item_dialog.edit_title')
                            : t('cms.menu_item_dialog.create_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('cms.menu_item_dialog.description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    key={item?.id ?? 'new'}
                    {...submit}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="label_en">
                                        {t('cms.menu_item_dialog.label_en')}
                                    </Label>
                                    <Input
                                        id="label_en"
                                        name="label_en"
                                        defaultValue={item?.label_en ?? ''}
                                        required
                                    />
                                    <InputError message={errors.label_en} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="label_bn">
                                        {t('cms.menu_item_dialog.label_bn')}
                                    </Label>
                                    <Input
                                        id="label_bn"
                                        name="label_bn"
                                        defaultValue={item?.label_bn ?? ''}
                                    />
                                </div>
                            </div>

                            <div className="grid gap-1.5">
                                <Label htmlFor="route_name">
                                    {t('cms.menu_item_dialog.route_name')}
                                </Label>
                                <Input
                                    id="route_name"
                                    name="route_name"
                                    defaultValue={item?.route_name ?? ''}
                                    placeholder="login"
                                />
                                <InputError message={errors.route_name} />
                            </div>

                            <div className="grid gap-1.5">
                                <Label htmlFor="external_url">
                                    {t('cms.menu_item_dialog.external_url')}
                                </Label>
                                <Input
                                    id="external_url"
                                    name="external_url"
                                    defaultValue={item?.external_url ?? ''}
                                    placeholder="/#how-it-works"
                                />
                                <InputError message={errors.external_url} />
                                <p className="text-muted-foreground text-xs">
                                    {t('cms.menu_item_dialog.destination_help')}
                                </p>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="link_target">
                                        {t('cms.menu_item_dialog.link_target')}
                                    </Label>
                                    <select
                                        id="link_target"
                                        name="link_target"
                                        defaultValue={
                                            item?.link_target ?? 'self'
                                        }
                                        className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                    >
                                        <option value="self">
                                            {t(
                                                'cms.menu_item_dialog.target_self',
                                            )}
                                        </option>
                                        <option value="blank">
                                            {t(
                                                'cms.menu_item_dialog.target_blank',
                                            )}
                                        </option>
                                    </select>
                                </div>

                                <EnabledField
                                    defaultChecked={item?.is_enabled ?? true}
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

function EnabledField({ defaultChecked }: { defaultChecked: boolean }) {
    const { t } = useTranslation();
    const [checked, setChecked] = useState(defaultChecked);

    useEffect(() => setChecked(defaultChecked), [defaultChecked]);

    return (
        <div className="flex items-end gap-2">
            <input
                type="hidden"
                name="is_enabled"
                value={checked ? '1' : '0'}
            />
            <Checkbox
                id="is_enabled"
                checked={checked}
                onCheckedChange={(next) => setChecked(next === true)}
            />
            <Label htmlFor="is_enabled" className="font-normal">
                {t('cms.menu_item_dialog.is_enabled')}
            </Label>
        </div>
    );
}
