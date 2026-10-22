import classes from "./EventImmersiveShell.module.scss";

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
    if (!partnerLogo && !brandLogo && !organizerLogo) {
        return null;
    }

    const rightLogo = brandLogo ? (
        <img src={brandLogo} alt={organizerName || ''}/>
    ) : organizerLogo ? (
        <img src={organizerLogo} alt={organizerName || ''} className={classes.invertedLogo}/>
    ) : null;

    return (
        <div className={classes.brandBar}>
            {partnerLogo ? <img src={partnerLogo} alt=""/> : <span/>}
            {rightLogo && homepageUrl ? <a href={homepageUrl}>{rightLogo}</a> : rightLogo}
        </div>
    );
};
