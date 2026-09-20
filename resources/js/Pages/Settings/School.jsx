import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, usePage } from '@inertiajs/react';

export default function School({ school }) {
    const { flash } = usePage().props;
    const { data, setData, patch, processing, errors } = useForm({
        name: school.name ?? '',
        region: school.region ?? '',
        city: school.city ?? '',
        subcity: school.subcity ?? '',
        woreda: school.woreda ?? '',
        phone: school.phone ?? '',
        email: school.email ?? '',
        calendar_preference: school.calendar_preference ?? 'both',
        bank_name: school.bank_name ?? '',
        bank_account_number: school.bank_account_number ?? '',
        bank_account_name: school.bank_account_name ?? '',
        mobile_money_number: school.mobile_money_number ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        patch(route('school-settings.update'));
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">
                    School Profile
                </h2>
            }
        >
            <Head title="School Profile" />

            <div className="py-8">
                <div className="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
                    {flash?.success && (
                        <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}

                    <form
                        onSubmit={submit}
                        className="space-y-6 rounded-lg bg-white p-6 shadow-sm dark:bg-gray-800"
                    >
                        <div>
                            <InputLabel htmlFor="name" value="School Name" />
                            <TextInput
                                id="name"
                                className="mt-1 block w-full"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                            />
                            <InputError message={errors.name} className="mt-1" />
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="region" value="Region" />
                                <TextInput
                                    id="region"
                                    className="mt-1 block w-full"
                                    value={data.region}
                                    onChange={(e) => setData('region', e.target.value)}
                                />
                            </div>
                            <div>
                                <InputLabel htmlFor="city" value="City" />
                                <TextInput
                                    id="city"
                                    className="mt-1 block w-full"
                                    value={data.city}
                                    onChange={(e) => setData('city', e.target.value)}
                                />
                            </div>
                            <div>
                                <InputLabel htmlFor="subcity" value="Sub-city" />
                                <TextInput
                                    id="subcity"
                                    className="mt-1 block w-full"
                                    value={data.subcity}
                                    onChange={(e) => setData('subcity', e.target.value)}
                                />
                            </div>
                            <div>
                                <InputLabel htmlFor="woreda" value="Woreda" />
                                <TextInput
                                    id="woreda"
                                    className="mt-1 block w-full"
                                    value={data.woreda}
                                    onChange={(e) => setData('woreda', e.target.value)}
                                />
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="phone" value="Phone" />
                                <TextInput
                                    id="phone"
                                    className="mt-1 block w-full"
                                    value={data.phone}
                                    onChange={(e) => setData('phone', e.target.value)}
                                />
                            </div>
                            <div>
                                <InputLabel htmlFor="email" value="Email" />
                                <TextInput
                                    id="email"
                                    type="email"
                                    className="mt-1 block w-full"
                                    value={data.email}
                                    onChange={(e) => setData('email', e.target.value)}
                                />
                                <InputError message={errors.email} className="mt-1" />
                            </div>
                        </div>

                        <div>
                            <InputLabel value="Calendar Display" />
                            <div className="mt-2 flex gap-4">
                                {[
                                    ['ethiopian', 'Ethiopian only'],
                                    ['gregorian', 'Gregorian only'],
                                    ['both', 'Both'],
                                ].map(([value, label]) => (
                                    <label
                                        key={value}
                                        className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"
                                    >
                                        <input
                                            type="radio"
                                            name="calendar_preference"
                                            value={value}
                                            checked={data.calendar_preference === value}
                                            onChange={(e) => setData('calendar_preference', e.target.value)}
                                        />
                                        {label}
                                    </label>
                                ))}
                            </div>
                        </div>

                        <div>
                            <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-400">Payment Account</h3>
                            <p className="mb-3 text-sm text-gray-500">
                                Shown to parents when they're about to pay an invoice, so they know exactly where to send money.
                            </p>
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel htmlFor="bank_name" value="Bank Name" />
                                    <TextInput id="bank_name" className="mt-1 block w-full" value={data.bank_name} onChange={(e) => setData('bank_name', e.target.value)} />
                                </div>
                                <div>
                                    <InputLabel htmlFor="bank_account_number" value="Account Number" />
                                    <TextInput id="bank_account_number" className="mt-1 block w-full" value={data.bank_account_number} onChange={(e) => setData('bank_account_number', e.target.value)} />
                                </div>
                                <div>
                                    <InputLabel htmlFor="bank_account_name" value="Account Name" />
                                    <TextInput id="bank_account_name" className="mt-1 block w-full" value={data.bank_account_name} onChange={(e) => setData('bank_account_name', e.target.value)} />
                                </div>
                                <div>
                                    <InputLabel htmlFor="mobile_money_number" value="Mobile Money Number (Telebirr, etc.)" />
                                    <TextInput id="mobile_money_number" className="mt-1 block w-full" value={data.mobile_money_number} onChange={(e) => setData('mobile_money_number', e.target.value)} />
                                </div>
                            </div>
                        </div>

                        <PrimaryButton disabled={processing}>Save</PrimaryButton>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
