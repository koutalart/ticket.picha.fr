import {useCallback, useRef, useState} from "react";
import {useParams} from "react-router";
import {Center, Stack, Text} from "@mantine/core";
import {t} from "@lingui/macro";
import {QRScannerComponent} from "../../components/common/AttendeeCheckInTable/QrScanner";
import {DeviceTokenGate} from "./DeviceTokenGate";
import {digitScanClient} from "./digitScanApiClient";

/**
 * DIGIT Bracelets scanner page. Orchestration only - reuses the native
 * <QRScannerComponent> (camera, flash, sound, visual feedback,
 * local scan de-dup) completely unmodified. The only thing this page
 * adds is: read the device token (DeviceTokenGate), send whatever string
 * the camera reads to the bracelet-aware scan endpoint instead of the
 * native check-in endpoint, and translate the response into the same
 * visual/audio feedback language the component already speaks.
 *
 * The prop name `onAttendeeScanned` is native and slightly misleading
 * here - the component makes no assumption about the scanned string's
 * shape, it simply forwards it. We treat it as a bracelet payload
 * (DGT1.{code}.{signature}), not an attendee public_id.
 */
const ScannerDeviceContent = () => {
    const {checkInListShortId} = useParams();
    const [bannerMessage, setBannerMessage] = useState<string | null>(null);
    const [bannerStatus, setBannerStatus] = useState<'success' | 'error' | null>(null);
    const isProcessingRef = useRef(false);
    const resetTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const handleScanned = useCallback(async (rawPayload: string) => {
        if (!checkInListShortId || isProcessingRef.current) {
            return;
        }
        isProcessingRef.current = true;
        if (resetTimeoutRef.current) {
            clearTimeout(resetTimeoutRef.current);
        }
        setBannerMessage(null);
        setBannerStatus(null);

        try {
            const result = await digitScanClient.scanBracelet(checkInListShortId, rawPayload);

            if (result.result === 'recorded') {
                setBannerStatus('success');
                setBannerMessage(t`Entry confirmed`);
            } else if (result.result === 'duplicate' || result.result === 'duplicate_retry') {
                setBannerStatus('error');
                setBannerMessage(t`Already checked in`);
            } else {
                setBannerStatus('error');
                setBannerMessage(result.messages?.[0] ?? t`Rejected`);
            }
        } catch (error: any) {
            setBannerStatus('error');

            if (!error?.response) {
                setBannerMessage(t`Network error - please try again`);
            } else {
                const reason: string | undefined = error?.response?.data?.errors?.reason;

                if (reason?.startsWith('bracelet_not_assigned')) {
                    setBannerMessage(t`Bracelet not assigned or already used`);
                } else if (reason === 'bracelet_not_found') {
                    setBannerMessage(t`Unknown bracelet`);
                } else if (reason === 'invalid_payload') {
                    setBannerMessage(t`Invalid or forged QR code`);
                } else if (reason === 'event_mismatch') {
                    setBannerMessage(t`Wrong event for this device`);
                } else {
                    setBannerMessage(error?.response?.data?.message ?? t`Scan failed`);
                }
            }
        } finally {
            isProcessingRef.current = false;
            resetTimeoutRef.current = setTimeout(() => {
                setBannerMessage(null);
                setBannerStatus(null);
            }, 2500);
        }
    }, [checkInListShortId]);

    return (
        <Stack h="100vh" gap={0} style={{overflow: 'hidden'}}>
            <Center
                py="sm"
                bg={bannerStatus === 'success' ? 'green.7' : bannerStatus === 'error' ? 'red.7' : 'dark.6'}
                style={{transition: 'background-color 150ms ease'}}
            >
                <Text c="white" fw={600} ta="center" px="md">
                    {bannerMessage ?? t`Scan a bracelet`}
                </Text>
            </Center>
            <div style={{flex: 1, position: 'relative'}}>
                <QRScannerComponent
                    onAttendeeScanned={handleScanned}
                    onClose={() => {
                        // Full-page scanner, no modal to close in the MVP -
                        // left as a no-op intentionally.
                    }}
                />
            </div>
        </Stack>
    );
};

export default function ScannerDevicePage() {
    return (
        <DeviceTokenGate>
            <ScannerDeviceContent/>
        </DeviceTokenGate>
    );
}
