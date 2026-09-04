import classes from "./EventLanding.module.scss";
import { FestivalEventData, TicketDay } from "../../types/festivalTheme.types";
import { HeroSection } from "../../components/HeroSection";
import { Countdown } from "../../components/Countdown";
import { TicketGrid } from "../../components/TicketGrid";
import { WhyAttendSection } from "../../components/WhyAttendSection";
import { VenueSection } from "../../components/VenueSection";
import { FAQ } from "../../components/FAQ";
import { Sponsors } from "../../components/Sponsors";
import { Footer } from "../../components/Footer";
import { Button } from "../../components/Button";

interface EventLandingProps {
    event: FestivalEventData;
    onBookDay: (day: TicketDay) => void;
}

const TICKETS_SECTION_ID = "billets";

export const EventLanding = ({ event, onBookDay }: EventLandingProps) => {
    const scrollToTickets = () => {
        document.getElementById(TICKETS_SECTION_ID)?.scrollIntoView({ behavior: "smooth" });
    };

    return (
        <div className={classes.page}>
            <div className={classes.announceBar}>
                Nouvelles dates confirmées — Billets en vente maintenant — {new Date(event.startDate).getDate()} → {new Date(event.endDate).getDate()} {new Date(event.endDate).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })}
            </div>

            <nav className={classes.nav}>
                <div className={classes.navLogo}>☀️ {event.eventName.toUpperCase()}</div>
                <Button variant="primary" className={classes.navBook} onClick={scrollToTickets}>
                    Réserver
                </Button>
            </nav>

            <HeroSection event={event} onBookClick={scrollToTickets} onDiscoverClick={scrollToTickets} />
            <Countdown targetDate={event.startDate} />
            <TicketGrid days={event.days} onBook={onBookDay} sectionId={TICKETS_SECTION_ID} />
            <WhyAttendSection />
            <VenueSection venue={event.venue} />
            <FAQ items={event.faq} />
            <Sponsors sponsors={event.sponsors} />
            <Footer event={event} />
        </div>
    );
};
