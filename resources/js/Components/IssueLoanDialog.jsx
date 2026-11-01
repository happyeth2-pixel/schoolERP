import Modal from '@/Components/Modal';
import { Button, Field, Input } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

// Who is this copy going to? A student (no login needed -- a staff member issues it on their behalf, the
// same staff-assisted shape as the rest of this app) or a staff member. Search-as-you-type, picked from a
// short result list -- never a dropdown of the whole school.
export default function IssueLoanDialog({ open, copyId, bookTitle, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        book_copy_id: copyId,
        borrower_type: '',
        borrower_id: '',
    });
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [selected, setSelected] = useState(null);
    const [searching, setSearching] = useState(false);

    useEffect(() => { setData('book_copy_id', copyId); }, [copyId]);

    useEffect(() => {
        if (selected || query.trim().length < 2) { setResults([]); return undefined; }
        setSearching(true);
        const handle = setTimeout(() => {
            fetch(route('library.borrower-search', { q: query.trim() }), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((r) => (r.ok ? r.json() : []))
                .then(setResults)
                .finally(() => setSearching(false));
        }, 300);
        return () => clearTimeout(handle);
    }, [query, selected]);

    const pick = (person) => {
        setSelected(person);
        setData((d) => ({ ...d, borrower_type: person.type, borrower_id: person.id }));
        clearErrors();
    };

    const clearSelection = () => {
        setSelected(null);
        setData((d) => ({ ...d, borrower_type: '', borrower_id: '' }));
        setQuery('');
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('library.issue'), {
            preserveScroll: true,
            onSuccess: () => { reset(); setSelected(null); setQuery(''); onClose(); },
        });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="issue-loan-form">
                    <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{t('library.issue_title', { book: bookTitle })}</h2>

                    {selected ? (
                        <div className="flex items-center justify-between rounded-xl border-2 border-brand-600 bg-brand-50 p-4 dark:border-brand-400 dark:bg-brand-900/30" data-testid="selected-borrower">
                            <div>
                                <p className="text-base font-semibold text-stone-900 dark:text-stone-100">{selected.name}</p>
                                {selected.detail && <p className="text-sm text-stone-600 dark:text-stone-400">{selected.detail}</p>}
                            </div>
                            <Button type="button" variant="quiet" size="md" onClick={clearSelection}>{t('library.change_person')}</Button>
                        </div>
                    ) : (
                        <Field id="borrower_search" label={t('library.borrower_label')} hint={t('library.borrower_hint')} error={errors.borrower_id}>
                            <Input value={query} onChange={(e) => setQuery(e.target.value)} autoComplete="off" placeholder={t('library.borrower_placeholder')} />
                        </Field>
                    )}

                    {!selected && query.trim().length >= 2 && (
                        <ul className="divide-y divide-stone-200 rounded-xl border-2 border-stone-200 dark:divide-stone-700 dark:border-stone-700" data-testid="borrower-results">
                            {searching && <li className="p-3 text-sm text-stone-600 dark:text-stone-400">{t('common.searching')}</li>}
                            {!searching && results.length === 0 && <li className="p-3 text-sm text-stone-600 dark:text-stone-400">{t('library.no_people_found')}</li>}
                            {results.map((r) => (
                                <li key={`${r.type}-${r.id}`}>
                                    <button type="button" className="flex w-full items-center justify-between gap-3 p-3 text-left hover:bg-stone-50 dark:hover:bg-stone-700" onClick={() => pick(r)}>
                                        <span className="font-medium text-stone-900 dark:text-stone-100">{r.name}</span>
                                        <span className="text-sm text-stone-600 dark:text-stone-400">{r.detail}</span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}

                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" busy={processing} disabled={!selected}>{t('library.issue_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
