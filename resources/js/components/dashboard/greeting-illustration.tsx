import { cn } from '@/lib/utils';

/**
 * The storefront scene beside a dashboard greeting, after Isomorphic: a phone
 * with a shop awning, shopping bags and a parcel on a soft ground.
 *
 * Decorative only — hidden from assistive technology, and painted entirely in
 * theme tokens, so it follows light and dark mode and a brand change without
 * a second asset to keep in step.
 */
export default function GreetingIllustration({
    className,
}: {
    className?: string;
}) {
    return (
        <svg
            viewBox="0 0 240 190"
            aria-hidden="true"
            focusable="false"
            className={cn('h-auto', className)}
        >
            {/* Ground blob */}
            <path
                className="fill-brand-subtle"
                d="M52 34c26-26 86-30 122-8 34 21 58 64 46 104-11 38-58 56-104 54-47-2-90-22-101-60C4 88 26 60 52 34Z"
            />
            <ellipse
                className="fill-foreground/10"
                cx="122"
                cy="176"
                rx="92"
                ry="9"
            />

            {/* Phone */}
            <rect
                className="fill-chart-4"
                x="84"
                y="22"
                width="76"
                height="150"
                rx="14"
            />
            <rect
                className="fill-card"
                x="90"
                y="34"
                width="64"
                height="128"
                rx="8"
            />
            <rect
                className="fill-chart-4/60"
                x="112"
                y="26"
                width="20"
                height="4"
                rx="2"
            />

            {/* Awning */}
            <path className="fill-brand" d="M86 46h72l-6 22H92Z" />
            <path
                className="fill-card"
                d="M101 46h9l-2 22h-8ZM124 46h9l1 22h-9Z"
            />
            <path className="fill-card/70" d="M147 46h7l-4 22h-6Z" />
            <path
                className="fill-brand"
                d="M92 68a6 6 0 0 0 12 0 6 6 0 0 0 12 0 6 6 0 0 0 12 0 6 6 0 0 0 12 0 6 6 0 0 0 12 0Z"
            />

            {/* Shop window and door */}
            <rect
                className="fill-chart-2/20"
                x="98"
                y="84"
                width="22"
                height="20"
                rx="3"
            />
            <rect
                className="fill-chart-2/60"
                x="126"
                y="84"
                width="20"
                height="44"
                rx="3"
            />
            <circle className="fill-card" cx="141" cy="107" r="1.8" />
            <rect
                className="fill-chart-3/70"
                x="98"
                y="110"
                width="22"
                height="4"
                rx="2"
            />
            <rect
                className="fill-muted-foreground/30"
                x="98"
                y="140"
                width="48"
                height="4"
                rx="2"
            />
            <rect
                className="fill-muted-foreground/20"
                x="98"
                y="148"
                width="32"
                height="4"
                rx="2"
            />

            {/* Parcel */}
            <rect
                className="fill-warning"
                x="30"
                y="124"
                width="46"
                height="46"
                rx="5"
            />
            <path
                className="fill-warning-subtle/70"
                d="M48 124h10v20l-5-3-5 3Z"
            />

            {/* Shopping bags */}
            <path
                className="stroke-brand fill-none"
                strokeWidth="3"
                strokeLinecap="round"
                d="M174 118v-8a10 10 0 0 1 20 0v8"
            />
            <path className="fill-brand" d="M164 116h40l-4 56h-32Z" />
            <path
                className="fill-brand-foreground/25"
                d="M168 116h6l-2 56h-4Z"
            />

            <path
                className="stroke-chart-2 fill-none"
                strokeWidth="3"
                strokeLinecap="round"
                d="M200 140v-6a8 8 0 0 1 16 0v6"
            />
            <path className="fill-chart-2" d="M192 138h32l-3 34h-26Z" />

            {/* Sparkles */}
            <path
                className="fill-chart-3"
                d="M44 58l3 7 7 3-7 3-3 7-3-7-7-3 7-3Z"
            />
            <path
                className="fill-chart-5"
                d="M196 40l2 5 5 2-5 2-2 5-2-5-5-2 5-2Z"
            />
            <circle className="fill-warning" cx="206" cy="84" r="4" />
            <circle className="fill-brand/50" cx="62" cy="96" r="3" />
        </svg>
    );
}
