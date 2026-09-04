import classes from "./TicketCard.module.scss";
import classNames from "classnames";
import { TicketDay } from "../../types/festivalTheme.types";

interface TicketCardProps {
    day: TicketDay;
    onBook: (day: TicketDay) => void;
}

export const TicketCard = ({ day, onBook }: TicketCardProps) => {
    return (
        <div className={classes.card} onClick={() => onBook(day)}>
            <div className={classes.cloud} style={{ background: day.colorAccent }}>
                {day.dayLabel.toUpperCase()}
            </div>
            <div className={classes.headliner}>{day.title}</div>
            <div className={classes.lineup}>
                {[...day.headliners, ...day.lineup].map((artist) => artist.name).join(" · ")}
            </div>
            <div className={classes.footer}>
                <div className={classes.price}>
                    {day.price.toLocaleString("fr-FR")} {day.currency === "MGA" ? "Ar" : day.currency}
                </div>
                <div className={classes.book}>
                    {day.isSoldOut ? "Épuisé" : "Réserver →"}
                </div>
            </div>
        </div>
    );
};
