self.addEventListener('push', function(event) {
    if (!(self.Notification && self.Notification.permission === 'granted')) {
        return;
    }

    const data = event.data ? event.data.json() : {};
    const title = data.title || 'Price Alert';
    const message = data.body || 'A product price has dropped!';
    const icon = data.icon || 'https://cdn-icons-png.flaticon.com/512/2529/2529521.png';
    const url = data.url || '/';

    const options = {
        body: message,
        icon: icon,
        badge: icon,
        data: {
            url: url
        }
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    event.waitUntil(
        clients.openWindow(event.notification.data.url)
    );
});
