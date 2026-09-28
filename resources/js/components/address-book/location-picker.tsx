import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none disabled:opacity-50';

export type LocationOption = {
    id: number;
    source_id: string;
    name_en: string;
    name_bn: string;
};

export type LocationChain = {
    division: LocationOption | null;
    district: LocationOption | null;
    upazila: LocationOption | null;
    union: LocationOption | null;
};

type ParentLevel = 'division' | 'district' | 'upazila';

async function fetchOptions(url: string): Promise<LocationOption[]> {
    const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        return [];
    }

    const body = (await response.json()) as { data: LocationOption[] };

    return body.data;
}

function findOption(
    options: LocationOption[],
    id: string,
): LocationOption | null {
    return options.find((option) => String(option.id) === id) ?? null;
}

/**
 * The cascading Division -> District -> Upazila -> optional Union select
 * every address form uses, fetching each level's children from the cached
 * lookup endpoints (§39: never the whole directory on one page load).
 *
 * `divisionsUrl`/`childrenUrl` are passed in rather than imported here, so
 * this component carries no guard-specific route import and renders
 * identically inside the Erp and the Supplier address forms.
 */
export default function LocationPicker({
    initial,
    errors,
    divisionsUrl,
    childrenUrl,
}: {
    initial?: LocationChain;
    errors?: Partial<
        Record<
            'division_id' | 'district_id' | 'upazila_id' | 'union_id',
            string
        >
    >;
    divisionsUrl: string;
    childrenUrl: (parentType: ParentLevel, parentSourceId: string) => string;
}) {
    const { t, locale } = useTranslation();
    const label = (option: LocationOption) =>
        locale === 'bn' ? option.name_bn : option.name_en;

    const [divisions, setDivisions] = useState<LocationOption[]>([]);
    const [districts, setDistricts] = useState<LocationOption[]>([]);
    const [upazilas, setUpazilas] = useState<LocationOption[]>([]);
    const [unions, setUnions] = useState<LocationOption[]>([]);

    const [division, setDivision] = useState<LocationOption | null>(
        initial?.division ?? null,
    );
    const [district, setDistrict] = useState<LocationOption | null>(
        initial?.district ?? null,
    );
    const [upazila, setUpazila] = useState<LocationOption | null>(
        initial?.upazila ?? null,
    );
    const [union, setUnion] = useState<LocationOption | null>(
        initial?.union ?? null,
    );

    useEffect(() => {
        void fetchOptions(divisionsUrl).then(setDivisions);
    }, [divisionsUrl]);

    useEffect(() => {
        if (division === null) {
            setDistricts([]);

            return;
        }

        void fetchOptions(childrenUrl('division', division.source_id)).then(
            setDistricts,
        );
    }, [division, childrenUrl]);

    useEffect(() => {
        if (district === null) {
            setUpazilas([]);

            return;
        }

        void fetchOptions(childrenUrl('district', district.source_id)).then(
            setUpazilas,
        );
    }, [district, childrenUrl]);

    useEffect(() => {
        if (upazila === null) {
            setUnions([]);

            return;
        }

        void fetchOptions(childrenUrl('upazila', upazila.source_id)).then(
            setUnions,
        );
    }, [upazila, childrenUrl]);

    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
                <Label htmlFor="address-division">
                    {t('address.fields.division')}
                </Label>
                <select
                    id="address-division"
                    name="division_id"
                    required
                    className={controlClass}
                    value={division?.id ?? ''}
                    onChange={(event) => {
                        setDivision(findOption(divisions, event.target.value));
                        setDistrict(null);
                        setUpazila(null);
                        setUnion(null);
                    }}
                >
                    <option value="" disabled>
                        {t('address.select.placeholder')}
                    </option>
                    {divisions.map((option) => (
                        <option key={option.id} value={option.id}>
                            {label(option)}
                        </option>
                    ))}
                </select>
                <InputError message={errors?.division_id} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="address-district">
                    {t('address.fields.district')}
                </Label>
                <select
                    id="address-district"
                    name="district_id"
                    required
                    disabled={division === null}
                    className={controlClass}
                    value={district?.id ?? ''}
                    onChange={(event) => {
                        setDistrict(findOption(districts, event.target.value));
                        setUpazila(null);
                        setUnion(null);
                    }}
                >
                    <option value="" disabled>
                        {t('address.select.placeholder')}
                    </option>
                    {districts.map((option) => (
                        <option key={option.id} value={option.id}>
                            {label(option)}
                        </option>
                    ))}
                </select>
                <InputError message={errors?.district_id} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="address-upazila">
                    {t('address.fields.upazila')}
                </Label>
                <select
                    id="address-upazila"
                    name="upazila_id"
                    required
                    disabled={district === null}
                    className={controlClass}
                    value={upazila?.id ?? ''}
                    onChange={(event) => {
                        setUpazila(findOption(upazilas, event.target.value));
                        setUnion(null);
                    }}
                >
                    <option value="" disabled>
                        {t('address.select.placeholder')}
                    </option>
                    {upazilas.map((option) => (
                        <option key={option.id} value={option.id}>
                            {label(option)}
                        </option>
                    ))}
                </select>
                <InputError message={errors?.upazila_id} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="address-union">
                    {t('address.fields.union')}
                </Label>
                <select
                    id="address-union"
                    name="union_id"
                    disabled={upazila === null}
                    className={controlClass}
                    value={union?.id ?? ''}
                    onChange={(event) =>
                        setUnion(findOption(unions, event.target.value))
                    }
                >
                    <option value="">{t('address.select.none')}</option>
                    {unions.map((option) => (
                        <option key={option.id} value={option.id}>
                            {label(option)}
                        </option>
                    ))}
                </select>
                <InputError message={errors?.union_id} />
            </div>
        </div>
    );
}
