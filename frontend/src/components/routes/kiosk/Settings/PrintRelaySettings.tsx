import {useState} from "react";
import {Alert, Button, CopyButton, Stack, Text, TextInput} from "@mantine/core";
import {IconCheck, IconCopy, IconKey} from "@tabler/icons-react";
import {t} from "@lingui/macro";
import {useGetKioskPrintRelayStatus} from "../../../../queries/useGetKioskPrintRelayStatus.ts";
import {useGenerateKioskPrintRelayToken} from "../../../../mutations/useGenerateKioskPrintRelayToken.ts";
import {confirmationDialog} from "../../../../utilites/confirmationDialog.tsx";
import {showError} from "../../../../utilites/notifications.tsx";
import {prettyDate} from "../../../../utilites/dates.ts";
import {IdParam} from "../../../../types.ts";
import classes from "../../../layouts/Kiosk/Kiosk.module.scss";

interface PrintRelaySettingsProps {
    eventId: IdParam;
    printerHost: string;
    timezone: string;
}

const CopyField = ({label, value}: { label: string; value: string }) => (
    <TextInput
        label={label}
        value={value}
        readOnly
        rightSectionWidth={110}
        rightSection={
            <CopyButton value={value}>
                {({copied, copy}) => (
                    <Button
                        size="compact-sm"
                        variant="subtle"
                        onClick={copy}
                        leftSection={copied ? <IconCheck size={14}/> : <IconCopy size={14}/>}
                    >
                        {copied ? t`Copied` : t`Copy`}
                    </Button>
                )}
            </CopyButton>
        }
    />
);

export const PrintRelaySettings = ({eventId, printerHost, timezone}: PrintRelaySettingsProps) => {
    const statusQuery = useGetKioskPrintRelayStatus(eventId);
    const generateToken = useGenerateKioskPrintRelayToken();
    const [token, setToken] = useState<string | null>(null);
    const status = statusQuery.data?.data;

    const generate = () => {
        generateToken.mutate({eventId}, {
            onSuccess: (response) => setToken(response.data.relay_token),
            onError: (error: any) => {
                if (error?.response?.status === 403) {
                    showError(t`Only an organizer of this event can generate a relay token.`);
                    return;
                }
                showError(t`Could not generate the relay token.`);
            },
        });
    };

    const onGenerateClick = () => {
        if (status?.configured) {
            confirmationDialog(
                t`A relay token already exists. The new token will replace it and the relay running with the old one will stop printing. Continue?`,
                generate,
            );
            return;
        }
        generate();
    };

    const command = `python3 picha_print_relay.py --token ${token ?? ''} --printer ${printerHost || '192.168.1.21'}`;

    return (
        <Stack gap="sm" mb="md">
            <Text size="sm" c="dimmed">
                {t`A computer on the venue network runs the PICHA print relay. It picks up the tickets printed from this station and sends them to the Zebra.`}
            </Text>
            <div className={classes.infoList}>
                <div className={classes.infoRow}>
                    <span className={classes.infoLabel}>{t`Relay token`}</span>
                    <span>{status ? (status.configured ? t`Configured` : t`Not configured`) : '...'}</span>
                </div>
                <div className={classes.infoRow}>
                    <span className={classes.infoLabel}>{t`Relay last seen`}</span>
                    <span>{status?.last_seen_at ? prettyDate(status.last_seen_at, timezone, true) : t`Never`}</span>
                </div>
                <div className={classes.infoRow}>
                    <span className={classes.infoLabel}>{t`Tickets waiting`}</span>
                    <span>{status?.pending_jobs ?? 0}</span>
                </div>
            </div>
            <div>
                <Button
                    variant="default"
                    leftSection={<IconKey size={16}/>}
                    loading={generateToken.isPending}
                    onClick={onGenerateClick}
                >
                    {status?.configured ? t`Generate a new relay token` : t`Generate a relay token`}
                </Button>
            </div>
            {token && (
                <Alert color="yellow" title={t`Copy this token now, it will not be shown again.`}>
                    <Stack gap="sm">
                        <CopyField label={t`Relay token`} value={token}/>
                        <CopyField label={t`Command to run on the venue computer`} value={command}/>
                    </Stack>
                </Alert>
            )}
        </Stack>
    );
};
