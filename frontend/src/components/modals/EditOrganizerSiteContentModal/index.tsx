import {useState} from "react";
import {ActionIcon, Button, FileButton, Group, Image, Stack, Tabs, Text, Textarea, TextInput} from "@mantine/core";
import {useForm} from "@mantine/form";
import {t} from "@lingui/macro";
import {IconArrowDown, IconArrowUp, IconPlus, IconTrash, IconUpload} from "@tabler/icons-react";
import {GenericModalProps, IdParam, OrganizerSiteContent} from "../../../types";
import {Modal} from "../../common/Modal";
import {Editor} from "../../common/Editor";
import {AdminOrganizer} from "../../../api/admin.client";
import {useUpdateAdminOrganizerSiteContent} from "../../../mutations/useUpdateAdminOrganizerSiteContent";
import {useUploadImage} from "../../../mutations/useUploadImage";
import {useFormErrorResponseHandler} from "../../../hooks/useFormErrorResponseHandler";
import {showError, showSuccess} from "../../../utilites/notifications";

interface EditOrganizerSiteContentModalProps extends GenericModalProps {
    accountId: IdParam;
    organizer: AdminOrganizer;
}

type ListField = { key: string; label: string; placeholder?: string; image?: boolean; multiline?: boolean };

interface FormValues {
    tagline: string;
    area: string;
    services_intro: string;
    services: Record<string, string>[];
    about_headline: string;
    story: string;
    vision: string;
    about_image_url: string;
    values: Record<string, string>[];
    stats: Record<string, string>[];
    team: Record<string, string>[];
    gallery: Record<string, string>[];
    partners_intro: string;
    partners: Record<string, string>[];
    press_text: string;
    press_email: string;
    press_phone: string;
    press_kit_url: string;
}

const LIST_KEYS = ['values', 'stats', 'team', 'gallery', 'partners', 'services'] as const;
const REQUIRED_FIELD: Record<typeof LIST_KEYS[number], string> = {
    values: 'title',
    stats: 'value',
    team: 'name',
    gallery: 'url',
    partners: 'name',
    services: 'title',
};

const toFormValues = (content?: OrganizerSiteContent | null): FormValues => {
    const list = (items?: object[] | null) => (items || []).map(item =>
        Object.fromEntries(Object.entries(item).map(([key, value]) => [key, value ?? '']))
    );

    return {
        tagline: content?.tagline || '',
        area: content?.area || '',
        services_intro: content?.services_intro || '',
        services: list(content?.services),
        about_headline: content?.about_headline || '',
        story: content?.story || '',
        vision: content?.vision || '',
        about_image_url: content?.about_image_url || '',
        values: list(content?.values),
        stats: list(content?.stats),
        team: list(content?.team),
        gallery: list(content?.gallery),
        partners_intro: content?.partners_intro || '',
        partners: list(content?.partners),
        press_text: content?.press_text || '',
        press_email: content?.press_email || '',
        press_phone: content?.press_phone || '',
        press_kit_url: content?.press_kit_url || '',
    };
};

const toPayload = (values: FormValues): OrganizerSiteContent => {
    const clean = (value: string) => value.trim() || null;
    const isEmptyHtml = (html: string) => html.replace(/<[^>]*>/g, '').trim() === '';

    const payload: Record<string, unknown> = {
        tagline: clean(values.tagline),
        area: clean(values.area),
        services_intro: clean(values.services_intro),
        about_headline: clean(values.about_headline),
        story: isEmptyHtml(values.story) ? null : values.story,
        vision: isEmptyHtml(values.vision) ? null : values.vision,
        about_image_url: clean(values.about_image_url),
        partners_intro: clean(values.partners_intro),
        press_text: clean(values.press_text),
        press_email: clean(values.press_email),
        press_phone: clean(values.press_phone),
        press_kit_url: clean(values.press_kit_url),
    };

    LIST_KEYS.forEach(key => {
        payload[key] = values[key]
            .filter(item => (item[REQUIRED_FIELD[key]] || '').trim() !== '')
            .map(item => Object.fromEntries(Object.entries(item).map(([field, value]) => [field, clean(value)])));
    });

    return payload as OrganizerSiteContent;
};

const ImageUrlInput = ({label, value, onChange, error}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: React.ReactNode;
}) => {
    const upload = useUploadImage();

    const handleFile = (file: File | null) => {
        if (!file) return;
        upload.mutate({image: file}, {
            onSuccess: (response) => onChange(response.data.url || ''),
            onError: () => showError(t`The image could not be uploaded`),
        });
    };

    return (
        <Group align="flex-end" gap="xs" wrap="nowrap">
            {value && <Image src={value} alt="" w={40} h={40} radius="sm" fit="cover"/>}
            <TextInput
                style={{flex: 1}}
                label={label}
                placeholder="https://…"
                value={value}
                error={error}
                onChange={(event) => onChange(event.currentTarget.value)}
            />
            <FileButton onChange={handleFile} accept="image/png,image/jpeg,image/webp,image/svg+xml">
                {(props) => (
                    <ActionIcon {...props} size="lg" variant="light" loading={upload.isPending} aria-label={t`Upload an image`}>
                        <IconUpload size={16}/>
                    </ActionIcon>
                )}
            </FileButton>
        </Group>
    );
};

const ListEditor = ({form, name, fields, addLabel, emptyItem}: {
    form: ReturnType<typeof useForm<FormValues>>;
    name: typeof LIST_KEYS[number];
    fields: ListField[];
    addLabel: string;
    emptyItem: Record<string, string>;
}) => {
    const items = form.values[name];

    return (
        <Stack gap="sm">
            {items.map((_, index) => (
                <Group key={index} align="flex-start" gap="xs" wrap="nowrap"
                       style={{padding: 12, border: '1px solid var(--mantine-color-gray-3)', borderRadius: 8}}>
                    <Stack gap="xs" style={{flex: 1}}>
                        {fields.map(field => field.image ? (
                            <ImageUrlInput
                                key={field.key}
                                label={field.label}
                                value={items[index][field.key] || ''}
                                error={form.errors[`${name}.${index}.${field.key}`]}
                                onChange={(value) => form.setFieldValue(`${name}.${index}.${field.key}`, value)}
                            />
                        ) : field.multiline ? (
                            <Textarea key={field.key} label={field.label} placeholder={field.placeholder} autosize minRows={2}
                                      {...form.getInputProps(`${name}.${index}.${field.key}`)}/>
                        ) : (
                            <TextInput key={field.key} label={field.label} placeholder={field.placeholder}
                                       {...form.getInputProps(`${name}.${index}.${field.key}`)}/>
                        ))}
                    </Stack>
                    <Stack gap={4}>
                        <ActionIcon variant="subtle" disabled={index === 0} aria-label={t`Move up`}
                                    onClick={() => form.reorderListItem(name, {from: index, to: index - 1})}>
                            <IconArrowUp size={16}/>
                        </ActionIcon>
                        <ActionIcon variant="subtle" disabled={index === items.length - 1} aria-label={t`Move down`}
                                    onClick={() => form.reorderListItem(name, {from: index, to: index + 1})}>
                            <IconArrowDown size={16}/>
                        </ActionIcon>
                        <ActionIcon variant="subtle" color="red" aria-label={t`Remove`}
                                    onClick={() => form.removeListItem(name, index)}>
                            <IconTrash size={16}/>
                        </ActionIcon>
                    </Stack>
                </Group>
            ))}
            <Button variant="light" leftSection={<IconPlus size={16}/>} onClick={() => form.insertListItem(name, {...emptyItem})}>
                {addLabel}
            </Button>
        </Stack>
    );
};

export const EditOrganizerSiteContentModal = ({onClose, accountId, organizer}: EditOrganizerSiteContentModalProps) => {
    const mutation = useUpdateAdminOrganizerSiteContent(accountId);
    const formErrorHandler = useFormErrorResponseHandler();
    const [tab, setTab] = useState<string | null>('universe');
    const form = useForm<FormValues>({initialValues: toFormValues(organizer.site_content)});

    const handleSubmit = (values: FormValues) => {
        mutation.mutate({organizerId: organizer.id, siteContent: toPayload(values)}, {
            onSuccess: () => {
                showSuccess(t`Site content saved`);
                onClose();
            },
            onError: (error: any) => formErrorHandler(form, error),
        });
    };

    return (
        <Modal heading={t`Website content — ${organizer.name}`} onClose={onClose} opened>
            <form onSubmit={form.onSubmit(handleSubmit)}>
                <Stack gap="md">
                    <Text size="sm" c="dimmed">
                        {t`Used by the Poster template. Empty sections are hidden on the website.`}
                    </Text>

                    <Tabs value={tab} onChange={setTab}>
                        <Tabs.List>
                            <Tabs.Tab value="universe">{t`Universe`}</Tabs.Tab>
                            <Tabs.Tab value="figures">{t`Figures & values`}</Tabs.Tab>
                            <Tabs.Tab value="services">{t`Services`}</Tabs.Tab>
                            <Tabs.Tab value="team">{t`Team`}</Tabs.Tab>
                            <Tabs.Tab value="gallery">{t`Gallery`}</Tabs.Tab>
                            <Tabs.Tab value="partners">{t`Partners`}</Tabs.Tab>
                            <Tabs.Tab value="press">{t`Press`}</Tabs.Tab>
                        </Tabs.List>

                        <Tabs.Panel value="universe" pt="md">
                            <Stack gap="md">
                                <TextInput label={t`Tagline`} description={t`One short sentence that sums up the organizer. Shown on the home page and in search results.`}
                                           maxLength={200} {...form.getInputProps('tagline')}/>
                                <TextInput label={t`Area`} placeholder="Mayotte"
                                           description={t`City or region where the organizer operates. Used in page titles for search engines.`}
                                           maxLength={80} {...form.getInputProps('area')}/>
                                <TextInput label={t`Universe page title`} maxLength={200} {...form.getInputProps('about_headline')}/>
                                <Editor label={t`Our story`} value={form.values.story}
                                        onChange={(value) => form.setFieldValue('story', value)} error={form.errors.story as string}/>
                                <Editor label={t`Our vision`} value={form.values.vision}
                                        onChange={(value) => form.setFieldValue('vision', value)} error={form.errors.vision as string}/>
                                <ImageUrlInput label={t`Story photo`} value={form.values.about_image_url}
                                               error={form.errors.about_image_url}
                                               onChange={(value) => form.setFieldValue('about_image_url', value)}/>
                            </Stack>
                        </Tabs.Panel>

                        <Tabs.Panel value="figures" pt="md">
                            <Stack gap="lg">
                                <div>
                                    <Text fw={600} mb="xs">{t`Key figures (max 6)`}</Text>
                                    <ListEditor form={form} name="stats" addLabel={t`Add a figure`} emptyItem={{value: '', label: ''}} fields={[
                                        {key: 'value', label: t`Figure`, placeholder: '10 000'},
                                        {key: 'label', label: t`Label`, placeholder: t`attendees since 2019`},
                                    ]}/>
                                </div>
                                <div>
                                    <Text fw={600} mb="xs">{t`Values (max 8)`}</Text>
                                    <ListEditor form={form} name="values" addLabel={t`Add a value`} emptyItem={{title: '', text: ''}} fields={[
                                        {key: 'title', label: t`Value`},
                                        {key: 'text', label: t`Description`, multiline: true},
                                    ]}/>
                                </div>
                            </Stack>
                        </Tabs.Panel>

                        <Tabs.Panel value="services" pt="md">
                            <Stack gap="md">
                                <Textarea label={t`Services page introduction`} autosize minRows={2} maxLength={1000}
                                          {...form.getInputProps('services_intro')}/>
                                <ListEditor form={form} name="services" addLabel={t`Add a service`} emptyItem={{title: '', text: ''}} fields={[
                                    {key: 'title', label: t`Service`, placeholder: t`Corporate events`},
                                    {key: 'text', label: t`Description`, multiline: true},
                                ]}/>
                            </Stack>
                        </Tabs.Panel>

                        <Tabs.Panel value="team" pt="md">
                            <ListEditor form={form} name="team" addLabel={t`Add a team member`} emptyItem={{name: '', role: '', photo_url: ''}} fields={[
                                {key: 'name', label: t`Name`},
                                {key: 'role', label: t`Role`},
                                {key: 'photo_url', label: t`Photo`, image: true},
                            ]}/>
                        </Tabs.Panel>

                        <Tabs.Panel value="gallery" pt="md">
                            <ListEditor form={form} name="gallery" addLabel={t`Add a photo`} emptyItem={{url: '', caption: ''}} fields={[
                                {key: 'url', label: t`Photo`, image: true},
                                {key: 'caption', label: t`Caption`},
                            ]}/>
                        </Tabs.Panel>

                        <Tabs.Panel value="partners" pt="md">
                            <Stack gap="md">
                                <Textarea label={t`Partners page introduction`} autosize minRows={2} maxLength={1000}
                                          {...form.getInputProps('partners_intro')}/>
                                <ListEditor form={form} name="partners" addLabel={t`Add a partner`}
                                            emptyItem={{name: '', category: '', logo_url: '', url: ''}} fields={[
                                    {key: 'name', label: t`Name`},
                                    {key: 'category', label: t`Category`, placeholder: t`Main partners, Media, Institutions…`},
                                    {key: 'logo_url', label: t`Logo`, image: true},
                                    {key: 'url', label: t`Website`, placeholder: 'https://…'},
                                ]}/>
                            </Stack>
                        </Tabs.Panel>

                        <Tabs.Panel value="press" pt="md">
                            <Stack gap="md">
                                <Textarea label={t`Press introduction`} autosize minRows={2} maxLength={2000} {...form.getInputProps('press_text')}/>
                                <TextInput label={t`Press e-mail`} type="email" {...form.getInputProps('press_email')}/>
                                <TextInput label={t`Press phone`} {...form.getInputProps('press_phone')}/>
                                <TextInput label={t`Press kit link`} placeholder="https://…" {...form.getInputProps('press_kit_url')}/>
                            </Stack>
                        </Tabs.Panel>
                    </Tabs>

                    <Group justify="flex-end">
                        <Button variant="default" onClick={onClose}>{t`Cancel`}</Button>
                        <Button type="submit" loading={mutation.isPending}>{t`Save`}</Button>
                    </Group>
                </Stack>
            </form>
        </Modal>
    );
};
