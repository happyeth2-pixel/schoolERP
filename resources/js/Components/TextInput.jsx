import { forwardRef, useEffect, useImperativeHandle, useRef } from 'react';

export default forwardRef(function TextInput(
    { type = 'text', className = '', isFocused = false, ...props },
    ref,
) {
    const localRef = useRef(null);

    useImperativeHandle(ref, () => ({
        focus: () => localRef.current?.focus(),
    }));

    useEffect(() => {
        if (isFocused) {
            localRef.current?.focus();
        }
    }, [isFocused]);

    return (
        <input
            {...props}
            type={type}
            className={
                'rounded-md border-stone-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-300 dark:focus:border-brand-600 dark:focus:ring-brand-600 ' +
                className
            }
            ref={localRef}
        />
    );
});
