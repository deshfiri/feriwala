import { router } from '@inertiajs/react';
import { ImageIcon, Upload, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import MediaController from '@/actions/App/Http/Controllers/Admin/Cms/MediaController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';

export type MediaPickerItem = {
    id: string;
    url: string;
    original_filename: string;
    mime_type: string;
    width: number | null;
    height: number | null;
    alt_text_en: string | null;
    alt_text_bn: string | null;
};

type Props = {
    /** Submitted name, e.g. `content[media_id]` or `content[poster_media_id]`. */
    name: string;
    label: string;
    /** The currently selected media's public id, or null for none. */
    value: string | null;
    media: MediaPickerItem[];
    canManage: boolean;
    /** Whether this field may be cleared once set. Defaults to true. */
    clearable?: boolean;
};

/**
 * The one media picker every section field and SEO image field shares
 * (§34, Stage 7 addendum) — never a bespoke picker per section. Browsing
 * the library needs `cms.media.view`; uploading a new asset from inside it
 * needs `cms.media.manage` on top of that, matched to the same split the
 * server enforces via CmsMediaPolicy.
 *
 * A plain button and file input, not a nested `<form>` — this is used
 * inside `SectionDialog`'s own Inertia `Form`, and HTML forms cannot
 * nest. The upload goes through `router.post` directly instead.
 */
export default function MediaPicker({ name, label, value, media, canManage, clearable = true }: Props) {
    const { t } = useTranslation();
    const [selected, setSelected] = useState(value);
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [uploading, setUploading] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    useEffect(() => setSelected(value), [value]);

    const current = media.find((item) => item.id === selected) ?? null;

    const filtered = media.filter((item) =>
        item.original_filename.toLowerCase().includes(search.toLowerCase()),
    );

    const choose = (item: MediaPickerItem) => {
        setSelected(item.id);
        setOpen(false);
    };

    const upload = (file: File) => {
        const formData = new FormData();
        formData.append('file', file);

        setUploading(true);

        router.post(MediaController.store.url(), formData, {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                const freshMedia = (page.props.media as MediaPickerItem[] | undefined) ?? [];
                if (freshMedia[0]) {
                    setSelected(freshMedia[0].id);
                }
                setOpen(false);
            },
            onFinish: () => setUploading(false),
        });
    };

    return (
        <div className="grid gap-1.5">
            <Label>{label}</Label>
            <input type="hidden" name={name} value={selected ?? ''} />

            {current ? (
                <div className="flex items-center gap-3 rounded-lg border p-2">
                    <img
                        src={current.url}
                        alt=""
                        className="size-14 shrink-0 rounded-md object-cover"
                    />
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium">
                            {current.original_filename}
                        </p>
                        <p className="text-muted-foreground text-xs">
                            {current.width && current.height
                                ? `${current.width}×${current.height}`
                                : current.mime_type}
                        </p>
                    </div>
                    <Button type="button" variant="outline" size="sm" onClick={() => setOpen(true)}>
                        {t('cms.media_picker.change')}
                    </Button>
                    {clearable && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label={t('cms.media_picker.remove')}
                            onClick={() => setSelected(null)}
                        >
                            <X className="size-4" />
                        </Button>
                    )}
                </div>
            ) : (
                <Button type="button" variant="outline" onClick={() => setOpen(true)}>
                    <ImageIcon className="size-4" />
                    {t('cms.media_picker.choose')}
                </Button>
            )}

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[85dvh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{t('cms.media_picker.title')}</DialogTitle>
                        <DialogDescription>
                            {t('cms.media_picker.description')}
                        </DialogDescription>
                    </DialogHeader>

                    <Input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={t('cms.media_picker.search_placeholder')}
                        aria-label={t('cms.media_picker.search_placeholder')}
                    />

                    {filtered.length === 0 ? (
                        <p className="text-muted-foreground py-6 text-center text-sm">
                            {t('cms.media_picker.empty')}
                        </p>
                    ) : (
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            {filtered.map((item) => (
                                <button
                                    key={item.id}
                                    type="button"
                                    onClick={() => choose(item)}
                                    className="hover:border-ring focus-visible:ring-ring group rounded-lg border p-1.5 text-left focus-visible:ring-2 focus-visible:outline-none"
                                >
                                    <img
                                        src={item.url}
                                        alt=""
                                        className="aspect-square w-full rounded-md object-cover"
                                    />
                                    <p className="mt-1 truncate text-xs font-medium">
                                        {item.original_filename}
                                    </p>
                                    <p className="text-muted-foreground truncate text-[11px]">
                                        {item.alt_text_en
                                            ? item.alt_text_en
                                            : t('cms.media_picker.no_alt_text')}
                                    </p>
                                </button>
                            ))}
                        </div>
                    )}

                    {canManage && (
                        <div className="border-t pt-4">
                            <input
                                ref={fileInputRef}
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                className="hidden"
                                onChange={(e) => {
                                    const file = e.target.files?.[0];
                                    if (file) {
                                        upload(file);
                                    }
                                    e.target.value = '';
                                }}
                            />
                            <Button
                                type="button"
                                variant="outline"
                                disabled={uploading}
                                onClick={() => fileInputRef.current?.click()}
                            >
                                <Upload className="size-4" />
                                {uploading
                                    ? t('cms.media_picker.uploading')
                                    : t('cms.media_picker.upload_new')}
                            </Button>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </div>
    );
}
