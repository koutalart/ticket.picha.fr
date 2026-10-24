import {Modal} from '@mantine/core';
import {t} from '@lingui/macro';
import {IdParam, Organizer} from '../../../types';
import classes from './ContactOrganizerModal.module.scss';
import {ContactOrganizerForm} from "./ContactOrganizerForm.tsx";
import {useGetLiveChatConfig} from "../../../queries/useGetLiveChatConfig.ts";
import {LiveChatWindow} from "../LiveChatWindow";

interface ContactOrganizerModalProps {
    opened: boolean;
    onClose: () => void;
    organizer: Organizer;
    eventId?: IdParam;
}

/**
 * « Contacter l'organisateur »: the live chat when the organizer turned it on, the classic contact form otherwise.
 */
export const ContactOrganizerModal = ({opened, onClose, organizer, eventId}: ContactOrganizerModalProps) => {
    const liveChatConfig = useGetLiveChatConfig(organizer?.id);

    if (liveChatConfig.data?.enabled) {
        return (
            <LiveChatWindow
                opened={opened}
                onClose={onClose}
                organizer={organizer}
                eventId={eventId}
                config={liveChatConfig.data}
            />
        );
    }

    return (
        <Modal
            opened={opened}
            onClose={onClose}
            title={t`Contact ${organizer?.name || 'Organizer'}`}
            size="md"
            className={classes.contactModal}
        >
            <ContactOrganizerForm organizer={organizer} onDone={onClose}/>
        </Modal>
    );
};
