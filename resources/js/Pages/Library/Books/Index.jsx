import BookDialog from '@/Components/BookDialog';
import { Button, Card, EmptyState, FlashMessages, Input, Page } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BookOpenIcon, PlusIcon } from '@heroicons/react/24/outline';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

export default function Index({ books, search, canManage }) {
    const { t } = useTranslation();
    const [dialog, setDialog] = useState(null);
    const [q, setQ] = useState(search ?? '');

    const submitSearch = (e) => {
        e.preventDefault();
        router.get(route('library-books.index'), { search: q }, { preserveState: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('library.catalog_title')}</h2>}>
            <Page
                title={t('library.catalog_title')}
                description={t('library.catalog_desc')}
                width="default"
                actions={canManage && <Button icon={PlusIcon} onClick={() => setDialog('new')}>{t('library.add_book_button')}</Button>}
            >
                <FlashMessages errorKeys={['delete']} />

                <Card>
                    <form onSubmit={submitSearch} className="flex gap-2">
                        <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('library.search_placeholder')} />
                        <Button type="submit" variant="secondary">{t('common.search')}</Button>
                    </form>
                </Card>

                {books.length === 0 ? (
                    <Card><EmptyState icon={BookOpenIcon} title={t('library.no_books_title')}>{canManage ? t('library.no_books_body_manage') : t('library.no_books_body')}</EmptyState></Card>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2">
                        {books.map((b) => (
                            <Link key={b.id} href={route('library-books.show', b.id)} data-testid="book-card" className="block rounded-2xl border-2 border-gray-200 bg-white p-5 hover:border-indigo-400 dark:border-gray-700 dark:bg-gray-800">
                                <h3 className="text-lg font-bold text-gray-900 dark:text-gray-100">{b.title}</h3>
                                {b.author && <p className="text-sm text-gray-600 dark:text-gray-400">{b.author}</p>}
                                <p className="mt-2 text-base text-gray-700 dark:text-gray-300">{t('library.copies_available', { available: b.available_count, total: b.copies_count })}</p>
                            </Link>
                        ))}
                    </div>
                )}
            </Page>
            {canManage && <BookDialog open={dialog !== null} book={null} onClose={() => setDialog(null)} />}
        </AuthenticatedLayout>
    );
}
