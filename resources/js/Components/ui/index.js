// The UX system for low-digital-literacy users. Import from here:
//   import { Button, Field, Input, ChoiceGroup, Alert, FlashMessages, StatusBadge, useConfirm, ... } from '@/Components/ui';
// Rules, when to use what, and the old-component -> new-component migration table: UX_GUIDELINES.md.
// A live, interactive gallery of everything (development only): /dev/ui-kit.

export { default as Button, IconButton } from './Button';
export { Spinner, LoadingState } from './Loading';
export { Card, SectionHeader, BackLink, PageHeader, Page } from './Layout';
export { Field, FieldError, Input, TextArea, Select, CheckRow } from './Form';
export { ChoiceCard, ChoiceGroup } from './Choices';
export { default as Alert, FlashMessages } from './Alert';
export { default as StatusBadge, STATUS_TONE } from './StatusBadge';
export { default as ConfirmDialog, useConfirm } from './ConfirmDialog';
export { EmptyState, SuccessPanel, Disclosure } from './States';
export { default as ActionTile } from './ActionTile';
export { PrintButton, PrintArea } from './Print';
export { useWizard, WizardShell, WizardProgress, WizardButtons, ReviewList } from './Wizard';
export { TAP, FOCUS, TONE, PAGE_WIDTH } from './tokens';
