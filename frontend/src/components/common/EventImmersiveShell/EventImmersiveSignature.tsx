import classes from "./EventImmersiveShell.module.scss";

interface EventImmersiveSignatureProps {
    tagline?: string;
    signatureLogo?: string;
}

export const EventImmersiveSignature = ({tagline, signatureLogo}: EventImmersiveSignatureProps) => {
    const text = tagline?.trim();

    if (!text && !signatureLogo) {
        return null;
    }

    return (
        <div className={classes.signature}>
            {text && <span>{text}</span>}
            {signatureLogo && <img src={signatureLogo} alt=""/>}
        </div>
    );
};
