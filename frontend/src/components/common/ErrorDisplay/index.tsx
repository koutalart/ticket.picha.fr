import {t} from '@lingui/macro';
import {Box, Button, Container, Image, rem, Stack, Text, Title} from '@mantine/core';
import {IconHome} from '@tabler/icons-react';
import classes from './ErrorDisplay.module.scss';
import {Helmet} from "react-helmet-async";
import {NavLink, useRouteError} from "react-router";
import {PoweredByFooter} from "../PoweredByFooter";
import {getAppName, getLogoForLightBackground} from "../../../utilites/branding.ts";
import {useIsEventImmersive} from "../EventImmersiveShell/EventImmersiveContext.ts";
import {HomepageInfoMessage} from "../HomepageInfoMessage";

export const ErrorDisplay = ({eventUrl}: { eventUrl?: string }) => {
    const error = useRouteError() as any;
    const immersive = useIsEventImmersive();

    const title = error?.status === 404
        ? t`Page not found`
        : t`Something went wrong`;

    const description = error?.status === 404
        ? t`The page you are looking for does not exist`
        : t`An error occurred while loading the page`;

    console.log('ErrorDisplay error:', error);

    if (immersive) {
        return (
            <>
                <Helmet title={title}/>
                <HomepageInfoMessage
                    status={error?.status === 404 ? 'not_found' : 'error'}
                    message={title}
                    subtitle={description}
                    link={eventUrl}
                    linkText={eventUrl ? t`Back to event page` : undefined}
                />
            </>
        );
    }

    return (
        <>
            <Helmet
                title={title}
                meta={[
                    {
                        name: 'description',
                        content: description,
                    },
                ]}
            />
            <Box className={classes.wrapper}>

                {/* Animated background elements */}
                <div className={classes.backgroundOrb1}/>
                <div className={classes.backgroundOrb2}/>

                <Container size="md" className={classes.root}>
                    <Stack gap="xl" align="center">
                        <Image
                            src={getLogoForLightBackground()}
                            alt={getAppName() + " Logo"}
                            w={rem(140)}
                            h="auto"
                            fit="contain"
                            className={classes.logo}
                        />

                        <Stack gap="lg" align="center" className={classes.content}>
                            <Title order={1} className={classes.title}>
                                {title}
                            </Title>

                            <Text size="lg" c="dimmed" className={classes.description}>
                                {description}
                            </Text>
                            <Button
                                component={NavLink}
                                to="/"
                                leftSection={<IconHome size={18}/>}
                                variant="gradient"
                                gradient={{from: 'primary', to: 'secondary'}}
                                className={classes.button}
                            >
                                {t`Go to home page`}
                            </Button>
                        </Stack>

                        <PoweredByFooter/>
                    </Stack>
                </Container>
            </Box>
        </>
    );
};

export default ErrorDisplay;
