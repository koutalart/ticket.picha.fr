import classes from "./Sponsors.module.scss";
import { Sponsor } from "../../types/festivalTheme.types";

interface SponsorsProps {
    sponsors: Sponsor[];
}

export const Sponsors = ({ sponsors }: SponsorsProps) => {
    return (
        <div className={classes.section}>
            <div className={classes.title}>Ils nous soutiennent</div>
            <div className={classes.row}>
                {sponsors.map((sponsor) => (
                    <div className={classes.chip} key={sponsor.id}>
                        {sponsor.logoUrl ? (
                            <img src={sponsor.logoUrl} alt={sponsor.name} />
                        ) : (
                            sponsor.name
                        )}
                    </div>
                ))}
            </div>
        </div>
    );
};
