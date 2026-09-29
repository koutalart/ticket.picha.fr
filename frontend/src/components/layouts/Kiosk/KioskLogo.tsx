const KIOSK_LOGO_ON_DARK = "/logos/picha-ai-on-dark.png";
const KIOSK_LOGO_ON_LIGHT = "/logos/picha-ai.png";
const KIOSK_LOGO_ALT = "PICHA";

export const KioskLogo = ({
    className,
    onDark = true,
}: {
    className?: string;
    onDark?: boolean;
}) => (
    <img
        className={className}
        src={onDark ? KIOSK_LOGO_ON_DARK : KIOSK_LOGO_ON_LIGHT}
        alt={KIOSK_LOGO_ALT}
    />
);
