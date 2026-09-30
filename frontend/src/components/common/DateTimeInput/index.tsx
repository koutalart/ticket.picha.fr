import {ReactNode} from "react";
import {DateTimePicker} from "@mantine/dates";
import dayjs from "dayjs";

const NATIVE_FORMAT = 'YYYY-MM-DDTHH:mm';

interface DateTimeInputProps {
    value?: string | null;
    onChange?: (value: string) => void;
    label?: ReactNode;
    description?: ReactNode;
    placeholder?: string;
    error?: ReactNode;
    required?: boolean;
    disabled?: boolean;
    clearable?: boolean;
    minDate?: string;
}

/**
 * Drop-in replacement for <TextInput type="datetime-local">: same
 * "YYYY-MM-DDTHH:mm" string value, but always displayed in 24-hour format,
 * whatever the language of the visitor's operating system.
 */
export const DateTimeInput = ({value, onChange, clearable = true, ...props}: DateTimeInputProps) => {
    const parsed = value ? dayjs(value) : null;

    return (
        <DateTimePicker
            {...props}
            value={parsed?.isValid() ? parsed.format('YYYY-MM-DD HH:mm:ss') : null}
            onChange={(next) => onChange?.(next ? dayjs(next).format(NATIVE_FORMAT) : '')}
            valueFormat="DD/MM/YYYY HH:mm"
            timePickerProps={{format: '24h'}}
            clearable={clearable}
        />
    );
};
