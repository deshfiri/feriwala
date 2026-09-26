import { Plus, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import MediaPicker, { type MediaPickerItem } from '@/components/admin/cms/media-picker';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Section content editors (§34, Stage 7 + addendum) — one per JSON shape
 * `App\Domain\Cms\Support\SectionContentValidator::rulesFor()` declares.
 * Every field submits under `content[...]`, so whatever the browser posts
 * lands exactly where `SaveSectionDraft` expects it; the server, not this
 * component, is the one place the shape is actually validated and
 * sanitized.
 *
 * A scalar localized field (heading, body, a CTA) is plain `defaultValue` +
 * `name`, uncontrolled like the rest of the app's dialogs. An array field
 * (bullets, items, steps) needs to grow and shrink, so it is controlled
 * state serialized into hidden inputs — the same shape
 * `packages/package-dialog.tsx`'s `ChargeEditor` already uses for charges.
 * A media field is `MediaPicker`, which renders its own hidden input and
 * needs nothing from the surrounding controlled state — see its own doc
 * comment.
 */

type LocalizedValue = { en: string; bn: string | null };
type CtaValue = { label: LocalizedValue; href: string };
type Errors = Record<string, string | undefined>;

function emptyLocalized(): LocalizedValue {
    return { en: '', bn: '' };
}

function emptyCta(): CtaValue {
    return { label: emptyLocalized(), href: '' };
}

function asLocalized(value: unknown): LocalizedValue {
    if (value && typeof value === 'object') {
        const v = value as Partial<LocalizedValue>;

        return { en: v.en ?? '', bn: v.bn ?? '' };
    }

    return emptyLocalized();
}

function asCta(value: unknown): CtaValue {
    if (value && typeof value === 'object') {
        const v = value as { label?: unknown; href?: string };

        return { label: asLocalized(v.label), href: v.href ?? '' };
    }

    return emptyCta();
}

function asMediaId(value: unknown): string | null {
    return typeof value === 'string' && value !== '' ? value : null;
}

/**
 * Server validation errors from SectionContentValidator are keyed by the
 * bare dot path inside `content` (`heading.en`, `primary_cta.label.en`) --
 * the validator never sees the `content[...]` wrapper the form's own field
 * `name` carries, since the controller already unwraps `content` before
 * handing it to the validator. This converts one to the other so an error
 * actually finds its field instead of silently never matching.
 */
function errorKey(name: string): string {
    const withoutPrefix = name.replace(/^content/, '');
    const segments = [...withoutPrefix.matchAll(/\[([^\]]*)\]/g)].map((match) => match[1]);

    return segments.join('.');
}

function LocalizedField({
    name,
    label,
    defaultValue,
    error,
    required,
    multiline,
}: {
    name: string;
    label: string;
    defaultValue: LocalizedValue;
    error?: Errors;
    required?: boolean;
    multiline?: boolean;
}) {
    const { t } = useTranslation();

    return (
        <div className="grid gap-3 sm:grid-cols-2">
            <div className="grid gap-1.5">
                <Label htmlFor={`${name}-en`}>
                    {label} ({t('cms.locale.en')})
                </Label>
                {multiline ? (
                    <textarea
                        id={`${name}-en`}
                        name={`${name}[en]`}
                        rows={3}
                        defaultValue={defaultValue.en}
                        required={required}
                        className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                    />
                ) : (
                    <Input
                        id={`${name}-en`}
                        name={`${name}[en]`}
                        defaultValue={defaultValue.en}
                        required={required}
                    />
                )}
                <InputError message={error?.[`${errorKey(name)}.en`]} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor={`${name}-bn`}>
                    {label} ({t('cms.locale.bn')})
                </Label>
                {multiline ? (
                    <textarea
                        id={`${name}-bn`}
                        name={`${name}[bn]`}
                        rows={3}
                        defaultValue={defaultValue.bn ?? ''}
                        className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                    />
                ) : (
                    <Input
                        id={`${name}-bn`}
                        name={`${name}[bn]`}
                        defaultValue={defaultValue.bn ?? ''}
                    />
                )}
                <InputError message={error?.[`${errorKey(name)}.bn`]} />
            </div>
        </div>
    );
}

function CtaFieldGroup({
    name,
    label,
    defaultValue,
    error,
    required,
}: {
    name: string;
    label: string;
    defaultValue: CtaValue;
    error?: Errors;
    required?: boolean;
}) {
    const { t } = useTranslation();

    return (
        <fieldset className="space-y-3 rounded-lg border p-3">
            <legend className="px-1 text-sm font-medium">{label}</legend>
            <LocalizedField
                name={`${name}[label]`}
                label={t('cms.section_fields.cta_label')}
                defaultValue={defaultValue.label}
                error={error}
                required={required}
            />
            <div className="grid gap-1.5">
                <Label htmlFor={`${name}-href`}>
                    {t('cms.section_fields.cta_href')}
                </Label>
                <Input
                    id={`${name}-href`}
                    name={`${name}[href]`}
                    defaultValue={defaultValue.href}
                    required={required}
                    placeholder="register"
                />
                <InputError message={error?.[`${errorKey(name)}.href`]} />
            </div>
        </fieldset>
    );
}

/**
 * `media_position`/`media_fit` (Stage 7 addendum) — plain uncontrolled
 * selects, exactly like the section-kind picker in `section-dialog.tsx`.
 */
function MediaPositionSelect({
    name,
    defaultValue,
    options,
}: {
    name: string;
    defaultValue: unknown;
    options: string[];
}) {
    const { t } = useTranslation();
    const current = typeof defaultValue === 'string' ? defaultValue : '';

    return (
        <div className="grid gap-1.5">
            <Label htmlFor={name}>{t('cms.section_fields.media_position')}</Label>
            <select
                id={name}
                name={name}
                defaultValue={current}
                className="border-input bg-background h-9 rounded-md border px-2 text-sm"
            >
                <option value="">{t('cms.section_fields.media_position_default')}</option>
                {options.map((option) => (
                    <option key={option} value={option}>
                        {t(`cms.section_fields.media_position_${option}`)}
                    </option>
                ))}
            </select>
        </div>
    );
}

function MediaFitSelect({ name, defaultValue }: { name: string; defaultValue: unknown }) {
    const { t } = useTranslation();
    const current = typeof defaultValue === 'string' ? defaultValue : '';

    return (
        <div className="grid gap-1.5">
            <Label htmlFor={name}>{t('cms.section_fields.media_fit')}</Label>
            <select
                id={name}
                name={name}
                defaultValue={current}
                className="border-input bg-background h-9 rounded-md border px-2 text-sm"
            >
                <option value="">{t('cms.section_fields.media_fit_default')}</option>
                <option value="cover">{t('cms.section_fields.media_fit_cover')}</option>
                <option value="contain">{t('cms.section_fields.media_fit_contain')}</option>
            </select>
        </div>
    );
}

/**
 * A growable list of localized strings (`bullets`) or richer objects
 * (`items`, `steps`) — controlled, since the Form component only ever sees
 * whatever inputs exist in the DOM at submit time.
 */
function RepeatableSection<T>({
    label,
    help,
    items,
    onChange,
    makeEmpty,
    renderItem,
    min = 0,
    max = 20,
}: {
    label: string;
    help?: string;
    items: T[];
    onChange: (items: T[]) => void;
    makeEmpty: () => T;
    renderItem: (
        item: T,
        update: (patch: Partial<T>) => void,
        index: number,
    ) => React.ReactNode;
    min?: number;
    max?: number;
}) {
    const { t } = useTranslation();

    return (
        <fieldset className="space-y-3 rounded-lg border p-3">
            <legend className="px-1 text-sm font-medium">{label}</legend>
            {help && <p className="text-muted-foreground text-xs">{help}</p>}

            <div className="space-y-3">
                {items.map((item, index) => (
                    <div
                        key={index}
                        className="space-y-2 rounded-md border p-3"
                    >
                        <div className="flex items-center justify-between">
                            <span className="text-muted-foreground text-xs font-medium">
                                #{index + 1}
                            </span>
                            {items.length > min && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() =>
                                        onChange(
                                            items.filter((_, i) => i !== index),
                                        )
                                    }
                                >
                                    <X className="size-4" />
                                    {t('cms.section_fields.remove_item')}
                                </Button>
                            )}
                        </div>
                        {renderItem(
                            item,
                            (patch) =>
                                onChange(
                                    items.map((existing, i) =>
                                        i === index
                                            ? { ...existing, ...patch }
                                            : existing,
                                    ),
                                ),
                            index,
                        )}
                    </div>
                ))}
            </div>

            {items.length < max && (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => onChange([...items, makeEmpty()])}
                >
                    <Plus className="size-4" />
                    {t('cms.section_fields.add_item')}
                </Button>
            )}
        </fieldset>
    );
}

function ControlledLocalizedField({
    label,
    value,
    onChange,
    multiline,
}: {
    label: string;
    value: LocalizedValue;
    onChange: (value: LocalizedValue) => void;
    multiline?: boolean;
}) {
    const { t } = useTranslation();

    return (
        <div className="grid gap-2 sm:grid-cols-2">
            <div className="grid gap-1">
                <Label className="text-xs">
                    {label} ({t('cms.locale.en')})
                </Label>
                {multiline ? (
                    <textarea
                        rows={2}
                        value={value.en}
                        onChange={(e) =>
                            onChange({ ...value, en: e.target.value })
                        }
                        className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                    />
                ) : (
                    <Input
                        value={value.en}
                        onChange={(e) =>
                            onChange({ ...value, en: e.target.value })
                        }
                    />
                )}
            </div>
            <div className="grid gap-1">
                <Label className="text-xs">
                    {label} ({t('cms.locale.bn')})
                </Label>
                {multiline ? (
                    <textarea
                        rows={2}
                        value={value.bn ?? ''}
                        onChange={(e) =>
                            onChange({ ...value, bn: e.target.value })
                        }
                        className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                    />
                ) : (
                    <Input
                        value={value.bn ?? ''}
                        onChange={(e) =>
                            onChange({ ...value, bn: e.target.value })
                        }
                    />
                )}
            </div>
        </div>
    );
}

function hiddenLocalized(name: string, value: LocalizedValue) {
    return (
        <>
            <input type="hidden" name={`${name}[en]`} value={value.en} />
            <input type="hidden" name={`${name}[bn]`} value={value.bn ?? ''} />
        </>
    );
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Content = Record<string, any>;

type MediaProps = {
    media: MediaPickerItem[];
    canManageMedia: boolean;
};

export function SectionContentFields({
    kind,
    content,
    errors,
    media,
    canManageMedia,
}: {
    kind: string;
    content: Content;
    errors: Errors;
} & MediaProps) {
    const { t } = useTranslation();

    switch (kind) {
        case 'hero':
            return (
                <>
                    <LocalizedField
                        name="content[heading]"
                        label={t('cms.section_fields.heading')}
                        defaultValue={asLocalized(content.heading)}
                        error={errors}
                        required
                    />
                    <LocalizedField
                        name="content[subheading]"
                        label={t('cms.section_fields.subheading')}
                        defaultValue={asLocalized(content.subheading)}
                        error={errors}
                    />
                    <LocalizedField
                        name="content[body]"
                        label={t('cms.section_fields.body')}
                        defaultValue={asLocalized(content.body)}
                        error={errors}
                        multiline
                    />
                    <CtaFieldGroup
                        name="content[primary_cta]"
                        label={t('cms.section_fields.primary_cta')}
                        defaultValue={asCta(content.primary_cta)}
                        error={errors}
                        required
                    />
                    <CtaFieldGroup
                        name="content[secondary_cta]"
                        label={t('cms.section_fields.secondary_cta')}
                        defaultValue={asCta(content.secondary_cta)}
                        error={errors}
                    />
                    <fieldset className="space-y-3 rounded-lg border p-3">
                        <legend className="px-1 text-sm font-medium">
                            {t('cms.section_fields.image')}
                        </legend>
                        <MediaPicker
                            name="content[media_id]"
                            label={t('cms.section_fields.image')}
                            value={asMediaId(content.media_id)}
                            media={media}
                            canManage={canManageMedia}
                        />
                        <MediaPicker
                            name="content[mobile_media_id]"
                            label={t('cms.section_fields.mobile_image')}
                            value={asMediaId(content.mobile_media_id)}
                            media={media}
                            canManage={canManageMedia}
                        />
                        <div className="grid gap-3 sm:grid-cols-2">
                            <MediaPositionSelect
                                name="content[media_position]"
                                defaultValue={content.media_position}
                                options={['left', 'right', 'background']}
                            />
                            <MediaFitSelect
                                name="content[media_fit]"
                                defaultValue={content.media_fit}
                            />
                        </div>
                        <LocalizedField
                            name="content[media_alt_override]"
                            label={t('cms.section_fields.media_alt_override')}
                            defaultValue={asLocalized(content.media_alt_override)}
                            error={errors}
                        />
                    </fieldset>
                </>
            );

        case 'about':
            return (
                <>
                    <LocalizedField
                        name="content[heading]"
                        label={t('cms.section_fields.heading')}
                        defaultValue={asLocalized(content.heading)}
                        error={errors}
                        required
                    />
                    <LocalizedField
                        name="content[body]"
                        label={t('cms.section_fields.body')}
                        defaultValue={asLocalized(content.body)}
                        error={errors}
                        multiline
                        required
                    />
                    <MediaPicker
                        name="content[media_id]"
                        label={t('cms.section_fields.image')}
                        value={asMediaId(content.media_id)}
                        media={media}
                        canManage={canManageMedia}
                    />
                    <MediaPositionSelect
                        name="content[media_position]"
                        defaultValue={content.media_position}
                        options={['left', 'right']}
                    />
                    <MediaFitSelect name="content[media_fit]" defaultValue={content.media_fit} />
                </>
            );

        case 'platform_introduction':
        case 'dropshipping':
        case 'wholesale':
        case 'partner_websites':
        case 'supplier_opportunity':
            return (
                <ValuePropositionFields
                    content={content}
                    errors={errors}
                    media={media}
                    canManageMedia={canManageMedia}
                />
            );

        case 'benefits':
            return (
                <BenefitsFields
                    content={content}
                    errors={errors}
                    media={media}
                    canManageMedia={canManageMedia}
                />
            );

        case 'how_it_works':
            return (
                <HowItWorksFields
                    content={content}
                    errors={errors}
                    media={media}
                    canManageMedia={canManageMedia}
                />
            );

        case 'package_preview':
            return (
                <>
                    <LocalizedField
                        name="content[heading]"
                        label={t('cms.section_fields.heading')}
                        defaultValue={asLocalized(content.heading)}
                        error={errors}
                        required
                    />
                    <LocalizedField
                        name="content[body]"
                        label={t('cms.section_fields.body')}
                        defaultValue={asLocalized(content.body)}
                        error={errors}
                        multiline
                    />
                    <CtaFieldGroup
                        name="content[cta]"
                        label={t('cms.section_fields.cta')}
                        defaultValue={asCta(content.cta)}
                        error={errors}
                    />
                    <p className="text-muted-foreground text-xs">
                        {t('cms.section_fields.package_preview_help')}
                    </p>
                </>
            );

        case 'video':
            return (
                <>
                    <LocalizedField
                        name="content[heading]"
                        label={t('cms.section_fields.heading')}
                        defaultValue={asLocalized(content.heading)}
                        error={errors}
                    />
                    <LocalizedField
                        name="content[body]"
                        label={t('cms.section_fields.body')}
                        defaultValue={asLocalized(content.body)}
                        error={errors}
                        multiline
                    />
                    <div className="grid gap-1.5">
                        <Label htmlFor="video_url">
                            {t('cms.section_fields.video_url')}
                        </Label>
                        <Input
                            id="video_url"
                            name="content[video_url]"
                            defaultValue={typeof content.video_url === 'string' ? content.video_url : ''}
                            required
                            placeholder="https://www.youtube.com/watch?v=..."
                        />
                        <p className="text-muted-foreground text-xs">
                            {t('cms.section_fields.video_url_help')}
                        </p>
                        <InputError message={errors.video_url} />
                    </div>
                    <MediaPicker
                        name="content[poster_media_id]"
                        label={t('cms.section_fields.poster_image')}
                        value={asMediaId(content.poster_media_id)}
                        media={media}
                        canManage={canManageMedia}
                        clearable={false}
                    />
                </>
            );

        case 'testimonials':
            return (
                <TestimonialsFields
                    content={content}
                    errors={errors}
                    media={media}
                    canManageMedia={canManageMedia}
                />
            );

        case 'clients_partners':
            return (
                <ClientsPartnersFields
                    content={content}
                    errors={errors}
                    media={media}
                    canManageMedia={canManageMedia}
                />
            );

        case 'faq':
            return <FaqFields content={content} errors={errors} />;

        case 'cta':
            return (
                <>
                    <LocalizedField
                        name="content[heading]"
                        label={t('cms.section_fields.heading')}
                        defaultValue={asLocalized(content.heading)}
                        error={errors}
                        required
                    />
                    <LocalizedField
                        name="content[body]"
                        label={t('cms.section_fields.body')}
                        defaultValue={asLocalized(content.body)}
                        error={errors}
                        multiline
                    />
                    <CtaFieldGroup
                        name="content[primary_cta]"
                        label={t('cms.section_fields.primary_cta')}
                        defaultValue={asCta(content.primary_cta)}
                        error={errors}
                        required
                    />
                    <CtaFieldGroup
                        name="content[secondary_cta]"
                        label={t('cms.section_fields.secondary_cta')}
                        defaultValue={asCta(content.secondary_cta)}
                        error={errors}
                    />
                    <MediaPicker
                        name="content[media_id]"
                        label={t('cms.section_fields.image')}
                        value={asMediaId(content.media_id)}
                        media={media}
                        canManage={canManageMedia}
                    />
                    <MediaPositionSelect
                        name="content[media_position]"
                        defaultValue={content.media_position}
                        options={['left', 'right', 'background']}
                    />
                </>
            );

        case 'footer':
            return (
                <>
                    <LocalizedField
                        name="content[tagline]"
                        label={t('cms.section_fields.tagline')}
                        defaultValue={asLocalized(content.tagline)}
                        error={errors}
                    />
                    <LocalizedField
                        name="content[copyright_text]"
                        label={t('cms.section_fields.copyright_text')}
                        defaultValue={asLocalized(content.copyright_text)}
                        error={errors}
                        required
                    />
                </>
            );

        case 'header_nav':
            return (
                <p className="text-muted-foreground text-sm">
                    {t('cms.section_fields.header_nav_help')}
                </p>
            );

        default:
            return (
                <p className="text-muted-foreground text-sm">
                    {t('cms.section_fields.no_schema')}
                </p>
            );
    }
}

const ALLOWED_ICONS = [
    'shield-check',
    'wallet',
    'truck',
    'store',
    'users',
    'package',
    'trending-up',
    'globe',
    'lock',
    'clock',
    'layers',
    'banknote',
];

function ValuePropositionFields({
    content,
    errors,
    media,
    canManageMedia,
}: {
    content: Content;
    errors: Errors;
} & MediaProps) {
    const { t } = useTranslation();
    const [bullets, setBullets] = useState<LocalizedValue[]>([]);

    useEffect(() => {
        const raw = Array.isArray(content.bullets) ? content.bullets : [];
        setBullets(raw.length > 0 ? raw.map(asLocalized) : []);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [content]);

    return (
        <>
            <LocalizedField
                name="content[heading]"
                label={t('cms.section_fields.heading')}
                defaultValue={asLocalized(content.heading)}
                error={errors}
                required
            />
            <LocalizedField
                name="content[body]"
                label={t('cms.section_fields.body')}
                defaultValue={asLocalized(content.body)}
                error={errors}
                multiline
            />

            <RepeatableSection
                label={t('cms.section_fields.bullets')}
                items={bullets}
                onChange={setBullets}
                makeEmpty={emptyLocalized}
                max={8}
                renderItem={(bullet, update) => (
                    <ControlledLocalizedField
                        label={t('cms.section_fields.bullet')}
                        value={bullet}
                        onChange={update}
                    />
                )}
            />
            {bullets.map((bullet, index) =>
                hiddenLocalized(`content[bullets][${index}]`, bullet),
            )}

            <CtaFieldGroup
                name="content[cta]"
                label={t('cms.section_fields.cta')}
                defaultValue={asCta(content.cta)}
                error={errors}
            />

            <MediaPicker
                name="content[media_id]"
                label={t('cms.section_fields.image')}
                value={asMediaId(content.media_id)}
                media={media}
                canManage={canManageMedia}
            />
            <MediaPositionSelect
                name="content[media_position]"
                defaultValue={content.media_position}
                options={['left', 'right']}
            />
            <MediaFitSelect name="content[media_fit]" defaultValue={content.media_fit} />
        </>
    );
}

function BenefitsFields({
    content,
    errors,
    media,
    canManageMedia,
}: {
    content: Content;
    errors: Errors;
} & MediaProps) {
    const { t } = useTranslation();
    const [items, setItems] = useState<
        { icon: string; heading: LocalizedValue; body: LocalizedValue; media_id: string | null }[]
    >([]);

    useEffect(() => {
        const raw = Array.isArray(content.items) ? content.items : [];
        setItems(
            raw.length > 0
                ? raw.map((item: Content) => ({
                      icon: item.icon ?? ALLOWED_ICONS[0],
                      heading: asLocalized(item.heading),
                      body: asLocalized(item.body),
                      media_id: asMediaId(item.media_id),
                  }))
                : [
                      {
                          icon: ALLOWED_ICONS[0],
                          heading: emptyLocalized(),
                          body: emptyLocalized(),
                          media_id: null,
                      },
                  ],
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [content]);

    return (
        <>
            <LocalizedField
                name="content[heading]"
                label={t('cms.section_fields.heading')}
                defaultValue={asLocalized(content.heading)}
                error={errors}
                required
            />

            <RepeatableSection
                label={t('cms.section_fields.benefit_items')}
                items={items}
                onChange={setItems}
                min={1}
                max={8}
                makeEmpty={() => ({
                    icon: ALLOWED_ICONS[0],
                    heading: emptyLocalized(),
                    body: emptyLocalized(),
                    media_id: null,
                })}
                renderItem={(item, update, index) => (
                    <>
                        <div className="grid gap-1">
                            <Label
                                htmlFor={`benefit-icon-${index}`}
                                className="text-xs"
                            >
                                {t('cms.section_fields.icon')}
                            </Label>
                            <select
                                id={`benefit-icon-${index}`}
                                value={item.icon}
                                onChange={(e) =>
                                    update({ icon: e.target.value })
                                }
                                className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                            >
                                {ALLOWED_ICONS.map((icon) => (
                                    <option key={icon} value={icon}>
                                        {icon}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <ControlledLocalizedField
                            label={t('cms.section_fields.heading')}
                            value={item.heading}
                            onChange={(heading) => update({ heading })}
                        />
                        <ControlledLocalizedField
                            label={t('cms.section_fields.body')}
                            value={item.body}
                            onChange={(body) => update({ body })}
                        />
                        <MediaPicker
                            name={`content[items][${index}][media_id]`}
                            label={t('cms.section_fields.image')}
                            value={item.media_id}
                            media={media}
                            canManage={canManageMedia}
                        />
                    </>
                )}
            />

            {items.map((item, index) => (
                <div key={index}>
                    <input
                        type="hidden"
                        name={`content[items][${index}][icon]`}
                        value={item.icon}
                    />
                    {hiddenLocalized(
                        `content[items][${index}][heading]`,
                        item.heading,
                    )}
                    {hiddenLocalized(
                        `content[items][${index}][body]`,
                        item.body,
                    )}
                </div>
            ))}
        </>
    );
}

function HowItWorksFields({
    content,
    errors,
    media,
    canManageMedia,
}: {
    content: Content;
    errors: Errors;
} & MediaProps) {
    const { t } = useTranslation();
    const [steps, setSteps] = useState<
        { heading: LocalizedValue; body: LocalizedValue; media_id: string | null }[]
    >([]);

    useEffect(() => {
        const raw = Array.isArray(content.steps) ? content.steps : [];
        setSteps(
            raw.length > 0
                ? raw.map((step: Content) => ({
                      heading: asLocalized(step.heading),
                      body: asLocalized(step.body),
                      media_id: asMediaId(step.media_id),
                  }))
                : [
                      { heading: emptyLocalized(), body: emptyLocalized(), media_id: null },
                      { heading: emptyLocalized(), body: emptyLocalized(), media_id: null },
                  ],
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [content]);

    return (
        <>
            <LocalizedField
                name="content[heading]"
                label={t('cms.section_fields.heading')}
                defaultValue={asLocalized(content.heading)}
                error={errors}
                required
            />

            <RepeatableSection
                label={t('cms.section_fields.steps')}
                items={steps}
                onChange={setSteps}
                min={2}
                max={8}
                makeEmpty={() => ({
                    heading: emptyLocalized(),
                    body: emptyLocalized(),
                    media_id: null,
                })}
                renderItem={(step, update, index) => (
                    <>
                        <ControlledLocalizedField
                            label={t('cms.section_fields.heading')}
                            value={step.heading}
                            onChange={(heading) => update({ heading })}
                        />
                        <ControlledLocalizedField
                            label={t('cms.section_fields.body')}
                            value={step.body}
                            onChange={(body) => update({ body })}
                        />
                        <MediaPicker
                            name={`content[steps][${index}][media_id]`}
                            label={t('cms.section_fields.image')}
                            value={step.media_id}
                            media={media}
                            canManage={canManageMedia}
                        />
                    </>
                )}
            />

            {steps.map((step, index) => (
                <div key={index}>
                    <input
                        type="hidden"
                        name={`content[steps][${index}][step_number]`}
                        value={index + 1}
                    />
                    {hiddenLocalized(
                        `content[steps][${index}][heading]`,
                        step.heading,
                    )}
                    {hiddenLocalized(
                        `content[steps][${index}][body]`,
                        step.body,
                    )}
                </div>
            ))}
        </>
    );
}

function TestimonialsFields({
    content,
    errors,
    media,
    canManageMedia,
}: {
    content: Content;
    errors: Errors;
} & MediaProps) {
    const { t } = useTranslation();
    const [items, setItems] = useState<
        {
            quote: LocalizedValue;
            author_name: string;
            author_role: LocalizedValue;
            media_id: string | null;
        }[]
    >([]);

    useEffect(() => {
        const raw = Array.isArray(content.items) ? content.items : [];
        setItems(
            raw.length > 0
                ? raw.map((item: Content) => ({
                      quote: asLocalized(item.quote),
                      author_name: typeof item.author_name === 'string' ? item.author_name : '',
                      author_role: asLocalized(item.author_role),
                      media_id: asMediaId(item.media_id),
                  }))
                : [
                      {
                          quote: emptyLocalized(),
                          author_name: '',
                          author_role: emptyLocalized(),
                          media_id: null,
                      },
                  ],
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [content]);

    return (
        <>
            <LocalizedField
                name="content[heading]"
                label={t('cms.section_fields.heading')}
                defaultValue={asLocalized(content.heading)}
                error={errors}
            />

            <RepeatableSection
                label={t('cms.section_fields.testimonial_items')}
                items={items}
                onChange={setItems}
                min={1}
                max={12}
                makeEmpty={() => ({
                    quote: emptyLocalized(),
                    author_name: '',
                    author_role: emptyLocalized(),
                    media_id: null,
                })}
                renderItem={(item, update, index) => (
                    <>
                        <ControlledLocalizedField
                            label={t('cms.section_fields.quote')}
                            value={item.quote}
                            onChange={(quote) => update({ quote })}
                            multiline
                        />
                        <div className="grid gap-1">
                            <Label className="text-xs">
                                {t('cms.section_fields.author_name')}
                            </Label>
                            <Input
                                value={item.author_name}
                                onChange={(e) => update({ author_name: e.target.value })}
                            />
                        </div>
                        <ControlledLocalizedField
                            label={t('cms.section_fields.author_role')}
                            value={item.author_role}
                            onChange={(author_role) => update({ author_role })}
                        />
                        <MediaPicker
                            name={`content[items][${index}][media_id]`}
                            label={t('cms.section_fields.avatar')}
                            value={item.media_id}
                            media={media}
                            canManage={canManageMedia}
                        />
                    </>
                )}
            />

            {items.map((item, index) => (
                <div key={index}>
                    {hiddenLocalized(`content[items][${index}][quote]`, item.quote)}
                    <input
                        type="hidden"
                        name={`content[items][${index}][author_name]`}
                        value={item.author_name}
                    />
                    {hiddenLocalized(
                        `content[items][${index}][author_role]`,
                        item.author_role,
                    )}
                </div>
            ))}
        </>
    );
}

function ClientsPartnersFields({
    content,
    errors,
    media,
    canManageMedia,
}: {
    content: Content;
    errors: Errors;
} & MediaProps) {
    const { t } = useTranslation();
    const [items, setItems] = useState<{ name: string; media_id: string | null }[]>([]);

    useEffect(() => {
        const raw = Array.isArray(content.items) ? content.items : [];
        setItems(
            raw.length > 0
                ? raw.map((item: Content) => ({
                      name: typeof item.name === 'string' ? item.name : '',
                      media_id: asMediaId(item.media_id),
                  }))
                : [{ name: '', media_id: null }],
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [content]);

    return (
        <>
            <LocalizedField
                name="content[heading]"
                label={t('cms.section_fields.heading')}
                defaultValue={asLocalized(content.heading)}
                error={errors}
            />

            <RepeatableSection
                label={t('cms.section_fields.logo_items')}
                items={items}
                onChange={setItems}
                min={1}
                max={24}
                makeEmpty={() => ({ name: '', media_id: null })}
                renderItem={(item, update, index) => (
                    <>
                        <div className="grid gap-1">
                            <Label className="text-xs">{t('cms.section_fields.client_name')}</Label>
                            <Input
                                value={item.name}
                                onChange={(e) => update({ name: e.target.value })}
                            />
                        </div>
                        <MediaPicker
                            name={`content[items][${index}][media_id]`}
                            label={t('cms.section_fields.logo')}
                            value={item.media_id}
                            media={media}
                            canManage={canManageMedia}
                            clearable={false}
                        />
                    </>
                )}
            />

            {items.map((item, index) => (
                <input
                    key={index}
                    type="hidden"
                    name={`content[items][${index}][name]`}
                    value={item.name}
                />
            ))}
        </>
    );
}

function FaqFields({ content, errors }: { content: Content; errors: Errors }) {
    const { t } = useTranslation();
    const [items, setItems] = useState<
        { question: LocalizedValue; answer: LocalizedValue }[]
    >([]);

    useEffect(() => {
        const raw = Array.isArray(content.items) ? content.items : [];
        setItems(
            raw.length > 0
                ? raw.map((item: Content) => ({
                      question: asLocalized(item.question),
                      answer: asLocalized(item.answer),
                  }))
                : [{ question: emptyLocalized(), answer: emptyLocalized() }],
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [content]);

    return (
        <>
            <LocalizedField
                name="content[heading]"
                label={t('cms.section_fields.heading')}
                defaultValue={asLocalized(content.heading)}
                error={errors}
                required
            />

            <RepeatableSection
                label={t('cms.section_fields.faq_items')}
                items={items}
                onChange={setItems}
                min={1}
                max={20}
                makeEmpty={() => ({
                    question: emptyLocalized(),
                    answer: emptyLocalized(),
                })}
                renderItem={(item, update) => (
                    <>
                        <ControlledLocalizedField
                            label={t('cms.section_fields.question')}
                            value={item.question}
                            onChange={(question) => update({ question })}
                        />
                        <ControlledLocalizedField
                            label={t('cms.section_fields.answer')}
                            value={item.answer}
                            onChange={(answer) => update({ answer })}
                            multiline
                        />
                    </>
                )}
            />

            {items.map((item, index) => (
                <div key={index}>
                    {hiddenLocalized(
                        `content[items][${index}][question]`,
                        item.question,
                    )}
                    {hiddenLocalized(
                        `content[items][${index}][answer]`,
                        item.answer,
                    )}
                </div>
            ))}
        </>
    );
}
