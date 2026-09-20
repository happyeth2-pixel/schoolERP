import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import {
    AcademicCapIcon,
    ChatBubbleLeftRightIcon,
    ClipboardDocumentCheckIcon,
    CurrencyDollarIcon,
    HomeIcon,
    PencilIcon,
    ShieldCheckIcon,
} from '@heroicons/react/24/outline';
import { Head, usePage } from '@inertiajs/react';

function StepList({ items }) {
    return (
        <ol className="mt-3 space-y-2">
            {items.map((text, i) => (
                <li key={i} className="flex gap-3 text-sm text-gray-700 dark:text-gray-300">
                    <span className="mt-0.5 flex h-5 w-5 flex-none items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white">{i + 1}</span>
                    <span>{text}</span>
                </li>
            ))}
        </ol>
    );
}

function Section({ icon: Icon, title, blurb, children }) {
    return (
        <div className="rounded-lg bg-white p-6 shadow-sm dark:bg-gray-800">
            <div className="mb-1 flex items-center gap-2">
                <Icon className="h-5 w-5 text-indigo-600 dark:text-indigo-400" />
                <h3 className="text-base font-semibold text-gray-900 dark:text-gray-100">{title}</h3>
            </div>
            <p className="text-sm text-gray-500">{blurb}</p>
            {children}
        </div>
    );
}

function Diagram({ viewBox, height = 100, children }) {
    return (
        <div className="mt-3 overflow-x-auto rounded-md bg-slate-50 p-3">
            <svg viewBox={viewBox} style={{ height, maxWidth: '100%' }} className="mx-auto block" xmlns="http://www.w3.org/2000/svg" fontFamily="inherit">
                {children}
            </svg>
        </div>
    );
}

function Box({ x, y, w, h = 46, label, sub, fill = '#4f46e5', textFill = 'white', subFill = '#e0e7ff', dashed = false, stroke }) {
    return (
        <g>
            <rect x={x} y={y} width={w} height={h} rx="8" fill={dashed ? 'white' : fill} stroke={stroke ?? (dashed ? '#a5b4fc' : 'none')} strokeWidth={dashed ? 2 : 0} strokeDasharray={dashed ? '5 3' : undefined} />
            <text x={x + w / 2} y={y + h / 2 - (sub ? 6 : -4)} textAnchor="middle" fontSize="11" fontWeight="700" fill={dashed ? '#3730a3' : textFill}>{label}</text>
            {sub && <text x={x + w / 2} y={y + h / 2 + 10} textAnchor="middle" fontSize="8" fill={dashed ? '#6b7280' : subFill}>{sub}</text>}
        </g>
    );
}

function Arrow({ x1, y1, x2, y2 }) {
    return <line x1={x1} y1={y1} x2={x2} y2={y2} stroke="#6366f1" strokeWidth="2" markerEnd="url(#help-arrow)" className="help-flow-line" />;
}

export default function Index() {
    const { auth } = usePage().props;
    const permissions = auth.permissions ?? [];
    const role = auth.role;
    const has = (perm) => permissions.includes(perm);
    const isAuditor = role === 'auditor';
    const isFamily = role === 'parent' || role === 'student';

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">Help</h2>}
        >
            <Head title="Help" />

            <style>{`
                @keyframes help-flow { to { stroke-dashoffset: -16; } }
                .help-flow-line { stroke-dasharray: 5 4; animation: help-flow 1s linear infinite; }
            `}</style>

            {/* Shared arrowhead definition, reused by every diagram's arrows below */}
            <svg width="0" height="0" style={{ position: 'absolute' }}>
                <defs>
                    <marker id="help-arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                        <path d="M0,0 L10,5 L0,10 z" fill="#6366f1" />
                    </marker>
                </defs>
            </svg>

            <div className="py-8">
                <div className="mx-auto max-w-3xl space-y-4 px-4 sm:px-6 lg:px-8">
                    <p className="text-sm text-gray-500">
                        A quick picture of how the pieces you can use actually fit together. This page only shows what your account
                        can do — someone else's Help page may look different.
                    </p>

                    <Section icon={HomeIcon} title="Getting Started" blurb="Every visit starts the same way.">
                        <Diagram viewBox="0 0 640 70">
                            <Box x={5} y={12} w={140} h={46} label="Log In" />
                            <Arrow x1={145} y1={35} x2={180} y2={35} />
                            <Box x={183} y={12} w={140} h={46} label="Dashboard" />
                            <Arrow x1={323} y1={35} x2={358} y2={35} />
                            <Box x={361} y={12} w={140} h={46} label="Sidebar" sub="only what you can use" />
                            <Arrow x1={501} y1={35} x2={536} y2={35} />
                            <Box x={539} y={12} w={95} h={46} label="Bell 🔔" sub="notifications" />
                        </Diagram>
                        <StepList items={[
                            'Log in with the email and password staff gave you — nobody signs themselves up.',
                            'The sidebar on the left only lists what your account is allowed to do.',
                            'The bell icon shows things that happened for you (an absence, a payment, a complaint update).',
                            'The sun/moon icon and your name (top-right) hold theme and profile settings — every account can upload its own photo there.',
                        ]} />
                    </Section>

                    {has('manage-students') && (
                        <Section icon={AcademicCapIcon} title="Enrolling & Moving Students" blurb="One student can travel through the system three ways.">
                            <Diagram viewBox="0 0 640 130">
                                <Box x={5} y={10} w={180} h={46} label="Enroll Student" sub="brand-new intake" />
                                <Arrow x1={95} y1={56} x2={95} y2={92} />
                                <Box x={5} y={95} w={180} h={30} label="Active in a section" fill="#818cf8" />
                                <Arrow x1={185} y1={30} x2={225} y2={30} />
                                <Box x={228} y={5} w={190} h={46} label="Mid-Year Transfer" sub="one student, any time" fill="#059669" />
                                <Arrow x1={185} y1={70} x2={225} y2={95} />
                                <Box x={228} y={90} w={190} h={46} label="Promote / Graduate" sub="whole class, year end" fill="#b45309" />
                            </Diagram>
                            <StepList items={[
                                'People → Students → Enroll Student — pick a section, link a parent (new, existing, or later).',
                                'People → Promote Students moves a WHOLE class into next year\'s section at once, or graduates the final grade.',
                                'A single student needing a different section right now (not the whole class) uses the "Section" box on their own Edit page instead.',
                            ]} />
                        </Section>
                    )}

                    {(has('manage-fee-structures') || has('manage-invoices') || has('verify-payments') || has('view-financial-reports')) && (
                        <Section icon={CurrencyDollarIcon} title="How Money Works" blurb="No payment gateway — every payment is confirmed by a person.">
                            <Diagram viewBox="0 0 640 100">
                                <Box x={5} y={27} w={140} h={46} label="Fee / Service" sub="Annual, Term, or Monthly" />
                                <Arrow x1={145} y1={50} x2={180} y2={50} />
                                <Box x={183} y={27} w={110} h={46} label="Invoice" />
                                <Arrow x1={293} y1={50} x2={328} y2={50} />
                                <Box x={331} y={27} w={140} h={46} label="Parent Pays" sub="bank / mobile / cash" />
                                <Arrow x1={471} y1={50} x2={506} y2={50} />
                                <Box x={509} y={27} w={125} h={46} label="Verified" fill="#059669" sub="or rejected" />
                            </Diagram>
                            <StepList items={[
                                'Finance → Fee Structures / Optional Services sets prices and how often they\'re charged.',
                                'Finance → Invoices → Generate Invoices builds the right number of invoices per student automatically.',
                                'A parent sees exactly where to send money (set in Settings → School Profile) right on their invoice.',
                                'Finance → Payment Verification is where a submitted payment gets approved or rejected — this is the only place money actually changes an invoice\'s status.',
                            ]} />
                        </Section>
                    )}

                    {(has('mark-attendance') || has('view-attendance-reports')) && (
                        <Section icon={ClipboardDocumentCheckIcon} title="Taking Attendance" blurb="One class, one date, one save.">
                            <Diagram viewBox="0 0 640 70">
                                <Box x={5} y={12} w={160} h={46} label="Pick Class + Date" />
                                <Arrow x1={165} y1={35} x2={200} y2={35} />
                                <Box x={203} y={12} w={180} h={46} label="Mark Each Student" />
                                <Arrow x1={383} y1={35} x2={418} y2={35} />
                                <Box x={421} y={12} w={100} h={46} label="Save" fill="#059669" />
                                <Arrow x1={521} y1={35} x2={556} y2={35} />
                                <Box x={559} y={12} w={76} h={46} label="Notify" sub="if absent" fill="#b45309" />
                            </Diagram>
                            <StepList items={[
                                'Attendance → Take Attendance. A teacher only ever sees their own class here.',
                                'A student marked Absent automatically notifies their parent — nothing extra to do.',
                                'Export to Excel prints a blank roster for a teacher without a device — tick it on paper, enter it later.',
                            ]} />
                        </Section>
                    )}

                    {(has('enter-marks') || has('approve-results') || has('publish-results')) && (
                        <Section icon={PencilIcon} title="Marks Workflow" blurb='Only "Published" ever reaches a report card.'>
                            <Diagram viewBox="0 0 640 70">
                                <Box x={5} y={12} w={130} h={46} label="Draft" fill="#818cf8" />
                                <Arrow x1={135} y1={35} x2={170} y2={35} />
                                <Box x={173} y={12} w={140} h={46} label="Submitted" sub="by teacher" fill="#818cf8" />
                                <Arrow x1={313} y1={35} x2={348} y2={35} />
                                <Box x={351} y={12} w={140} h={46} label="Approved" sub="or sent back" fill="#818cf8" />
                                <Arrow x1={491} y1={35} x2={526} y2={35} />
                                <Box x={529} y={12} w={106} h={46} label="Published" />
                            </Diagram>
                            <StepList items={[
                                'Academics → Enter Marks (teacher) → Result Approvals (approver) → Publish.',
                                'Sending a batch back with a note is normal — nothing is visible to parents until it\'s Published.',
                                'Report cards are always calculated live from published marks — nobody types an average in by hand.',
                            ]} />
                        </Section>
                    )}

                    {(has('manage-complaints') || isAuditor) && (
                        <Section icon={ChatBubbleLeftRightIcon} title="Handling Complaints" blurb="Every complaint shows who sent it and which child it's about.">
                            <Diagram viewBox="0 0 640 70">
                                <Box x={5} y={12} w={190} h={46} label="Family Submits" />
                                <Arrow x1={195} y1={35} x2={230} y2={35} />
                                <Box x={233} y={12} w={190} h={46} label="Your Inbox" sub="notified instantly" />
                                <Arrow x1={423} y1={35} x2={458} y2={35} />
                                <Box x={461} y={12} w={174} h={46} label="Resolved" fill="#059669" sub="family is notified" />
                            </Diagram>
                            <StepList items={[
                                'Complaints shows the child\'s current grade/section automatically, plus any siblings also enrolled — you never have to look that up separately.',
                                'Change its status (Open / In Progress / Resolved) and add a note — the family sees that note and gets notified.',
                            ]} />
                        </Section>
                    )}

                    {isFamily && (
                        <>
                            <Section icon={ChatBubbleLeftRightIcon} title="Submitting a Complaint" blurb="Tell the school something, and know when it's handled.">
                                <Diagram viewBox="0 0 640 70">
                                    <Box x={5} y={12} w={190} h={46} label="Write It" sub="pick a child, optional" />
                                    <Arrow x1={195} y1={35} x2={230} y2={35} />
                                    <Box x={233} y={12} w={190} h={46} label="Staff Sees It" />
                                    <Arrow x1={423} y1={35} x2={458} y2={35} />
                                    <Box x={461} y={12} w={174} h={46} label="You're Notified" fill="#059669" sub="when resolved" />
                                </Diagram>
                                <StepList items={[
                                    'Complaints → New Complaint. If you have more than one child, you pick which one it\'s about (or leave it general).',
                                    'Track it any time under Complaints — My Complaints shows only your own.',
                                ]} />
                            </Section>

                            <Section icon={CurrencyDollarIcon} title="Paying an Invoice" blurb="Pay outside the app, then prove it.">
                                <Diagram viewBox="0 0 640 70">
                                    <Box x={5} y={12} w={170} h={46} label="Open Invoice" sub="see where to send money" />
                                    <Arrow x1={175} y1={35} x2={210} y2={35} />
                                    <Box x={213} y={12} w={170} h={46} label="Pay Outside" sub="bank / mobile / cash" />
                                    <Arrow x1={383} y1={35} x2={418} y2={35} />
                                    <Box x={421} y={12} w={140} h={46} label="Submit Proof" />
                                    <Arrow x1={561} y1={35} x2={596} y2={35} />
                                    <Box x={599} y={12} w={36} h={46} label="✓" fill="#059669" />
                                </Diagram>
                                <StepList items={[
                                    'Open your child\'s invoice — the school\'s account details are shown right there.',
                                    'Submit the amount, method, a reference number, and a photo of the receipt.',
                                    'You\'re notified the moment it\'s verified — or rejected, with the reason.',
                                ]} />
                            </Section>
                        </>
                    )}

                    {isAuditor && (
                        <Section icon={ShieldCheckIcon} title="What You Can See" blurb="Everything, read-only.">
                            <p className="mt-3 text-sm text-gray-600 dark:text-gray-300">
                                Wherever a list or an export button exists for a specific job (Students, Invoices, Attendance, Payments...),
                                you see it too automatically — without needing that job's permission yourself. What you will never see is an
                                "Add," "Edit," or "Delete" button. If you're trying to change something and can't find the button, that's not
                                a bug — Auditor accounts genuinely cannot change anything, anywhere.
                            </p>
                        </Section>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
