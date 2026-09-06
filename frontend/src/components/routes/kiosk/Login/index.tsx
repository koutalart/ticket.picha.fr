import {Button, PasswordInput, TextInput} from "@mantine/core";
import {useMutation, useQueryClient} from "@tanstack/react-query";
import {useForm} from "@mantine/form";
import {t} from "@lingui/macro";
import {useEffect, useState} from "react";
import {useNavigate} from "react-router";
import {authClient} from "../../../../api/auth.client.ts";
import {boxOfficeClient} from "../../../../api/box-office.client.ts";
import {LoginData, LoginResponse} from "../../../../types.ts";
import {showError} from "../../../../utilites/notifications.tsx";
import {ChooseAccountModal} from "../../../modals/ChooseAccountModal";
import {GET_ME_QUERY_KEY} from "../../../../queries/useGetMe.ts";
import {GET_BOX_OFFICE_CONTEXT_QUERY_KEY} from "../../../../queries/useGetBoxOfficeContext.ts";
import {kioskPathForEvents, KIOSK_CONNECTED_AT_KEY} from "../../../../utilites/kioskAuth.ts";
import {KioskLogo} from "../../../layouts/Kiosk/KioskLogo";
import classes from "../../../layouts/Kiosk/Kiosk.module.scss";

const KioskLogin = () => {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [showChooseAccount, setShowChooseAccount] = useState(false);
    const form = useForm({
        initialValues: {
            email: '',
            password: '',
            account_id: '',
        }
    });

    const redirectFromContext = async () => {
        await queryClient.invalidateQueries({queryKey: [GET_ME_QUERY_KEY]});
        const response = await queryClient.fetchQuery({
            queryKey: [GET_BOX_OFFICE_CONTEXT_QUERY_KEY],
            queryFn: () => boxOfficeClient.getContext(),
        });
        navigate(kioskPathForEvents(response.data ?? []), {replace: true});
    };

    const {mutate: loginUser, isPending, data} = useMutation({
        mutationFn: (userData: LoginData) => authClient.login(userData),
        onSuccess: async (response: LoginResponse) => {
            if (typeof window !== 'undefined') {
                window.sessionStorage.setItem(KIOSK_CONNECTED_AT_KEY, new Date().toISOString());
            }

            if (response.token) {
                await redirectFromContext();
                return;
            }

            if (response.accounts.length > 1) {
                setShowChooseAccount(true);
            }
        },
        onError: () => {
            showError(t`Please check your email and password and try again`);
        }
    });

    useEffect(() => {
        if (form.values.account_id) {
            loginUser(form.values);
        }
    }, [form.values.account_id]);

    return (
        <div className={classes.loginShell}>
            <div className={classes.loginCard}>
                <header className={classes.loginBrand}>
                    <KioskLogo className={classes.loginBrandLogo} onDark={false}/>
                </header>
                <form onSubmit={form.onSubmit((values) => loginUser(values))}>
                    <TextInput
                        {...form.getInputProps('email')}
                        placeholder={t`Email`}
                        required
                        size="md"
                    />
                    <PasswordInput
                        {...form.getInputProps('password')}
                        placeholder={t`Password`}
                        required
                        mt="md"
                        size="md"
                    />
                    <Button
                        type="submit"
                        fullWidth
                        loading={isPending}
                        disabled={isPending}
                        mt="xl"
                        size="lg"
                        className={classes.loginSubmit}
                    >
                        {isPending ? t`Logging in` : t`Log in`}
                    </Button>
                </form>
                {showChooseAccount && (
                    <ChooseAccountModal
                        accounts={data?.accounts || []}
                        onAccountChosen={(accountId) => {
                            form.setFieldValue('account_id', String(accountId));
                        }}
                    />
                )}
            </div>
        </div>
    );
};

export default KioskLogin;
