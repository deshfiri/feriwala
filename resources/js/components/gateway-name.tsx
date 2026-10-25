import { useState } from 'react';

/**
 * Gateway name → logo file in `public/images`. A gateway with no entry, or whose
 * file is missing, shows its text label instead.
 */
const GATEWAY_LOGOS: Record<string, string> = {
    eps: '/images/eps_logo.png',
    bkash: '/images/bkash_logo.png',
    nagad: '/images/nagad_logo.png',
    sslcommerz: '/images/sslcommerz_logo.png',
    surjopay: '/images/surjopay_logo.png',
    amarpay: '/images/amarpay_logo.png',
    stripe: '/images/stripe_logo.png',
    paypal: '/images/paypal_logo.png',
};

/**
 * A gateway's name, or its own logo where one is available.
 *
 * The label stays as the image's alt text, so a screen reader and the radio's own
 * label still say which gateway this is.
 */
export default function GatewayName({
    name,
    label,
    className = 'h-7',
}: {
    name: string;
    label: string;
    className?: string;
}) {
    const [failed, setFailed] = useState(false);
    const logo = GATEWAY_LOGOS[name];

    if (!logo || failed) {
        return <span className="font-medium">{label}</span>;
    }

    return (
        <img
            src={logo}
            alt={label}
            onError={() => setFailed(true)}
            className={`${className} w-auto object-contain`}
        />
    );
}
