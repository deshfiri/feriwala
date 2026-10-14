import { Camera, FileText, Layers, ListChecks, Type } from 'lucide-react';
import StatusPill from '@/components/status-pill';
import { useTranslation } from '@/hooks/use-translation';
import type { KycOutline, KycOutlineSlot } from '@/types';

/**
 * A batch drawn as the tree it is: groups, the options inside each, and the
 * evidence slots inside each option.
 *
 *   Batch → Verification group → Option → Evidence slot
 *
 * Read-only, and built by the server from the batch's live structure, so it is
 * what the builder will publish. It exists because the builder's forms show one
 * level at a time; this shows all of them at once, with the rule that decides how
 * each group is satisfied. A National ID's front and back are two slots of one
 * option — drawn that way — not two options.
 */
export default function StructureOutline({
    outline,
    selfie,
}: {
    outline: KycOutline;
    selfie: 'disabled' | 'optional' | 'required';
}) {
    const { t } = useTranslation();

    const rule = (group: KycOutline['groups'][number]): string =>
        group.rule === 'any'
            ? t('kyc.builder.rule_any')
            : group.rule === 'min'
              ? t('kyc.builder.rule_min', { count: group.minimum ?? 1 })
              : t('kyc.builder.rule_all');

    const kind = (slot: KycOutlineSlot): string =>
        slot.evidence === 'value' && slot.sensitive
            ? t('kyc.builder.kind_sensitive')
            : t(`kyc.batch_builder.evidence_types.${slot.evidence}`);

    const icon = (slot: KycOutlineSlot) =>
        slot.evidence === 'value' || slot.evidence === 'declaration' ? (
            <Type className="size-3.5 shrink-0" aria-hidden="true" />
        ) : (
            <FileText className="size-3.5 shrink-0" aria-hidden="true" />
        );

    if (outline.groups.length === 0 && selfie === 'disabled') {
        return (
            <p className="text-muted-foreground text-sm">
                {t('kyc.builder.outline_empty')}
            </p>
        );
    }

    return (
        <ol className="space-y-4" aria-label={t('kyc.builder.outline_title')}>
            {outline.groups.map((group, groupIndex) => (
                <li key={`${group.name}-${groupIndex}`} className="space-y-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <Layers
                            className="text-muted-foreground size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span className="text-sm font-semibold">
                            {groupIndex + 1}. {group.name}
                        </span>
                        <StatusPill tone="info" label={rule(group)} />
                        {!group.required && (
                            <StatusPill
                                tone="neutral"
                                label={t('kyc.batch_form.optional')}
                            />
                        )}
                    </div>

                    <ul className="border-border ml-2 space-y-2 border-l pl-4">
                        {group.options.map((option, optionIndex) => (
                            <li
                                key={`${option.name}-${optionIndex}`}
                                className="space-y-1"
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <ListChecks
                                        className="text-muted-foreground size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <span className="text-sm font-medium">
                                        {option.name}
                                    </span>
                                    {group.rule === 'all' &&
                                        !option.required && (
                                            <span className="text-muted-foreground text-xs">
                                                {t('kyc.batch_form.optional')}
                                            </span>
                                        )}
                                </div>

                                <ul className="border-border text-muted-foreground ml-2 space-y-0.5 border-l pl-4 text-xs">
                                    {option.slots.map((slot, slotIndex) => (
                                        <li
                                            key={`${slot.label}-${slotIndex}`}
                                            className="flex flex-wrap items-center gap-1.5"
                                        >
                                            {icon(slot)}
                                            <span className="text-foreground">
                                                {slot.label}
                                            </span>
                                            <span>· {kind(slot)}</span>
                                            <span>
                                                ·{' '}
                                                {t(
                                                    slot.required
                                                        ? 'kyc.batch_form.required'
                                                        : 'kyc.batch_form.optional',
                                                )}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </li>
                        ))}
                    </ul>
                </li>
            ))}

            {selfie !== 'disabled' && (
                <li className="flex flex-wrap items-center gap-2">
                    <Camera
                        className="text-muted-foreground size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <span className="text-sm font-semibold">
                        {t('kyc.builder.outline_selfie')}
                    </span>
                    <StatusPill
                        tone={selfie === 'required' ? 'info' : 'neutral'}
                        label={t(
                            selfie === 'required'
                                ? 'kyc.batch_form.required'
                                : 'kyc.batch_form.optional',
                        )}
                    />
                </li>
            )}
        </ol>
    );
}
