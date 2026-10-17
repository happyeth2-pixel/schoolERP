import { ChoiceCard, ChoiceGroup } from '@/Components/ui';
import { BuildingLibraryIcon, TruckIcon, XMarkIcon } from '@heroicons/react/24/outline';
import { useTranslation } from 'react-i18next';

// Optional services as big cards, grouped the way a school thinks about them:
//   Transport  - one route at a time (single choice) plus "No transport"
//   ASP        - one programme: a big Yes / No; several: tap the ones wanted
//   Other      - tap to add or remove
// This component only shows choices and reports taps (onPick / onDrop); the caller decides what a tap does
// (the profile saves at once, the registration wizard keeps the choice until "Register"). The rule that a
// student has one transport route is enforced by the server either way.

const WHEN = { annual: 'freq_annual', termly: 'freq_termly', monthly: 'freq_monthly' };
const UNIT = { annual: 'unit_year', termly: 'unit_term', monthly: 'unit_month' };

/** The price this student pays: their own price if they have one, else the service's. */
export const effectivePrice = (s) => Number(s.custom_amount ?? s.amount);

const fmt = (n) => Number(n).toLocaleString(undefined, { maximumFractionDigits: 2 });

/** "Transport - Kela" -> "Kela": inside the Transport group the route is the title. */
export const routeName = (name) => name.replace(/^\s*transport(ation)?\s*[-–—:]\s*/i, '').trim() || name;

export function usePriceText() {
    const { t } = useTranslation();
    return (s) => {
        const price = `${fmt(effectivePrice(s))} ETB ${t(`student.${WHEN[s.frequency] ?? 'freq_annual'}`)}`;
        return s.custom_amount != null ? `${price} (${t('services.special_price')})` : price;
    };
}

/** "Transport: Kela (800 ETB/month) + ASP Classes (600 ETB/term)" */
export function useServiceSummary() {
    const { t } = useTranslation();
    return (services, selectedIds) => {
        const chosen = services.filter((s) => selectedIds.includes(s.id));
        if (chosen.length === 0) return t('services.summary_none');
        const order = { transport: 0, asp: 1, other: 2 };
        return chosen
            .sort((a, b) => (order[a.category] ?? 2) - (order[b.category] ?? 2))
            .map((s) => {
                const price = `${fmt(effectivePrice(s))} ETB/${t(`services.${UNIT[s.frequency] ?? 'unit_year'}`)}`;
                return s.category === 'transport' ? t('services.summary_transport', { name: routeName(s.name), price }) : `${s.name} (${price})`;
            })
            .join(' + ');
    };
}

function Group({ title, hint, children }) {
    return (
        <section>
            <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">{title}</h3>
            {hint && <p className="mb-3 mt-0.5 text-base text-gray-600 dark:text-gray-400">{hint}</p>}
            {children}
        </section>
    );
}

export default function ServiceChoices({ services, selectedIds, onPick, onDrop, busyId = null }) {
    const { t } = useTranslation();
    const priceText = usePriceText();
    const summarise = useServiceSummary();

    const transport = services.filter((s) => s.category === 'transport');
    const asp = services.filter((s) => s.category === 'asp');
    const other = services.filter((s) => s.category !== 'transport' && s.category !== 'asp');
    const isOn = (s) => selectedIds.includes(s.id);
    const disabled = busyId !== null;
    const toggle = (s) => (isOn(s) ? onDrop(s) : onPick(s));
    const currentTransport = transport.find(isOn);

    return (
        <div className="space-y-7">
            {transport.length > 0 && (
                <Group title={t('services.group_transport')} hint={t('services.group_transport_hint')}>
                    <div role="radiogroup" aria-label={t('services.group_transport')} className="space-y-3">
                        {transport.map((s) => (
                            <ChoiceCard key={s.id} icon={TruckIcon} title={routeName(s.name)} hint={priceText(s)} selected={isOn(s)} disabled={disabled} onSelect={() => !isOn(s) && onPick(s)} />
                        ))}
                        <ChoiceCard icon={XMarkIcon} title={t('services.no_transport')} hint={t('services.no_transport_hint')} selected={!currentTransport} disabled={disabled} onSelect={() => currentTransport && onDrop(currentTransport)} />
                    </div>
                </Group>
            )}

            {asp.length === 1 && (
                <Group title={asp[0].name} hint={`${priceText(asp[0])}. ${t('services.group_asp_hint')}`}>
                    <ChoiceGroup
                        layout="segmented"
                        label={asp[0].name}
                        value={isOn(asp[0]) ? 'yes' : 'no'}
                        onChange={(v) => (v === 'yes' ? !isOn(asp[0]) && onPick(asp[0]) : isOn(asp[0]) && onDrop(asp[0]))}
                        options={[{ value: 'yes', label: t('services.yes'), disabled }, { value: 'no', label: t('services.no'), disabled }]}
                    />
                </Group>
            )}

            {asp.length > 1 && (
                <Group title={t('services.group_asp')} hint={t('services.group_asp_hint')}>
                    <div role="group" aria-label={t('services.group_asp')} className="space-y-3">
                        {asp.map((s) => <ChoiceCard key={s.id} role="checkbox" icon={BuildingLibraryIcon} title={s.name} hint={priceText(s)} selected={isOn(s)} disabled={disabled} onSelect={() => toggle(s)} />)}
                    </div>
                </Group>
            )}

            {other.length > 0 && (
                <Group title={t('services.group_other')} hint={t('services.group_other_hint')}>
                    <div role="group" aria-label={t('services.group_other')} className="space-y-3">
                        {other.map((s) => <ChoiceCard key={s.id} role="checkbox" title={s.name} hint={priceText(s)} selected={isOn(s)} disabled={disabled} onSelect={() => toggle(s)} />)}
                    </div>
                </Group>
            )}

            <div className="rounded-xl border-2 border-indigo-200 bg-indigo-50 p-4 dark:border-indigo-800 dark:bg-indigo-900/20" aria-live="polite" data-testid="service-summary">
                <p className="text-sm font-medium text-indigo-900 dark:text-indigo-200">{t('services.summary_title')}</p>
                <p className="mt-1 text-lg font-semibold text-gray-900 dark:text-gray-100">{summarise(services, selectedIds)}</p>
            </div>
        </div>
    );
}
