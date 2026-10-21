import {ssrLoaderCss} from "../../../utilites/ssrLoader.ts";

type SsrLoaderStyleProps = Parameters<typeof ssrLoaderCss>[0];

/**
 * React escapes text children of <style> during SSR (" becomes &quot;), which turned the
 * logo url("...") into a relative URL. The CSS is injected raw instead, with "<" escaped
 * so organizer values can never close the tag.
 */
export const SsrLoaderStyle = (props: SsrLoaderStyleProps) => (
    <style dangerouslySetInnerHTML={{__html: ssrLoaderCss(props).replace(/</g, '\\3C ')}}/>
);
