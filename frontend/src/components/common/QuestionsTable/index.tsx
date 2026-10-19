import {
    ActionIcon,
    Button,
    Collapse,
    Group,
    Menu,
    Text,
    UnstyledButton,
} from '@mantine/core';
import {IdParam, Question} from "../../../types.ts";
import {
    IconChevronDown,
    IconChevronUp,
    IconDots,
    IconEye,
    IconEyeOff,
    IconGripVertical,
    IconPencil,
    IconPlus,
    IconTableExport,
    IconTrash,
    IconUser,
    IconUsers,
} from "@tabler/icons-react";
import classes from "./QuestionsTable.module.scss";
import {QuestionInput} from "../CheckoutQuestion";
import {useForm} from "@mantine/form";
import {CreateQuestionModal} from "../../modals/CreateQuestionModal";
import {useDisclosure} from "@mantine/hooks";
import {Card} from "../Card";
import {t} from "@lingui/macro";
import {ReactNode, useEffect, useState} from "react";
import {useQueryClient} from "@tanstack/react-query";
import {questionClient} from "../../../api/question.client.ts";
import {GET_EVENT_QUESTIONS_QUERY_KEY} from "../../../queries/useGetEventQuestions.ts";
import {EditQuestionModal} from "../../modals/EditQuestionModal";
import {useDeleteQuestion} from "../../../mutations/useDeleteQuestion.ts";
import {useParams} from "react-router";
import {showError, showSuccess} from "../../../utilites/notifications.tsx";
import {confirmationDialog} from "../../../utilites/confirmationDialog.tsx";
import {useDragItemsHandler} from "../../../hooks/useDragItemsHandler.ts";
import {
    closestCenter,
    DndContext,
    PointerSensor,
    TouchSensor,
    UniqueIdentifier,
    useSensor,
    useSensors
} from "@dnd-kit/core";
import {SortableContext, useSortable, verticalListSortingStrategy} from "@dnd-kit/sortable";
import {CSS} from "@dnd-kit/utilities";
import {useSortQuestions} from "../../../mutations/useSortQuestions.ts";
import classNames from "classnames";
import {useExportAnswers} from "../../../mutations/useExportAnswers.ts";
import {useGetEventSettings} from "../../../queries/useGetEventSettings.ts";
import {useUpdateEventSettings} from "../../../mutations/useUpdateEventSettings.ts";

interface QuestionsTableProp {
    questions: Partial<Question>[];
    isSystemDefault?: boolean;
    onEditModalOpen?: (id: IdParam) => void;
}

const questionTypeLabel = (type?: string) => ({
    ADDRESS: t`Address`,
    PHONE: t`Phone`,
    SINGLE_LINE_TEXT: t`Short text`,
    MULTI_LINE_TEXT: t`Long text`,
    CHECKBOX: t`Several choices`,
    RADIO: t`Single choice`,
    DROPDOWN: t`Drop-down list`,
    DATE: t`Date`,
    FILE: t`File (PDF)`,
} as Record<string, string>)[type ?? ''] ?? type ?? '';

const SortableQuestion = ({
    question,
    onEditModalOpen,
    onDelete,
    onToggleHidden,
}: {
    question: Partial<Question>;
    onEditModalOpen?: (id: IdParam) => void;
    onDelete: (id: IdParam) => void;
    onToggleHidden: (question: Partial<Question>) => void;
}) => {
    const uniqueId = question.id as UniqueIdentifier;
    const {attributes, listeners, setNodeRef, transform, transition, isDragging} = useSortable({
        id: uniqueId,
    });

    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
    };

    const isHidden = question.is_hidden;
    const typeLabel = questionTypeLabel(question.type);
    const whoLabel = question.belongs_to === 'PRODUCT' ? t`For each ticket` : t`Once per order`;

    return (
        <div
            style={style}
            ref={setNodeRef}
            className={classNames(classes.questionCard, {
                [classes.hidden]: isHidden,
                [classes.dragging]: isDragging,
            })}
        >
            <div {...attributes} {...listeners} className={classes.dragHandle} aria-label={t`Move`}>
                <IconGripVertical size={18} stroke={1.5}/>
            </div>

            <div className={classes.questionContent}>
                <span className={classes.questionTitle}>
                    {question.title}
                    {question.required && <span className={classes.requiredMark} title={t`Required`}> *</span>}
                </span>
                <span className={classes.questionMeta}>
                    {whoLabel} · {typeLabel}
                    {question.options && question.options.length > 0 && <> : {question.options.slice(0, 3).join(', ')}{question.options.length > 3 ? '…' : ''}</>}
                </span>
            </div>

            <div className={classes.questionActions}>
                <span className={isHidden ? classes.stateHidden : classes.stateVisible}>
                    {isHidden ? <IconEyeOff size={14}/> : <IconEye size={14}/>}
                    {isHidden ? t({message: 'Hidden', context: 'question state'}) : t`Shown`}
                </span>
                <Button size="xs" variant="default" radius="md" leftSection={<IconPencil size={14}/>}
                        onClick={() => onEditModalOpen?.(question.id)}>
                    {t`Edit`}
                </Button>
                <Menu shadow="md" width={200} position="bottom-end">
                    <Menu.Target>
                        <ActionIcon variant="subtle" color="gray" aria-label={t`More actions`}>
                            <IconDots size={18}/>
                        </ActionIcon>
                    </Menu.Target>
                    <Menu.Dropdown>
                        <Menu.Item
                            leftSection={isHidden ? <IconEye size={14}/> : <IconEyeOff size={14}/>}
                            onClick={() => onToggleHidden(question)}
                        >
                            {isHidden ? t`Show this question` : t`Hide this question`}
                        </Menu.Item>
                        <Menu.Divider/>
                        <Menu.Item
                            color="red"
                            leftSection={<IconTrash size={14}/>}
                            onClick={() => onDelete(question.id)}
                        >
                            {t`Delete`}
                        </Menu.Item>
                    </Menu.Dropdown>
                </Menu>
            </div>
        </div>
    );
};

interface QuestionsListProps extends QuestionsTableProp {
    emptyMessage: string;
    emptyHint: string;
    onAddQuestion: () => void;
}

const QuestionsList = ({
    questions,
    onEditModalOpen,
    emptyMessage,
    emptyHint,
    onAddQuestion,
}: QuestionsListProps) => {
    const {eventId} = useParams();
    const queryClient = useQueryClient();
    const deleteQuestionMutation = useDeleteQuestion();
    const sortMutation = useSortQuestions();
    const {items, setItems, handleDragEnd} = useDragItemsHandler({
        initialItemIds: questions.map((question) => Number(question.id)),
        onSortEnd: (newArray) => {
            sortMutation.mutate(
                {
                    sortedQuestionIds: newArray.map((id, index) => ({id, order: index + 1})),
                    eventId: eventId,
                },
                {
                    onSuccess: () => showSuccess(t`Questions reordered`),
                    onError: () => showError(t`Failed to reorder questions`),
                }
            );
        },
    });

    useEffect(() => {
        setItems(questions.map((question) => Number(question.id)));
    }, [questions]);

    const sensors = useSensors(useSensor(PointerSensor), useSensor(TouchSensor));

    const toggleHidden = (question: Partial<Question>) => {
        questionClient.update(eventId, question.id as IdParam, {
            title: question.title ?? '',
            description: question.description,
            required: Boolean(question.required),
            is_hidden: !question.is_hidden,
            type: question.type ?? 'SINGLE_LINE_TEXT',
            options: question.options ?? [],
            product_ids: (question.product_ids ?? question.products?.map((product) => product.id) ?? []).map(String),
            belongs_to: question.belongs_to ?? 'ORDER',
        }).then(() => {
            showSuccess(question.is_hidden ? t`Question shown` : t`Question hidden`);
            return queryClient.invalidateQueries({queryKey: [GET_EVENT_QUESTIONS_QUERY_KEY]});
        }).catch(() => showError(t`The change could not be saved. Please try again.`));
    };

    const onDelete = (id: IdParam) => {
        confirmationDialog(t`Delete this question? This cannot be undone.`, () => {
            deleteQuestionMutation.mutate(
                {eventId, questionId: id},
                {
                    onSuccess: () => showSuccess(t`Question deleted`),
                    onError: (error: any) =>
                        showError(error?.response?.data?.message || t`Failed to delete question`),
                }
            );
        });
    };

    if (questions.length === 0) {
        return (
            <div className={classes.emptyState}>
                <Text size="sm" fw={500} mb={4}>
                    {emptyMessage}
                </Text>
                <Text size="xs" c="dimmed" mb="sm">
                    {emptyHint}
                </Text>
                <Button
                    variant="light"
                    size="xs"
                    leftSection={<IconPlus size={14}/>}
                    onClick={onAddQuestion}
                >
                    {t`Add Question`}
                </Button>
            </div>
        );
    }

    return (
        <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
            <SortableContext items={items as UniqueIdentifier[]} strategy={verticalListSortingStrategy}>
                <div className={classes.questionsList}>
                    {questions.map((question) => (
                        <SortableQuestion
                            key={question.id}
                            question={question}
                            onEditModalOpen={onEditModalOpen}
                            onDelete={onDelete}
                            onToggleHidden={toggleHidden}
                        />
                    ))}
                </div>
            </SortableContext>
        </DndContext>
    );
};

const DefaultFieldsPreview = () => (
    <div className={classes.defaultFields}>
        <div className={classes.defaultFieldsGrid}>
            <span className={classes.fieldPill}>{t`First Name`}</span>
            <span className={classes.fieldPill}>{t`Last Name`}</span>
            <span className={classes.fieldPill}>{t`Email`}</span>
        </div>
    </div>
);

const LivePreview = ({
    questions,
    isPerOrderCollection,
}: {
    questions: Partial<Question>[];
    isPerOrderCollection: boolean;
}) => {
    const form = useForm();
    const [isOpen, setIsOpen] = useState(false);

    form.getInputProps = (name: string) => ({
        id: name,
        value: form.values[name],
        onChange: () => void 0,
    });

    const orderQuestions = questions.filter((q) => q.belongs_to === 'ORDER' && !q.is_hidden);
    const productQuestions = questions.filter((q) => q.belongs_to === 'PRODUCT' && !q.is_hidden);

    return (
        <Card className={classes.previewCard}>
            <UnstyledButton
                className={classes.previewToggle}
                onClick={() => setIsOpen(!isOpen)}
            >
                <Group gap="xs">
                    <IconEye size={16}/>
                    <Text size="sm" fw={500}>{t`Preview checkout form`}</Text>
                </Group>
                {isOpen ? <IconChevronUp size={16}/> : <IconChevronDown size={16}/>}
            </UnstyledButton>

            <Collapse in={isOpen}>
                <div className={classes.previewContent}>
                    <div className={classes.previewPane}>
                        <Text size="xs" fw={600} c="dimmed" tt="uppercase" mb={6}>
                            {t`Order`}
                        </Text>
                        <DefaultFieldsPreview/>
                        {orderQuestions.length > 0 && (
                            <div className={classes.previewQuestions}>
                                {orderQuestions.map((q) => (
                                    <QuestionInput
                                        key={q.id}
                                        question={q}
                                        name={String(q.id)}
                                        form={form}
                                    />
                                ))}
                            </div>
                        )}
                    </div>

                    <div className={classes.previewDivider}/>

                    <div className={classes.previewPane}>
                        <Text size="xs" fw={600} c="dimmed" tt="uppercase" mb={6}>
                            {t`Per Attendee`}
                        </Text>
                        {!isPerOrderCollection && <DefaultFieldsPreview/>}
                        {productQuestions.length > 0 ? (
                            <div className={classes.previewQuestions}>
                                {productQuestions.map((q) => (
                                    <QuestionInput
                                        key={q.id}
                                        question={q}
                                        name={String(q.id)}
                                        form={form}
                                    />
                                ))}
                            </div>
                        ) : isPerOrderCollection ? (
                            <Text size="xs" c="dimmed" fs="italic">
                                {t`Attendee details copied from order`}
                            </Text>
                        ) : null}
                    </div>
                </div>
            </Collapse>
        </Card>
    );
};

const PRESETS = () => [
    {key: 'phone', title: t`Phone number`, type: 'PHONE', options: [] as string[]},
    {key: 'city', title: t`City`, type: 'SINGLE_LINE_TEXT', options: [] as string[]},
    {
        key: 'source',
        title: t`How did you hear about us?`,
        type: 'RADIO',
        options: [t`Instagram`, t`Facebook`, t`TikTok`, t`Word of mouth`, t`Poster or flyer`, t`Other`],
    },
    {key: 'birthdate', title: t`Date of birth`, type: 'DATE', options: [] as string[]},
    {key: 'company', title: t`Company (invoice)`, type: 'SINGLE_LINE_TEXT', options: [] as string[]},
];

export const QuestionsTable = ({questions}: QuestionsTableProp) => {
    const {eventId} = useParams();
    const queryClient = useQueryClient();
    const [createModalOpen, {open: openCreateModal, close: closeCreateModal}] = useDisclosure(false);
    const [editModalOpen, {open: openEditModal, close: closeEditModal}] = useDisclosure(false);
    const [questionId, setQuestionId] = useState<IdParam>();
    const [addingPreset, setAddingPreset] = useState<string | null>(null);
    const {startExport, isExporting} = useExportAnswers(eventId);

    const eventSettingsQuery = useGetEventSettings(eventId);
    const updateSettingsMutation = useUpdateEventSettings();
    const collectionMethod = eventSettingsQuery.data?.attendee_details_collection_method || 'PER_TICKET';
    const isPerOrderCollection = collectionMethod === 'PER_ORDER';

    const existingTitles = new Set(questions.map((question) => question.title?.trim().toLowerCase()));
    const presets = PRESETS().filter((preset) => !existingTitles.has(preset.title.toLowerCase()));

    const handleModalOpen = (id: IdParam) => {
        setQuestionId(id);
        openEditModal();
    };

    const setCollectionMethod = (value: 'PER_TICKET' | 'PER_ORDER') => {
        if (value === collectionMethod) return;
        updateSettingsMutation.mutate({
            eventSettings: {attendee_details_collection_method: value},
            eventId: eventId,
        }, {
            onSuccess: () => showSuccess(t`Setting updated`),
            onError: () => showError(t`Failed to update setting`),
        });
    };

    const addPreset = (preset: ReturnType<typeof PRESETS>[number]) => {
        setAddingPreset(preset.key);
        questionClient.create(eventId, {
            title: preset.title,
            type: preset.type,
            options: preset.options,
            required: false,
            is_hidden: false,
            belongs_to: 'ORDER',
            product_ids: [],
        }).then(() => {
            showSuccess(t`Question added`);
            return queryClient.invalidateQueries({queryKey: [GET_EVENT_QUESTIONS_QUERY_KEY]});
        }).catch(() => showError(t`The question could not be added. Please try again.`))
            .finally(() => setAddingPreset(null));
    };

    const collectionChoices: { value: 'PER_ORDER' | 'PER_TICKET', title: string, description: string, icon: ReactNode }[] = [
        {
            value: 'PER_ORDER',
            title: t`Once per order`,
            description: t`The fastest: the buyer fills in for everyone.`,
            icon: <IconUser size={20}/>,
        },
        {
            value: 'PER_TICKET',
            title: t`For each ticket`,
            description: t`Name and email of each attendee.`,
            icon: <IconUsers size={20}/>,
        },
    ];

    return (
        <div className={classes.container}>
            <header className={classes.pageHeader}>
                <div>
                    <h1 className={classes.pageTitle}>{t`Registration Questions`}</h1>
                    <p className={classes.pageSubtitle}>{t`What the buyer fills in when paying.`}</p>
                </div>
                <div className={classes.pageActions}>
                    <Button variant="default" radius="md" leftSection={<IconTableExport size={16}/>}
                            loading={isExporting} onClick={() => startExport()}>
                        {t`Export Answers`}
                    </Button>
                    <Button radius="md" leftSection={<IconPlus size={16}/>} onClick={openCreateModal}>
                        {t`Add Question`}
                    </Button>
                </div>
            </header>

            <section className={classes.section}>
                <h2 className={classes.sectionTitle}>{t`Who fills in the details?`}</h2>
                <div className={classes.choices} role="radiogroup">
                    {collectionChoices.map((choice) => (
                        <button key={choice.value} type="button" role="radio"
                                aria-checked={collectionMethod === choice.value}
                                disabled={updateSettingsMutation.isPending}
                                className={classNames(classes.choice, {[classes.choiceActive]: collectionMethod === choice.value})}
                                onClick={() => setCollectionMethod(choice.value)}>
                            <span className={classes.choiceIcon}>{choice.icon}</span>
                            <span className={classes.choiceText}>
                                <span className={classes.choiceTitle}>{choice.title}</span>
                                <span className={classes.choiceDescription}>{choice.description}</span>
                            </span>
                        </button>
                    ))}
                </div>
            </section>

            {presets.length > 0 && (
                <section className={classes.section}>
                    <h2 className={classes.sectionTitle}>{t`Add in one click`}</h2>
                    <div className={classes.presets}>
                        {presets.map((preset) => (
                            <button key={preset.key} type="button" className={classes.preset}
                                    disabled={addingPreset !== null} onClick={() => addPreset(preset)}>
                                <IconPlus size={14}/> {preset.title}
                            </button>
                        ))}
                    </div>
                </section>
            )}

            <section className={classes.section}>
                <h2 className={classes.sectionTitle}>
                    {t`Your questions`} <span className={classes.sectionCount}>· {questions.length}</span>
                </h2>
                <QuestionsList
                    questions={questions}
                    onEditModalOpen={handleModalOpen}
                    onAddQuestion={openCreateModal}
                    emptyMessage={t`No questions yet`}
                    emptyHint={t`Only the name and email are asked. Add a question above if you need more.`}
                />
            </section>

            <LivePreview
                questions={questions}
                isPerOrderCollection={isPerOrderCollection}
            />

            {createModalOpen && (
                <CreateQuestionModal
                    onCompleted={() => undefined}
                    onClose={closeCreateModal}
                    defaultBelongsTo={'ORDER'}
                />
            )}
            {editModalOpen && questionId && (
                <EditQuestionModal questionId={questionId} onClose={closeEditModal}/>
            )}
        </div>
    );
};
