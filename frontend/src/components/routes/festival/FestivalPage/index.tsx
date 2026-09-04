import { useParams } from "react-router";
import { TicketDay } from "../../../../themes/festival/types/festivalTheme.types";
import { getFestivalEventBySlugSync } from "../../../../themes/festival/services/eventApi";
import { redirectToHiEventsCheckout } from "../../../../themes/festival/services/ticketApi";
import { EventLanding } from "../../../../themes/festival/layouts/EventLanding";

const FestivalPage = () => {
    const { eventSlug } = useParams<{ eventSlug: string }>();
    const event = eventSlug ? getFestivalEventBySlugSync(eventSlug) : null;

    const handleBookDay = (day: TicketDay) => {
        redirectToHiEventsCheckout(event?.hiEventsEventId, day);
    };

    if (!event) {
        return <div style={{ padding: 60, textAlign: "center", color: "#fff", background: "#0a0a0a", minHeight: "100vh" }}>Événement introuvable</div>;
    }

    return <EventLanding event={event} onBookDay={handleBookDay} />;
};

export default FestivalPage;
