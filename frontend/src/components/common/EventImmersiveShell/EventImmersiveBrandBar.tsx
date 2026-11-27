import classes from "./EventImmersiveShell.module.scss";
import {useOpaqueLogo} from "../../../hooks/useOpaqueLogo.ts";

interface EventImmersiveBrandBarProps {
    partnerLogo?: string;
    brandLogo?: string;
    organizerLogo?: string;
    organizerName?: string;
    homepageUrl?: string;
}

export const EventImmersiveBrandBar = ({
                                           partnerLogo,
                                           brandLogo,
                                           organizerLogo,
                                           organizerName,
                                           homepageUrl,
                                       }: EventImmersiveBrandBarProps) => {
    const organizerLogoOpaque = useOpaqueLogo(brandLogo ? undefined : organizerLogo);

    if (!partnerLogo && !brandLogo && !organizerLogo) {
        return null;
    }

    const rightLogo = brandLogo ? (
        <img src={brandLogo} alt={organizerName || ''}/>
    ) : organizerLogo ? (
        <img src={organizerLogo} alt={organizerName || ''} className={classes.invertedLogo}
             data-opaque={organizerLogoOpaque || undefined}/>
    ) : null;

    return (
        <div className={classes.brandBar}>
            {partnerLogo ? <img src={partnerLogo} alt=""/> : <span/>}
            {rightLogo && homepageUrl ? <a href={homepageUrl}>{rightLogo}</a> : rightLogo}
        </div>
    );
};
