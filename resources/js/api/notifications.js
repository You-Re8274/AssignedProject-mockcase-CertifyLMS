import { getJson, postJson } from '../utils/fetch-json.js';

const notificationsUrl = '/api/v1/notifications';

export function getNotifications() {
    return getJson(notificationsUrl, { sanctum: true });
}

export function markNotificationAsRead(notificationId) {
    const id = encodeURIComponent(notificationId);

    return postJson(`${notificationsUrl}/${id}/read`, null, { sanctum: true });
}

export function markAllNotificationsAsRead() {
    return postJson(`${notificationsUrl}/read-all`, null, { sanctum: true });
}
