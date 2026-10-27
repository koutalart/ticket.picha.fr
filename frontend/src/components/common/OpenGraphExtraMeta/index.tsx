/* eslint-disable lingui/no-unlocalized-strings */
import {Helmet} from "react-helmet-async";
import {i18n} from "@lingui/core";
import {Image} from "../../../types.ts";
import {getConfig} from "../../../utilites/config.ts";

export const toOpenGraphLocale = (locale: string) => {
    const [language, region] = locale.split("-");
    return `${language}_${(region || (language === 'en' ? 'gb' : language)).toUpperCase()}`;
};

interface OpenGraphExtraMetaProps {
    image?: Image;
    imageAlt?: string;
    siteName?: string;
}

/**
 * What Facebook, WhatsApp and Instagram need to show the preview image on the first share:
 * its size and type (otherwise Meta processes it later and the first previews have no image),
 * the site name and the locale.
 */
export const OpenGraphExtraMeta = ({image, imageAlt, siteName}: OpenGraphExtraMetaProps) => {
    const facebookAppId = getConfig('VITE_FACEBOOK_APP_ID');

    return (
        <Helmet>
            {image?.url?.startsWith('https://') && <meta property="og:image:secure_url" content={image.url}/>}
            {image?.mime_type && <meta property="og:image:type" content={image.mime_type}/>}
            {image?.width && <meta property="og:image:width" content={String(image.width)}/>}
            {image?.height && <meta property="og:image:height" content={String(image.height)}/>}
            {image && imageAlt && <meta property="og:image:alt" content={imageAlt}/>}
            {image && imageAlt && <meta name="twitter:image:alt" content={imageAlt}/>}
            {siteName && <meta property="og:site_name" content={siteName}/>}
            <meta property="og:locale" content={toOpenGraphLocale(i18n.locale || 'fr')}/>
            {facebookAppId && <meta property="fb:app_id" content={facebookAppId}/>}
        </Helmet>
    );
};
