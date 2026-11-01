export default function Checkbox({ className = '', ...props }) {
    return (
        <input
            {...props}
            type="checkbox"
            className={
                'rounded border-stone-300 text-brand-600 shadow-sm focus:ring-brand-500 dark:border-stone-700 dark:bg-stone-900 dark:focus:ring-brand-600 dark:focus:ring-offset-stone-800 ' +
                className
            }
        />
    );
}
