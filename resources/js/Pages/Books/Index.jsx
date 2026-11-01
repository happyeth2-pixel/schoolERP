import Modal from '@/Components/Modal';
import { BackLink, Button, Card, EmptyState, Field, FlashMessages, Input, Page, Select, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BookOpenIcon, PlusIcon } from '@heroicons/react/24/outline';
import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

// The book catalog, grouped by grade since that's the whole point: Grade 1's books are not Grade 8's,
// each with its own size and price. Adding/editing is a small dialog (far fewer fields than a fee
// structure needs), selling a book to a specific student happens from that student's own profile
// (Students/Edit.jsx -> "Books"), not from here.

const money = (n) => Number(n).toLocaleString(undefined, { maximumFractionDigits: 2 });

function BookDialog({ open, onClose, book, branches, grades, academicYears }) {
    const { t } = useTranslation();
    const editing = Boolean(book);
    const current = academicYears.find((y) => y.is_current) ?? academicYears[0];
    const { data, setData, post, patch, processing, errors, reset } = useForm({
        branch_id: book?.branch_id ? String(book.branch_id) : (branches.length === 1 ? String(branches[0].id) : ''),
        grade_id: book?.grade_id ? String(book.grade_id) : '',
        academic_year_id: book?.academic_year_id ? String(book.academic_year_id) : (current ? String(current.id) : ''),
        title: book?.title ?? '',
        size: book?.size ?? '',
        price: book?.price ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => { reset(); onClose(); } };
        if (editing) patch(route('books.update', book.id), opts);
        else post(route('books.store'), opts);
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="md">
            <form onSubmit={submit} className="space-y-5 p-6">
                <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{editing ? t('books.edit_title') : t('books.add_title')}</h2>
                {!editing && branches.length > 1 && (
                    <Field id="book_branch" label={t('books.branch')} error={errors.branch_id}>
                        <Select value={data.branch_id} onChange={(e) => setData('branch_id', e.target.value)}>
                            <option value="">{t('enroll.choose')}</option>
                            {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                        </Select>
                    </Field>
                )}
                {!editing && (
                    <>
                        <Field id="book_grade" label={t('books.grade')} error={errors.grade_id}>
                            <Select value={data.grade_id} onChange={(e) => setData('grade_id', e.target.value)}>
                                <option value="">{t('enroll.choose')}</option>
                                {grades.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
                            </Select>
                        </Field>
                        <Field id="book_year" label={t('books.academic_year')} error={errors.academic_year_id}>
                            <Select value={data.academic_year_id} onChange={(e) => setData('academic_year_id', e.target.value)}>
                                {academicYears.map((y) => <option key={y.id} value={y.id}>{y.name}</option>)}
                            </Select>
                        </Field>
                    </>
                )}
                <Field id="book_title" label={t('books.book_title')} error={errors.title}>
                    <Input value={data.title} onChange={(e) => setData('title', e.target.value)} autoFocus autoComplete="off" maxLength={255} />
                </Field>
                <Field id="book_size" label={t('books.size')} hint={t('books.size_hint')} optional error={errors.size}>
                    <Input value={data.size} onChange={(e) => setData('size', e.target.value)} maxLength={100} autoComplete="off" />
                </Field>
                <Field id="book_price" label={t('books.price')} error={errors.price}>
                    <Input inputMode="decimal" value={data.price} onChange={(e) => setData('price', e.target.value)} autoComplete="off" />
                </Field>
                <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                    <Button type="submit" busy={processing} disabled={!data.title || !data.price || (!editing && (!data.branch_id || !data.grade_id))}>
                        {editing ? t('books.save_button') : t('books.add_button')}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

export default function Index({ books, branches, grades, academicYears }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const [ask, confirmDialog] = useConfirm();
    const [dialogFor, setDialogFor] = useState(null); // null closed, 'new', or a book object
    const current = academicYears.find((y) => y.is_current) ?? academicYears[0];
    const [yearId, setYearId] = useState(current ? String(current.id) : '');
    const multiBranch = branches.length > 1;

    const shown = books.filter((b) => yearId === '' || String(b.academic_year_id) === yearId);
    const byGrade = grades.map((g) => ({ grade: g, books: shown.filter((b) => b.grade_id === g.id).sort((a, b) => a.title.localeCompare(b.title)) }));
    const withBooks = byGrade.filter((x) => x.books.length > 0);

    const remove = async (book) => {
        const ok = await ask({
            title: t('books.remove_title', { title: book.title }),
            body: <p>{t('books.remove_body', { count: book.sold_count })}</p>,
            confirmLabel: t('books.remove_confirm'),
            tone: 'danger',
        });
        if (ok) router.delete(route('books.destroy', book.id), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('books.page_title')}</h2>}>
            <Page
                title={t('books.page_title')}
                description={t('books.page_desc')}
                width="default"
                back={<BackLink big={auth.role === 'teacher'} href={route('dashboard')}>{t('common.back')}</BackLink>}
                actions={<Button onClick={() => setDialogFor('new')} icon={PlusIcon}>{t('books.add_button')}</Button>}
            >
                <FlashMessages />

                {academicYears.length > 1 && (
                    <Card>
                        <p className="mb-2 text-base font-medium text-stone-900 dark:text-stone-100">{t('fee.year_filter')}</p>
                        <div role="radiogroup" aria-label={t('fee.year_filter')} className="flex flex-wrap gap-2">
                            {[...academicYears.map((y) => [String(y.id), y.name]), ['', t('fee.all_years')]].map(([value, label]) => (
                                <button key={value} type="button" role="radio" aria-checked={yearId === value} onClick={() => setYearId(value)} className={`min-h-[48px] rounded-lg border-2 px-4 text-base font-semibold ${yearId === value ? 'border-brand-700 bg-brand-700 text-white' : 'border-stone-300 bg-white text-stone-900 dark:border-stone-600 dark:bg-stone-800 dark:text-stone-100'}`}>{label}</button>
                            ))}
                        </div>
                    </Card>
                )}

                {withBooks.length === 0 && (
                    <Card><EmptyState icon={BookOpenIcon} title={t('books.none_title')} action={<Button onClick={() => setDialogFor('new')} icon={PlusIcon}>{t('books.add_button')}</Button>}>{t('books.none_body')}</EmptyState></Card>
                )}

                {withBooks.map(({ grade, books: list }) => (
                    <Card key={grade.id} as="section" aria-label={grade.name} data-testid="grade-card">
                        <h2 className="text-xl font-bold text-stone-900 dark:text-stone-100">{grade.name}</h2>
                        <ul className="mt-2 divide-y divide-stone-200 dark:divide-stone-700">
                            {list.map((b) => (
                                <li key={b.id} data-testid="book-row" className="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="min-w-0">
                                        <p className="text-lg font-semibold text-stone-900 dark:text-stone-100">{b.title}{b.size ? ` (${b.size})` : ''}</p>
                                        <p className="text-base text-stone-700 dark:text-stone-300">
                                            <span className="font-semibold">{money(b.price)} ETB</span> · {t('books.sold_n', { count: b.sold_count })}
                                            {yearId === '' ? ` · ${b.academic_year}` : ''}{multiBranch ? ` · ${b.branch}` : ''}
                                        </p>
                                    </div>
                                    <div className="flex gap-3">
                                        <Button variant="secondary" size="md" onClick={() => setDialogFor(b)}>{t('books.edit_button')}</Button>
                                        <Button variant="quiet" size="md" onClick={() => remove(b)}>{t('books.remove_button')}</Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </Card>
                ))}
            </Page>
            <BookDialog
                open={dialogFor !== null}
                onClose={() => setDialogFor(null)}
                book={dialogFor === 'new' ? null : dialogFor}
                branches={branches}
                grades={grades}
                academicYears={academicYears}
            />
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
