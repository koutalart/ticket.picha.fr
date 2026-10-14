import {t, Trans} from "@lingui/macro";
import {Button} from "@mantine/core";
import {useClipboard, useDisclosure} from "@mantine/hooks";
import {IconCheck, IconCopy, IconDots, IconLink, IconPencil, IconPlus, IconTrash} from "@tabler/icons-react";
import {useState} from "react";
import {Event, PromoCode, PromoCodeDiscountType} from "../../../types.ts";
import {formatDateWithLocale} from "../../../utilites/dates.ts";
import {formatCurrency} from "../../../utilites/currency.ts";
import {showSuccess} from "../../../utilites/notifications.tsx";
import {EditPromoCodeModal} from "../../modals/EditPromoCodeModal";
import {confirmationDialog} from "../../../utilites/confirmationDialog.tsx";
import {useDeletePromoCode} from "../../../mutations/useDeletePromoCode.ts";
import {eventHomepageUrl} from "../../../utilites/urlHelper.ts";
import {ActionMenu} from "../ActionMenu";
import classes from "./PromoCodeTable.module.scss";

interface PromoCodeTableProps {
    event: Event,
    promoCodes: PromoCode[],
    openCreateModal: () => void,
}

const PromoCodeRow = ({code, event, onEdit, onDelete}: {
    code: PromoCode,
    event: Event,
    onEdit: () => void,
    onDelete: () => void,
}) => {
    const clipboard = useClipboard({timeout: 1200});
    const linkClipboard = useClipboard({timeout: 1200});
    const products = event.product_categories?.flatMap((category) => category.products ?? []) ?? [];
    const productIds = (code.applicable_product_ids ?? []).map(Number);
    const productTitles = products.filter((product) => productIds.includes(Number(product.id))).map((product) => product.title);
    const uses = code.order_usage_count ?? 0;
    const max = code.max_allowed_usages ?? null;
    const isExpired = !!code.expiry_date && new Date(code.expiry_date) < new Date();
    const isUsedUp = max !== null && uses >= max;
    const isAccess = code.discount_type === PromoCodeDiscountType.None || !code.discount;

    const discount = isAccess
        ? null
        : code.discount_type === PromoCodeDiscountType.Fixed
            ? `−${formatCurrency(Number(code.discount), event.currency)}`
            : `−${code.discount} %`;

    const scope = isAccess
        ? (productTitles.length > 0 ? t`Unlocks ${productTitles.join(', ')}` : t`Unlocks hidden tickets`)
        : productIds.length === 0
            ? t`All tickets`
            : productTitles.length <= 2 ? productTitles.join(', ') : t`${productTitles.length} tickets`;

    const validity = code.expiry_date
        ? (isExpired
            ? t`Expired on ${formatDateWithLocale(code.expiry_date, 'shortDate', event.timezone)}`
            : t`Until ${formatDateWithLocale(code.expiry_date, 'shortDate', event.timezone)}`)
        : t`No end date`;

    const copyCode = () => {
        clipboard.copy(code.code.toUpperCase());
        showSuccess(t`${code.code.toUpperCase()} copied`);
    };

    const copyLink = () => {
        linkClipboard.copy(eventHomepageUrl(event) + `?promo_code=${code.code}`);
        showSuccess(t`Link copied: the code will be applied automatically`);
    };

    return (
        <article className={`${classes.row} ${isExpired || isUsedUp ? classes.rowInactive : ''}`}>
            <div className={classes.main}>
                <button type="button" className={classes.code} onClick={copyCode} title={t`Copy the code`}>
                    {code.code.toUpperCase()}
                    {clipboard.copied ? <IconCheck size={14}/> : <IconCopy size={14}/>}
                </button>
                <span className={classes.meta}>{scope} · {validity}</span>
            </div>

            <div className={classes.discount}>
                {discount ?? <span className={classes.accessBadge}><Trans>Access</Trans></span>}
            </div>

            <div className={classes.usage}>
                {isExpired && <span className={classes.statusBadge}><Trans>Expired</Trans></span>}
                {!isExpired && isUsedUp && <span className={classes.statusBadge}><Trans>Used up</Trans></span>}
                <span className={classes.meta}>
                    {max !== null ? t`${uses} / ${max} used` : t`${uses} used`}
                </span>
                {max !== null && (
                    <span className={classes.bar}>
                        <span style={{width: `${Math.min(100, Math.round(uses / max * 100))}%`}}/>
                    </span>
                )}
            </div>

            <ActionMenu
                itemsGroups={[{
                    label: '',
                    items: [
                        {label: t`Edit`, icon: <IconPencil size={14}/>, onClick: onEdit},
                        {label: t`Copy the link with the code`, icon: <IconLink size={14}/>, onClick: copyLink},
                        {label: t`Delete`, icon: <IconTrash size={14}/>, onClick: onDelete, color: 'red'},
                    ],
                }]}
                target={<span className={classes.more} aria-label={t`More actions`}><IconDots size={20}/></span>}
            />
        </article>
    );
};

export const PromoCodeTable = ({event, promoCodes, openCreateModal}: PromoCodeTableProps) => {
    const [promoCodeId, setPromoCodeId] = useState<number | undefined>();
    const [editModalOpen, {open: openEditModal, close: closeEditModal}] = useDisclosure(false);
    const deleteMutation = useDeletePromoCode();

    const handleDeleteCode = (id: number) => {
        confirmationDialog(
            t`Are you sure you want to delete this promo code?`,
            () => deleteMutation.mutate({eventId: event.id, promoCodeId: id}),
            {confirm: t`Delete`, cancel: t`Cancel`}
        );
    };

    if (promoCodes.length === 0) {
        return (
            <div className={classes.empty}>
                <h2><Trans>Create your first promo code</Trans></h2>
                <p>{t`Offer a discount, or give access to a hidden ticket (VIP, partners).`}</p>
                <Button radius="md" leftSection={<IconPlus size={18}/>} onClick={openCreateModal}>
                    <Trans>Create a code</Trans>
                </Button>
            </div>
        );
    }

    return (
        <>
            <div className={classes.list}>
                {promoCodes.map((code) => (
                    <PromoCodeRow
                        key={code.id}
                        code={code}
                        event={event}
                        onEdit={() => {
                            setPromoCodeId(code.id);
                            openEditModal();
                        }}
                        onDelete={() => handleDeleteCode(code.id as number)}
                    />
                ))}
            </div>

            {(promoCodeId && editModalOpen) &&
                <EditPromoCodeModal promoCodeId={promoCodeId} onClose={closeEditModal}/>}
        </>
    );
};
