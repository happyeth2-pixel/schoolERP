import InputError from '@/Components/InputError';
import { UserCircleIcon } from '@heroicons/react/24/outline';
import { useForm, usePage } from '@inertiajs/react';

export default function UpdatePhotoForm({ className = '' }) {
    const user = usePage().props.auth.user;
    const { data, setData, post, processing, errors, reset } = useForm({ photo: null });

    const submit = (e) => {
        e.preventDefault();
        post(route('profile.photo'), {
            forceFormData: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <section className={className}>
            <header>
                <h2 className="text-lg font-medium text-gray-900 dark:text-gray-100">Profile Photo</h2>
                <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    Shown next to your name in the header. Only you can change it.
                </p>
            </header>

            <form onSubmit={submit} className="mt-4 flex items-center gap-4">
                {user.profile_photo_url ? (
                    <img src={user.profile_photo_url} alt="" className="h-16 w-16 rounded-full object-cover" />
                ) : (
                    <UserCircleIcon className="h-16 w-16 text-gray-300 dark:text-gray-600" />
                )}
                <div>
                    <input
                        type="file"
                        accept="image/*"
                        onChange={(e) => setData('photo', e.target.files[0])}
                        className="block text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm dark:text-gray-300 dark:file:bg-gray-700 dark:file:text-gray-200"
                    />
                    <InputError message={errors.photo} className="mt-1" />
                    <button type="submit" disabled={!data.photo || processing} className="mt-2 text-sm text-indigo-600 hover:underline disabled:opacity-50 dark:text-indigo-400">
                        Upload
                    </button>
                </div>
            </form>
        </section>
    );
}
