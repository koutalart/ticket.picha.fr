import classes from "./HeroSection.module.scss";
import { Button } from "../Button";
import { FestivalEventData } from "../../types/festivalTheme.types";

interface HeroSectionProps {
    event: FestivalEventData;
    onBookClick: () => void;
    onDiscoverClick: () => void;
}

export const HeroSection = ({ event, onBookClick, onDiscoverClick }: HeroSectionProps) => {
    const dateRange = `${new Date(event.startDate).getDate()} → ${new Date(event.endDate).getDate()} ${new Date(event.endDate).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })}`;

    return (
        <div className={classes.hero}>
            <img className={classes.heroImg} src={event.heroImageUrl} alt={event.eventName} />
            <div className={classes.heroOverlay} />
            <div className={classes.heroContent}>
                <div className={classes.eyebrow}>{event.edition} · {event.venue.name}</div>
                <h1 className={classes.title}>{event.eventName}</h1>
                <div className={classes.sub}>{dateRange} · {event.venue.address}</div>
                <div className={classes.ctaRow}>
                    <Button variant="primary" onClick={onBookClick}>
                        Réserver mon billet
                    </Button>
                    <Button variant="secondary" onClick={onDiscoverClick}>
                        Découvrir la programmation
                    </Button>
                </div>
            </div>
        </div>
    );
};
