import classes from "./WhyAttendSection.module.scss";
import { SectionTitle } from "../SectionTitle";

interface WhyAttendItem {
    icon: string;
    title: string;
    description: string;
}

const items: WhyAttendItem[] = [
    { icon: "🎤", title: "Line-up international", description: "20+ artistes sur 5 jours" },
    { icon: "🌴", title: "Ambiance carnaval", description: "L'esprit festif malgache" },
    { icon: "🍹", title: "Food & Drinks", description: "Stands locaux sur place" },
    { icon: "⛺", title: "Camping", description: "Option hébergement sur site" },
    { icon: "👑", title: "Accès VIP", description: "Zones et services premium" },
    { icon: "🏝️", title: "Nosy Be", description: "Un cadre paradisiaque" },
];

export const WhyAttendSection = () => {
    return (
        <div className={classes.section}>
            <SectionTitle eyebrow="L'expérience" title="Pourquoi venir ?" />
            <div className={classes.grid}>
                {items.map((item) => (
                    <div className={classes.item} key={item.title}>
                        <div className={classes.icon}>{item.icon}</div>
                        <div className={classes.itemTitle}>{item.title}</div>
                        <div className={classes.itemDesc}>{item.description}</div>
                    </div>
                ))}
            </div>
        </div>
    );
};
