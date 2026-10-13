import {t} from "@lingui/macro";
import {Badge, Button, Group} from "@mantine/core";
import {IconPlus} from "@tabler/icons-react";
import {useDisclosure} from "@mantine/hooks";
import {useParams} from "react-router";
import {Card} from "../../../../../common/Card";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {OrganizerWebhookTable} from "../../../../../common/OrganizerWebhookTable";
import {CreateOrganizerWebhookModal} from "../../../../../modals/CreateOrganizerWebhookModal";
import {TableSkeleton} from "../../../../../common/TableSkeleton";
import {useGetOrganizerWebhooks} from "../../../../../../queries/useGetOrganizerWebhooks";
import {IdParam} from "../../../../../../types";

export const WebhookSettings = () => {
    const {organizerId} = useParams();
    const [createModalOpen, {open: openCreateModal, close: closeCreateModal}] = useDisclosure(false);
    const webhooksQuery = useGetOrganizerWebhooks(organizerId as IdParam);
    const webhooks = webhooksQuery.data?.data?.data;

    const getWebhookCountText = () => {
        if (!webhooks) return t`Loading Webhooks`;
        if (webhooks.length === 0) return t`No Active Webhooks`;
        if (webhooks.length === 1) return t`1 Active Webhook`;
        return t`${webhooks.length} Active Webhooks`;
    };

    return (
        <Card>
            <HeadingWithDescription
                heading={t`Webhooks`}
                description={t`Send your orders and attendees automatically to another tool (CRM, spreadsheet, automation).`}
            />
            <Group justify="space-between" mb="md">
                <Button rightSection={<IconPlus size={16}/>} onClick={openCreateModal}>
                    {t`Add Webhook`}
                </Button>
                <Badge variant="transparent" size="lg">{getWebhookCountText()}</Badge>
            </Group>

            <TableSkeleton isVisible={!webhooks}/>
            {webhooks && <OrganizerWebhookTable webhooks={webhooks} openCreateModal={openCreateModal}/>}

            {createModalOpen && <CreateOrganizerWebhookModal onClose={closeCreateModal}/>}
        </Card>
    );
};
