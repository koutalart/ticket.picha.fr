import {RefObject} from "react";
import {t} from "@lingui/macro";
import {IconCircleCheck} from "@tabler/icons-react";
import classes from "./AttendanceAccess.module.scss";

export {AttendanceAccessModal} from "./AttendanceAccessModal.tsx";

interface RegisteredNoticeProps {
    sectionRef: RefObject<HTMLElement | null>;
    onManageAttendance: () => void;
    onRegisterAnother: () => void;
}

export const RegisteredNotice = ({sectionRef, onManageAttendance, onRegisterAnother}: RegisteredNoticeProps) => (
    <section
        className={classes.root}
        ref={sectionRef as RefObject<HTMLElement>}
        id="tickets"
        aria-labelledby="registered-notice-title"
    >
        <div className={classes.panel}>
            <p className={classes.noticeEyebrow}>
                <IconCircleCheck size={18} aria-hidden="true"/>
                {t`Free registration`}
            </p>
            <h2 className={classes.noticeTitle} id="registered-notice-title">{t`You are registered`}</h2>
            <p className={classes.noticeText}>
                {t`A registration for this event was made on this device. To confirm or cancel your attendance, we will send you a code by email or SMS.`}
            </p>
            <button type="button" className={classes.primaryLink} onClick={onManageAttendance}>
                {t`Confirm or cancel my attendance`}
                <span aria-hidden="true">→</span>
            </button>
            <button type="button" className={classes.anotherLink} onClick={onRegisterAnother}>
                {t`Register another person`}
            </button>
        </div>
    </section>
);

export const AlreadyRegisteredLink = ({onClick, className}: { onClick: () => void, className?: string }) => (
    <button type="button" className={`${classes.alreadyLink} ${className ?? ''}`} onClick={onClick}>
        {t`Already registered? Manage my attendance`}
    </button>
);
