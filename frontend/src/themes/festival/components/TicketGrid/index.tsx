import classes from "./TicketGrid.module.scss";
import { TicketDay } from "../../types/festivalTheme.types";
import { TicketCard } from "../TicketCard";
import { SectionTitle } from "../SectionTitle";

interface TicketGridProps {
    days: TicketDay[];
    onBook: (day: TicketDay) => void;
    sectionId?: string;
}

export const TicketGrid = ({ days, onBook, sectionId }: TicketGridProps) => {
    return (
        <div className={classes.section} id={sectionId}>
            <SectionTitle
                eyebrow="Du 5 au 9 août"
                title="Le programme"
                subtitle="5 jours, 5 ambiances, un seul festival"
            />
            <div className={classes.grid}>
                {days.map((day) => (
                    <TicketCard key={day.id} day={day} onBook={onBook} />
                ))}
            </div>
        </div>
    );
};
