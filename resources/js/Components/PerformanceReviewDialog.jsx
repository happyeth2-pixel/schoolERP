import Modal from '@/Components/Modal';
import { Button, ChoiceGroup, Field, FieldError, Select, TextArea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const RATINGS = [1, 2, 3, 4, 5];

export default function PerformanceReviewDialog({ open, employeeId, cycles, review, onClose }) {
    const { t } = useTranslation();
    const editing = Boolean(review);
    const { data, setData, post, put, processing, errors, reset } = useForm({
        cycle_id: review?.cycle_id ?? (cycles[0]?.id ?? ''),
        rating: review?.rating ?? '',
        comments: review?.comments ?? '',
        action: 'finalize',
    });

    const submit = (actionValue) => (e) => {
        e.preventDefault();
        setData('action', actionValue);
        const onSuccess = () => { reset(); onClose(); };
        const options = { preserveScroll: true, onSuccess };
        if (editing) {
            put(route('performance-reviews.update', review.id), options);
        } else {
            post(route('performance-reviews.store', employeeId), options);
        }
    };

    const ratingOptions = RATINGS.map((n) => ({ value: n, label: t(`performance.rating_${n}`) }));

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form className="space-y-6 p-6" data-testid="performance-review-form">
                    <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{editing ? t('performance.edit_review_title') : t('performance.add_review_title')}</h2>

                    {!editing && (
                        <Field id="pr_cycle" label={t('performance.cycle_label')} error={errors.cycle_id}>
                            <Select id="pr_cycle" value={data.cycle_id} onChange={(e) => setData('cycle_id', e.target.value)}>
                                {cycles.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </Select>
                        </Field>
                    )}
                    <div>
                        <ChoiceGroup
                            heading={t('performance.rating_heading')}
                            value={data.rating}
                            onChange={(v) => setData('rating', v)}
                            options={ratingOptions}
                        />
                        <FieldError message={errors.rating} />
                    </div>

                    <Field id="pr_comments" label={t('performance.comments_label')} error={errors.comments}>
                        <TextArea value={data.comments} onChange={(e) => setData('comments', e.target.value)} rows={4} maxLength={2000} />
                    </Field>

                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="button" variant="secondary" busy={processing} onClick={submit('draft')} disabled={!data.cycle_id || !data.rating}>{t('performance.save_draft')}</Button>
                        <Button type="button" busy={processing} onClick={submit('finalize')} disabled={!data.cycle_id || !data.rating || !data.comments}>{t('performance.finalize_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
