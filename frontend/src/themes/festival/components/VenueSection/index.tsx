import classes from "./VenueSection.module.scss";
import { SectionTitle } from "../SectionTitle";
import { VenueInfo } from "../../types/festivalTheme.types";

interface VenueSectionProps {
    venue: VenueInfo;
}

export const VenueSection = ({ venue }: VenueSectionProps) => {
    return (
        <div className={classes.section}>
            <SectionTitle eyebrow="À savoir" title="Infos pratiques" />
            <div className={classes.inner}>
                <div className={classes.item}>
                    <div className={classes.label}>📍 Lieu</div>
                    <div className={classes.text}>{venue.name}, {venue.address}</div>
                </div>
                <div className={classes.item}>
                    <div className={classes.label}>🕐 Horaires</div>
                    <div className={classes.text}>{venue.openingHours}</div>
                </div>
                <div className={classes.item}>
                    <div className={classes.label}>🚗 Accès</div>
                    <div className={classes.text}>{venue.accessInfo}</div>
                </div>
                <div className={classes.item}>
                    <div className={classes.label}>🎫 Billets</div>
                    <div className={classes.text}>Billet nominatif, à présenter avec une pièce d'identité</div>
                </div>
            </div>
        </div>
    );
};
