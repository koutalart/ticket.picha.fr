import {t} from "@lingui/macro";
import {useForm} from "@mantine/form";
import {ActionIcon, Alert, Button, Switch, Text, TextInput} from "@mantine/core";
import {IconTrash} from "@tabler/icons-react";
import {Card} from "../../../common/Card";
import {HeadingWithDescription} from "../../../common/Card/CardHeading";
import {IdParam, SmsLineKeyword} from "../../../../types.ts";
import {useGetSmsLineKeywords} from "../../../../queries/useGetSmsLineKeywords.ts";
import {
    useCreateSmsLineKeyword,
    useDeleteSmsLineKeyword,
    useUpdateSmsLineKeyword,
} from "../../../../mutations/useSaveSmsLineKeyword.ts";
import {useFormErrorResponseHandler} from "../../../../hooks/useFormErrorResponseHandler.tsx";
import {showError, showSuccess} from "../../../../utilites/notifications.tsx";
import classes from "./KeywordsView.module.scss";

interface KeywordsViewProps {
    organizerId: IdParam;
}

export const KeywordsView = ({organizerId}: KeywordsViewProps) => {
    const keywordsQuery = useGetSmsLineKeywords(organizerId);
    const createMutation = useCreateSmsLineKeyword();
    const updateMutation = useUpdateSmsLineKeyword();
    const deleteMutation = useDeleteSmsLineKeyword();
    const formErrorHandle = useFormErrorResponseHandler();
    const data = keywordsQuery.data;
    const keywords = data?.keywords ?? [];
    const lineNumber = data?.line_number || t`the shared line`;

    const form = useForm({
        initialValues: {keyword: ''},
        validate: {keyword: (value) => value.trim() === '' ? t`Enter a keyword` : null},
    });

    const addKeyword = (values: typeof form.values) => {
        createMutation.mutate({organizerId, keyword: {keyword: values.keyword.trim()}}, {
            onSuccess: () => {
                form.reset();
                showSuccess(t`Keyword added`);
            },
            onError: (error) => formErrorHandle(form, error),
        });
    };

    const toggleKeyword = (keyword: SmsLineKeyword, isActive: boolean) => {
        updateMutation.mutate({
            organizerId,
            keywordId: keyword.id,
            keyword: {is_active: isActive, welcome_message: keyword.welcome_message},
        }, {
            onSuccess: () => showSuccess(isActive ? t`Keyword enabled` : t`Keyword disabled`),
            onError: () => showError(t`The keyword could not be updated. Please try again.`),
        });
    };

    const removeKeyword = (keyword: SmsLineKeyword) => {
        deleteMutation.mutate({organizerId, keywordId: keyword.id}, {
            onSuccess: () => showSuccess(t`Keyword removed`),
            onError: () => showError(t`The keyword could not be removed. Please try again.`),
        });
    };

    return (
        <Card>
            <HeadingWithDescription
                heading={t`SMS keywords`}
                description={t`A person who sends one of your keywords to ${lineNumber} reaches your inbox directly, even if another organizer of the line wrote to them, and joins your contacts with their consent to receive your SMS. Nothing is sent back automatically.`}
            />

            {keywordsQuery.isFetched && data && !data.available && (
                <Alert color="gray" variant="light" mb="md">
                    {t`Keywords are available when your SMS go through the shared PICHA line.`}
                </Alert>
            )}

            <div className={classes.keywords}>
                {keywordsQuery.isFetched && keywords.length === 0 && (
                    <Text size="sm" c="dimmed">{t`No keyword yet.`}</Text>
                )}
                {keywords.map((keyword) => (
                    <div key={keyword.id} className={classes.keyword}>
                        <div className={classes.keywordInfo}>
                            <span className={classes.keywordName}>{keyword.keyword}</span>
                            <span className={classes.keywordState}>
                                {keyword.is_active ? t`Active` : t`Disabled`}
                            </span>
                        </div>
                        <div className={classes.keywordActions}>
                            <Switch
                                checked={keyword.is_active}
                                aria-label={t`Active`}
                                disabled={updateMutation.isPending && updateMutation.variables?.keywordId === keyword.id}
                                onChange={(event) => toggleKeyword(keyword, event.currentTarget.checked)}
                            />
                            <ActionIcon
                                variant="subtle"
                                color="red"
                                aria-label={t`Remove`}
                                loading={deleteMutation.isPending && deleteMutation.variables?.keywordId === keyword.id}
                                onClick={() => removeKeyword(keyword)}
                            >
                                <IconTrash size={16}/>
                            </ActionIcon>
                        </div>
                    </div>
                ))}
            </div>

            {data?.available && (
                <form onSubmit={form.onSubmit(addKeyword)} className={classes.addForm}>
                    <TextInput
                        {...form.getInputProps('keyword')}
                        label={t`New keyword`}
                        description={t`One word. Capitals, accents and punctuation do not matter.`}
                        placeholder="JUNGLE"
                        maxLength={40}
                    />
                    <Button type="submit" loading={createMutation.isPending}>{t`Add`}</Button>
                </form>
            )}
        </Card>
    );
};
