import { Form, router } from '@inertiajs/react';
import { Check, LayoutGrid, RotateCcw, Save } from 'lucide-react';
import { useState } from 'react';
import type { CSSProperties } from 'react';
import BrandingController from '@/actions/App/Http/Controllers/Admin/BrandingController';
import FormField from '@/components/forms/form-field';
import Notice from '@/components/notice';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import {
    ACCENT_PRESETS,
    HEX_COLOR_PATTERN,
    accentPreviewStyle,
    isReadableAccent,
} from '@/lib/accent';
import { cn } from '@/lib/utils';

export type AccentState = {
    /** The accent in use now, `#rrggbb`. */
    color: string;
    default: string;
    is_custom: boolean;
    readable: boolean;
};

/**
 * Choose the accent colour every panel paints its current menu row, badges,
 * focus rings and highlights in. Primary buttons stay near-black: the accent
 * is the accent, not the workhorse.
 *
 * A preset or any colour from the picker, previewed live on real controls
 * before it is saved. Nothing changes for anybody until Save; the server then
 * validates the value and decides the label colours itself.
 */
export default function AccentColorPicker({ accent }: { accent: AccentState }) {
    const { t } = useTranslation();
    const [color, setColor] = useState(accent.color);

    const isValid = HEX_COLOR_PATTERN.test(color);
    const previewColor = isValid ? color.toLowerCase() : accent.color;
    const isUnchanged = previewColor === accent.color.toLowerCase();

    const restore = () => {
        if (!window.confirm(t('branding.accent.restore_confirm'))) {
            return;
        }

        router.delete(BrandingController.destroyAccent.url(), {
            preserveScroll: true,
            onSuccess: () => setColor(accent.default),
        });
    };

    return (
        <SectionCard
            title={t('branding.accent.title')}
            description={t('branding.accent.description')}
            actions={
                <StatusPill
                    tone={accent.is_custom ? 'info' : 'neutral'}
                    label={t(
                        accent.is_custom
                            ? 'branding.accent.state_custom'
                            : 'branding.state_default',
                    )}
                />
            }
        >
            <Form
                {...BrandingController.updateAccent.form()}
                options={{ preserveScroll: true }}
                className="space-y-6"
            >
                {({ errors, processing }) => (
                    <>
                        <fieldset className="space-y-3">
                            <legend className="text-sm font-medium">
                                {t('branding.accent.presets')}
                            </legend>
                            <div className="flex flex-wrap gap-2.5">
                                {ACCENT_PRESETS.map((preset) => {
                                    const isSelected =
                                        previewColor === preset.hex;

                                    return (
                                        <button
                                            key={preset.hex}
                                            type="button"
                                            onClick={() => setColor(preset.hex)}
                                            aria-pressed={isSelected}
                                            aria-label={t(
                                                `branding.accent.names.${preset.name}`,
                                            )}
                                            title={t(
                                                `branding.accent.names.${preset.name}`,
                                            )}
                                            style={
                                                accentPreviewStyle(
                                                    preset.hex,
                                                ) as CSSProperties
                                            }
                                            className={cn(
                                                'flex size-10 items-center justify-center rounded-full border-2 border-transparent bg-(--brand-base) text-(--brand-on) transition-transform hover:scale-105',
                                                isSelected &&
                                                    'ring-foreground ring-offset-card ring-2 ring-offset-2',
                                            )}
                                        >
                                            {isSelected && (
                                                <Check
                                                    aria-hidden="true"
                                                    className="size-4"
                                                />
                                            )}
                                        </button>
                                    );
                                })}
                            </div>
                        </fieldset>

                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start">
                            <div className="grid gap-2">
                                <span className="text-sm font-medium">
                                    {t('branding.accent.custom')}
                                </span>
                                <input
                                    type="color"
                                    value={previewColor}
                                    onChange={(event) =>
                                        setColor(event.target.value)
                                    }
                                    aria-label={t('branding.accent.custom')}
                                    className="border-input bg-card h-(--control-height) w-16 cursor-pointer rounded-lg border p-1"
                                />
                            </div>

                            <FormField
                                label={t('branding.accent.hex')}
                                hint={t('branding.accent.hex_hint')}
                                error={errors.accent_color}
                                className="flex-1 sm:max-w-56"
                            >
                                {(field) => (
                                    <Input
                                        {...field}
                                        name="accent_color"
                                        value={color}
                                        onChange={(event) =>
                                            setColor(event.target.value.trim())
                                        }
                                        maxLength={7}
                                        spellCheck={false}
                                        autoComplete="off"
                                        className="font-mono uppercase"
                                        required
                                    />
                                )}
                            </FormField>
                        </div>

                        <AccentPreview color={previewColor} />

                        {!isReadableAccent(previewColor) && (
                            <Notice tone="warning" inset>
                                {t('branding.accent.low_contrast')}
                            </Notice>
                        )}

                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                type="submit"
                                disabled={processing || !isValid || isUnchanged}
                            >
                                <Save className="size-4" aria-hidden="true" />
                                {t('branding.accent.save')}
                            </Button>

                            {accent.is_custom && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={restore}
                                >
                                    <RotateCcw
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {t('branding.accent.restore')}
                                </Button>
                            )}
                        </div>
                    </>
                )}
            </Form>
        </SectionCard>
    );
}

/**
 * The accent on the things that actually carry it — the current menu row with
 * its edge rail, a badge, a focused field and an avatar —
 * inside a box that paints its own accent (`.accent-scope`), so the rest of
 * the page keeps the saved colour until Save.
 */
function AccentPreview({ color }: { color: string }) {
    const { t } = useTranslation();

    return (
        <div className="space-y-2">
            <p className="text-sm font-medium">
                {t('branding.accent.preview')}
            </p>
            <div
                aria-hidden="true"
                style={accentPreviewStyle(color) as CSSProperties}
                className="accent-scope bg-surface-subtle flex flex-wrap items-center gap-4 rounded-lg border p-4"
            >
                <span className="bg-brand-subtle text-brand relative inline-flex h-9 w-52 items-center gap-2.5 rounded-lg px-2.5 text-sm font-semibold">
                    <span className="bg-brand absolute inset-y-2 left-0 w-0.5 rounded-full" />
                    <LayoutGrid className="size-4" />
                    {t('branding.accent.preview_nav')}
                </span>

                <span className="bg-brand-subtle text-brand text-2xs rounded-full px-1.5 py-0.5 leading-none font-semibold tracking-wide uppercase">
                    {t('branding.accent.preview_badge')}
                </span>

                <span className="ring-ring ring-offset-surface-subtle bg-card inline-flex h-9 items-center rounded-lg border px-3 text-sm ring-2 ring-offset-2">
                    {t('branding.accent.preview_focus')}
                </span>

                <span className="bg-brand-subtle text-brand flex size-8 items-center justify-center rounded-full text-xs font-semibold">
                    AB
                </span>
            </div>
        </div>
    );
}
