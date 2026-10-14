import {useParams} from "react-router";
import {Button, Skeleton} from "@mantine/core";
import {IconPlus} from "@tabler/icons-react";
import {t, Trans} from "@lingui/macro";
import {useDisclosure} from "@mantine/hooks";
import {useGetEvent} from "../../../queries/useGetEvent.ts";
import {useGetEventPromoCodes} from "../../../queries/useGetEventPromoCodes.ts";
import {useFilterQueryParamSync} from "../../../hooks/useFilterQueryParamSync.ts";
import {QueryFilters} from "../../../types.ts";
import {PromoCodeTable} from "../../common/PromoCodeTable";
import {CreatePromoCodeModal} from "../../modals/CreatePromoCodeModal";
import {Pagination} from "../../common/Pagination";
import classes from "./PromoCodes.module.scss";

export const PromoCodes = () => {
    const {eventId} = useParams();
    const {data: event} = useGetEvent(eventId);
    const [searchParams, setSearchParams] = useFilterQueryParamSync();
    const promoCodesQuery = useGetEventPromoCodes(eventId, searchParams as QueryFilters);
    const promoCodes = promoCodesQuery?.data?.data;
    const pagination = promoCodesQuery?.data?.meta;
    const [createModalOpen, {open: openCreateModal, close: closeCreateModal}] = useDisclosure(false);
    const totalUses = (promoCodes ?? []).reduce((sum, code) => sum + (code.order_usage_count ?? 0), 0);

    return (
        <div className={classes.page}>
            <header className={classes.header}>
                <div>
                    <span className={classes.kicker}>{t`Promo Codes`}</span>
                    {event
                        ? <h1 className={classes.title}>{event.title}</h1>
                        : <Skeleton height={36} width={240} radius="sm"/>}
                </div>
                <Button radius="md" leftSection={<IconPlus size={18}/>} onClick={openCreateModal}>
                    <Trans>Create a code</Trans>
                </Button>
            </header>

            {!!promoCodes?.length && (
                <p className={classes.summary}>
                    {promoCodes.length === 1 ? t`1 code` : t`${promoCodes.length} codes`}
                    {' · '}
                    {totalUses === 1 ? t`1 use` : t`${totalUses} uses`}
                </p>
            )}

            {(!promoCodes || !event) && <Skeleton height={180} radius="md"/>}

            {(promoCodes && event) && (
                <PromoCodeTable openCreateModal={openCreateModal} event={event} promoCodes={promoCodes}/>
            )}

            {Number(pagination?.last_page) > 1 && (
                <Pagination
                    value={searchParams.pageNumber}
                    onChange={(value) => setSearchParams({pageNumber: value})}
                    total={Number(pagination?.last_page)}
                />
            )}

            {createModalOpen && <CreatePromoCodeModal onClose={closeCreateModal} isOpen/>}
        </div>
    );
};

export default PromoCodes;
