import Pagination from '@/Components/Pagination';
import { BackLink, Button, Card, CheckRow, EmptyState, Page, StatusBadge, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ClockIcon, DocumentPlusIcon } from '@heroicons/react/24/outline';
import { Head, Link, router } from '@inertiajs/react';

const STATUS_TONE = { completed: 'success', no_invoices: 'neutral', blocked: 'warning', failed: 'danger' };
const STATUS_LABEL = { completed: 'Completed', no_invoices: 'Nothing new to bill', blocked: 'Blocked', failed: 'Failed' };

// "Which bills were generated, when, for which classes, how much, and did it work" -- every Create Bills
// run, success or not (see BillingBatchController). A blocked/failed run shows its own explanation instead
// of just vanishing, which is exactly the thing a registrar most needs to see after something goes wrong.
// Cancelled runs are kept forever (same "never delete money history" rule as a cancelled invoice) but
// hidden by default so re-running "All Classes" after a cancel doesn't bury the list in old entries --
// the toggle below brings them all back.
export default function Batches({ batches, pagination, filters }) {
    const [ask, confirmDialog] = useConfirm();

    const cancel = async (batch) => {
        const ok = await ask({
            title: `Cancel this batch of ${batch.invoices_created} bill(s)?`,
            body: (
                <p>
                    Any bill in this batch that already has a payment on it is left alone. This cannot be undone from here afterward.
                </p>
            ),
            confirmLabel: 'Cancel this batch',
            tone: 'danger',
        });
        if (ok) router.post(route('invoices.batches.cancel', batch.generation_batch_id), {}, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">Billing Batches</h2>}>
            <Head title="Billing Batches" />
            <Page
                title="Billing Batches"
                description="Every time bills were generated -- when, for which class, how much, and whether it worked."
                back={<BackLink href={route('invoices.index')}>Back to Invoices</BackLink>}
                actions={<Button as={Link} href={route('invoices.wizard')} icon={DocumentPlusIcon}>Create Bills</Button>}
            >
                <div className="mb-4">
                    <CheckRow
                        id="show_cancelled"
                        label="Show cancelled batches too"
                        hint="Cancelled runs are kept as permanent history -- they're just hidden here by default."
                        checked={filters.show_cancelled}
                        onChange={(checked) => router.get(route('invoices.batches.index'), { ...(filters.status ? { status: filters.status } : {}), show_cancelled: checked ? 1 : 0 }, { preserveScroll: true })}
                    />
                </div>

                {batches.length === 0 ? (
                    <Card><EmptyState icon={ClockIcon} title="No bills have been generated yet" description="Use Create Bills to generate the first batch." /></Card>
                ) : (
                    <div className="space-y-3">
                        {batches.map((b) => (
                            <Card key={b.id} data-testid="batch-row">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <p className="text-base font-semibold text-stone-900 dark:text-stone-100">{b.scope_label}</p>
                                            <StatusBadge tone={STATUS_TONE[b.status]} label={STATUS_LABEL[b.status]} />
                                            {b.cancelled && <StatusBadge tone="neutral" label={`Cancelled by ${b.cancelled_by}`} />}
                                        </div>
                                        <p className="text-sm text-stone-500 dark:text-stone-400">
                                            {b.created_at} · by {b.initiated_by} · {b.branch} · {b.academic_year}
                                        </p>
                                        {b.message && (
                                            <p className="mt-2 text-sm text-amber-800 dark:text-amber-300">{b.message}</p>
                                        )}
                                    </div>
                                    <div className="text-right">
                                        <p className="text-xl font-bold text-stone-900 dark:text-stone-100">{b.invoices_created}</p>
                                        <p className="text-sm text-stone-500 dark:text-stone-400">bill(s) · {b.total_amount.toLocaleString(undefined, { minimumFractionDigits: 2 })} ETB</p>
                                    </div>
                                </div>
                                {b.can_cancel && (
                                    <div className="mt-3 border-t border-stone-200 pt-3 dark:border-stone-700">
                                        <Button size="md" variant="quiet" onClick={() => cancel(b)}>Cancel this batch</Button>
                                    </div>
                                )}
                            </Card>
                        ))}
                    </div>
                )}

                <div className="mt-4">
                    <Pagination meta={pagination} onPageChange={(page) => router.get(route('invoices.batches.index'), { page }, { preserveScroll: true, preserveState: true })} />
                </div>
            </Page>
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
