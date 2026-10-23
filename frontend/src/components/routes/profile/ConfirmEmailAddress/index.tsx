import {useEffect, useRef, useState} from "react";
import {Link, useParams} from "react-router";
import {t} from "@lingui/macro";
import {Button, Container, Stack, Text, Title} from "@mantine/core";
import {publicApi} from "../../../../api/public-client.ts";

type Status = 'pending' | 'confirmed' | 'error';

/**
 * Opened from the email link, often on another device: works without being logged in,
 * the encrypted token in the link is enough.
 */
const ConfirmEmailAddress = () => {
    const {token} = useParams();
    const [status, setStatus] = useState<Status>('pending');
    const [message, setMessage] = useState<string | null>(null);
    const sent = useRef(false);

    useEffect(() => {
        if (sent.current || !token) {
            return;
        }
        sent.current = true;
        publicApi.post(`auth/confirm-email/${token}`)
            .then(() => setStatus('confirmed'))
            .catch((error) => {
                setMessage(error?.response?.data?.message ?? null);
                setStatus('error');
            });
    }, [token]);

    return (
        <Container size="xs" py="xl">
            <Stack align="center" gap="md" ta="center">
                {status === 'pending' && <Text>{t`Confirming email address...`}</Text>}
                {status === 'confirmed' && (
                    <>
                        <Title order={2}>{t`Email address confirmed`}</Title>
                        <Text c="dimmed">{t`Your account is confirmed. You can now publish your events.`}</Text>
                        <Button component={Link} to="/manage/events">{t`Go to my events`}</Button>
                    </>
                )}
                {status === 'error' && (
                    <>
                        <Title order={2}>{t`Error confirming email address`}</Title>
                        <Text c="dimmed">{message ?? t`The email confirmation link is invalid.`}</Text>
                        <Button component={Link} to="/manage/events" variant="light">{t`Go to my events`}</Button>
                    </>
                )}
            </Stack>
        </Container>
    );
};

export default ConfirmEmailAddress;
