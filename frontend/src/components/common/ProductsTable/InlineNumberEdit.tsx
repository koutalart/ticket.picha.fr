import {ReactNode, useState} from "react";
import {NumberInput} from "@mantine/core";
import {IconPencil} from "@tabler/icons-react";
import {t} from "@lingui/macro";
import {i18n} from "@lingui/core";
import classes from "./ProductsTable.module.scss";

interface InlineNumberEditProps {
    value: number | null | undefined;
    display: ReactNode;
    onSave: (value: number | null) => Promise<unknown>;
    allowEmpty?: boolean;
    decimals?: number;
    suffix?: string;
    placeholder?: string;
    label: string;
}

export const InlineNumberEdit = ({value, display, onSave, allowEmpty, decimals = 0, suffix, placeholder, label}: InlineNumberEditProps) => {
    const [editing, setEditing] = useState(false);
    const [saving, setSaving] = useState(false);
    const [draft, setDraft] = useState<number | ''>(value ?? '');

    const start = () => {
        setDraft(value ?? '');
        setEditing(true);
    };

    const save = async () => {
        if (saving) return;
        const next = draft === '' ? null : Number(draft);
        if (next === (value ?? null) || (next === null && !allowEmpty)) {
            setEditing(false);
            return;
        }
        setSaving(true);
        try {
            await onSave(next);
            setEditing(false);
        } finally {
            setSaving(false);
        }
    };

    if (!editing) {
        return (
            <button type="button" className={classes.inlineEdit} onClick={start} aria-label={label} title={t`Click to edit`}>
                {display}
                <IconPencil size={13} className={classes.inlineEditIcon}/>
            </button>
        );
    }

    return (
        <NumberInput
            autoFocus
            size="sm"
            radius="md"
            className={classes.inlineInput}
            aria-label={label}
            value={draft}
            min={0}
            hideControls
            decimalScale={decimals}
            allowDecimal={decimals > 0}
            allowNegative={false}
            decimalSeparator={i18n.locale.startsWith('fr') ? ',' : '.'}
            suffix={suffix}
            placeholder={placeholder}
            disabled={saving}
            onChange={(next) => setDraft(next === '' ? '' : Number(next))}
            onBlur={save}
            onKeyDown={(event) => {
                if (event.key === 'Enter') save();
                if (event.key === 'Escape') setEditing(false);
            }}
        />
    );
};
