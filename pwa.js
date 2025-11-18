(function registerServiceWorker() {
  if (!('serviceWorker' in navigator)) {
    return;
  }
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('service-worker.js').catch(function (error) {
      console.error('Service worker registration failed:', error);
    });
  });
})();
