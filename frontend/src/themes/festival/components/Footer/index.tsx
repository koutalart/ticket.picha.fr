import classes from "./Footer.module.scss";
import { FestivalEventData } from "../../types/festivalTheme.types";

interface FooterProps {
    event: FestivalEventData;
}

export const Footer = ({ event }: FooterProps) => {
    return (
        <footer className={classes.footer}>
            <div className={classes.links}>
                <a href="#">FAQ</a>
                <a href="#">Retrouver mon billet</a>
                <a href="#">CGV</a>
                <a href="#">Contact</a>
            </div>
            <div className={classes.social}>
                <span>📘</span><span>📷</span><span>🎵</span>
            </div>
            <div className={classes.bottom}>
                © {new Date().getFullYear()} {event.eventName} · Billetterie sécurisée par DIGIT Ticket
            </div>
        </footer>
    );
};
