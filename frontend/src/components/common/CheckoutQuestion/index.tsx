import {Product, Question, QuestionType} from "../../../types.ts";
import {UseFormReturnType} from "@mantine/form";
import {
    ActionIcon,
    Box,
    Checkbox,
    ComboboxItem,
    FileInput,
    Group,
    Input,
    NativeSelect,
    Radio,
    Select,
    Text,
    Textarea,
    TextInput
} from "@mantine/core";
import {t} from "@lingui/macro";
import {useState} from "react";
import {useParams} from "react-router";
import {IconFileTypePdf, IconUpload, IconX} from "@tabler/icons-react";
import {useUploadQuestionFile} from "../../../mutations/useUploadQuestionFile.ts";
import countries from "../../../../data/countries.json";
import {InputGroup} from "../InputGroup";
import classes from "./CheckoutQuestion.module.scss";
import {UserGeneratedContent} from "../UserGeneratedContent";

interface CheckoutQuestionProps {
    questions: Question[],
    form: UseFormReturnType<any, any>;
}

interface QuestionInputProps {
    question: Partial<Question>,
    name: string,
    form: UseFormReturnType<any, any>,
}

interface CheckoutProductQuestionProps {
    questions: Question[],
    form: UseFormReturnType<any, any>;
    product: Product,
    index: number,
}

const DropDownInput = ({question, name, form}: QuestionInputProps) => {
    const items: ComboboxItem[] = [];

    question.options?.map((option) => {
        items.push({
            value: option,
            label: option,
        })
    });
    return (
        <Select
            classNames={{
                description: classes.descriptionWithNoStyle,
            }}
            description={(<UserGeneratedContent dangerouslySetInnerHTML={{__html: question.description || ''}}/>)}
            {...form.getInputProps(`${name}.answer`)}
            data={items}
            label={question.title}
            withAsterisk={question.required}
        />
    );
}

const MultiLineTextInput = ({question, name, form}: QuestionInputProps) => {
    return (
        <>
            <Textarea
                classNames={{
                    description: classes.descriptionWithNoStyle,
                }}
                description={(<UserGeneratedContent dangerouslySetInnerHTML={{__html: question.description || ''}}/>)}
                {...form.getInputProps(`${name}.answer`)} withAsterisk={question.required}
                label={question.title}/>
        </>
    );
}

const DateInput = ({question, name, form}: QuestionInputProps) => {
    return (
        <>
            <TextInput withAsterisk={question.required}
                       type="date"
                       {...form.getInputProps(`${name}.answer`)}
                       label={question.title}
                       description={(
                           <UserGeneratedContent dangerouslySetInnerHTML={{__html: question.description || ''}}/>)}
            />
        </>
    );
}

const SingleLineTextInput = ({question, name, form}: QuestionInputProps) => {
    return (
        <>
            <TextInput
                classNames={{
                    description: classes.descriptionWithNoStyle,
                }}
                {...form.getInputProps(`${name}.answer`)}
                withAsterisk={question.required}
                label={question.title}
                description={(<UserGeneratedContent dangerouslySetInnerHTML={{__html: question.description || ''}}/>)}
            />
        </>
    );
}

const PhoneInput = ({question, name, form}: QuestionInputProps) => {
    return (
        <>
            <TextInput
                classNames={{
                    description: classes.descriptionWithNoStyle,
                }}
                {...form.getInputProps(`${name}.answer`)}
                type="tel"
                inputMode="tel"
                autoComplete={name.startsWith('order.') ? 'tel' : 'off'}
                pattern="[0-9+\-\s\(\)]{8,20}"
                title={t`Please enter a valid phone number (digits, spaces, +, -, ( ) only, minimum 8 characters)`}
                withAsterisk={question.required}
                label={question.title}
                description={(<UserGeneratedContent dangerouslySetInnerHTML={{__html: question.description || ''}}/>)}
            />
        </>
    );
}

const RadioInput = ({question, name, form}: QuestionInputProps) => {
    return (
        <Radio.Group
            classNames={{
                description: classes.descriptionWithNoStyle,
            }}
            withAsterisk={question.required}
            {...form.getInputProps(`${name}.answer`)}
            label={question.title}
            description={(<UserGeneratedContent dangerouslySetInnerHTML={{__html: question.description || ''}}/>)}
        >
            <Group mt="xs">
                {question.options?.map((option, index) => {
                    return (
                        <Radio
                            key={`${question.id}-radio-${index}`}
                            label={option}
                            value={option}
                        />
                    )
                })}
            </Group>
        </Radio.Group>
    )
}

const CheckBoxInput = ({question, name, form}: QuestionInputProps) => {
    return (
        <Checkbox.Group
            classNames={{
                description: classes.descriptionWithNoStyle,
            }}
            withAsterisk={question.required}
            {...form.getInputProps(`${name}.answer`)}
            label={question.title}
            description={(<UserGeneratedContent dangerouslySetInnerHTML={{__html: question.description || ''}}/>)}
        >
            <Group mt="xs">
                {question.options?.map((option, index) => {
                    return (
                        <Checkbox
                            key={`${question.id}-checkbox-${index}`}
                            label={option}
                            value={option}
                        />
                    )
                })}
            </Group>
        </Checkbox.Group>
    );
}

const MAX_QUESTION_FILE_SIZE_BYTES = 5 * 1024 * 1024;

const getUploadErrorMessage = (error: any): string => {
    const errors = error?.response?.data?.errors;
    const firstError = errors?.file?.[0] ?? errors?.question_id?.[0];

    return firstError ?? error?.response?.data?.message ?? t`The file could not be uploaded. Please try again.`;
}

const FileQuestionInput = ({question, name, form}: QuestionInputProps) => {
    const {eventId, orderShortId} = useParams();
    const uploadMutation = useUploadQuestionFile();
    const [fileName, setFileName] = useState<string | null>(null);
    const [uploadError, setUploadError] = useState<string | null>(null);
    const answerPath = `${name}.answer`;
    const answer = form.getInputProps(answerPath).value;
    const formError = form.getInputProps(answerPath).error;
    const canUpload = !!eventId && !!orderShortId && !!question.id;
    const description = (<UserGeneratedContent dangerouslySetInnerHTML={{__html: question.description || ''}}/>);

    const handleFileChange = (file: File | null) => {
        setUploadError(null);

        if (!file || !canUpload) {
            return;
        }

        const isPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
        if (!isPdf) {
            setUploadError(t`Only PDF files are accepted.`);
            return;
        }

        if (file.size > MAX_QUESTION_FILE_SIZE_BYTES) {
            setUploadError(t`The file must not be larger than 5 MB.`);
            return;
        }

        uploadMutation.mutate({
            eventId: eventId as string,
            orderShortId: orderShortId as string,
            questionId: question.id as number,
            file,
        }, {
            onSuccess: ({data}) => {
                setFileName(data.original_filename);
                form.setFieldValue(answerPath, data.id);
                form.clearFieldError(answerPath);
            },
            onError: (error: any) => {
                setUploadError(getUploadErrorMessage(error));
            },
        });
    };

    const handleRemove = () => {
        setFileName(null);
        setUploadError(null);
        form.setFieldValue(answerPath, '');
    };

    if (answer && fileName) {
        return (
            <Input.Wrapper
                classNames={{description: classes.descriptionWithNoStyle}}
                label={question.title}
                description={description}
                withAsterisk={question.required}
                error={formError}
            >
                <Group gap="xs" wrap="nowrap" mt={6} className={classes.uploadedFile}>
                    <IconFileTypePdf size={20}/>
                    <Text size="sm" truncate="end" className={classes.uploadedFileName}>{fileName}</Text>
                    <ActionIcon
                        variant="subtle"
                        color="gray"
                        onClick={handleRemove}
                        aria-label={t`Remove file`}
                        title={t`Remove file`}
                    >
                        <IconX size={16}/>
                    </ActionIcon>
                </Group>
            </Input.Wrapper>
        );
    }

    return (
        <FileInput
            classNames={{
                description: classes.descriptionWithNoStyle,
            }}
            accept="application/pdf"
            label={question.title}
            description={description}
            withAsterisk={question.required}
            placeholder={uploadMutation.isPending ? t`Uploading...` : t`Choose a PDF file (5 MB max)`}
            leftSection={<IconUpload size={16}/>}
            value={null}
            onChange={handleFileChange}
            disabled={!canUpload || uploadMutation.isPending}
            error={uploadError || formError}
            clearable={false}
        />
    );
}

const AddressInput = ({question, name, form}: QuestionInputProps) => {
    return (
        <>
            <h4>{question.title}</h4>
            <UserGeneratedContent className={classes.description}
                                  dangerouslySetInnerHTML={{__html: question.description || ''}}/>

            <TextInput withAsterisk={question.required}
                       {...form.getInputProps(`${name}.address_line_1`)}
                       label={t`Address line 1`}/>
            <TextInput mt={20}
                       {...form.getInputProps(`${name}.address_line_2`)}
                       label={t`Address line 2`}/>
            <InputGroup>
                <TextInput withAsterisk={question.required}
                           {...form.getInputProps(`${name}.city`)} label={t`City`}/>
                <TextInput withAsterisk={question.required}
                           {...form.getInputProps(`${name}.state_or_region`)}
                           label={t`State or Region`}/>
            </InputGroup>
            <InputGroup>
                <TextInput withAsterisk={question.required}
                           {...form.getInputProps(`${name}.zip_or_postal_code`)}
                           label={t`Zip or Postal Code`}/>
                <NativeSelect withAsterisk={question.required}
                              data={countries}
                              {...form.getInputProps(`${name}.country`)}
                              label={t`Country`}/>
            </InputGroup>
        </>
    );
}

export const QuestionInput = ({question, name, form}: QuestionInputProps) => {
    let input;
    switch (question.type) {
        case QuestionType.ADDRESS:
            input = <AddressInput question={question} name={name} form={form}/>
            break;
        case QuestionType.CHECKBOX:
            input = <CheckBoxInput question={question} name={name} form={form}/>
            break;
        case QuestionType.MULTI_LINE_TEXT:
            input = <MultiLineTextInput question={question} name={name} form={form}/>;
            break;
        case QuestionType.RADIO:
            input = <RadioInput question={question} name={name} form={form}/>;
            break;
        case QuestionType.DROPDOWN:
            input = <DropDownInput question={question} name={name} form={form}/>;
            break;
        case QuestionType.SINGLE_LINE_TEXT:
            input = <SingleLineTextInput question={question} name={name} form={form}/>;
            break;
        case QuestionType.PHONE:
            input = <PhoneInput question={question} name={name} form={form}/>;
            break;
        case QuestionType.DATE:
            input = <DateInput question={question} name={name} form={form}/>;
            break;
        case QuestionType.FILE:
            input = <FileQuestionInput question={question} name={name} form={form}/>;
            break;
    }

    return (
        <Box mt={20}>
            {input}
        </Box>
    )
};

export const CheckoutOrderQuestions = ({questions, form}: CheckoutQuestionProps) => {
    let questionIndex = 0;
    return (
        <>
            {questions.map((question, index) => {
                const name = `order.questions.${questionIndex++}.response`;
                return <QuestionInput key={`${index}-question`} question={question} name={name} form={form}/>
            })}
        </>
    )
}

export const CheckoutProductQuestions = ({
                                             questions,
                                             form,
                                             product,
                                             index: productIndex
                                         }: CheckoutProductQuestionProps) => {
    let questionIndex = 0;
    return (
        <>
            {questions.map((question, index) => {
                if (!question.product_ids?.includes(Number(product.id))) {
                    return;
                }

                const name = `products.${productIndex}.questions.${questionIndex++}.response`;
                return <QuestionInput key={`${index}-product`} question={question} name={name} form={form}/>
            })}
        </>
    )
}

