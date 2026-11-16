import {Button} from "@mantine/core";
import {t} from "@lingui/macro";
import {authClient} from "../../../api/auth.client.ts";

export const LogoutButton = () => {
    const logout = async () => {
        try {
            await authClient.logout();
        } finally {
            window.location.href = '/auth/login';
        }
    };

    return (
        <Button variant="subtle" onClick={logout}>{t`Log out`}</Button>
    );
};
