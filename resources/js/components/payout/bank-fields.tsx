import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

type Bank = { bank_code: string; name: string; slug: string };
type LocationOption = {
    id: number;
    source_id: string;
    name_en: string;
    name_bn: string;
};
type Branch = {
    routing_number: string;
    name: string;
    branch_code: string | null;
};

/**
 * Bank -> District -> Branch, the flow the bank payout-method step requires.
 * `bank_code` and `routing_number` are submitted as top-level fields (never
 * inside `details`) -- which bank/branch a method uses is not itself
 * sensitive, and the server resolves them to the real `bd_bank_id`/
 * `bd_bank_branch_id` foreign keys.
 */
export default function BankFields({
    banksUrl,
    divisionsUrl,
    districtsUrlFor,
    branchesUrlFor,
    errors,
    defaultBankCode,
    defaultRoutingNumber,
}: {
    banksUrl: string;
    divisionsUrl: string;
    districtsUrlFor: (divisionSourceId: string) => string;
    branchesUrlFor: (bankCode: string, districtId: number) => string;
    errors: Record<string, string | undefined>;
    defaultBankCode?: string;
    defaultRoutingNumber?: string;
}) {
    const { t, locale } = useTranslation();
    const [banks, setBanks] = useState<Bank[]>([]);
    const [divisions, setDivisions] = useState<LocationOption[]>([]);
    const [districts, setDistricts] = useState<LocationOption[]>([]);
    const [branches, setBranches] = useState<Branch[]>([]);
    const [bankCode, setBankCode] = useState(defaultBankCode ?? '');
    const [divisionId, setDivisionId] = useState('');
    const [districtId, setDistrictId] = useState<number | ''>('');
    const [routingNumber, setRoutingNumber] = useState(
        defaultRoutingNumber ?? '',
    );

    useEffect(() => {
        fetch(banksUrl, { headers: { Accept: 'application/json' } })
            .then((response) => response.json())
            .then((body: { data: Bank[] }) => setBanks(body.data))
            .catch(() => setBanks([]));

        fetch(divisionsUrl, { headers: { Accept: 'application/json' } })
            .then((response) => response.json())
            .then((body: { data: LocationOption[] }) => setDivisions(body.data))
            .catch(() => setDivisions([]));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (divisionId === '') {
            setDistricts([]);

            return;
        }

        fetch(districtsUrlFor(divisionId), {
            headers: { Accept: 'application/json' },
        })
            .then((response) => response.json())
            .then((body: { data: LocationOption[] }) => setDistricts(body.data))
            .catch(() => setDistricts([]));
    }, [divisionId, districtsUrlFor]);

    useEffect(() => {
        if (bankCode === '' || districtId === '') {
            setBranches([]);

            return;
        }

        fetch(branchesUrlFor(bankCode, districtId), {
            headers: { Accept: 'application/json' },
        })
            .then((response) => response.json())
            .then((body: { data: Branch[] }) => setBranches(body.data))
            .catch(() => setBranches([]));
    }, [bankCode, districtId, branchesUrlFor]);

    return (
        <>
            <div className="grid gap-2">
                <Label htmlFor="payout-bank">
                    {t('supplier.payout_methods.fields.bank')}
                </Label>
                <select
                    id="payout-bank"
                    name="bank_code"
                    required
                    className={controlClass}
                    value={bankCode}
                    onChange={(event) => {
                        setBankCode(event.target.value);
                        setRoutingNumber('');
                    }}
                >
                    <option value="" disabled>
                        {t('common.actions.select')}
                    </option>
                    {banks.map((bank) => (
                        <option key={bank.bank_code} value={bank.bank_code}>
                            {bank.name}
                        </option>
                    ))}
                </select>
                <InputError message={errors.bank_code} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="payout-division">
                    {t('address.fields.division')}
                </Label>
                <select
                    id="payout-division"
                    className={controlClass}
                    value={divisionId}
                    onChange={(event) => {
                        setDivisionId(event.target.value);
                        setDistrictId('');
                        setRoutingNumber('');
                    }}
                >
                    <option value="" disabled>
                        {t('common.actions.select')}
                    </option>
                    {divisions.map((division) => (
                        <option
                            key={division.source_id}
                            value={division.source_id}
                        >
                            {locale === 'bn'
                                ? division.name_bn
                                : division.name_en}
                        </option>
                    ))}
                </select>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="payout-district">
                    {t('supplier.payout_methods.fields.district')}
                </Label>
                <select
                    id="payout-district"
                    className={controlClass}
                    value={districtId}
                    disabled={divisionId === ''}
                    onChange={(event) => {
                        setDistrictId(Number(event.target.value));
                        setRoutingNumber('');
                    }}
                >
                    <option value="" disabled>
                        {t('common.actions.select')}
                    </option>
                    {districts.map((district) => (
                        <option key={district.id} value={district.id}>
                            {locale === 'bn'
                                ? district.name_bn
                                : district.name_en}
                        </option>
                    ))}
                </select>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="payout-branch">
                    {t('supplier.payout_methods.fields.branch')}
                </Label>
                <select
                    id="payout-branch"
                    name="routing_number"
                    required
                    className={controlClass}
                    value={routingNumber}
                    disabled={districtId === ''}
                    onChange={(event) => setRoutingNumber(event.target.value)}
                >
                    <option value="" disabled>
                        {t('common.actions.select')}
                    </option>
                    {branches.map((branch) => (
                        <option
                            key={branch.routing_number}
                            value={branch.routing_number}
                        >
                            {branch.name}
                            {branch.branch_code
                                ? ` (${branch.branch_code})`
                                : ''}
                        </option>
                    ))}
                </select>
                <InputError message={errors.routing_number} />
            </div>
        </>
    );
}
