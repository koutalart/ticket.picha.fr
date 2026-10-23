import React, {useEffect, useMemo, useState} from "react";
import {useParams} from "react-router";
import {t} from "@lingui/macro";
import {ActionIcon, Badge, Button, Group, SegmentedControl, SimpleGrid, Tabs, Text, TextInput, Tooltip} from "@mantine/core";
import {useForm} from "@mantine/form";
import {
    IconArrowDown,
    IconArrowUp,
    IconBrandFacebook,
    IconBrandInstagram,
    IconBrandSpotify,
    IconBrandTiktok,
    IconBrandYoutube,
    IconExternalLink,
    IconPlus,
    IconTrash,
    IconWorld,
} from "@tabler/icons-react";
import {PageBody} from "../../../common/PageBody";
import {PageTitle} from "../../../common/PageTitle";
import {Card} from "../../../common/Card";
import {Editor} from "../../../common/Editor";
import {ImageUploadDropzone} from "../../../common/ImageUploadDropzone";
import {ArtistsList, FaqList, PracticalInfoList} from "../../../common/EventPagesContent";
import {useGetEvent} from "../../../../queries/useGetEvent.ts";
import {useGetEventPages} from "../../../../queries/useGetEventPages.ts";
import {useUpdateEventPages} from "../../../../mutations/useUpdateEventPages.ts";
import {useFormErrorResponseHandler} from "../../../../hooks/useFormErrorResponseHandler.tsx";
import {showSuccess} from "../../../../utilites/notifications.tsx";
import {computeThemeVariables, validateThemeSettings} from "../../../../utilites/themeUtils.ts";
import {eventDedicatedPagePath, eventPageLabel, EVENT_PAGE_SLUGS} from "../../../../utilites/eventPages.ts";
import {
    EventArtist,
    EventArtistLinkType,
    EventFaqItem,
    EventPages as EventPagesData,
    EventPageSection,
    EventPageSlug,
} from "../../../../types.ts";
import classes from "./EventPages.module.scss";

type Keyed<T> = T & { _key: string };

interface FormValues {
    artists: Keyed<EventArtist>[];
    practical_info: Keyed<EventPageSection>[];
    faq: Keyed<EventFaqItem>[];
}

type ListField = keyof FormValues;

/* eslint-disable lingui/no-unlocalized-strings */
const LINK_FIELDS: { type: EventArtistLinkType, icon: typeof IconWorld, label: string }[] = [
    {type: 'instagram', icon: IconBrandInstagram, label: 'Instagram'},
    {type: 'facebook', icon: IconBrandFacebook, label: 'Facebook'},
    {type: 'tiktok', icon: IconBrandTiktok, label: 'TikTok'},
    {type: 'youtube', icon: IconBrandYoutube, label: 'YouTube'},
    {type: 'spotify', icon: IconBrandSpotify, label: 'Spotify'},
    {type: 'website', icon: IconWorld, label: ''},
];
/* eslint-enable lingui/no-unlocalized-strings */

let keySequence = 0;
const nextKey = () => `item-${Date.now()}-${keySequence++}`;
const withKeys = <T, >(items: T[] = []): Keyed<T>[] => items.map((item) => ({...item, _key: nextKey()}));
const withoutKey = <T extends { _key?: string }>({_key, ...item}: T) => item;

const suggestedSections = (): Keyed<EventPageSection>[] => withKeys([
    t`Date and times`,
    t`Venue and access`,
    t`Identity document`,
    t`Minors`,
    t`Accessibility`,
    t`Seats`,
    t`Broadcast`,
].map((title) => ({title, content: ''})));

const ItemToolbar = ({index, total, label, onMove, onRemove}: {
    index: number,
    total: number,
    label: string,
    onMove: (from: number, to: number) => void,
    onRemove: (index: number) => void,
}) => (
    <div className={classes.itemToolbar}>
        <span className={classes.itemNumber}>{index + 1}</span>
        <span className={classes.itemLabel}>{label}</span>
        <Group gap={4} wrap="nowrap">
            <Tooltip label={t`Move up`}>
                <ActionIcon variant="subtle" color="gray" disabled={index === 0}
                            onClick={() => onMove(index, index - 1)} aria-label={t`Move up`}>
                    <IconArrowUp size={16}/>
                </ActionIcon>
            </Tooltip>
            <Tooltip label={t`Move down`}>
                <ActionIcon variant="subtle" color="gray" disabled={index === total - 1}
                            onClick={() => onMove(index, index + 1)} aria-label={t`Move down`}>
                    <IconArrowDown size={16}/>
                </ActionIcon>
            </Tooltip>
            <Tooltip label={t`Delete`}>
                <ActionIcon variant="subtle" color="red" onClick={() => onRemove(index)} aria-label={t`Delete`}>
                    <IconTrash size={16}/>
                </ActionIcon>
            </Tooltip>
        </Group>
    </div>
);

export const EventPages = () => {
    const {eventId} = useParams();
    const {data: event} = useGetEvent(eventId);
    const pagesQuery = useGetEventPages(eventId);
    const updateMutation = useUpdateEventPages();
    const formErrorHandle = useFormErrorResponseHandler();
    const [activeTab, setActiveTab] = useState<EventPageSlug>('artistes');

    const form = useForm<FormValues>({
        initialValues: {artists: [], practical_info: [], faq: []},
    });

    useEffect(() => {
        if (pagesQuery.data) {
            form.setValues({
                artists: withKeys(pagesQuery.data.artists),
                practical_info: withKeys(pagesQuery.data.practical_info),
                faq: withKeys(pagesQuery.data.faq),
            });
            form.resetDirty();
        }
    }, [pagesQuery.data]);

    const publishedPages = pagesQuery.data?.available_pages ?? [];

    const previewStyles = useMemo(() => {
        const theme = validateThemeSettings(event?.settings?.homepage_theme_settings);
        const vars = computeThemeVariables(theme, event?.organizer?.custom_font);
        return {
            '--event-bg-color': theme.background,
            '--event-content-bg-color': vars['--theme-surface'],
            '--event-primary-color': theme.accent,
            '--event-primary-text-color': vars['--theme-text-primary'],
            '--event-secondary-color': vars['--theme-text-secondary'],
            '--event-accent-contrast': vars['--theme-accent-contrast'],
            '--event-accent-soft': vars['--theme-accent-soft'],
            '--event-border-color': vars['--theme-border'],
            '--theme-font-family': vars['--theme-font-family'],
            fontFamily: vars['--theme-font-family'],
        } as React.CSSProperties;
    }, [event]);

    const move = (field: ListField) => (from: number, to: number) => form.reorderListItem(field, {from, to});
    const remove = (field: ListField) => (index: number) => form.removeListItem(field, index);

    const handleSubmit = (values: FormValues) => {
        const pages: EventPagesData = {
            artists: values.artists.map((artist) => {
                const {photo_url, slug, ...rest} = withoutKey(artist);
                return rest;
            }),
            practical_info: values.practical_info.map(withoutKey),
            faq: values.faq.map(withoutKey),
        };

        updateMutation.mutate({eventId: eventId!, pages}, {
            onSuccess: () => showSuccess(t`Event pages saved`),
            onError: (error) => formErrorHandle(form, error),
        });
    };

    const publicLink = (page: EventPageSlug) => event && publishedPages.includes(page) ? (
        <Button component="a" href={eventDedicatedPagePath(event, page)} target="_blank" variant="light" size="xs"
                rightSection={<IconExternalLink size={14}/>}>
            {t`View the public page`}
        </Button>
    ) : (
        <Text size="xs" c="dimmed">{t`Not published: the page appears once it has content and is saved.`}</Text>
    );

    const renderArtists = () => (
        <div className={classes.list}>
            {form.values.artists.map((artist, index) => (
                <div key={artist._key} className={classes.item}>
                    <ItemToolbar index={index} total={form.values.artists.length}
                                 label={artist.name || t`New artist`}
                                 onMove={move('artists')} onRemove={remove('artists')}/>
                    <div className={classes.artistFields}>
                        <div className={classes.artistPhoto}>
                            <ImageUploadDropzone
                                imageType="EVENT_ARTIST_PHOTO"
                                entityId={eventId!}
                                existingImageData={{url: artist.photo_url || undefined, id: artist.image_id || undefined}}
                                onUploadSuccess={(image) => {
                                    form.setFieldValue(`artists.${index}.image_id`, image.id);
                                    form.setFieldValue(`artists.${index}.photo_url`, image.url);
                                }}
                                onDeleteSuccess={() => {
                                    form.setFieldValue(`artists.${index}.image_id`, null);
                                    form.setFieldValue(`artists.${index}.photo_url`, null);
                                }}
                                helpText={t`Square photo, at least 600 x 600.`}
                                displayMode="compact"
                            />
                        </div>
                        <div className={classes.artistMain}>
                            <TextInput required label={t`Name`} {...form.getInputProps(`artists.${index}.name`)}/>
                            <SegmentedControl
                                size="xs"
                                value={artist.kind || 'PERSON'}
                                onChange={(value) => form.setFieldValue(`artists.${index}.kind`, value as EventArtist['kind'])}
                                data={[
                                    {value: 'PERSON', label: t`Solo artist`},
                                    {value: 'GROUP', label: t`Group or duo`},
                                ]}
                            />
                            <TextInput label={t`Performed song`} placeholder={t`Title of the song performed`}
                                       {...form.getInputProps(`artists.${index}.song`)}/>
                        </div>
                    </div>
                    <Editor
                        label={t`Short biography`}
                        editorType="simple"
                        value={artist.bio || ''}
                        onChange={(value) => form.setFieldValue(`artists.${index}.bio`, value)}
                        error={form.errors[`artists.${index}.bio`] as string}
                    />
                    <SimpleGrid cols={{base: 1, sm: 2}} spacing="xs" mt="xs">
                        {LINK_FIELDS.map(({type, icon: Icon, label}) => (
                            <TextInput
                                key={type}
                                size="xs"
                                leftSection={<Icon size={16}/>}
                                placeholder={label || t`Website`}
                                aria-label={label || t`Website`}
                                value={artist.links?.[type] || ''}
                                onChange={(e) => form.setFieldValue(`artists.${index}.links`, {
                                    ...(artist.links || {}),
                                    [type]: e.currentTarget.value,
                                })}
                                error={form.errors[`artists.${index}.links.${type}`]}
                            />
                        ))}
                    </SimpleGrid>
                </div>
            ))}
            <Button variant="light" leftSection={<IconPlus size={16}/>}
                    onClick={() => form.insertListItem('artists', {name: '', kind: 'PERSON', links: {}, _key: nextKey()})}>
                {t`Add an artist`}
            </Button>
        </div>
    );

    const renderPracticalInfo = () => (
        <div className={classes.list}>
            {form.values.practical_info.length === 0 && (
                <Button variant="default" onClick={() => form.setFieldValue('practical_info', suggestedSections())}>
                    {t`Start from the suggested sections`}
                </Button>
            )}
            {form.values.practical_info.map((section, index) => (
                <div key={section._key} className={classes.item}>
                    <ItemToolbar index={index} total={form.values.practical_info.length}
                                 label={section.title || t`New section`}
                                 onMove={move('practical_info')} onRemove={remove('practical_info')}/>
                    <TextInput required label={t`Title`} {...form.getInputProps(`practical_info.${index}.title`)}/>
                    <Editor
                        label={t`Content`}
                        value={section.content || ''}
                        onChange={(value) => form.setFieldValue(`practical_info.${index}.content`, value)}
                        error={form.errors[`practical_info.${index}.content`] as string}
                    />
                </div>
            ))}
            <Button variant="light" leftSection={<IconPlus size={16}/>}
                    onClick={() => form.insertListItem('practical_info', {title: '', content: '', _key: nextKey()})}>
                {t`Add a section`}
            </Button>
        </div>
    );

    const renderFaq = () => (
        <div className={classes.list}>
            {form.values.faq.map((item, index) => (
                <div key={item._key} className={classes.item}>
                    <ItemToolbar index={index} total={form.values.faq.length}
                                 label={item.question || t`New question`}
                                 onMove={move('faq')} onRemove={remove('faq')}/>
                    <TextInput required label={t`Question`} {...form.getInputProps(`faq.${index}.question`)}/>
                    <Editor
                        label={t`Answer`}
                        editorType="simple"
                        value={item.answer || ''}
                        onChange={(value) => form.setFieldValue(`faq.${index}.answer`, value)}
                        error={form.errors[`faq.${index}.answer`] as string}
                    />
                </div>
            ))}
            <Button variant="light" leftSection={<IconPlus size={16}/>}
                    onClick={() => form.insertListItem('faq', {question: '', answer: '', _key: nextKey()})}>
                {t`Add a question`}
            </Button>
        </div>
    );

    const counts: Record<EventPageSlug, number> = {
        'artistes': form.values.artists.length,
        'infos-pratiques': form.values.practical_info.length,
        'faq': form.values.faq.length,
    };

    return (
        <PageBody>
            <PageTitle subheading={t`Dedicated pages for the line-up, practical information and FAQ. Each page is indexed by search engines and links to registration. A page without content is not published.`}>
                {t`Event pages`}
            </PageTitle>

            <form onSubmit={form.onSubmit(handleSubmit)}>
                <div className={classes.layout}>
                    <Card className={classes.editor}>
                        <fieldset disabled={pagesQuery.isLoading || updateMutation.isPending} className={classes.fieldset}>
                            <Tabs value={activeTab} onChange={(value) => value && setActiveTab(value as EventPageSlug)}>
                                <Tabs.List>
                                    {EVENT_PAGE_SLUGS.map((page) => (
                                        <Tabs.Tab key={page} value={page} rightSection={
                                            <Badge size="xs" variant={publishedPages.includes(page) ? 'filled' : 'light'}
                                                   color={publishedPages.includes(page) ? 'green' : 'gray'}>
                                                {counts[page]}
                                            </Badge>
                                        }>
                                            {eventPageLabel(page)}
                                        </Tabs.Tab>
                                    ))}
                                </Tabs.List>

                                {EVENT_PAGE_SLUGS.map((page) => (
                                    <Tabs.Panel key={page} value={page} pt="md">
                                        <div className={classes.panelHeader}>{publicLink(page)}</div>
                                        {page === 'artistes' && renderArtists()}
                                        {page === 'infos-pratiques' && renderPracticalInfo()}
                                        {page === 'faq' && renderFaq()}
                                    </Tabs.Panel>
                                ))}
                            </Tabs>

                            <div className={classes.actions}>
                                <Button type="submit" loading={updateMutation.isPending}>{t`Save`}</Button>
                            </div>
                        </fieldset>
                    </Card>

                    <aside className={classes.previewColumn}>
                        <div className={classes.previewHeader}>
                            <Text fw={700} size="sm">{t`Preview`}</Text>
                            <Text size="xs" c="dimmed">{eventPageLabel(activeTab)}</Text>
                        </div>
                        <div className={classes.preview} style={previewStyles}>
                            {activeTab === 'artistes' && (form.values.artists.length
                                ? <ArtistsList artists={form.values.artists.filter((artist) => artist.name)}/>
                                : <Text size="sm" c="dimmed">{t`No artist yet.`}</Text>)}
                            {activeTab === 'infos-pratiques' && (form.values.practical_info.length
                                ? <PracticalInfoList sections={form.values.practical_info.filter((section) => section.title)}/>
                                : <Text size="sm" c="dimmed">{t`No section yet.`}</Text>)}
                            {activeTab === 'faq' && (form.values.faq.length
                                ? <FaqList items={form.values.faq.filter((item) => item.question)}/>
                                : <Text size="sm" c="dimmed">{t`No question yet.`}</Text>)}
                        </div>
                    </aside>
                </div>
            </form>
        </PageBody>
    );
};

export default EventPages;
