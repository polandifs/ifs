self.addEventListener('push', event => {
  event.waitUntil((async () => {
    let title = 'Nowe mieszkanie w Kwidzynie';
    let body = 'Otwórz panel, aby zobaczyć nową ofertę sprzedaży.';
    try {
      const response = await fetch('alerts.php?since=0', {credentials: 'same-origin', cache: 'no-store'});
      if (response.ok) {
        const data = await response.json();
        if (data.alerts && data.alerts.length) {
          title = 'Nowe oferty mieszkań w Kwidzynie';
          body = data.alerts[0].title;
        }
      }
    } catch (_) {}
    await self.registration.showNotification(title, {
      body, tag: 'kwidzyn-mieszkania', renotify: true,
      data: {url: './'}, icon: '../favicon.ico'
    });
  })());
});
self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil(clients.openWindow(event.notification.data.url));
});
