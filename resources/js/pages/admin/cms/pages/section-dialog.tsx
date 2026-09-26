import { Form } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import PageSectionController from '@/actions/App/Http/Controllers/Admin/Cms/PageSectionController';
import type { MediaPickerItem } from '@/components/admin/cms/media-picker';
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
import type { CmsAdminSection } from '@/types';
import { SectionContentFields } from './section-fields';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    pageId: string;
    /** Null creates a new section; a row edits it. */
    section: CmsAdminSection | null;
    sectionKinds: { value: string; label: string }[];
    media: MediaPickerItem[];
    canManageMedia: boolean;
};

/**
 * Create or edit one of a page's live, editable sections (§34, Stage 7).
 * The content fields shown depend on `kind` — see
 * {@see SectionContentFields} — and the server re-validates and sanitizes
 * whatever is posted through the exact same
 * `App\Domain\Cms\Support\SectionContentValidator` every other write goes
 * through, whatever this dialog does or does not enforce client-side.
 */
export default function SectionDialog({
    open,
    onOpenChange,
    pageId,
    section,
    sectionKinds,
    media,
    canManageMedia,
}: Props) {
    const { t } = useTranslation();
    const [kind, setKind] = useState(
        section?.kind ?? sectionKinds[0]?.value ?? 'hero',
    );

    useEffect(() => {
        if (open) {
            setKind(section?.kind ?? sectionKinds[0]?.value ?? 'hero');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, section]);

    const submit = section
        ? PageSectionController.update.form([pageId, section.id])
        : PageSectionController.store.form(pageId);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {section
                            ? t('cms.section_dialog.edit_title')
                            : t('cms.section_dialog.create_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('cms.section_dialog.description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    key={section?.id ?? 'new'}
                    {...submit}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                    className="space-y-5"
                >
                    {({ processing, errors }) => (
                        <>
                            {section === null && (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="section_key">
                                            {t(
                                                'cms.section_dialog.section_key',
                                            )}
                                        </Label>
                                        <Input
                                            id="section_key"
                                            name="section_key"
                                            required
                                            placeholder="cta-2"
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            {t(
                                                'cms.section_dialog.section_key_help',
                                            )}
                                        </p>
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="kind">
                                            {t('cms.section_dialog.kind')}
                                        </Label>
                                        <select
                                            id="kind"
                                            name="kind"
                                            value={kind}
                                            onChange={(e) =>
                                                setKind(e.target.value)
                                            }
                                            className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                        >
                                            {sectionKinds.map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                </div>
                            )}

                            {section !== null && (
                                <input
                                    type="hidden"
                                    name="kind"
                                    value={section.kind}
                                />
                            )}

                            <SectionContentFields
                                kind={kind}
                                content={section?.content ?? {}}
                                errors={errors}
                                media={media}
                                canManageMedia={canManageMedia}
                            />

                            <div className="grid gap-3 sm:grid-cols-3">
                                <ToggleField
                                    name="is_enabled"
                                    label={t('cms.section_dialog.is_enabled')}
                                    defaultChecked={section?.is_enabled ?? true}
                                />
                                <ToggleField
                                    name="visible_on_desktop"
                                    label={t(
                                        'cms.section_dialog.visible_on_desktop',
                                    )}
                                    defaultChecked={
                                        section?.visible_on_desktop ?? true
                                    }
                                />
                                <ToggleField
                                    name="visible_on_mobile"
                                    label={t(
                                        'cms.section_dialog.visible_on_mobile',
                                    )}
                                    defaultChecked={
                                        section?.visible_on_mobile ?? true
                                    }
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
                                    {t('cms.actions.save_draft')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function ToggleField({
    name,
    label,
    defaultChecked,
}: {
    name: string;
    label: string;
    defaultChecked: boolean;
}) {
    const [checked, setChecked] = useState(defaultChecked);

    useEffect(() => setChecked(defaultChecked), [defaultChecked]);

    return (
        <label className="flex items-center gap-2 text-sm">
            <input type="hidden" name={name} value={checked ? '1' : '0'} />
            <Checkbox
                checked={checked}
                onCheckedChange={(next) => setChecked(next === true)}
            />
            {label}
        </label>
    );
}
