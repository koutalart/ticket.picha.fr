import {createContext, useContext} from "react";

export const EventImmersiveContext = createContext(false);

export const useIsEventImmersive = (): boolean => useContext(EventImmersiveContext);
