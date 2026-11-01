import Avatar from '@/Components/Avatar';
import { QRCodeSVG } from 'qrcode.react';
import { useTranslation } from 'react-i18next';

// A standard CR80 card (85.6mm x 54mm -- a bank card's size, what almost every card printer and most
// plastic card stock in the world is cut to) by default, so what prints is a real, correctly-proportioned
// card, not just "whatever the browser decides to scale a div to." `widthMm`/`heightMm` let a caller use a
// bigger size instead -- the class sheet (IdCard/Batch.jsx) does, since an A4 sheet has room for cards
// noticeably bigger than a wallet card and a registrar printing a whole class wants them easy to read, not
// identical to a laminated badge. Shared by both pages, so both always show the same fields the same way.
//
// Every label on the card (never a value, never a person's name, never a number) is shown in three
// languages at once -- English / Amharic / Afaan Oromoo -- regardless of which language the signed-in
// staff member's own screen is in: the card is read by whoever holds it, not by whoever printed it.
//
// Two fixed size presets, not a continuously-scaled one: font sizes are in px (a FIXED physical size at
// print, unlike a %-based size), so they can't just be multiplied by whatever widthMm/heightMm happen to
// be passed without risking overflow on the smaller card. `wrap: true` (SPACIOUS only) lets a value break
// onto a second line instead of being cut off with "..." -- the sheet card has vertical room to spare, so
// that's a better trade than truncating a trilingual label plus a value in a column also sharing space
// with the photo and the QR code. Student ID and Class move out of that narrow column entirely for the
// same reason: they read fine from the wider grid below, where only the card's own padding competes with
// them, not a photo and a QR code too.
const COMPACT = { avatar: 68, qr: 44, school: 'text-[11.5px]', state: 'text-[8px]', name: 'text-[15px]', line: 'text-[10px]', lineEmphasis: 'text-[11.5px]', wrap: false };
const SPACIOUS = { avatar: 80, qr: 48, school: 'text-[14px]', state: 'text-[9.5px]', name: 'text-[19px]', line: 'text-[13px]', lineEmphasis: 'text-[15px]', wrap: true };

function triLabel(t, key) {
    return [t(key, { lng: 'en' }), t(key, { lng: 'am' }), t(key, { lng: 'om' })].join(' / ');
}

function DetailLine({ label, value, className = '', emphasis = false, size }) {
    if (!value) return null;

    return (
        <p className={`${size.wrap ? 'line-clamp-2' : 'truncate'} leading-tight ${emphasis ? `${size.lineEmphasis} font-bold text-brand-900 dark:text-brand-300` : `${size.line} text-stone-700 dark:text-stone-300`} ${className}`}>
            <span className={`font-semibold ${emphasis ? 'text-brand-700 dark:text-brand-400' : 'text-stone-500 dark:text-stone-400'}`}>{label}: </span>
            {value}
        </p>
    );
}

export default function IdCardFace({ student, school, widthMm = 85.6, heightMm = 54, spacious = false, className = '' }) {
    const { t } = useTranslation();
    const size = spacious ? SPACIOUS : COMPACT;
    const L = (key) => triLabel(t, key);
    const classLine = [student.grade, student.section].filter(Boolean).join(' - ');
    const genderLabel = student.gender ? t(`idcard.gender_${student.gender}`, student.gender) : null;
    const state = student.card_validity.state;

    return (
        <div
            className={`id-card-print flex flex-col gap-1.5 rounded-xl border border-stone-200 bg-white p-2.5 shadow-sm dark:border-stone-700 dark:bg-stone-800 ${className}`}
            style={{ aspectRatio: `${widthMm} / ${heightMm}`, '--card-w': `${widthMm}mm`, '--card-h': `${heightMm}mm` }}
        >
            <div className="flex items-start justify-between gap-2">
                <p className={`min-w-0 truncate font-bold uppercase tracking-wide text-brand-700 dark:text-brand-400 ${size.school}`}>{school}</p>
                <p className={`shrink-0 font-medium uppercase text-stone-400 ${size.state}`}>{t(`idcard.state_${state}`, state)}</p>
            </div>

            <div className="flex items-center gap-2">
                <Avatar src={student.photo_url} name={student.name} size={size.avatar} shape="rounded" className="shrink-0 border border-stone-200 dark:border-stone-600" />
                <p className={`line-clamp-2 min-w-0 flex-1 font-bold leading-tight text-stone-900 dark:text-stone-100 ${size.name}`}>{student.name}</p>
                <QRCodeSVG value={student.verify_url} size={size.qr} className="shrink-0" />
            </div>

            <div className="grid grid-cols-2 gap-x-2 border-t border-dashed border-stone-200 pt-1 dark:border-stone-700">
                <DetailLine size={size} label={L('idcard.student_id')} value={student.student_id_number} />
                <DetailLine size={size} label={L('idcard.grade_section')} value={classLine} />
                <DetailLine size={size} label={L('idcard.age')} value={student.age} />
                <DetailLine size={size} label={L('idcard.gender')} value={genderLabel} />
                <DetailLine size={size} className="col-span-2" label={L('idcard.address')} value={student.location} />
                <DetailLine size={size} label={L('idcard.mother')} value={student.mother_name} />
                <DetailLine size={size} label={L('idcard.father')} value={student.father_name} />
            </div>

            <DetailLine size={size} emphasis className="border-t border-dashed border-stone-200 pt-1 dark:border-stone-700" label={L('idcard.emergency_contact')} value={student.emergency_contact} />
        </div>
    );
}
