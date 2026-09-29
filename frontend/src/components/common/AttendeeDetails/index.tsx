import {Anchor} from "@mantine/core";
import {Attendee, Product} from "../../../types.ts";
import classes from "./AttendeeDetails.module.scss";
import {displayAttendeeEmail} from "../../../utilites/isKioskSentinelEmail.ts";
import {t} from "@lingui/macro";
import {getAttendeeProductTitle} from "../../../utilites/products.ts";
import {SupportedLocales} from "../../../locales.ts";
import {getLocaleName} from "../../../utilites/localeNames.ts";
import {relativeDate} from "../../../utilites/dates.ts";

export const AttendeeDetails = ({attendee}: { attendee: Attendee }) => {
    return (
        <div className={classes.orderDetails}>
            <div className={classes.block}>
                <div className={classes.title}>
                    {t`Name`}
                </div>
                <div className={classes.amount}>
                    {attendee.first_name} {attendee.last_name}
                </div>
            </div>
            <div className={classes.block}>
                <div className={classes.title}>
                    {t`Email`}
                </div>
                <div className={classes.value}>
                    {displayAttendeeEmail(attendee.email)
                        ? <Anchor href={'mailto:' + displayAttendeeEmail(attendee.email)} target={'_blank'}>{displayAttendeeEmail(attendee.email)}</Anchor>
                        : t`—`}
                </div>
            </div>
            <div className={classes.block}>
                <div className={classes.title}>
                    {t`Product`}
                </div>
                <div className={classes.amount}>
                    {getAttendeeProductTitle(attendee, attendee.product as Product)}
                </div>
            </div>
            <div className={classes.block}>
                <div className={classes.title}>
                    {t`Language`}
                </div>
                <div className={classes.amount}>
                    {getLocaleName(attendee.locale as SupportedLocales)}
                </div>
            </div>
            {attendee.check_ins && attendee.check_ins.length > 0 && (
                <div className={classes.block}>
                    <div className={classes.title}>
                        {t`Check-Ins`}
                    </div>
                    <div className={classes.value}>
                        {attendee.check_ins.map((checkIn) => (
                            <div key={checkIn.id}>
                                <strong>{checkIn.check_in_list?.name}</strong> - {relativeDate(checkIn.created_at)}
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}
