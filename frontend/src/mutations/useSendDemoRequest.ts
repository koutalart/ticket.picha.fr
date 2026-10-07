import {useMutation} from "@tanstack/react-query";
import {DemoRequest, demoRequestClient} from "../api/demo-request.client.ts";

export const useSendDemoRequest = () => {
    return useMutation({
        mutationFn: (demoRequest: DemoRequest) => demoRequestClient.send(demoRequest),
    });
};
