import {t} from "@lingui/macro";
import {ActionIcon, Button, Group, Paper, Stack, Switch, Text, TextInput} from "@mantine/core";
import {IconPlus, IconTrash} from "@tabler/icons-react";
import {HomepageFooterEventLink, HomepageFooterRelatedLink, HomepageFooterSettings} from "../../../../types.ts";

const MAX_EVENT_LINKS = 4;
const MAX_RELATED_LINKS = 3;

interface FooterSettingsPanelProps {
    enabled: boolean;
    settings: HomepageFooterSettings;
    embeddedRegistrationActive: boolean;
    errors?: Record<string, unknown>;
    onEnabledChange: (enabled: boolean) => void;
    onSettingsChange: (settings: HomepageFooterSettings) => void;
}

export const FooterSettingsPanel = ({
                                        enabled,
                                        settings,
                                        embeddedRegistrationActive,
                                        errors = {},
                                        onEnabledChange,
                                        onSettingsChange,
                                    }: FooterSettingsPanelProps) => {
    const eventLinks = settings.event_links || [];
    const relatedLinks = settings.related_links || [];
    const error = (key: string) => errors[`homepage_footer_settings.${key}`] as string | undefined;
    const update = (patch: Partial<HomepageFooterSettings>) => onSettingsChange({...settings, ...patch});

    const updateEventLink = (index: number, patch: Partial<HomepageFooterEventLink>) =>
        update({event_links: eventLinks.map((link, i) => i === index ? {...link, ...patch} : link)});
    const updateRelatedLink = (index: number, patch: Partial<HomepageFooterRelatedLink>) =>
        update({related_links: relatedLinks.map((link, i) => i === index ? {...link, ...patch} : link)});

    return (
        <Stack gap="md">
            <Switch
                label={t`Rich footer`}
                description={t`Replaces the footer of the page when the full screen header and the embedded registration are used: newsletter, social networks, useful links and legal information.`}
                checked={enabled}
                onChange={(event) => onEnabledChange(event.currentTarget.checked)}
            />

            {enabled && !embeddedRegistrationActive && (
                <Text size="xs" c="orange">
                    {t`The rich footer only appears with the full screen header and the embedded registration enabled in the event settings.`}
                </Text>
            )}

            {enabled && (
                <>
                    <Switch
                        label={t`Newsletter block`}
                        description={t`Visitors can leave their e-mail address. Addresses are saved as contacts of the organizer with their consent, no e-mail is sent.`}
                        checked={settings.newsletter_enabled !== false}
                        onChange={(event) => update({newsletter_enabled: event.currentTarget.checked})}
                    />
                    {settings.newsletter_enabled !== false && (
                        <TextInput
                            label={t`Newsletter title`}
                            placeholder={t`Stay informed`}
                            maxLength={120}
                            value={settings.newsletter_title || ''}
                            error={error('newsletter_title')}
                            onChange={(event) => update({newsletter_title: event.currentTarget.value})}
                        />
                    )}

                    <div>
                        <Text fw={500} size="sm">{t`Links in the "The event" column`}</Text>
                        <Text size="xs" c="dimmed" mb="xs">
                            {t`"Register" is always shown. Add links to other pages of the event, for example the programme or practical information. Empty links are hidden.`}
                        </Text>
                        <Stack gap="xs">
                            {eventLinks.map((link, index) => (
                                <Paper key={index} withBorder p="xs" radius="md">
                                    <Group gap="xs" align="flex-end" wrap="nowrap">
                                        <Stack gap={6} style={{flex: 1}}>
                                            <TextInput
                                                size="xs"
                                                label={t`Label`}
                                                maxLength={60}
                                                value={link.label || ''}
                                                error={error(`event_links.${index}.label`)}
                                                onChange={(event) => updateEventLink(index, {label: event.currentTarget.value})}
                                            />
                                            <TextInput
                                                size="xs"
                                                label={t`Link`}
                                                placeholder="https://"
                                                value={link.url || ''}
                                                error={error(`event_links.${index}.url`)}
                                                onChange={(event) => updateEventLink(index, {url: event.currentTarget.value})}
                                            />
                                        </Stack>
                                        <ActionIcon
                                            variant="subtle"
                                            color="red"
                                            aria-label={t`Remove`}
                                            onClick={() => update({event_links: eventLinks.filter((_, i) => i !== index)})}
                                        >
                                            <IconTrash size={16}/>
                                        </ActionIcon>
                                    </Group>
                                </Paper>
                            ))}
                            {eventLinks.length < MAX_EVENT_LINKS && (
                                <Button
                                    variant="light"
                                    size="xs"
                                    leftSection={<IconPlus size={14}/>}
                                    onClick={() => update({event_links: [...eventLinks, {label: '', url: ''}]})}
                                >
                                    {t`Add a link`}
                                </Button>
                            )}
                        </Stack>
                    </div>

                    <div>
                        <Text fw={500} size="sm">{t`"See also" cards`}</Text>
                        <Text size="xs" c="dimmed" mb="xs">
                            {t`Up to 3 links to other websites, with an optional image.`}
                        </Text>
                        <Stack gap="xs">
                            {relatedLinks.map((link, index) => (
                                <Paper key={index} withBorder p="xs" radius="md">
                                    <Group gap="xs" align="flex-end" wrap="nowrap">
                                        <Stack gap={6} style={{flex: 1}}>
                                            <TextInput
                                                size="xs"
                                                label={t`Title`}
                                                maxLength={80}
                                                value={link.title || ''}
                                                error={error(`related_links.${index}.title`)}
                                                onChange={(event) => updateRelatedLink(index, {title: event.currentTarget.value})}
                                            />
                                            <TextInput
                                                size="xs"
                                                label={t`Link`}
                                                placeholder="https://"
                                                value={link.url || ''}
                                                error={error(`related_links.${index}.url`)}
                                                onChange={(event) => updateRelatedLink(index, {url: event.currentTarget.value})}
                                            />
                                            <TextInput
                                                size="xs"
                                                label={t`Image address (optional)`}
                                                placeholder="https://"
                                                value={link.image_url || ''}
                                                error={error(`related_links.${index}.image_url`)}
                                                onChange={(event) => updateRelatedLink(index, {image_url: event.currentTarget.value})}
                                            />
                                        </Stack>
                                        <ActionIcon
                                            variant="subtle"
                                            color="red"
                                            aria-label={t`Remove`}
                                            onClick={() => update({related_links: relatedLinks.filter((_, i) => i !== index)})}
                                        >
                                            <IconTrash size={16}/>
                                        </ActionIcon>
                                    </Group>
                                </Paper>
                            ))}
                            {relatedLinks.length < MAX_RELATED_LINKS && (
                                <Button
                                    variant="light"
                                    size="xs"
                                    leftSection={<IconPlus size={14}/>}
                                    onClick={() => update({related_links: [...relatedLinks, {title: '', url: '', image_url: ''}]})}
                                >
                                    {t`Add a card`}
                                </Button>
                            )}
                        </Stack>
                    </div>

                    <Text fw={500} size="sm">{t`Legal links`}</Text>
                    <TextInput
                        label={t`Privacy policy`}
                        description={t`Leave empty to use the platform privacy policy.`}
                        placeholder="https://"
                        value={settings.privacy_policy_url || ''}
                        error={error('privacy_policy_url')}
                        onChange={(event) => update({privacy_policy_url: event.currentTarget.value})}
                    />
                    <TextInput
                        label={t`Terms of use and legal notice`}
                        description={t`Leave empty to use the platform terms of use.`}
                        placeholder="https://"
                        value={settings.terms_url || ''}
                        error={error('terms_url')}
                        onChange={(event) => update({terms_url: event.currentTarget.value})}
                    />
                    <TextInput
                        label={t`Accessibility`}
                        description={t`Optional. The link is hidden when empty.`}
                        placeholder="https://"
                        value={settings.accessibility_url || ''}
                        error={error('accessibility_url')}
                        onChange={(event) => update({accessibility_url: event.currentTarget.value})}
                    />
                </>
            )}
        </Stack>
    );
};

export const cleanFooterSettings = (settings: HomepageFooterSettings): HomepageFooterSettings => ({
    newsletter_enabled: settings.newsletter_enabled !== false,
    newsletter_title: settings.newsletter_title?.trim() || null,
    privacy_policy_url: settings.privacy_policy_url?.trim() || null,
    terms_url: settings.terms_url?.trim() || null,
    accessibility_url: settings.accessibility_url?.trim() || null,
    event_links: (settings.event_links || [])
        .map(link => ({label: link.label?.trim() || '', url: link.url?.trim() || ''}))
        .filter(link => link.label && link.url),
    related_links: (settings.related_links || [])
        .map(link => ({
            title: link.title?.trim() || '',
            url: link.url?.trim() || '',
            image_url: link.image_url?.trim() || null,
        }))
        .filter(link => link.title && link.url),
});
