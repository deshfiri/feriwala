import {
    Banknote,
    Clock,
    Globe,
    Layers,
    Lock,
    Package,
    ShieldCheck,
    Store,
    TrendingUp,
    Truck,
    Users,
    Wallet,
    type LucideIcon,
} from 'lucide-react';
import SectionContainer from '@/components/public/section-container';

/** Mirrors App\Domain\Cms\Support\SectionContentValidator::ALLOWED_ICONS exactly. */
const ICONS: Record<string, LucideIcon> = {
    'shield-check': ShieldCheck,
    wallet: Wallet,
    truck: Truck,
    store: Store,
    users: Users,
    package: Package,
    'trending-up': TrendingUp,
    globe: Globe,
    lock: Lock,
    clock: Clock,
    layers: Layers,
    banknote: Banknote,
};

type BenefitsContent = {
    heading: string;
    items: { icon: string; heading: string; body?: string | null }[];
};

export default function Benefits({ content }: { content: BenefitsContent }) {
    return (
        <SectionContainer>
            <h2 className="text-center text-3xl font-semibold tracking-tight text-balance">
                {content.heading}
            </h2>

            <div className="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {content.items.map((item, index) => {
                    const Icon = ICONS[item.icon] ?? ShieldCheck;

                    return (
                        <div
                            key={index}
                            className="bg-card border-border rounded-xl border p-5"
                        >
                            <div className="bg-brand-subtle text-brand inline-flex size-10 items-center justify-center rounded-lg">
                                <Icon aria-hidden="true" className="size-5" />
                            </div>
                            <h3 className="mt-4 font-semibold">
                                {item.heading}
                            </h3>
                            {item.body && (
                                <p className="text-muted-foreground mt-1 text-sm">
                                    {item.body}
                                </p>
                            )}
                        </div>
                    );
                })}
            </div>
        </SectionContainer>
    );
}
