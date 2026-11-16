import {User} from "../types.ts";

export const EVENT_VIEWER_HOME = '/lecture';

export const isEventViewer = (user?: Pick<User, 'role'> | null): boolean => {
    return user?.role === 'EVENT_VIEWER';
};
