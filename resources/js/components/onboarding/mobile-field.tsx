import FormField from '@/components/forms/form-field';
import NativeSelect from '@/components/forms/native-select';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';

export type DiallingCode = { value: string; label: string; code: string };

/**
 * A mobile number from any country (D39): the country picks the calling code,
 * the number is typed without it. The server turns the two into E.164 and is
 * the only judge of whether they make a valid number.
 */
export default function MobileField({
    label,
    countries,
    country,
    number,
    onCountryChange,
    onNumberChange,
    error,
    help,
    disabled = false,
    namePrefix = 'mobile',
}: {
    label: string;
    countries: DiallingCode[];
    country: string;
    number: string;
    onCountryChange: (value: string) => void;
    onNumberChange: (value: string) => void;
    error?: string;
    help?: string;
    disabled?: boolean;
    namePrefix?: string;
}) {
    const { t } = useTranslation();
    const selected = countries.find((option) => option.value === country);

    return (
        <FormField
            label={label}
            description={help ?? t('onboarding.mobile.help')}
            error={error}
            required
        >
            {(props) => (
                <div className="grid grid-cols-[minmax(0,10rem)_minmax(0,1fr)] gap-2">
                    <NativeSelect
                        name={`${namePrefix}_country`}
                        aria-label={t('onboarding.mobile.country')}
                        value={country}
                        disabled={disabled}
                        onChange={(event) =>
                            onCountryChange(event.target.value)
                        }
                    >
                        {countries.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <div className="relative">
                        {selected && (
                            <span className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm tabular-nums">
                                +{selected.code}
                            </span>
                        )}
                        <Input
                            {...props}
                            name={`${namePrefix}_number`}
                            type="tel"
                            inputMode="tel"
                            autoComplete="tel-national"
                            value={number}
                            disabled={disabled}
                            onChange={(event) =>
                                onNumberChange(event.target.value)
                            }
                            aria-label={t('onboarding.mobile.number')}
                            style={
                                selected
                                    ? {
                                          paddingLeft: `${selected.code.length + 2.2}ch`,
                                      }
                                    : undefined
                            }
                        />
                    </div>
                </div>
            )}
        </FormField>
    );
}
