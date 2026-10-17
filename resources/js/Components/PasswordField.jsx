import { Button, Field, Input } from '@/Components/ui';
import { EyeIcon, EyeSlashIcon } from '@heroicons/react/24/outline';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

// A password box with a large "Show password" button underneath, for people who cannot tell what they typed.
// The button is a real button (never hover-only) and says what it does in words.
export default function PasswordField({ id, label, hint, error, value, onChange, autoComplete = 'current-password', autoFocus = false, showToggle = true }) {
    const { t } = useTranslation();
    const [shown, setShown] = useState(false);

    return (
        <div>
            <Field id={id} label={label} hint={hint} error={error}>
                <Input type={shown ? 'text' : 'password'} value={value} onChange={(e) => onChange(e.target.value)} autoComplete={autoComplete} autoFocus={autoFocus} autoCapitalize="none" autoCorrect="off" spellCheck={false} />
            </Field>
            {showToggle && (
                <Button size="md" variant="quiet" icon={shown ? EyeSlashIcon : EyeIcon} onClick={() => setShown((s) => !s)} aria-pressed={shown} className="mt-1">
                    {shown ? t('signin.hide_password') : t('signin.show_password')}
                </Button>
            )}
        </div>
    );
}
