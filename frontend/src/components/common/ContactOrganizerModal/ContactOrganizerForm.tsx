import {Button, Group, Textarea, TextInput} from '@mantine/core';
import {useForm} from '@mantine/form';
import {t} from '@lingui/macro';
import {Organizer} from '../../../types';
import {useContactOrganizer} from '../../../mutations/useContactOrganizer';
import {showError, showSuccess} from '../../../utilites/notifications';
import classes from './ContactOrganizerModal.module.scss';
import {InputGroup} from "../InputGroup";

interface ContactOrganizerFormProps {
    organizer: Organizer;
    onDone: () => void;
    initialMessage?: string;
}

/**
 * The classic contact form (name, e-mail, message), sent to the organizer by e-mail.
 */
export const ContactOrganizerForm = ({organizer, onDone, initialMessage = ''}: ContactOrganizerFormProps) => {
    const contactMutation = useContactOrganizer();

    const contactForm = useForm({
        initialValues: {
            name: '',
            email: '',
            message: initialMessage,
        },
        validateInputOnBlur: true,
        validate: {
            name: (value) => !value ? t`Name is required` : null,
            email: (value) => {
                if (!value) return t`Email is required`;
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) return t`Invalid email`;
                return null;
            },
            message: (value) => {
                if (value.length > 5000) return t`Message cannot exceed 5000 characters`;

                return !value ? t`Message is required` : null;
            },
        },
    });

    const handleContactSubmit = (values: typeof contactForm.values) => {
        contactMutation.mutate({
            organizerId: organizer.id,
            contactData: values,
        }, {
            onSuccess: () => {
                showSuccess(t`Your message has been sent successfully!`);
                onDone();
                contactForm.reset();
            },
            onError: (error: any) => {
                showError(error?.response?.data?.message || t`Failed to send message. Please try again.`);
            },
        });
    };

    return (
        <form onSubmit={contactForm.onSubmit(handleContactSubmit)}>
            <InputGroup>
                <TextInput
                    label={t`Your Name`}
                    placeholder={t`Enter your name`}
                    required
                    {...contactForm.getInputProps('name')}
                />
                <TextInput
                    label={t`Your Email`}
                    placeholder={t`Enter your email`}
                    required
                    type="email"
                    {...contactForm.getInputProps('email')}
                />
            </InputGroup>

            <Textarea
                label={t`Message`}
                placeholder={t`Write your message here...`}
                required
                minRows={4}
                autosize
                maxLength={5001}
                mb="md"
                {...contactForm.getInputProps('message')}
            />

            <Group justify="flex-end">
                <Button
                    variant="subtle"
                    onClick={onDone}
                    className={classes.cancelButton}
                >
                    {t`Cancel`}
                </Button>
                <Button
                    type="submit"
                    className={classes.submitButton}
                    loading={contactMutation.isPending}
                    disabled={contactMutation.isPending}
                >
                    {t`Send Message`}
                </Button>
            </Group>
        </form>
    );
};
