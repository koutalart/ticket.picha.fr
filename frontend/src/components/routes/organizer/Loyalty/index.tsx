import {useState} from "react";
import {useParams} from "react-router";
import {t} from "@lingui/macro";
import {Alert, Badge, Button, CopyButton, Modal, Skeleton, Text} from "@mantine/core";
import {IconCheck, IconCopy, IconGift, IconScan} from "@tabler/icons-react";
import {PageBody} from "../../../common/PageBody";
import {PageTitle} from "../../../common/PageTitle";
import {QRScannerComponent, QrScanOutcome} from "../../../common/AttendeeCheckInTable/QrScanner.tsx";
import {useGetLoyaltyProgram} from "../../../../queries/useGetLoyaltyProgram.ts";
import {useGetOrganizer} from "../../../../queries/useGetOrganizer.ts";
import {useStampLoyaltyCard} from "../../../../mutations/useStampLoyaltyCard.ts";
import {LoyaltyStampResult} from "../../../../types.ts";
import {loyaltyCardPageUrl} from "../../../../utilites/urlHelper.ts";
import {showError} from "../../../../utilites/notifications.tsx";
import {LoyaltyProgramForm} from "./LoyaltyProgramForm.tsx";
import classes from "./Loyalty.module.scss";

type StampError = { response?: { data?: { errors?: Record<string, string | string[]>, message?: string } } };

const stampErrorMessage = (error: StampError): string => {
    const message = error?.response?.data?.errors?.code ?? error?.response?.data?.message;
    return (Array.isArray(message) ? message[0] : message) || t`This card could not be stamped.`;
};

const Loyalty = () => {
    const {organizerId} = useParams();
    const {data: settings, isLoading} = useGetLoyaltyProgram(organizerId);
    const {data: organizer} = useGetOrganizer(organizerId);
    const stampMutation = useStampLoyaltyCard();
    const [scannerOpen, setScannerOpen] = useState(false);
    const [lastResult, setLastResult] = useState<LoyaltyStampResult | null>(null);

    const program = settings?.program ?? null;
    const publicUrl = organizer ? loyaltyCardPageUrl(organizer) : '';

    const handleScan = async (code: string): Promise<QrScanOutcome> => {
        try {
            const {data} = await stampMutation.mutateAsync({organizerId, code});
            setLastResult(data);
            setScannerOpen(false);
            return 'success';
        } catch (error) {
            showError(stampErrorMessage(error as StampError));
            return 'error';
        }
    };

    return (
        <PageBody>
            <PageTitle>{t`Loyalty card`}</PageTitle>

            {isLoading && <Skeleton height={200}/>}

            {!isLoading && settings && (
                <>
                    {program && (
                        <div className={classes.counter}>
                            <div className={classes.counterHeader}>
                                <div>
                                    <Text fw={600}>{program.name}</Text>
                                    <Text size="sm" c="dimmed">
                                        {t`${program.stamps_required} stamps = ${program.reward_description}`}
                                    </Text>
                                </div>
                                <Badge color={program.is_active ? 'green' : 'gray'}>
                                    {program.is_active ? t`Active` : t`Inactive`}
                                </Badge>
                            </div>

                            <Button
                                size="lg"
                                fullWidth
                                leftSection={<IconScan size={22}/>}
                                onClick={() => setScannerOpen(true)}
                                disabled={!program.is_active}
                            >
                                {t`Stamp a card`}
                            </Button>

                            {lastResult && (
                                <Alert
                                    mt="md"
                                    color={lastResult.reward_unlocked ? 'green' : 'blue'}
                                    icon={lastResult.reward_unlocked ? <IconGift/> : <IconCheck/>}
                                    title={lastResult.reward_unlocked
                                        ? t`Reward unlocked for ${lastResult.card.first_name}!`
                                        : t`Stamp added for ${lastResult.card.first_name}`}
                                >
                                    {lastResult.reward_unlocked
                                        ? t`Give them: ${program.reward_description}. The card starts again from zero.`
                                        : t`${lastResult.card.stamps} / ${program.stamps_required} stamps`}
                                </Alert>
                            )}

                            {program.is_active && publicUrl && (
                                <div className={classes.share}>
                                    <Text size="sm" c="dimmed">{t`Link to share with customers`}</Text>
                                    <div className={classes.shareRow}>
                                        <Text size="sm" className={classes.shareUrl}>{publicUrl}</Text>
                                        <CopyButton value={publicUrl}>
                                            {({copied, copy}) => (
                                                <Button size="xs" variant="light" onClick={copy}
                                                        leftSection={copied ? <IconCheck size={14}/> : <IconCopy size={14}/>}>
                                                    {copied ? t`Copied` : t`Copy`}
                                                </Button>
                                            )}
                                        </CopyButton>
                                    </div>
                                </div>
                            )}
                        </div>
                    )}

                    {(!settings.apple_enabled || !settings.google_enabled) && (
                        <Alert color="yellow" mb="md">
                            {!settings.apple_enabled && !settings.google_enabled
                                ? t`Apple Wallet and Google Wallet are not connected yet. Customers can sign up, but the card cannot be added to a phone until PICHA connects them.`
                                : !settings.apple_enabled
                                    ? t`Apple Wallet is not connected yet. Only Google Wallet is available.`
                                    : t`Google Wallet is not connected yet. Only Apple Wallet is available.`}
                        </Alert>
                    )}

                    <LoyaltyProgramForm organizerId={organizerId} program={program}/>
                </>
            )}

            {scannerOpen && (
                <Modal.Root
                    opened
                    onClose={() => setScannerOpen(false)}
                    fullScreen
                    radius={0}
                    transitionProps={{transition: 'fade', duration: 200}}
                    padding={'none'}
                >
                    <Modal.Overlay/>
                    <Modal.Content>
                        <QRScannerComponent
                            onAttendeeScanned={handleScan}
                            onClose={() => setScannerOpen(false)}
                        />
                    </Modal.Content>
                </Modal.Root>
            )}
        </PageBody>
    );
};

export default Loyalty;
