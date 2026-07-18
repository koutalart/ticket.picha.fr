import {ReactNode, useState} from "react";
import {Button, Stack, Text, TextInput, Title} from "@mantine/core";
import {t, Trans} from "@lingui/macro";
import {clearDeviceToken, getStoredDeviceToken, storeDeviceToken} from "./digitScanApiClient";

interface DeviceTokenGateProps {
    children: ReactNode;
}

/**
 * One-time-per-device setup screen. A staff member pastes the token
 * generated once via `php artisan digit:scan:device:create` (Module 3,
 * unchanged); it is stored in localStorage and reused for every
 * subsequent scan until explicitly reset. Not a login system - devices
 * are provisioned by an operator ahead of time, not by end users.
 */
export const DeviceTokenGate = ({children}: DeviceTokenGateProps) => {
    const [token, setToken] = useState<string | null>(() => getStoredDeviceToken());
    const [inputValue, setInputValue] = useState("");

    const handleReset = () => {
        clearDeviceToken();
        setToken(null);
        setInputValue("");
    };

    if (token) {
        return (
            <>
                {children}
                <Button
                    variant="subtle"
                    color="gray"
                    size="xs"
                    onClick={handleReset}
                    style={{position: 'fixed', bottom: 8, right: 8, opacity: 0.6, zIndex: 1000}}
                >
                    <Trans>Reset device</Trans>
                </Button>
            </>
        );
    }

    const handleSubmit = () => {
        const trimmed = inputValue.trim();
        if (trimmed.length > 0) {
            storeDeviceToken(trimmed);
            setToken(trimmed);
        }
    };

    return (
        <Stack p="xl" maw={420} mx="auto" mt="10vh" gap="md">
            <Title order={3}><Trans>Configure this scanner</Trans></Title>
            <Text size="sm" c="dimmed">
                <Trans>
                    Paste the device token given by your event organizer. This is a one-time
                    setup per device - it will be remembered for future scans.
                </Trans>
            </Text>
            <TextInput
                placeholder={t`Device token`}
                value={inputValue}
                onChange={(event) => setInputValue(event.currentTarget.value)}
                type="password"
                autoComplete="off"
                autoFocus
            />
            <Button onClick={handleSubmit} disabled={inputValue.trim().length === 0}>
                <Trans>Save and continue</Trans>
            </Button>
        </Stack>
    );
};
