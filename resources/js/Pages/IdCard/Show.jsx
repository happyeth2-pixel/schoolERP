import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PrinterIcon, UserCircleIcon } from '@heroicons/react/24/outline';
import { Head } from '@inertiajs/react';
import { QRCodeSVG } from 'qrcode.react';

const STATUS_STYLE = {
    active: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
};

export default function Show({ student, school, verifyUrl }) {
    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">Digital ID Card</h2>}
        >
            <Head title={`ID Card — ${student.name}`} />

            <style>{`
                @media print {
                    body * { visibility: hidden; }
                    #id-card-print-area, #id-card-print-area * { visibility: visible; }
                    #id-card-print-area { position: absolute; top: 0; left: 0; }
                }
            `}</style>

            <div className="py-8">
                <div className="mx-auto max-w-md space-y-4 px-4 sm:px-6 lg:px-8">
                    <button
                        onClick={() => window.print()}
                        className="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                    >
                        <PrinterIcon className="h-4 w-4" /> Print
                    </button>

                    <div
                        id="id-card-print-area"
                        className="mx-auto flex w-full max-w-[340px] flex-col gap-3 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800"
                    >
                        <p className="text-center text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{school}</p>

                        <div className="flex items-center gap-4">
                            {student.photo_url ? (
                                <img src={student.photo_url} alt={student.name} className="h-20 w-20 rounded-md object-cover" />
                            ) : (
                                <UserCircleIcon className="h-20 w-20 text-gray-300 dark:text-gray-600" />
                            )}
                            <div>
                                <p className="font-semibold text-gray-900 dark:text-gray-100">{student.name}</p>
                                <p className="text-sm text-gray-500">{student.student_id_number}</p>
                                <span className={`mt-1 inline-block rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_STYLE[student.status] ?? 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'}`}>
                                    {student.status}
                                </span>
                            </div>
                        </div>

                        <div className="flex justify-center border-t border-dashed border-gray-200 pt-3 dark:border-gray-700">
                            <QRCodeSVG value={verifyUrl} size={120} />
                        </div>
                        <p className="text-center text-xs text-gray-400">Scan to verify this card</p>
                    </div>

                    <p className="text-center text-xs text-gray-400">
                        If this student has no photo yet, add one from the student's edit page before printing.
                    </p>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
