import {Event, IdParam, Product} from "../../../types.ts";
import {useDisclosure} from "@mantine/hooks";
import {JoinWaitlistModal} from "../../modals/JoinWaitlistModal";
import {t} from "@lingui/macro";
import {useWaitlistJoined} from "../../../hooks/useWaitlistJoined.ts";
import {useWaitlistTunnel} from "./WaitlistTunnelContext.ts";

interface JoinWaitlistButtonProps {
    product: Product;
    event: Event;
    productPriceId: IdParam;
    priceLabel?: string;
}

export const JoinWaitlistButton = ({product, event, productPriceId, priceLabel}: JoinWaitlistButtonProps) => {
    const [modalOpen, {open: openModal, close: closeModal}] = useDisclosure(false);
    const {joined: hasJoined, markJoined} = useWaitlistJoined(event.id, productPriceId);
    const openInTunnel = useWaitlistTunnel();

    return (
        <>
            <button
                type="button"
                className="hi-waitlist-button"
                onClick={(e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    if (openInTunnel) {
                        openInTunnel({product, productPriceId, priceLabel});
                        return;
                    }
                    openModal();
                }}
                disabled={hasJoined}
            >
                {hasJoined ? t`Joined` : t`Join Waitlist`}
            </button>
            {modalOpen && (
                <JoinWaitlistModal
                    onClose={closeModal}
                    isOpen
                    product={product}
                    event={event}
                    productPriceId={productPriceId}
                    priceLabel={priceLabel}
                    onSuccess={() => {
                        markJoined();
                        closeModal();
                    }}
                />
            )}
        </>
    );
};
