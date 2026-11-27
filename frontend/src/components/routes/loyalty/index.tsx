import {useState} from "react";
import {Helmet} from "react-helmet-async";
import {useLoaderData, useParams} from "react-router";
import {t} from "@lingui/macro";
import {Alert, Button, Loader, Text, TextInput} from "@mantine/core";
import {useForm} from "@mantine/form";
import {IconBrandApple, IconBrandGoogle} from "@tabler/icons-react";
import {useGetLoyaltyProgramPublic} from "../../../queries/useGetLoyaltyProgramPublic.ts";
import {useEnrollLoyaltyCard} from "../../../mutations/useEnrollLoyaltyCard.ts";
import {useFormErrorResponseHandler} from "../../../hooks/useFormErrorResponseHandler.tsx";
import {EnrollLoyaltyCardRequest} from "../../../api/loyalty.client.ts";
import {LoyaltyCard} from "../../../types.ts";
import {loyaltyApplePassUrl, loyaltyGooglePassUrl} from "../../../utilites/urlHelper.ts";
import classes from "./LoyaltyCardPage.module.scss";

interface LoyaltyLoaderData {
    organizerId?: string;
}

const LoyaltyCardPage = () => {
    const params = useParams();
    const loaderData = useLoaderData() as LoyaltyLoaderData | null;
    const organizerId = params.organizerId ?? loaderData?.organizerId;
    const {data: program, isLoading, isError} = useGetLoyaltyProgramPublic(organizerId);
    const enrollMutation = useEnrollLoyaltyCard();
    const handleFormError = useFormErrorResponseHandler();
    const [card, setCard] = useState<LoyaltyCard | null>(null);

    const form = useForm<EnrollLoyaltyCardRequest>({
        initialValues: {first_name: '', email: '', phone: ''},
        validate: {
            first_name: (value) => (value.trim() === '' ? t`Enter your first name` : null),
            email: (value, values) => (!value?.trim() && !values.phone?.trim()
                ? t`Enter your email address or your phone number.`
                : null),
        },
    });

    const handleSubmit = (values: EnrollLoyaltyCardRequest) => {
        enrollMutation.mutate({
            organizerId,
            request: {
                first_name: values.first_name.trim(),
                email: values.email?.trim() || null,
                phone: values.phone?.trim() || null,
            },
        }, {
            onSuccess: ({data}) => setCard(data),
            onError: (error) => handleFormError(form, error),
        });
    };

    if (isLoading) {
        return <div className={classes.page}><Loader/></div>;
    }

    if (isError || !program) {
        return (
            <div className={classes.page}>
                <Alert color="gray">{t`This loyalty card is not available.`}</Alert>
            </div>
        );
    }

    return (
        <div className={classes.page}>
            <Helmet>
                <title>{program.name}</title>
                <meta name="robots" content="noindex"/>
            </Helmet>
            <div
                className={classes.card}
                style={{background: program.background_color, color: program.foreground_color}}
            >
                {program.logo_url && <img src={program.logo_url} alt="" className={classes.logo}/>}
                <div className={classes.cardName}>{program.name}</div>
                <div className={classes.stamps}>
                    {Array.from({length: program.stamps_required}).map((_, index) => (
                        <span
                            key={index}
                            className={classes.stamp}
                            style={{
                                borderColor: program.label_color,
                                background: card && index < card.stamps ? program.foreground_color : 'transparent',
                            }}
                        />
                    ))}
                </div>
                <div className={classes.reward} style={{color: program.label_color}}>
                    {t`${program.stamps_required} orders = ${program.reward_description}`}
                </div>
            </div>

            {!card && (
                <form onSubmit={form.onSubmit(handleSubmit)} className={classes.form}>
                    <Text fw={600} size="lg">{t`Add my card to my phone`}</Text>
                    <TextInput required label={t`First name`} autoComplete="given-name" maxLength={40}
                               {...form.getInputProps('first_name')}/>
                    <TextInput label={t`Email`} type="email" autoComplete="email" inputMode="email"
                               {...form.getInputProps('email')}/>
                    <TextInput label={t`Or phone`} type="tel" autoComplete="tel" inputMode="tel"
                               {...form.getInputProps('phone')}/>
                    <Button type="submit" size="lg" fullWidth loading={enrollMutation.isPending}>
                        {t`Get my card`}
                    </Button>
                </form>
            )}

            {card?.public_id && (
                <div className={classes.form}>
                    <Text fw={600} size="lg">{t`Your card is ready, ${card.first_name}`}</Text>
                    <Text size="sm" c="dimmed">
                        {t`Add it to your phone, then show the QR code at the counter at each order.`}
                    </Text>
                    {program.apple_enabled && (
                        <Button component="a" href={loyaltyApplePassUrl(card.public_id)} size="lg" fullWidth
                                color="dark" leftSection={<IconBrandApple size={20}/>}>
                            {t`Add to Apple Wallet`}
                        </Button>
                    )}
                    {program.google_enabled && (
                        <Button component="a" href={loyaltyGooglePassUrl(card.public_id)} size="lg" fullWidth
                                variant="outline" color="dark" leftSection={<IconBrandGoogle size={20}/>}>
                            {t`Save to Google Wallet`}
                        </Button>
                    )}
                    {!program.apple_enabled && !program.google_enabled && (
                        <Alert color="yellow">
                            {t`Your card is saved. Adding it to the phone will be available very soon.`}
                        </Alert>
                    )}
                </div>
            )}
        </div>
    );
};

export default LoyaltyCardPage;
