/**
 * Accent colours offered as one-click presets under Admin → Branding. The
 * first is the shipped accent (`AccentColor::DEFAULT_HEX`).
 */
export const ACCENT_PRESETS = [
    { name: 'crimson', hex: '#e11d48' },
    { name: 'orange', hex: '#ea580c' },
    { name: 'amber', hex: '#d97706' },
    { name: 'emerald', hex: '#059669' },
    { name: 'teal', hex: '#0d9488' },
    { name: 'blue', hex: '#4680ff' },
    { name: 'indigo', hex: '#4f46e5' },
    { name: 'violet', hex: '#7c3aed' },
    { name: 'slate', hex: '#1d2630' },
] as const;

export const HEX_COLOR_PATTERN = /^#[0-9a-f]{6}$/i;

const LIGHT_TEXT = '#ffffff';
const DARK_TEXT = '#1d2630';
const DARK_LIFT_PERCENT = 25;
const MINIMUM_TEXT_CONTRAST = 4.5;

type Channels = [number, number, number];

function channels(hex: string): Channels {
    return [
        parseInt(hex.slice(1, 3), 16),
        parseInt(hex.slice(3, 5), 16),
        parseInt(hex.slice(5, 7), 16),
    ];
}

function luminance([red, green, blue]: Channels): number {
    const [r, g, b] = [red, green, blue].map((channel) => {
        const value = channel / 255;

        return value <= 0.03928
            ? value / 12.92
            : ((value + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function contrast(first: Channels, second: Channels): number {
    const a = luminance(first);
    const b = luminance(second);

    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

function readableTextOn(background: Channels): string {
    return contrast(background, channels(LIGHT_TEXT)) >=
        contrast(background, channels(DARK_TEXT))
        ? LIGHT_TEXT
        : DARK_TEXT;
}

/**
 * The custom properties a preview needs to paint `hex` exactly as the shell
 * will once it is saved.
 *
 * For the live preview only. It mirrors `App\Domain\Settings\AccentColor`,
 * which stays the authority: what every page actually paints is what the
 * server derives from the saved value.
 */
export function accentPreviewStyle(hex: string): Record<string, string> {
    const base = channels(hex);
    const keep = 100 - DARK_LIFT_PERCENT;
    const lifted = base.map((channel) =>
        Math.floor((channel * keep + 255 * DARK_LIFT_PERCENT + 50) / 100),
    ) as Channels;

    return {
        '--brand-base': hex,
        '--brand-on': readableTextOn(base),
        '--brand-lifted-on': readableTextOn(lifted),
    };
}

/**
 * Whether text drawn in `hex` on a white card meets WCAG AA. Mirrors
 * `AccentColor::isReadableAsText()`.
 */
export function isReadableAccent(hex: string): boolean {
    return (
        contrast(channels(hex), channels(LIGHT_TEXT)) >= MINIMUM_TEXT_CONTRAST
    );
}
