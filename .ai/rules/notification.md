---
paths:
    - 'app/Domain/Notification/**'
---

# Notification

## Notification event names contain dots — index the lang array, do not path into it

Stored notifications keep an `event` like `account.activated` plus that event's own fields; no two events agree on a shape, so `RecentNotifications` turns them into a title and description for the header bell.

The trap: `__('notification.events.account.activated.title')` does NOT work. The translator splits on dots and looks for a nested `account` array, then silently returns the key. Fetch `__('notification.events')` and index it with the raw event string instead.

A missing line falls back to the raw event name, deliberately — a visible `wallet.topped_up` in the bell is a bug someone fixes; a dropped row is one nobody notices.
