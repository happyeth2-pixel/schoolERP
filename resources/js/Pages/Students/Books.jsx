import { Alert, BackLink, Button, Card, CheckRow, EmptyState, Page } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BookOpenIcon } from '@heroicons/react/24/outline';
import { useForm } from '@inertiajs/react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';

// Selling specific books to one student: their own grade's book list, with what they already have marked
// and excluded from the picker -- so staff sell only what's missing, never the whole set again. One tap
// sells everything ticked, added to today's bill for this student (BookSales::sell).

const money = (n) => Number(n).toLocaleString(undefined, { maximumFractionDigits: 2 });

export default function Books({ student, books, result }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors } = useForm({ book_item_ids: [] });

    const remaining = books.filter((b) => !b.already_owned);
    const owned = books.filter((b) => b.already_owned);
    const total = useMemo(() => books.filter((b) => data.book_item_ids.includes(b.id)).reduce((sum, b) => sum + Number(b.price), 0), [data.book_item_ids, books]);

    const toggle = (id) => {
        setData('book_item_ids', data.book_item_ids.includes(id) ? data.book_item_ids.filter((x) => x !== id) : [...data.book_item_ids, id]);
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('students.books.store', student.id), { preserveScroll: true, onSuccess: () => setData('book_item_ids', []) });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('books.sell_title', { name: student.name })}</h2>}>
            <Page title={t('books.sell_title', { name: student.name })} description={t('books.sell_desc', { grade: student.grade })} width="narrow" back={<BackLink href={route('students.edit', student.id)}>{t('idcard.back_to_profile')}</BackLink>}>
                {result && result.purchased.length > 0 && (
                    <Alert tone="success" title={t('books.sold_title')}>{t('books.sold_body', { names: result.purchased.join(', '), invoice: result.invoice_number })}</Alert>
                )}
                {errors.book_item_ids && <Alert tone="danger">{errors.book_item_ids}</Alert>}

                {books.length === 0 ? (
                    <Card><EmptyState icon={BookOpenIcon} title={t('books.none_for_grade_title')}>{t('books.none_for_grade_body', { grade: student.grade })}</EmptyState></Card>
                ) : (
                    <form onSubmit={submit}>
                        <Card className="space-y-5">
                            {remaining.length === 0 ? (
                                <Alert tone="info">{t('books.all_owned')}</Alert>
                            ) : (
                                <div className="space-y-2">
                                    <p className="text-base font-semibold text-stone-900 dark:text-stone-100">{t('books.pick_books')}</p>
                                    {remaining.map((b) => (
                                        <CheckRow
                                            key={b.id}
                                            id={`book-${b.id}`}
                                            checked={data.book_item_ids.includes(b.id)}
                                            onChange={() => toggle(b.id)}
                                            label={b.size ? `${b.title} (${b.size})` : b.title}
                                            hint={`${money(b.price)} ETB`}
                                        />
                                    ))}
                                </div>
                            )}

                            {owned.length > 0 && (
                                <div className="space-y-1 border-t border-stone-200 pt-4 dark:border-stone-700">
                                    <p className="text-base font-semibold text-stone-900 dark:text-stone-100">{t('books.already_has')}</p>
                                    <p className="text-base text-stone-600 dark:text-stone-400">{owned.map((b) => b.title).join(', ')}</p>
                                </div>
                            )}

                            {data.book_item_ids.length > 0 && (
                                <p className="text-lg font-semibold text-stone-900 dark:text-stone-100" data-testid="book-sale-total">{t('books.total_x', { amount: money(total) })}</p>
                            )}

                            <Button type="submit" fullWidth busy={processing} disabled={data.book_item_ids.length === 0}>
                                {t('books.sell_button', { count: data.book_item_ids.length })}
                            </Button>
                        </Card>
                    </form>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
