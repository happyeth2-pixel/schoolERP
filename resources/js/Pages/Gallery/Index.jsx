import { BackLink, Button, Card, EmptyState, Field, FlashMessages, Input, Page } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ArrowDownIcon, ArrowUpIcon, PhotoIcon, TrashIcon } from '@heroicons/react/24/outline';
import { router, useForm } from '@inertiajs/react';
import { useRef } from 'react';
import { useTranslation } from 'react-i18next';

// The owner/branch admin's photo gallery for the public website -- shown on the Home page's "School Life"
// section once at least one photo exists. Upload, reorder (swap with a neighbour), delete -- nothing more.
export default function Index({ images }) {
    const { t } = useTranslation();
    const fileRef = useRef(null);
    const { data, setData, post, processing, errors, reset } = useForm({ photo: null, caption: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('gallery.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => { reset(); if (fileRef.current) fileRef.current.value = ''; },
        });
    };

    const move = (image, direction) => {
        router.post(route('gallery.move', image.id), { direction }, { preserveScroll: true, preserveState: true });
    };

    const destroy = (image) => {
        if (confirm(t('gallery.confirm_delete'))) {
            router.delete(route('gallery.destroy', image.id), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('gallery.title')}</h2>}>
            <Page title={t('gallery.title')} description={t('gallery.desc')} back={<BackLink href={route('school-settings.edit')}>{t('common.back')}</BackLink>}>
                <FlashMessages />

                <Card>
                    <form onSubmit={submit} className="space-y-4" data-testid="gallery-upload-form">
                        <Field id="gallery_photo" label={t('gallery.upload_label')} error={errors.photo}>
                            <input
                                ref={fileRef}
                                id="gallery_photo"
                                type="file"
                                accept="image/jpeg,image/png"
                                onChange={(e) => setData('photo', e.target.files?.[0] ?? null)}
                                className="block w-full text-base text-stone-700 file:mr-4 file:rounded-lg file:border-0 file:bg-brand-600 file:px-4 file:py-2.5 file:text-sm file:font-semibold file:text-white hover:file:bg-brand-700 dark:text-stone-300"
                            />
                        </Field>
                        <Field id="gallery_caption" label={t('gallery.caption_label')} error={errors.caption}>
                            <Input id="gallery_caption" value={data.caption} onChange={(e) => setData('caption', e.target.value)} maxLength={160} />
                        </Field>
                        <Button type="submit" busy={processing} disabled={!data.photo}>{t('gallery.upload_button')}</Button>
                    </form>
                </Card>

                {images.length === 0 ? (
                    <Card><EmptyState icon={PhotoIcon} title={t('gallery.empty_title')}>{t('gallery.empty_body')}</EmptyState></Card>
                ) : (
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                        {images.map((img, i) => (
                            <div key={img.id} className="overflow-hidden rounded-xl border border-stone-200 bg-white dark:border-stone-700 dark:bg-stone-800" data-testid="gallery-image-row">
                                <div className="aspect-square bg-stone-100 dark:bg-stone-900">
                                    <img src={img.url} alt={img.caption || ''} className="h-full w-full object-cover" />
                                </div>
                                <div className="p-3">
                                    {img.caption && <p className="truncate text-sm text-stone-700 dark:text-stone-300">{img.caption}</p>}
                                    <div className="mt-2 flex items-center justify-between">
                                        <div className="flex gap-1">
                                            <button type="button" onClick={() => move(img, 'up')} disabled={i === 0} className="flex h-9 w-9 items-center justify-center rounded-lg text-stone-500 hover:bg-stone-100 disabled:opacity-30 dark:hover:bg-stone-700" aria-label={t('gallery.move_up')}>
                                                <ArrowUpIcon className="h-4 w-4" />
                                            </button>
                                            <button type="button" onClick={() => move(img, 'down')} disabled={i === images.length - 1} className="flex h-9 w-9 items-center justify-center rounded-lg text-stone-500 hover:bg-stone-100 disabled:opacity-30 dark:hover:bg-stone-700" aria-label={t('gallery.move_down')}>
                                                <ArrowDownIcon className="h-4 w-4" />
                                            </button>
                                        </div>
                                        <button type="button" onClick={() => destroy(img)} className="flex h-9 w-9 items-center justify-center rounded-lg text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30" aria-label={t('gallery.delete')}>
                                            <TrashIcon className="h-4 w-4" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
