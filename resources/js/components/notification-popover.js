import {
    getNotifications,
    markAllNotificationsAsRead,
    markNotificationAsRead,
} from '../api/notifications';

const formatCount = (count) => (count > 99 ? '99+' : String(count));

function parseCount(value) {
    if (!Number.isSafeInteger(value) || value < 0) {
        throw new TypeError('Invalid notification unread count');
    }

    return value;
}

function relativeTime(value) {
    const timestamp = Date.parse(value ?? '');
    if (Number.isNaN(timestamp)) return '';

    const seconds = (timestamp - Date.now()) / 1000;
    const absoluteSeconds = Math.abs(seconds);
    const units = [
        ['year', 60 * 60 * 24 * 365],
        ['month', 60 * 60 * 24 * 30],
        ['day', 60 * 60 * 24],
        ['hour', 60 * 60],
        ['minute', 60],
        ['second', 1],
    ];
    const [unit, unitSeconds] = units.find(([, size]) => absoluteSeconds >= size) ?? units[5];

    return new Intl.RelativeTimeFormat('ja', { numeric: 'auto' })
        .format(Math.round(seconds / unitSeconds), unit);
}

function safeActionUrl(value) {
    try {
        const url = new URL(value, window.location.origin);
        if (url.origin === window.location.origin) {
            return url.href;
        }
    } catch {
        // Invalid URLs fall back to the notifications page.
    }

    return '/notifications';
}

function setVisible(element, visible, display = '') {
    element.classList.toggle('hidden', !visible);
    element.style.display = visible ? display : 'none';
}

export function initNotificationPopover() {
    const root = document.querySelector('[data-notification-popover-root]');
    if (!root || root.dataset.notificationPopoverInitialized === 'true') return;

    const trigger = root.querySelector('[data-notification-popover-trigger]');
    const panel = root.querySelector('[data-notification-popover-panel]');
    const badge = root.querySelector('[data-notification-popover-badge]');
    const tabs = [...root.querySelectorAll('[data-notification-popover-tab]')];
    const unreadCountElement = root.querySelector('[data-notification-popover-unread-count]');
    const markAllButton = root.querySelector('[data-notification-popover-mark-all]');
    const loading = root.querySelector('[data-notification-popover-loading]');
    const error = root.querySelector('[data-notification-popover-error]');
    const empty = root.querySelector('[data-notification-popover-empty]');
    const items = root.querySelector('[data-notification-popover-items]');
    const rowTemplate = root.querySelector('[data-notification-popover-row-template]');

    if (
        !trigger || !panel || !badge || tabs.length === 0 || !unreadCountElement
        || !markAllButton || !loading || !error || !empty || !items || !rowTemplate
    ) {
        return;
    }

    root.dataset.notificationPopoverInitialized = 'true';

    let notifications = [];
    let unreadCount = Number.parseInt(badge.dataset.unreadCount, 10) || 0;
    let activeTab = 'all';
    let isOpen = false;
    let isLoading = false;
    let isMarkingAll = false;

    const updateCounts = () => {
        badge.textContent = formatCount(unreadCount);
        badge.dataset.unreadCount = String(unreadCount);
        badge.classList.toggle('hidden', unreadCount === 0);
        unreadCountElement.textContent = formatCount(unreadCount);
        trigger.setAttribute('aria-label', `通知 (${unreadCount} 件未読)`);
        markAllButton.disabled = unreadCount === 0 || isLoading || isMarkingAll;
        markAllButton.setAttribute('aria-disabled', String(markAllButton.disabled));
    };

    const clearError = () => {
        error.textContent = '';
        setVisible(error, false);
    };

    const showError = (message, cause) => {
        console.error('Notification popover request failed.', cause);
        error.textContent = message;
        setVisible(error, true);
    };

    const renderRow = (notification) => {
        const fragment = rowTemplate.content.cloneNode(true);
        const link = fragment.querySelector('[data-notification-popover-row]');
        const dot = fragment.querySelector('[data-notification-popover-row-dot]');
        const title = fragment.querySelector('[data-notification-popover-row-title]');
        const message = fragment.querySelector('[data-notification-popover-row-message]');
        const time = fragment.querySelector('[data-notification-popover-row-time]');
        const isUnread = notification.read_at === null;
        const actionUrl = safeActionUrl(notification.action_url);

        link.href = actionUrl;
        link.dataset.unread = String(isUnread);
        dot.classList.toggle('hidden', !isUnread);
        title.textContent = notification.title || '通知';
        message.textContent = notification.message || '';
        message.classList.toggle('hidden', !notification.message);
        time.textContent = relativeTime(notification.created_at);

        link.addEventListener('click', async (event) => {
            if (
                event.button !== 0 || event.metaKey || event.ctrlKey
                || event.shiftKey || event.altKey
            ) {
                return;
            }

            event.preventDefault();
            if (link.getAttribute('aria-disabled') === 'true') return;
            link.setAttribute('aria-disabled', 'true');

            let result;
            try {
                result = await markNotificationAsRead(notification.id);
                const nextUnreadCount = parseCount(result.unread_count);
                if (
                    !result.notification
                    || result.notification.id !== notification.id
                    || typeof result.notification.read_at !== 'string'
                ) {
                    throw new TypeError('Invalid notification read response');
                }
                unreadCount = nextUnreadCount;
            } catch (cause) {
                link.removeAttribute('aria-disabled');
                showError('通知を既読にできませんでした。時間をおいて再度お試しください。', cause);
                return;
            }

            notification.read_at = result.notification.read_at;
            clearError();
            updateCounts();
            renderNotifications();
            window.location.assign(actionUrl);
        });

        return fragment;
    };

    function renderNotifications() {
        const visibleNotifications = activeTab === 'unread'
            ? notifications.filter((notification) => notification.read_at === null)
            : notifications;

        items.replaceChildren(...visibleNotifications.map(renderRow));
        setVisible(items, visibleNotifications.length > 0);
        setVisible(empty, visibleNotifications.length === 0);
        empty.textContent = activeTab === 'unread'
            ? '未読の通知はありません。'
            : '通知はありません。';
        updateCounts();
    }

    const setTab = (tab) => {
        activeTab = tab;
        tabs.forEach((button) => {
            button.setAttribute('aria-selected', String(button.dataset.notificationPopoverTab === tab));
        });
        clearError();
        renderNotifications();
    };

    const loadNotifications = async () => {
        if (isLoading) return;

        isLoading = true;
        updateCounts();
        clearError();
        items.replaceChildren();
        setVisible(items, false);
        setVisible(empty, false);
        setVisible(loading, true, 'flex');
        panel.setAttribute('aria-busy', 'true');

        try {
            const result = await getNotifications();
            if (!Array.isArray(result.notifications)) {
                throw new TypeError('Invalid notification list response');
            }

            notifications = result.notifications.map((notification) => {
                if (
                    typeof notification.id !== 'string'
                    || typeof notification.action_url !== 'string'
                    || (notification.read_at !== null && typeof notification.read_at !== 'string')
                ) {
                    throw new TypeError('Invalid notification item response');
                }

                return {
                    ...notification,
                    title: typeof notification.title === 'string' ? notification.title : '',
                    message: typeof notification.message === 'string' ? notification.message : '',
                };
            });
            unreadCount = parseCount(result.unread_count);
            renderNotifications();
        } catch (cause) {
            notifications = [];
            showError('通知を読み込めませんでした。時間をおいて再度お試しください。', cause);
        } finally {
            isLoading = false;
            setVisible(loading, false);
            panel.removeAttribute('aria-busy');
            updateCounts();
        }
    };

    const setOpen = (open) => {
        isOpen = open;
        trigger.setAttribute('aria-expanded', String(open));
        panel.classList.toggle('hidden', !open);
        panel.classList.toggle('opacity-0', !open);
        panel.classList.toggle('translate-y-1', !open);
        panel.classList.toggle('opacity-100', open);
        panel.classList.toggle('translate-y-0', open);
        panel.style.display = open ? 'flex' : 'none';

        if (open) loadNotifications();
    };

    trigger.addEventListener('click', () => setOpen(!isOpen));
    tabs.forEach((button) => {
        button.addEventListener('click', () => setTab(button.dataset.notificationPopoverTab));
    });

    markAllButton.addEventListener('click', async () => {
        if (unreadCount === 0 || isLoading || isMarkingAll) return;

        isMarkingAll = true;
        markAllButton.disabled = true;
        markAllButton.setAttribute('aria-busy', 'true');
        clearError();

        try {
            const result = await markAllNotificationsAsRead();
            unreadCount = parseCount(result.unread_count);
            notifications = notifications.map((notification) => ({
                ...notification,
                read_at: notification.read_at ?? new Date().toISOString(),
            }));
            clearError();
            renderNotifications();
        } catch (cause) {
            showError('通知をすべて既読にできませんでした。時間をおいて再度お試しください。', cause);
        } finally {
            isMarkingAll = false;
            markAllButton.removeAttribute('aria-busy');
            updateCounts();
        }
    });

    document.addEventListener('click', (event) => {
        if (isOpen && !root.contains(event.target)) setOpen(false);
    });

    document.addEventListener('keydown', (event) => {
        if (isOpen && event.key === 'Escape') {
            setOpen(false);
            trigger.focus();
        }
    });

    updateCounts();
}
