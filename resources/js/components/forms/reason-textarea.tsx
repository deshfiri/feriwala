import { useState, type ChangeEvent, type ComponentProps } from 'react';
import { useTranslation } from '@/hooks/use-translation';
import { REASON_PRESETS, type ReasonContext } from '@/lib/reasons';
import { cn } from '@/lib/utils';

type Props = ComponentProps<'textarea'> & {
    /** Which ready-made reasons to offer; defaults to the general set. */
    context?: ReasonContext;
};

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

const OTHER = '__other';

/**
 * A reason, chosen rather than typed: a list of ready-made reasons for the part
 * of the system it is asked in, and "Other" to write one by hand.
 *
 * A drop-in for the `<textarea name="reason">` it replaces. Chosen from the
 * list, the reason is posted exactly as a typed one would be (a hidden field of
 * the same name); under "Other" the textarea appears with every prop it was
 * given. A controlled caller (`value` / `onChange`) is told the chosen text the
 * same way it is told typing, so nothing downstream changes.
 */
export default function ReasonTextarea({
    context = 'generic',
    name,
    required,
    id,
    value,
    defaultValue,
    onChange,
    className,
    ...rest
}: Props) {
    const { t } = useTranslation();

    const keys = REASON_PRESETS[context] ?? REASON_PRESETS.generic;
    const textOf = (key: string) => t(`reasons.items.${key}`);

    const initial = String(value ?? defaultValue ?? '');
    const initialKey = keys.find((key) => textOf(key) === initial);

    const [choice, setChoice] = useState<string>(
        initialKey ?? (initial !== '' ? OTHER : ''),
    );

    const preset = choice !== '' && choice !== OTHER ? textOf(choice) : null;

    const emit = (text: string) =>
        onChange?.({
            target: { value: text, name },
            currentTarget: { value: text, name },
        } as unknown as ChangeEvent<HTMLTextAreaElement>);

    const choose = (next: string) => {
        setChoice(next);
        emit(next === '' || next === OTHER ? '' : textOf(next));
    };

    return (
        <div className="space-y-2">
            <select
                id={id}
                value={choice}
                required={required && preset === null && choice !== OTHER}
                aria-invalid={rest['aria-invalid']}
                aria-describedby={rest['aria-describedby']}
                onChange={(event) => choose(event.target.value)}
                className={cn(controlClass, 'h-9 py-0')}
            >
                <option value="">{t('reasons.choose')}</option>
                {keys.map((key) => (
                    <option key={key} value={key}>
                        {textOf(key)}
                    </option>
                ))}
                <option value={OTHER}>{t('reasons.other')}</option>
            </select>

            {preset !== null && name !== undefined && (
                <input type="hidden" name={name} value={preset} />
            )}

            {choice === OTHER && (
                <textarea
                    {...rest}
                    name={name}
                    required={required}
                    rows={rest.rows ?? 3}
                    placeholder={t('reasons.write')}
                    value={value}
                    defaultValue={
                        value === undefined ? defaultValue : undefined
                    }
                    onChange={onChange}
                    className={cn(controlClass, className)}
                    // Typing is the point of "Other": go straight to it.
                    autoFocus
                />
            )}
        </div>
    );
}
