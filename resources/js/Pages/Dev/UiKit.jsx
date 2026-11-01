import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import {
    Alert,
    BackLink,
    Button,
    Card,
    CheckRow,
    ChoiceGroup,
    ConfirmDialog,
    EmptyState,
    Field,
    IconButton,
    Input,
    LoadingState,
    Page,
    PrintArea,
    PrintButton,
    ReviewList,
    Select,
    SectionHeader,
    StatusBadge,
    STATUS_TONE,
    SuccessPanel,
    TextArea,
    WizardShell,
    useConfirm,
    useWizard,
} from '@/Components/ui';
import { BanknotesIcon, BellIcon, BuildingLibraryIcon, DevicePhoneMobileIcon, PlusIcon, TrashIcon, UserGroupIcon } from '@heroicons/react/24/outline';
import { useState } from 'react';

// DEVELOPMENT ONLY (route does not exist in production). Everything here is the real component, live.
// Text is English on purpose: this page documents components, it is not part of the product.
// The rules for when to use which component are in UX_GUIDELINES.md.

function Block({ id, title, description, children }) {
    return (
        <Card as="section" id={id}>
            <SectionHeader title={title} description={description} />
            {children}
        </Card>
    );
}

function WizardDemo() {
    const [kind, setKind] = useState('');
    const [name, setName] = useState('');
    const [done, setDone] = useState(false);
    const wizard = useWizard({
        onSubmit: () => setDone(true),
        steps: [
            { key: 'kind', label: 'What are you adding?', description: 'Pick one.', isValid: () => kind !== '', whyBlocked: 'Choose one to continue.' },
            { key: 'name', label: 'What is it called?', description: 'A short name people will recognise.', isValid: () => name.trim().length >= 3, whyBlocked: 'Type at least 3 letters to continue.' },
            { key: 'review', label: 'Check and create' },
        ],
    });

    if (done) {
        return (
            <SuccessPanel title="Item created" actions={<Button variant="secondary" onClick={() => { setDone(false); setKind(''); setName(''); wizard.reset(); }}>Add another item</Button>}>
                It now appears in the list.
            </SuccessPanel>
        );
    }

    return (
        <WizardShell wizard={wizard} finalLabel="Create item">
            {wizard.step.key === 'kind' && (
                <ChoiceGroup
                    label="What are you adding?"
                    value={kind}
                    onChange={setKind}
                    options={[
                        { value: 'fee', label: 'A fee', hint: 'Money the school charges', icon: BanknotesIcon },
                        { value: 'class', label: 'A class', hint: 'A group of students', icon: UserGroupIcon },
                    ]}
                />
            )}
            {wizard.step.key === 'name' && (
                <Field id="wiz-name" label="Name">
                    <Input value={name} onChange={(e) => setName(e.target.value)} autoFocus />
                </Field>
            )}
            {wizard.step.key === 'review' && (
                <ReviewList onEdit={wizard.goTo} rows={[{ label: 'Kind', value: kind === 'fee' ? 'A fee' : 'A class', step: 0 }, { label: 'Name', value: name, step: 1 }]} />
            )}
        </WizardShell>
    );
}

export default function UiKit() {
    const [busy, setBusy] = useState(false);
    const [method, setMethod] = useState('');
    const [gender, setGender] = useState('');
    const [perms, setPerms] = useState({ students: true, money: false });
    const [alerts, setAlerts] = useState({ success: true, danger: true });
    const [answer, setAnswer] = useState('(nothing asked yet)');
    const [ask, confirmDialog] = useConfirm();
    const [name, setName] = useState('');
    const [busyConfirm, setBusyConfirm] = useState(false);

    const del = async () => {
        const yes = await ask({
            title: 'Delete Student?',
            body: (
                <>
                    <p>Bethlehem Girma will be removed from Grade 4 A.</p>
                    <p className="font-medium">Her invoices and payments are kept.</p>
                </>
            ),
            confirmLabel: 'Delete Student',
        });
        setAnswer(yes ? 'You chose: Delete Student' : 'You chose: Cancel');
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">UI kit</h2>}>
            <Page
                title="UI kit"
                description="Every shared component, live. Development only."
                back={<BackLink href={route('dashboard')}>Back to the dashboard</BackLink>}
                actions={<Button icon={PlusIcon}>Create example</Button>}
                width="wide"
            >
                <Block id="buttons" title="Buttons" description="Large by default (56px), sentence case, one primary action per screen. Labels name the outcome.">
                    <div className="flex flex-wrap items-center gap-3">
                        <Button>Save Attendance</Button>
                        <Button variant="secondary">Cancel</Button>
                        <Button variant="danger" icon={TrashIcon}>Delete Student</Button>
                        <Button variant="success">Create invoices</Button>
                        <Button variant="quiet">Change</Button>
                        <Button size="md">Compact (44px)</Button>
                        <Button disabled>Disabled</Button>
                        <Button busy={busy} busyLabel="Saving…" onClick={() => { setBusy(true); setTimeout(() => setBusy(false), 1500); }}>Save with progress</Button>
                        <IconButton icon={BellIcon} label="Notifications" badge={3} />
                    </div>
                </Block>

                <Block id="forms" title="Forms" description="Label you can read, a hint saying what to type, the error right beside the field.">
                    <div className="grid gap-5 sm:grid-cols-2">
                        <Field id="kit-name" label="Full name" hint="As it appears on the birth certificate.">
                            <Input value={name} onChange={(e) => setName(e.target.value)} />
                        </Field>
                        <Field id="kit-phone" label="Phone number" optional error="That doesn't look like an Ethiopian phone number. Try 0911 22 33 44.">
                            <Input defaultValue="123" />
                        </Field>
                        <Field id="kit-notes" label="Notes" optional className="sm:col-span-2">
                            <TextArea />
                        </Field>
                        <Field id="kit-section" label="Section (a long list: a dropdown is fine here)" className="sm:col-span-2">
                            <Select defaultValue="">
                                <option value="">Select…</option>
                                {Array.from({ length: 30 }, (_, i) => <option key={i} value={i}>Grade {Math.floor(i / 4) + 1} — Section {String.fromCharCode(65 + (i % 4))}</option>)}
                            </Select>
                        </Field>
                    </div>
                    <div className="mt-5 space-y-3">
                        <CheckRow checked={perms.students} onChange={(v) => setPerms((p) => ({ ...p, students: v }))} label="Manage students" hint="Register, edit and withdraw students." />
                        <CheckRow checked={perms.money} onChange={(v) => setPerms((p) => ({ ...p, money: v }))} label="Verify payments" hint="Approve or reject a parent's proof of payment." />
                    </div>
                </Block>

                <Block id="choices" title="Choices instead of dropdowns" description="Cards for 2–6 options that need a sentence each; segmented for 2–3 short ones.">
                    <div className="space-y-5">
                        <ChoiceGroup
                            label="How did you pay?"
                            value={method}
                            onChange={setMethod}
                            options={[
                                { value: 'bank', label: 'Bank transfer', hint: 'From your bank app or a branch', icon: BuildingLibraryIcon },
                                { value: 'mobile', label: 'Mobile money', hint: 'Telebirr, CBE Birr', icon: DevicePhoneMobileIcon },
                                { value: 'cash', label: 'Cash', hint: 'Paid at the school or a bank', icon: BanknotesIcon },
                            ]}
                        />
                        <ChoiceGroup label="Gender" layout="segmented" value={gender} onChange={setGender} options={[{ value: 'female', label: 'Female' }, { value: 'male', label: 'Male' }]} />
                        <p id="choice-readout" className="text-sm text-stone-600 dark:text-stone-400">Selected: {method || 'nothing'} / {gender || 'nothing'}</p>
                    </div>
                </Block>

                <Block id="alerts" title="Alerts and messages" description="Icon + words + colour. Errors are announced at once; a success can be dismissed.">
                    <div className="space-y-3">
                        {alerts.success && <Alert tone="success" title="Attendance saved" onDismiss={() => setAlerts((a) => ({ ...a, success: false }))}>27 students were marked for today.</Alert>}
                        <Alert tone="info" title="What happens next">The school will check your proof and notify you.</Alert>
                        <Alert tone="warning" title="This class is almost full" action={<Button size="md" variant="secondary">See the class list</Button>}>3 places are left in Grade 4 A.</Alert>
                        {alerts.danger && <Alert tone="danger" title="The payment could not be saved">The file you chose is not a real image. Please choose the original photo.</Alert>}
                    </div>
                </Block>

                <Block id="badges" title="Status badges" description="One table for every status in the app; icon + words + colour.">
                    <div className="flex flex-wrap gap-2">
                        {Object.keys(STATUS_TONE).map((s) => <StatusBadge key={s} status={s} />)}
                    </div>
                </Block>

                <Block id="confirm" title="Destructive-action confirmation" description="Replaces window.confirm(). The confirm button names the outcome; Cancel is what has focus.">
                    <Button variant="danger" icon={TrashIcon} onClick={del}>Delete Student…</Button>
                    <p id="confirm-readout" className="mt-3 text-sm text-stone-600 dark:text-stone-400">{answer}</p>
                    <div className="mt-4">
                        <Button variant="secondary" onClick={() => setBusyConfirm(true)}>Show the dialog while working</Button>
                        <ConfirmDialog open={busyConfirm} title="Create invoices?" tone="primary" confirmLabel="Create invoices" busy onConfirm={() => {}} onCancel={() => setBusyConfirm(false)}>
                            <p>This will create 3 invoices for Grade 9.</p>
                        </ConfirmDialog>
                    </div>
                </Block>

                <Block id="states" title="Empty, loading and success states">
                    <div className="grid gap-4 md:grid-cols-3">
                        <div className="rounded-xl border border-stone-200 dark:border-stone-700"><EmptyState title="No students in this class yet" action={<Button icon={PlusIcon}>Enroll a student</Button>}>Students you enroll in Grade 4 A will appear here.</EmptyState></div>
                        <div className="rounded-xl border border-stone-200 dark:border-stone-700"><LoadingState label="Loading students…" /></div>
                        <SuccessPanel title="Invoices created" actions={<Button variant="secondary">See the invoices</Button>}>3 invoices for Grade 9 were created.</SuccessPanel>
                    </div>
                </Block>

                <Block id="print" title="Print action" description="Names the document, says what opens, and how to keep a file instead.">
                    <PrintButton label="Print ID card" />
                    <PrintArea className="mt-4 max-w-xs rounded-xl border border-stone-300 p-4"><p className="font-semibold">Bethlehem Girma</p><p className="text-sm text-stone-600">Grade 4 A · STU-2026-000123</p></PrintArea>
                </Block>

                <Block id="wizard" title="Wizard" description="Step X of Y, title, explanation, validation before Next (with the reason), Back keeps answers, a review, and a strong final action.">
                    <WizardDemo />
                </Block>
            </Page>
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
