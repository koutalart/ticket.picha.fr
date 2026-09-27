import {useState} from "react";
import {t} from "@lingui/macro";
import {Button, Group, Modal, Stack, TextInput} from "@mantine/core";
import {IconPrinter} from "@tabler/icons-react";
import {boxOfficeClient} from "../api/box-office.client.ts";
import {useReprintBoxOfficeTicket} from "../mutations/useReprintBoxOfficeTicket.ts";
import {showError} from "../utilites/notifications.tsx";
import {IdParam} from "../types.ts";
import {KioskPrintOutput} from "./useKioskSettings.ts";

interface PrinterPrompt {
    attendeePublicIds: string[];
    host: string;
    isReprint: boolean;
}

interface UseKioskTicketPrinterOptions {
    eventId: IdParam;
    printMode: KioskPrintOutput | 'a4';
    skipPrint?: boolean;
    zebraPrinterHost?: string;
    onZebraPrinterHostChange?: (host: string) => void;
}

const openPdfBlobInNewTab = (blob: Blob) => {
    const blobUrl = URL.createObjectURL(blob);
    const printWindow = window.open(blobUrl, '_blank');
    printWindow?.print();
};

/**
 * Prints box office tickets or badges: Zebra (ZPL) with a "printer not
 * reachable" prompt (new IP + retry, or PDF fallback), or PDF directly.
 */
export const useKioskTicketPrinter = ({
    eventId,
    printMode,
    skipPrint = false,
    zebraPrinterHost = '',
    onZebraPrinterHostChange,
}: UseKioskTicketPrinterOptions) => {
    const reprintTicket = useReprintBoxOfficeTicket();
    const [printerPrompt, setPrinterPrompt] = useState<PrinterPrompt | null>(null);
    const [isRetryingPrint, setIsRetryingPrint] = useState(false);

    const printOnZebra = async (attendeePublicIds: string[], host: string): Promise<string[]> => {
        const failed: string[] = [];
        for (const attendeePublicId of attendeePublicIds) {
            if (failed.length > 0) {
                failed.push(attendeePublicId);
                continue;
            }
            try {
                await boxOfficeClient.printZpl(eventId, attendeePublicId, host);
            } catch {
                failed.push(attendeePublicId);
            }
        }
        return failed;
    };

    const openTicketPdf = async (attendeePublicId: string) => {
        try {
            const pdf = await boxOfficeClient.getTicketPdf(eventId, attendeePublicId);
            openPdfBlobInNewTab(pdf);
        } catch {
            showError(t`Could not open the ticket PDF. Use the reprint button to try again.`);
        }
    };

    const openTicketPdfs = async (attendeePublicIds: string[], isReprint: boolean) => {
        for (const attendeePublicId of attendeePublicIds) {
            if (isReprint) {
                try {
                    openPdfBlobInNewTab(await reprintTicket.mutateAsync({eventId, attendeePublicId}));
                } catch {
                    showError(t`Could not reprint the ticket. Please try again.`);
                }
                continue;
            }
            await openTicketPdf(attendeePublicId);
        }
    };

    /** Resolves true when every ticket was sent to the printer or opened as PDF. */
    const printTickets = async (attendeePublicIds: string[], isReprint = false): Promise<boolean> => {
        if (attendeePublicIds.length === 0 || printMode === 'none' || skipPrint) {
            return true;
        }

        if (printMode !== 'zebra') {
            await openTicketPdfs(attendeePublicIds, isReprint);
            return true;
        }

        const host = zebraPrinterHost.trim();
        const failed = host ? await printOnZebra(attendeePublicIds, host) : attendeePublicIds;
        if (failed.length > 0) {
            setPrinterPrompt({attendeePublicIds: failed, host, isReprint});
            return false;
        }
        return true;
    };

    const retryPrinterPrompt = async () => {
        if (!printerPrompt) {
            return;
        }
        const host = printerPrompt.host.trim();
        if (!host) {
            return;
        }

        setIsRetryingPrint(true);
        try {
            const failed = await printOnZebra(printerPrompt.attendeePublicIds, host);
            if (failed.length === printerPrompt.attendeePublicIds.length) {
                showError(t`The Zebra printer did not respond.`);
                return;
            }
            onZebraPrinterHostChange?.(host);
            if (failed.length > 0) {
                setPrinterPrompt({...printerPrompt, attendeePublicIds: failed, host});
                showError(t`Some tickets could not be printed.`);
                return;
            }
            setPrinterPrompt(null);
        } finally {
            setIsRetryingPrint(false);
        }
    };

    const openPrinterPromptPdfs = async () => {
        if (!printerPrompt) {
            return;
        }
        const {attendeePublicIds, isReprint} = printerPrompt;
        setPrinterPrompt(null);
        await openTicketPdfs(attendeePublicIds, isReprint);
    };

    const printerPromptModal = (
        <Modal
            opened={printerPrompt !== null}
            onClose={() => setPrinterPrompt(null)}
            title={t`Printer not reachable`}
            centered
        >
            <Stack>
                <p>
                    {printerPrompt?.host
                        ? t`The Zebra printer at ${printerPrompt.host} did not respond. Enter its current IP address to try again, or open the tickets as PDF.`
                        : t`No Zebra printer IP is set for this station. Enter it to print, or open the tickets as PDF.`}
                </p>
                <TextInput
                    label={t`Printer IP address`}
                    placeholder="192.168.1.50"
                    value={printerPrompt?.host ?? ''}
                    onChange={(event) => {
                        const host = event.currentTarget.value.trim();
                        setPrinterPrompt((current) => current ? {...current, host} : current);
                    }}
                    data-autofocus
                />
                <Group justify="flex-end">
                    <Button variant="default" onClick={() => void openPrinterPromptPdfs()}>
                        {t`Open tickets as PDF`}
                    </Button>
                    <Button
                        leftSection={<IconPrinter/>}
                        loading={isRetryingPrint}
                        disabled={!printerPrompt?.host}
                        onClick={() => void retryPrinterPrompt()}
                    >
                        {t`Retry`}
                    </Button>
                </Group>
            </Stack>
        </Modal>
    );

    return {
        printTickets,
        printerPromptModal,
        isReprinting: reprintTicket.isPending,
    };
};
