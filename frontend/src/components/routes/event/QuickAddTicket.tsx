import {FormEvent, useState} from "react";
import {Button, NumberInput, SegmentedControl, Select, TextInput} from "@mantine/core";
import {IconPlus} from "@tabler/icons-react";
import {t, Trans} from "@lingui/macro";
import {i18n} from "@lingui/core";
import {useCreateProduct} from "../../../mutations/useCreateProduct.ts";
import {IdParam, Product, ProductCategory, ProductPriceType, ProductType} from "../../../types.ts";
import {showSuccess} from "../../../utilites/notifications.tsx";
import classes from "./ProductsPage.module.scss";

type Channel = 'both' | 'online' | 'box_office';

interface QuickAddTicketProps {
    eventId: IdParam;
    categories: ProductCategory[];
    onMoreOptions: () => void;
}

export const QuickAddTicket = ({eventId, categories, onMoreOptions}: QuickAddTicketProps) => {
    const createMutation = useCreateProduct();
    const [title, setTitle] = useState('');
    const [isFree, setIsFree] = useState(false);
    const [price, setPrice] = useState<number | ''>('');
    const [quantity, setQuantity] = useState<number | ''>('');
    const [channel, setChannel] = useState<Channel>('both');
    const [error, setError] = useState<string | null>(null);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!title.trim()) {
            setError(t`Give the ticket a name.`);
            return;
        }
        if (!isFree && (price === '' || Number(price) <= 0)) {
            setError(t`Enter a price, or choose "Free".`);
            return;
        }
        setError(null);

        createMutation.mutate({
            eventId,
            productData: {
                title: title.trim(),
                type: isFree ? ProductPriceType.Free : ProductPriceType.Paid,
                product_type: ProductType.Ticket,
                product_category_id: categories[0]?.id,
                is_hidden: channel === 'box_office',
                is_visible_at_box_office: channel !== 'online',
                max_per_order: 100,
                min_per_order: 1,
                tax_and_fee_ids: [],
                prices: [{
                    price: isFree ? 0 : Number(price),
                    initial_quantity_available: quantity === '' ? undefined : Number(quantity),
                }],
            } as unknown as Product,
        }, {
            onSuccess: () => {
                showSuccess(t`Ticket added`);
                setTitle('');
                setPrice('');
                setQuantity('');
            },
            onError: (mutationError: any) => setError(mutationError?.response?.data?.message || t`The ticket could not be added. Please try again.`),
        });
    };

    return (
        <form className={classes.quickAdd} onSubmit={submit} noValidate>
            <div className={classes.quickAddHead}>
                <span className={classes.quickAddTitle}><Trans>Quick add</Trans></span>
                <button type="button" className={classes.moreOptions} onClick={onMoreOptions}>
                    <Trans>More options</Trans>
                </button>
            </div>
            <div className={classes.quickAddFields}>
                <TextInput
                    className={classes.quickName}
                    label={t`Name`}
                    placeholder={t`E.g. On-site entry`}
                    radius="md"
                    value={title}
                    maxLength={255}
                    onChange={(changeEvent) => setTitle(changeEvent.currentTarget.value)}
                />
                <div className={classes.quickPrice}>
                    <span className={classes.quickLabel}><Trans>Price</Trans></span>
                    <div className={classes.quickPriceRow}>
                        <SegmentedControl
                            className={classes.quickSegmented}
                            size="sm"
                            radius="md"
                            value={isFree ? 'free' : 'paid'}
                            onChange={(value) => setIsFree(value === 'free')}
                            data={[{value: 'paid', label: t`Paid`}, {value: 'free', label: t`Free`}]}
                        />
                        {!isFree && (
                            <NumberInput
                                aria-label={t`Price`}
                                placeholder="20"
                                radius="md"
                                min={0}
                                hideControls
                                decimalScale={2}
                                decimalSeparator={i18n.locale.startsWith('fr') ? ',' : '.'}
                                suffix=" €"
                                value={price}
                                onChange={(value) => setPrice(value === '' ? '' : Number(value))}
                                className={classes.quickPriceInput}
                            />
                        )}
                    </div>
                </div>
                <NumberInput
                    className={classes.quickQuantity}
                    label={t`Quantity`}
                    placeholder={t`Unlimited`}
                    radius="md"
                    min={1}
                    hideControls
                    allowDecimal={false}
                    allowNegative={false}
                    value={quantity}
                    onChange={(value) => setQuantity(value === '' ? '' : Number(value))}
                />
                <Select
                    className={classes.quickChannel}
                    label={t`Sold`}
                    radius="md"
                    allowDeselect={false}
                    value={channel}
                    onChange={(value) => setChannel((value ?? 'both') as Channel)}
                    data={[
                        {value: 'both', label: t`Online and box office`},
                        {value: 'online', label: t`Online`},
                        {value: 'box_office', label: t`Box office only`},
                    ]}
                />
                <Button type="submit" size="sm" radius="md" leftSection={<IconPlus size={16}/>} loading={createMutation.isPending}
                        className={classes.quickSubmit}>
                    <Trans>Add</Trans>
                </Button>
            </div>
            {error && <p className={classes.quickError}>{error}</p>}
        </form>
    );
};
