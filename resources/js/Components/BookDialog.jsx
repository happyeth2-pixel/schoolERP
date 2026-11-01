import Modal from '@/Components/Modal';
import { Button, Field, Input } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function BookDialog({ open, book, onClose }) {
    const { t } = useTranslation();
    const editing = Boolean(book);
    const { data, setData, post, put, processing, errors, reset } = useForm({
        title: book?.title ?? '',
        author: book?.author ?? '',
        isbn: book?.isbn ?? '',
        category: book?.category ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        const onSuccess = () => { reset(); onClose(); };
        if (editing) {
            put(route('library-books.update', book.id), { preserveScroll: true, onSuccess });
        } else {
            post(route('library-books.store'), { onSuccess });
        }
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="book-form">
                    <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{editing ? t('library.edit_book_title') : t('library.add_book_title')}</h2>
                    <Field id="book_title" label={t('library.book_title_label')} error={errors.title}>
                        <Input value={data.title} onChange={(e) => setData('title', e.target.value)} maxLength={255} autoComplete="off" />
                    </Field>
                    <Field id="book_author" label={t('library.book_author_label')} optional error={errors.author}>
                        <Input value={data.author} onChange={(e) => setData('author', e.target.value)} maxLength={255} />
                    </Field>
                    <Field id="book_isbn" label={t('library.book_isbn_label')} optional error={errors.isbn}>
                        <Input value={data.isbn} onChange={(e) => setData('isbn', e.target.value)} maxLength={50} />
                    </Field>
                    <Field id="book_category" label={t('library.book_category_label')} optional error={errors.category}>
                        <Input value={data.category} onChange={(e) => setData('category', e.target.value)} maxLength={100} />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" busy={processing} disabled={!data.title}>{t('library.save_book_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
