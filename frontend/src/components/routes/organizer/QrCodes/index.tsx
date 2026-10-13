import {useState} from "react";
import {useParams} from "react-router";
import {t} from "@lingui/macro";
import {Box, Button, Text} from "@mantine/core";
import {IconPlus, IconQrcode} from "@tabler/icons-react";
import {PageBody} from "../../../common/PageBody";
import {PageTitle} from "../../../common/PageTitle";
import {Card} from "../../../common/Card";
import {TableSkeleton} from "../../../common/TableSkeleton";
import {QrLink} from "../../../../types.ts";
import {useGetQrLinks} from "../../../../queries/useGetQrLinks.ts";
import {useGetOrganizer} from "../../../../queries/useGetOrganizer.ts";
import {useUpdateQrLink} from "../../../../mutations/useUpdateQrLink.ts";
import {useDeleteQrLink} from "../../../../mutations/useDeleteQrLink.ts";
import {showError, showSuccess} from "../../../../utilites/notifications.tsx";
import {confirmationDialog} from "../../../../utilites/confirmationDialog.tsx";
import {QrLinkCard} from "./QrLinkCard.tsx";
import {QrLinkFormModal} from "./QrLinkFormModal.tsx";
import {QrLinkStatsDrawer} from "./QrLinkStatsDrawer.tsx";
import classes from "./QrCodes.module.scss";

const QrCodes = () => {
    const {organizerId} = useParams();
    const qrLinksQuery = useGetQrLinks(organizerId!);
    const {data: organizer} = useGetOrganizer(organizerId!);
    const updateMutation = useUpdateQrLink();
    const deleteMutation = useDeleteQrLink();
    const [formTarget, setFormTarget] = useState<QrLink | 'new' | null>(null);
    const [statsTarget, setStatsTarget] = useState<QrLink | null>(null);
    const qrLinks = qrLinksQuery.data;
    const currency = organizer?.currency || 'EUR';

    const toggleStatus = (qrLink: QrLink) => {
        updateMutation.mutate({
            organizerId: organizerId!,
            qrLinkId: qrLink.id,
            qrLink: {
                name: qrLink.name,
                destination_type: qrLink.destination_type,
                destination_url: qrLink.destination_url,
                event_id: qrLink.event_id,
                title: qrLink.title,
                description: qrLink.description,
                social_links: qrLink.social_links,
                design: qrLink.design,
                status: qrLink.status === 'ACTIVE' ? 'PAUSED' : 'ACTIVE',
            },
        }, {
            onSuccess: () => showSuccess(qrLink.status === 'ACTIVE' ? t`QR code paused` : t`QR code reactivated`),
            onError: () => showError(t`Unable to update the QR code`),
        });
    };

    const remove = (qrLink: QrLink) => {
        confirmationDialog(t`Delete this QR code? Printed copies will stop working and its statistics will be lost.`, () => {
            deleteMutation.mutate({organizerId: organizerId!, qrLinkId: qrLink.id}, {
                onSuccess: () => showSuccess(t`QR code deleted`),
                onError: () => showError(t`Unable to delete the QR code`),
            });
        });
    };

    return (
        <PageBody>
            <PageTitle>{t`QR codes`}</PageTitle>
            <Box>
                <Card>
                    <div className={classes.intro}>
                        <Text size="sm" c="dimmed" maw={620}>
                            {t`Create a QR code for each medium (poster, flyer, screen...) and see how many people scan it, from which device, and how many tickets it sells. You can change where a QR code leads at any time without reprinting it.`}
                        </Text>
                        <Button leftSection={<IconPlus size={16}/>} onClick={() => setFormTarget('new')}>
                            {t`New QR code`}
                        </Button>
                    </div>
                </Card>

                <TableSkeleton isVisible={!qrLinks}/>

                {qrLinks && qrLinks.length === 0 && (
                    <Card className={classes.emptyState}>
                        <IconQrcode size={40} stroke={1.5}/>
                        <Text fw={600} mt="sm">{t`No QR codes yet`}</Text>
                        <Text size="sm" c="dimmed" mb="md">
                            {t`Start with the poster for your next event.`}
                        </Text>
                        <Button onClick={() => setFormTarget('new')}>{t`Create my first QR code`}</Button>
                    </Card>
                )}

                {qrLinks && qrLinks.length > 0 && (
                    <div className={classes.grid}>
                        {qrLinks.map(qrLink => (
                            <QrLinkCard
                                key={qrLink.id}
                                qrLink={qrLink}
                                currency={currency}
                                onEdit={() => setFormTarget(qrLink)}
                                onStats={() => setStatsTarget(qrLink)}
                                onToggleStatus={() => toggleStatus(qrLink)}
                                onDelete={() => remove(qrLink)}
                            />
                        ))}
                    </div>
                )}
            </Box>

            {formTarget && (
                <QrLinkFormModal
                    qrLink={formTarget === 'new' ? undefined : formTarget}
                    onClose={() => setFormTarget(null)}
                />
            )}

            <QrLinkStatsDrawer qrLink={statsTarget} currency={currency} onClose={() => setStatsTarget(null)}/>
        </PageBody>
    );
};

export default QrCodes;
