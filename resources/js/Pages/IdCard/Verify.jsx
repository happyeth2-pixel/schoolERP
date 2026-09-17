import { CheckCircleIcon, UserCircleIcon, XCircleIcon } from '@heroicons/react/24/outline';
import { Head } from '@inertiajs/react';

export default function Verify({ found, valid, name, status, photo_url, school }) {
    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-100 px-4 dark:bg-gray-900">
            <Head title="ID Verification" />

            <div className="w-full max-w-sm rounded-xl bg-white p-6 text-center shadow-md dark:bg-gray-800">
                {!found ? (
                    <>
                        <XCircleIcon className="mx-auto h-16 w-16 text-red-500" />
                        <p className="mt-3 text-lg font-semibold text-gray-900 dark:text-gray-100">Not a valid ID card</p>
                        <p className="mt-1 text-sm text-gray-500">This code doesn't match any issued ID card.</p>
                    </>
                ) : (
                    <>
                        {photo_url ? (
                            <img src={photo_url} alt={name} className="mx-auto h-24 w-24 rounded-md object-cover" />
                        ) : (
                            <UserCircleIcon className="mx-auto h-24 w-24 text-gray-300 dark:text-gray-600" />
                        )}
                        <p className="mt-3 text-lg font-semibold text-gray-900 dark:text-gray-100">{name}</p>
                        <p className="text-sm text-gray-500">{school}</p>

                        <div className={`mx-auto mt-4 flex w-fit items-center gap-2 rounded-full px-4 py-1.5 text-sm font-medium ${valid ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'}`}>
                            {valid ? <CheckCircleIcon className="h-5 w-5" /> : <XCircleIcon className="h-5 w-5" />}
                            {valid ? 'Valid — Active Student' : `Not valid — ${status}`}
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}
