import {createContext, useContext} from "react";
import {IdParam, Product} from "../../../types.ts";

export interface WaitlistTarget {
    product: Product;
    productPriceId: IdParam;
    priceLabel?: string;
}

export const WaitlistTunnelContext = createContext<((target: WaitlistTarget) => void) | null>(null);

export const useWaitlistTunnel = () => useContext(WaitlistTunnelContext);
