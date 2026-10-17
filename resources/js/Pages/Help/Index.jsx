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
import { useTranslation } from 'react-i18next';

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
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const permissions = auth.permissions ?? [];
    const role = auth.role;
    const has = (perm) => permissions.includes(perm);
    const isAuditor = role === 'auditor';
    const isFamily = role === 'parent';

    // returnObjects: true is needed for the step lists, which are stored as JSON arrays
    // in resources/js/lang/*.json rather than one key per line -- see LOCALIZATION.md.
    const steps = (key) => t(key, { returnObjects: true });

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('nav.help')}</h2>}
        >
            <Head title={t('nav.help')} />

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
                    <p className="text-sm text-gray-500">{t('help.intro')}</p>

                    <Section icon={HomeIcon} title={t('help.getting_started.title')} blurb={t('help.getting_started.blurb')}>
                        <Diagram viewBox="0 0 640 70">
                            <Box x={5} y={12} w={140} h={46} label={t('help.getting_started.diagram.log_in')} />
                            <Arrow x1={145} y1={35} x2={180} y2={35} />
                            <Box x={183} y={12} w={140} h={46} label={t('help.getting_started.diagram.dashboard')} />
                            <Arrow x1={323} y1={35} x2={358} y2={35} />
                            <Box x={361} y={12} w={140} h={46} label={t('help.getting_started.diagram.sidebar')} sub={t('help.getting_started.diagram.sidebar_sub')} />
                            <Arrow x1={501} y1={35} x2={536} y2={35} />
                            <Box x={539} y={12} w={95} h={46} label={t('help.getting_started.diagram.bell')} sub={t('help.getting_started.diagram.bell_sub')} />
                        </Diagram>
                        <StepList items={steps('help.getting_started.steps')} />
                    </Section>

                    {has('manage-students') && (
                        <Section icon={AcademicCapIcon} title={t('help.enrolling.title')} blurb={t('help.enrolling.blurb')}>
                            <Diagram viewBox="0 0 640 130">
                                <Box x={5} y={10} w={180} h={46} label={t('help.enrolling.diagram.enroll')} sub={t('help.enrolling.diagram.enroll_sub')} />
                                <Arrow x1={95} y1={56} x2={95} y2={92} />
                                <Box x={5} y={95} w={180} h={30} label={t('help.enrolling.diagram.active')} fill="#818cf8" />
                                <Arrow x1={185} y1={30} x2={225} y2={30} />
                                <Box x={228} y={5} w={190} h={46} label={t('help.enrolling.diagram.transfer')} sub={t('help.enrolling.diagram.transfer_sub')} fill="#059669" />
                                <Arrow x1={185} y1={70} x2={225} y2={95} />
                                <Box x={228} y={90} w={190} h={46} label={t('help.enrolling.diagram.promote')} sub={t('help.enrolling.diagram.promote_sub')} fill="#b45309" />
                            </Diagram>
                            <StepList items={steps('help.enrolling.steps')} />
                        </Section>
                    )}

                    {(has('manage-fee-structures') || has('manage-invoices') || has('verify-payments') || has('view-financial-reports')) && (
                        <Section icon={CurrencyDollarIcon} title={t('help.money.title')} blurb={t('help.money.blurb')}>
                            <Diagram viewBox="0 0 640 100">
                                <Box x={5} y={27} w={140} h={46} label={t('help.money.diagram.fee')} sub={t('help.money.diagram.fee_sub')} />
                                <Arrow x1={145} y1={50} x2={180} y2={50} />
                                <Box x={183} y={27} w={110} h={46} label={t('help.money.diagram.invoice')} />
                                <Arrow x1={293} y1={50} x2={328} y2={50} />
                                <Box x={331} y={27} w={140} h={46} label={t('help.money.diagram.parent_pays')} sub={t('help.money.diagram.parent_pays_sub')} />
                                <Arrow x1={471} y1={50} x2={506} y2={50} />
                                <Box x={509} y={27} w={125} h={46} label={t('help.money.diagram.verified')} fill="#059669" sub={t('help.money.diagram.verified_sub')} />
                            </Diagram>
                            <StepList items={steps('help.money.steps')} />
                        </Section>
                    )}

                    {(has('mark-attendance') || has('view-attendance-reports')) && (
                        <Section icon={ClipboardDocumentCheckIcon} title={t('help.attendance_flow.title')} blurb={t('help.attendance_flow.blurb')}>
                            <Diagram viewBox="0 0 640 70">
                                <Box x={5} y={12} w={160} h={46} label={t('help.attendance_flow.diagram.pick')} />
                                <Arrow x1={165} y1={35} x2={200} y2={35} />
                                <Box x={203} y={12} w={180} h={46} label={t('help.attendance_flow.diagram.mark')} />
                                <Arrow x1={383} y1={35} x2={418} y2={35} />
                                <Box x={421} y={12} w={100} h={46} label={t('help.attendance_flow.diagram.save')} fill="#059669" />
                                <Arrow x1={521} y1={35} x2={556} y2={35} />
                                <Box x={559} y={12} w={76} h={46} label={t('help.attendance_flow.diagram.notify')} sub={t('help.attendance_flow.diagram.notify_sub')} fill="#b45309" />
                            </Diagram>
                            <StepList items={steps('help.attendance_flow.steps')} />
                        </Section>
                    )}

                    {(has('enter-marks') || has('approve-results') || has('publish-results')) && (
                        <Section icon={PencilIcon} title={t('help.marks_flow.title')} blurb={t('help.marks_flow.blurb')}>
                            <Diagram viewBox="0 0 640 70">
                                <Box x={5} y={12} w={130} h={46} label={t('help.marks_flow.diagram.draft')} fill="#818cf8" />
                                <Arrow x1={135} y1={35} x2={170} y2={35} />
                                <Box x={173} y={12} w={140} h={46} label={t('help.marks_flow.diagram.submitted')} sub={t('help.marks_flow.diagram.submitted_sub')} fill="#818cf8" />
                                <Arrow x1={313} y1={35} x2={348} y2={35} />
                                <Box x={351} y={12} w={140} h={46} label={t('help.marks_flow.diagram.approved')} sub={t('help.marks_flow.diagram.approved_sub')} fill="#818cf8" />
                                <Arrow x1={491} y1={35} x2={526} y2={35} />
                                <Box x={529} y={12} w={106} h={46} label={t('help.marks_flow.diagram.published')} />
                            </Diagram>
                            <StepList items={steps('help.marks_flow.steps')} />
                        </Section>
                    )}

                    {(has('manage-complaints') || isAuditor) && (
                        <Section icon={ChatBubbleLeftRightIcon} title={t('help.complaints_staff.title')} blurb={t('help.complaints_staff.blurb')}>
                            <Diagram viewBox="0 0 640 70">
                                <Box x={5} y={12} w={190} h={46} label={t('help.complaints_staff.diagram.submits')} />
                                <Arrow x1={195} y1={35} x2={230} y2={35} />
                                <Box x={233} y={12} w={190} h={46} label={t('help.complaints_staff.diagram.inbox')} sub={t('help.complaints_staff.diagram.inbox_sub')} />
                                <Arrow x1={423} y1={35} x2={458} y2={35} />
                                <Box x={461} y={12} w={174} h={46} label={t('help.complaints_staff.diagram.resolved')} fill="#059669" sub={t('help.complaints_staff.diagram.resolved_sub')} />
                            </Diagram>
                            <StepList items={steps('help.complaints_staff.steps')} />
                        </Section>
                    )}

                    {isFamily && (
                        <>
                            <Section icon={ChatBubbleLeftRightIcon} title={t('help.complaints_family.title')} blurb={t('help.complaints_family.blurb')}>
                                <Diagram viewBox="0 0 640 70">
                                    <Box x={5} y={12} w={190} h={46} label={t('help.complaints_family.diagram.write')} sub={t('help.complaints_family.diagram.write_sub')} />
                                    <Arrow x1={195} y1={35} x2={230} y2={35} />
                                    <Box x={233} y={12} w={190} h={46} label={t('help.complaints_family.diagram.sees')} />
                                    <Arrow x1={423} y1={35} x2={458} y2={35} />
                                    <Box x={461} y={12} w={174} h={46} label={t('help.complaints_family.diagram.notified')} fill="#059669" sub={t('help.complaints_family.diagram.notified_sub')} />
                                </Diagram>
                                <StepList items={steps('help.complaints_family.steps')} />
                            </Section>

                            <Section icon={CurrencyDollarIcon} title={t('help.paying_invoice.title')} blurb={t('help.paying_invoice.blurb')}>
                                <Diagram viewBox="0 0 640 70">
                                    <Box x={5} y={12} w={170} h={46} label={t('help.paying_invoice.diagram.open')} sub={t('help.paying_invoice.diagram.open_sub')} />
                                    <Arrow x1={175} y1={35} x2={210} y2={35} />
                                    <Box x={213} y={12} w={170} h={46} label={t('help.paying_invoice.diagram.pay')} sub={t('help.paying_invoice.diagram.pay_sub')} />
                                    <Arrow x1={383} y1={35} x2={418} y2={35} />
                                    <Box x={421} y={12} w={140} h={46} label={t('help.paying_invoice.diagram.submit')} />
                                    <Arrow x1={561} y1={35} x2={596} y2={35} />
                                    <Box x={599} y={12} w={36} h={46} label="✓" fill="#059669" />
                                </Diagram>
                                <StepList items={steps('help.paying_invoice.steps')} />
                            </Section>
                        </>
                    )}

                    {isAuditor && (
                        <Section icon={ShieldCheckIcon} title={t('help.auditor.title')} blurb={t('help.auditor.blurb')}>
                            <p className="mt-3 text-sm text-gray-600 dark:text-gray-300">
                                {t('help.auditor.body')}
                            </p>
                        </Section>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
