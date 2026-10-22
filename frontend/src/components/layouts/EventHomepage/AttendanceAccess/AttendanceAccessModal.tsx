import {CSSProperties, FormEvent, useEffect, useState} from "react";
import {Modal, PinInput, TextInput} from "@mantine/core";
import {useReducedMotion} from "@mantine/hooks";
import {t} from "@lingui/macro";
import {useNavigate} from "react-router";
import {AttendanceAccessLink, IdParam} from "../../../../types.ts";
import {useRequestAttendanceAccessCode, useVerifyAttendanceAccessCode} from "../../../../mutations/useAttendanceAccessPublic.ts";
import {venuePath} from "../../../../utilites/rememberedVenue.ts";
import classes from "./AttendanceAccess.module.scss";

type Step = 'contact' | 'code' | 'choose' | 'none';

interface AttendanceAccessModalProps {
    opened: boolean;
    onClose: () => void;
    eventId: IdParam;
    themeStyle?: CSSProperties;
    onNoActiveRegistration?: () => void;
}

const CODE_LENGTH = 6;

const responseMessage = (error: any, field?: string): string | null => {
    const data = error?.response?.data;
    if (field && Array.isArray(data?.errors?.[field]) && data.errors[field][0]) {
        return data.errors[field][0];
    }
    return data?.message || null;
};

export const AttendanceAccessModal = ({opened, onClose, eventId, themeStyle, onNoActiveRegistration}: AttendanceAccessModalProps) => {
    const navigate = useNavigate();
    const reduceMotion = useReducedMotion();
    const [step, setStep] = useState<Step>('contact');
    const [contact, setContact] = useState('');
    const [code, setCode] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [links, setLinks] = useState<AttendanceAccessLink[]>([]);
    const requestMutation = useRequestAttendanceAccessCode();
    const verifyMutation = useVerifyAttendanceAccessCode();

    useEffect(() => {
        if (!opened) {
            setStep('contact');
            setCode('');
            setError(null);
            setLinks([]);
        }
    }, [opened]);

    const openVenue = (link: AttendanceAccessLink) => {
        onClose();
        navigate(venuePath(eventId, link.short_id, link.code));
    };

    const requestCode = (submitEvent?: FormEvent) => {
        submitEvent?.preventDefault();
        if (!contact.trim()) {
            setError(t`Enter the email address or phone number used to register.`);
            return;
        }
        setError(null);
        requestMutation.mutate({eventId, contact: contact.trim()}, {
            onSuccess: () => {
                setCode('');
                setStep('code');
            },
            onError: (requestError) => setError(
                responseMessage(requestError, 'contact') || t`We could not send the code. Please try again.`
            ),
        });
    };

    const verifyCode = (value: string) => {
        if (value.length !== CODE_LENGTH || verifyMutation.isPending) {
            return;
        }
        setError(null);
        verifyMutation.mutate({eventId, contact: contact.trim(), code: value}, {
            onSuccess: (result) => {
                if (result.length === 0) {
                    onNoActiveRegistration?.();
                    setStep('none');
                    return;
                }
                if (result.length === 1) {
                    openVenue(result[0]);
                    return;
                }
                setLinks(result);
                setStep('choose');
            },
            onError: (verifyError) => {
                setCode('');
                setError(responseMessage(verifyError, 'code') || t`This code is incorrect or has expired. Request a new code.`);
            },
        });
    };

    return (
        <Modal
            opened={opened}
            onClose={onClose}
            centered
            radius="lg"
            size="md"
            title={t`Manage my attendance`}
            style={themeStyle}
            classNames={{
                content: classes.modalContent,
                header: classes.modalHeader,
                title: classes.modalTitle,
                body: classes.modalBody,
                close: classes.modalClose,
            }}
            overlayProps={{backgroundOpacity: 0.6, blur: 4}}
            transitionProps={{duration: reduceMotion ? 0 : 200}}
            closeButtonProps={{'aria-label': t`Close`}}
        >
            {step === 'contact' && (
                <form onSubmit={requestCode} noValidate>
                    <p className={classes.text}>
                        {t`Enter the email address or phone number used to register. We will send a code to this contact only.`}
                    </p>
                    <TextInput
                        value={contact}
                        onChange={(changeEvent) => setContact(changeEvent.currentTarget.value)}
                        label={t`Email or phone`}
                        placeholder={t`name@example.com or 06 39 00 00 00`}
                        error={error}
                        data-autofocus
                        classNames={{label: classes.label, input: classes.input, error: classes.error}}
                    />
                    <button type="submit" className={classes.primaryButton} disabled={requestMutation.isPending}>
                        {requestMutation.isPending ? t`Sending...` : t`Receive a code`}
                    </button>
                </form>
            )}

            {step === 'code' && (
                <div>
                    <p className={classes.text}>
                        {t`If a registration matches, you will receive a message with a 6 digit code. It is valid for 10 minutes.`}
                    </p>
                    <p className={classes.label} id="attendance-access-code-label">{t`Code received`}</p>
                    <PinInput
                        length={CODE_LENGTH}
                        type="number"
                        oneTimeCode
                        value={code}
                        onChange={setCode}
                        onComplete={verifyCode}
                        error={!!error}
                        disabled={verifyMutation.isPending}
                        size="lg"
                        gap="xs"
                        aria-labelledby="attendance-access-code-label"
                        classNames={{root: classes.pinRoot, input: classes.pinInput}}
                        data-autofocus
                    />
                    {error && <p className={classes.errorText} role="alert">{error}</p>}
                    <button
                        type="button"
                        className={classes.primaryButton}
                        disabled={code.length !== CODE_LENGTH || verifyMutation.isPending}
                        onClick={() => verifyCode(code)}
                    >
                        {verifyMutation.isPending ? t`Checking...` : t`Validate`}
                    </button>
                    <div className={classes.secondaryRow}>
                        <button type="button" className={classes.linkButton} onClick={() => requestCode()} disabled={requestMutation.isPending}>
                            {t`Send a new code`}
                        </button>
                        <button
                            type="button"
                            className={classes.linkButton}
                            onClick={() => {
                                setError(null);
                                setStep('contact');
                            }}
                        >
                            {t`Change email or phone`}
                        </button>
                    </div>
                </div>
            )}

            {step === 'choose' && (
                <div>
                    <p className={classes.eyebrow}>{t`For whom?`}</p>
                    <ul className={classes.peopleList}>
                        {links.map((link, index) => (
                            <li key={link.short_id}>
                                <button type="button" className={classes.personButton} onClick={() => openVenue(link)}>
                                    <span className={classes.personName}>{link.first_name || t`Participant ${index + 1}`}</span>
                                    <span className={classes.personArrow} aria-hidden="true">→</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {step === 'none' && (
                <p className={classes.text}>
                    {t`No active registration was found for this contact. Your registration may have been cancelled.`}
                </p>
            )}
        </Modal>
    );
};
