import {useEffect, useMemo, useRef, useState} from "react";
import {NativeSelect, TextInput} from "@mantine/core";
import {useForm} from "@mantine/form";
import {t} from "@lingui/macro";
import {i18n} from "@lingui/core";
import {IconCircleCheck} from "@tabler/icons-react";
import {DemoRequest} from "../../../../api/demo-request.client.ts";
import {useSendDemoRequest} from "../../../../mutations/useSendDemoRequest.ts";
import {useFormErrorResponseHandler} from "../../../../hooks/useFormErrorResponseHandler.tsx";
import {AnalyticsEvents, trackEvent} from "../../../../utilites/analytics.ts";
import {getPrivacyPolicyUrl} from "../../../../utilites/branding.ts";
import classes from "./DemoRequestForm.module.scss";

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

const getUpcomingMonths = (count: number) => {
    const formatter = new Intl.DateTimeFormat(i18n.locale || "en", {month: "long", year: "numeric"});
    const now = new Date();

    return Array.from({length: count}, (_, index) => {
        const date = new Date(now.getFullYear(), now.getMonth() + index, 1);
        const value = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}`;
        const label = formatter.format(date);
        return {value, label: label.charAt(0).toUpperCase() + label.slice(1)};
    });
};

export const DemoRequestForm = () => {
    const mutation = useSendDemoRequest();
    const errorHandler = useFormErrorResponseHandler();
    const [submitted, setSubmitted] = useState<{ firstName: string; email: string } | null>(null);
    const confirmationRef = useRef<HTMLDivElement>(null);

    const form = useForm<DemoRequest>({
        initialValues: {
            first_name: "",
            last_name: "",
            email: "",
            organization: "",
            event_type: "",
            event_date: "",
            attendee_count: "",
            website: "",
        },
        validate: {
            first_name: (value) => value.trim() ? null : t`Please enter your first name`,
            last_name: (value) => value.trim() ? null : t`Please enter your last name`,
            email: (value) => EMAIL_PATTERN.test(value.trim()) ? null : t`Please enter a valid work e-mail`,
            organization: (value) => value.trim() ? null : t`Please enter your organization`,
            event_type: (value) => value ? null : t`Please choose an event type`,
            attendee_count: (value) => value ? null : t`Please choose a number of attendees`,
        },
    });

    const eventTypeOptions = [
        {value: "", label: t`Choose…`, disabled: true},
        {value: "CONFERENCE", label: t`Conference or symposium`},
        {value: "SEMINAR", label: t`Seminar or convention`},
        {value: "GENERAL_ASSEMBLY", label: t`General assembly`},
        {value: "TRADE_SHOW", label: t`Trade show or open day`},
        {value: "CEREMONY", label: t`Ceremony, gala or award night`},
        {value: "INTERNAL_EVENT", label: t`Internal event`},
        {value: "OTHER", label: t`Other`},
    ];

    const attendeeCountOptions = [
        {value: "", label: t`Choose…`, disabled: true},
        {value: "<50", label: t`Fewer than 50`},
        {value: "50-150", label: t`50 to 150`},
        {value: "150-500", label: t`150 to 500`},
        {value: "500-1000", label: t`500 to 1,000`},
        {value: ">1000", label: t`More than 1,000`},
    ];

    const monthOptions = useMemo(() => [
        {value: "", label: t`Not decided yet`},
        ...getUpcomingMonths(18),
    ], [i18n.locale]);

    useEffect(() => {
        if (submitted) {
            confirmationRef.current?.focus();
        }
    }, [submitted]);

    const handleSubmit = (values: DemoRequest) => {
        mutation.mutate(values, {
            onSuccess: () => {
                trackEvent(AnalyticsEvents.DEMO_REQUESTED);
                setSubmitted({firstName: values.first_name.trim(), email: values.email.trim()});
                form.reset();
            },
            onError: (error) => errorHandler(form, error),
        });
    };

    if (submitted) {
        return (
            <div ref={confirmationRef} className={classes.confirmation} role="status" tabIndex={-1}>
                <IconCircleCheck size={48} stroke={1.5} className={classes.confirmationIcon} aria-hidden="true"/>
                <h3 className={classes.confirmationTitle}>{t`Thank you ${submitted.firstName}, your request has been received.`}</h3>
                <p className={classes.confirmationText}>
                    {t`We will reply within 1 business day at ${submitted.email} to schedule your demo.`}
                </p>
            </div>
        );
    }

    return (
        <form className={classes.form} onSubmit={form.onSubmit(handleSubmit)} noValidate>
            <div className={classes.row}>
                <TextInput
                    label={t`First name`}
                    autoComplete="given-name"
                    required
                    size="md"
                    {...form.getInputProps("first_name")}
                />
                <TextInput
                    label={t`Last name`}
                    autoComplete="family-name"
                    required
                    size="md"
                    {...form.getInputProps("last_name")}
                />
            </div>
            <TextInput
                label={t`Work e-mail`}
                type="email"
                autoComplete="email"
                inputMode="email"
                required
                size="md"
                {...form.getInputProps("email")}
            />
            <TextInput
                label={t`Organization`}
                autoComplete="organization"
                required
                size="md"
                {...form.getInputProps("organization")}
            />
            <NativeSelect
                label={t`Event type`}
                data={eventTypeOptions}
                required
                size="md"
                {...form.getInputProps("event_type")}
            />
            <div className={classes.row}>
                <NativeSelect
                    label={t`Estimated date`}
                    data={monthOptions}
                    size="md"
                    {...form.getInputProps("event_date")}
                />
                <NativeSelect
                    label={t`Number of attendees`}
                    data={attendeeCountOptions}
                    required
                    size="md"
                    {...form.getInputProps("attendee_count")}
                />
            </div>

            <div className={classes.honeypot} aria-hidden="true">
                <label>
                    Website
                    <input type="text" tabIndex={-1} autoComplete="off" {...form.getInputProps("website")}/>
                </label>
            </div>

            <button type="submit" className={classes.submit} disabled={mutation.isPending}>
                {mutation.isPending ? t`Sending…` : t`Schedule my demo`}
            </button>
            <p className={classes.legal}>
                {t`Reply within 1 business day. Your details are only used to contact you about this request.`}{" "}
                <a href={getPrivacyPolicyUrl()}>{t`Privacy policy`}</a>
            </p>
        </form>
    );
};
