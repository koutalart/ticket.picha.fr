import {useState} from "react";
import {t, Trans} from "@lingui/macro";
import {Alert, Button, Checkbox, Group, Select, Stack, Stepper, Table, Text, TextInput} from "@mantine/core";
import {Dropzone} from "@mantine/dropzone";
import {IconAlertTriangle, IconFileSpreadsheet, IconUpload} from "@tabler/icons-react";
import {Modal} from "../../../common/Modal";
import {
    ContactImportField,
    ContactImportPreview,
    ContactImportResult,
    RunContactImportRequest,
} from "../../../../api/contact.client.ts";
import {useRevertContactImport, useRunContactImport, useUploadContactImport} from "../../../../mutations/useContactImport.ts";
import {showError, showSuccess} from "../../../../utilites/notifications.tsx";
import {IdParam} from "../../../../types.ts";
import classes from "./Contacts.module.scss";

interface ImportContactsModalProps {
    organizerId: IdParam;
    onClose: () => void;
}

const ACCEPTED_TYPES = [
    'text/csv',
    'text/plain',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.oasis.opendocument.spreadsheet',
];

const errorMessage = (error: any): string => {
    const errors = error?.response?.data?.errors;
    if (errors) {
        const first = Object.values(errors)[0];
        return Array.isArray(first) ? String(first[0]) : String(first);
    }
    return t`Something went wrong. Please try again.`;
};

export const ImportContactsModal = ({organizerId, onClose}: ImportContactsModalProps) => {
    const [step, setStep] = useState(0);
    const [preview, setPreview] = useState<ContactImportPreview | null>(null);
    const [mapping, setMapping] = useState<ContactImportField[]>([]);
    const [tagName, setTagName] = useState('');
    const [emailConsent, setEmailConsent] = useState(false);
    const [smsConsent, setSmsConsent] = useState(false);
    const [country, setCountry] = useState<RunContactImportRequest['default_country']>('YT');
    const [estimate, setEstimate] = useState<ContactImportResult | null>(null);
    const [result, setResult] = useState<ContactImportResult | null>(null);

    const upload = useUploadContactImport();
    const run = useRunContactImport();
    const revert = useRevertContactImport();

    const fieldOptions: { value: ContactImportField, label: string }[] = [
        {value: 'FIRST_NAME', label: t`First name`},
        {value: 'LAST_NAME', label: t`Last name`},
        {value: 'FULL_NAME', label: t`Full name`},
        {value: 'EMAIL', label: t`Email`},
        {value: 'PHONE', label: t`Phone`},
        {value: 'TAG', label: t`Tag`},
        {value: 'IGNORE', label: t`Ignore`},
    ];

    const rejectionReasons = {
        INVALID_EMAIL: t`Invalid email address`,
        INVALID_PHONE: t`Invalid phone number`,
        NO_EMAIL_OR_PHONE: t`No email address or phone number`,
    };

    const handleFile = (file?: File) => {
        if (!file) {
            return;
        }
        upload.mutate({organizerId, file}, {
            onSuccess: (data) => {
                setPreview(data);
                setMapping(data.suggested_mapping);
                setTagName(data.file_name.replace(/\.[^.]+$/, ''));
                setStep(1);
            },
            onError: (error) => showError(errorMessage(error)),
        });
    };

    const buildRequest = (dryRun: boolean): RunContactImportRequest => ({
        import_token: preview!.import_token,
        file_name: preview!.file_name,
        mapping,
        tag_name: tagName.trim() || null,
        email_consent: emailConsent,
        sms_consent: smsConsent,
        default_country: country,
        dry_run: dryRun,
    });

    const handleEstimate = () => {
        run.mutate({organizerId, data: buildRequest(true)}, {
            onSuccess: (data) => {
                setEstimate(data);
                setStep(2);
            },
            onError: (error) => showError(errorMessage(error)),
        });
    };

    const handleImport = () => {
        run.mutate({organizerId, data: buildRequest(false)}, {
            onSuccess: (data) => {
                setResult(data);
                setStep(3);
                showSuccess(t`Contacts imported`);
            },
            onError: (error) => showError(errorMessage(error)),
        });
    };

    const handleRevert = () => {
        if (!result?.import_id) {
            return;
        }
        revert.mutate({organizerId, importId: result.import_id}, {
            onSuccess: () => {
                showSuccess(t`Import cancelled`);
                onClose();
            },
            onError: () => showError(t`The import could not be cancelled. Please try again.`),
        });
    };

    const hasIdentifier = mapping.includes('EMAIL') || mapping.includes('PHONE');
    const importable = estimate ? estimate.created_count + estimate.updated_count : 0;

    return (
        <Modal opened onClose={onClose} heading={t`Import contacts`}>
            <Stepper active={step} size="sm" mb="lg">
                <Stepper.Step label={t`File`}/>
                <Stepper.Step label={t`Columns`}/>
                <Stepper.Step label={t`Check`}/>
                <Stepper.Completed>{null}</Stepper.Completed>
            </Stepper>

            {step === 0 && (
                <Stack>
                    <Dropzone
                        onDrop={(files) => handleFile(files[0])}
                        onReject={() => showError(t`Use a CSV or Excel file of 10 MB maximum.`)}
                        maxSize={10 * 1024 * 1024}
                        accept={ACCEPTED_TYPES}
                        multiple={false}
                        loading={upload.isPending}
                    >
                        <Group justify="center" gap="md" mih={160} style={{pointerEvents: 'none'}}>
                            <Dropzone.Accept><IconUpload size={40}/></Dropzone.Accept>
                            <Dropzone.Idle><IconFileSpreadsheet size={40}/></Dropzone.Idle>
                            <div>
                                <Text size="lg">{t`Drop your file here or click to choose it`}</Text>
                                <Text size="sm" c="dimmed">
                                    {t`CSV or Excel, one contact per line, with a first line of column names. A Brevo export works as is.`}
                                </Text>
                            </div>
                        </Group>
                    </Dropzone>
                </Stack>
            )}

            {step === 1 && preview && (
                <Stack>
                    <Text size="sm">
                        <Trans>{preview.file_name} · {preview.row_count} lines. Tell us what each column contains.</Trans>
                    </Text>
                    <Table.ScrollContainer minWidth={520}>
                        <Table verticalSpacing="xs">
                            <Table.Thead>
                                <Table.Tr>
                                    <Table.Th>{t`Column in your file`}</Table.Th>
                                    <Table.Th>{t`Example`}</Table.Th>
                                    <Table.Th>{t`Field`}</Table.Th>
                                </Table.Tr>
                            </Table.Thead>
                            <Table.Tbody>
                                {preview.headers.map((header, index) => (
                                    <Table.Tr key={index}>
                                        <Table.Td>{header || t`Column ${index + 1}`}</Table.Td>
                                        <Table.Td className={classes.email}>
                                            {preview.sample_rows.map(row => row[index]).find(Boolean) ?? '—'}
                                        </Table.Td>
                                        <Table.Td>
                                            <Select
                                                size="xs"
                                                allowDeselect={false}
                                                data={fieldOptions}
                                                value={mapping[index]}
                                                onChange={(value) => setMapping(current => current.map(
                                                    (field, position) => position === index ? value as ContactImportField : field
                                                ))}
                                            />
                                        </Table.Td>
                                    </Table.Tr>
                                ))}
                            </Table.Tbody>
                        </Table>
                    </Table.ScrollContainer>

                    {!hasIdentifier && (
                        <Alert color="orange" icon={<IconAlertTriangle size={16}/>}>
                            {t`Choose the column that contains the email address or the phone number.`}
                        </Alert>
                    )}

                    <Group grow align="flex-start">
                        <TextInput
                            label={t`Tag added to every imported contact`}
                            value={tagName}
                            onChange={(event) => setTagName(event.currentTarget.value)}
                        />
                        <Select
                            label={t`Numbers without a country code are from`}
                            allowDeselect={false}
                            value={country}
                            onChange={(value) => setCountry(value as RunContactImportRequest['default_country'])}
                            data={[
                                {value: 'YT', label: t`Mayotte or France`},
                                {value: 'MG', label: t`Madagascar`},
                                {value: 'DK', label: t`Denmark`},
                            ]}
                        />
                    </Group>

                    <Stack gap="xs">
                        <Checkbox
                            label={t`These people agreed to receive my emails`}
                            checked={emailConsent}
                            onChange={(event) => setEmailConsent(event.currentTarget.checked)}
                        />
                        <Checkbox
                            label={t`These people agreed to receive my SMS`}
                            checked={smsConsent}
                            onChange={(event) => setSmsConsent(event.currentTarget.checked)}
                        />
                        <Text size="xs" c="dimmed">
                            {t`Only tick these boxes if you can prove it. Otherwise these contacts will be visible but will not receive any announcement.`}
                        </Text>
                    </Stack>

                    <Group justify="space-between">
                        <Button variant="default" onClick={() => setStep(0)}>{t`Back`}</Button>
                        <Button onClick={handleEstimate} loading={run.isPending} disabled={!hasIdentifier}>
                            {t`Continue`}
                        </Button>
                    </Group>
                </Stack>
            )}

            {step === 2 && estimate && (
                <Stack>
                    <div className={classes.importSummary}>
                        <div><strong>{estimate.created_count}</strong> {t`new contacts`}</div>
                        <div><strong>{estimate.updated_count}</strong> {t`already known, completed without being overwritten`}</div>
                        <div><strong>{estimate.rejected_count}</strong> {t`lines rejected`}</div>
                    </div>

                    {estimate.rejected_rows.length > 0 && (
                        <Table.ScrollContainer minWidth={480} mah={220}>
                            <Table verticalSpacing={4} fz="xs">
                                <Table.Thead>
                                    <Table.Tr>
                                        <Table.Th>{t`Line`}</Table.Th>
                                        <Table.Th>{t`Reason`}</Table.Th>
                                        <Table.Th>{t`Content`}</Table.Th>
                                    </Table.Tr>
                                </Table.Thead>
                                <Table.Tbody>
                                    {estimate.rejected_rows.map(row => (
                                        <Table.Tr key={row.line}>
                                            <Table.Td>{row.line}</Table.Td>
                                            <Table.Td>{rejectionReasons[row.reason]}</Table.Td>
                                            <Table.Td>{row.values.filter(Boolean).join(' · ')}</Table.Td>
                                        </Table.Tr>
                                    ))}
                                </Table.Tbody>
                            </Table>
                        </Table.ScrollContainer>
                    )}

                    <Text size="xs" c="dimmed">{t`No contact is created before you click Import.`}</Text>

                    <Group justify="space-between">
                        <Button variant="default" onClick={() => setStep(1)}>{t`Back`}</Button>
                        <Button onClick={handleImport} loading={run.isPending} disabled={importable === 0}>
                            <Trans>Import {importable} contacts</Trans>
                        </Button>
                    </Group>
                </Stack>
            )}

            {step === 3 && result && (
                <Stack>
                    <div className={classes.importSummary}>
                        <div><strong>{result.created_count}</strong> {t`new contacts`}</div>
                        <div><strong>{result.updated_count}</strong> {t`contacts completed`}</div>
                        <div><strong>{result.rejected_count}</strong> {t`lines rejected`}</div>
                    </div>
                    <Group justify="space-between">
                        <Button variant="subtle" color="red" onClick={handleRevert} loading={revert.isPending}>
                            {t`Cancel this import`}
                        </Button>
                        <Button onClick={onClose}>{t`Close`}</Button>
                    </Group>
                </Stack>
            )}
        </Modal>
    );
};
