const menuButton = document.querySelector('[data-menu]');
const backdrop = document.querySelector('[data-backdrop]');
const installButton = document.querySelector('[data-install-app]');
const pushButton = document.querySelector('[data-push-toggle]');
const pwaStatus = document.querySelector('[data-pwa-status]');
const publicKey = document.body.dataset.pushPublicKey || '';
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
let installPrompt = null;
let serviceWorkerRegistration = null;

function closeMenu() {
  document.body.classList.remove('menu-open');
}

function setPwaStatus(message) {
  if (pwaStatus) pwaStatus.textContent = message;
}

function base64UrlToBytes(value) {
  const padding = '='.repeat((4 - (value.length % 4)) % 4);
  const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
  const raw = window.atob(base64);
  return Uint8Array.from(raw, (character) => character.charCodeAt(0));
}

async function sendSubscription(path, payload) {
  const response = await fetch(path, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': csrfToken,
    },
    body: JSON.stringify(payload),
  });
  const result = await response.json().catch(() => ({}));
  if (!response.ok || !result.ok) {
    throw new Error(result.error || 'Operazione push non riuscita.');
  }
}

async function refreshPushButton() {
  if (!pushButton || !serviceWorkerRegistration) return;
  const subscription = await serviceWorkerRegistration.pushManager.getSubscription();
  pushButton.hidden = false;
  pushButton.classList.toggle('active', Boolean(subscription));
  pushButton.textContent = subscription ? 'Notifiche attive · Disattiva' : 'Attiva notifiche';
}

async function enablePush() {
  if (!serviceWorkerRegistration || !publicKey) return;
  const permission = await Notification.requestPermission();
  if (permission !== 'granted') {
    throw new Error('Permesso notifiche non concesso.');
  }
  const subscription = await serviceWorkerRegistration.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: base64UrlToBytes(publicKey),
  });
  const payload = subscription.toJSON();
  payload.contentEncoding = PushManager.supportedContentEncodings?.[0] || 'aes128gcm';
  try {
    await sendSubscription('push_subscribe.php', payload);
  } catch (error) {
    await subscription.unsubscribe();
    throw error;
  }
  setPwaStatus('Le scadenze arriveranno su questo dispositivo.');
}

async function disablePush(subscription) {
  await sendSubscription('push_unsubscribe.php', { endpoint: subscription.endpoint });
  await subscription.unsubscribe();
  setPwaStatus('Notifiche disattivate su questo dispositivo.');
}

menuButton?.addEventListener('click', () => document.body.classList.toggle('menu-open'));
backdrop?.addEventListener('click', closeMenu);

document.querySelectorAll('[data-confirm]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    if (!window.confirm(form.dataset.confirm || 'Confermare?')) {
      event.preventDefault();
    }
  });
});

window.addEventListener('beforeinstallprompt', (event) => {
  event.preventDefault();
  installPrompt = event;
  if (installButton) installButton.hidden = false;
});

installButton?.addEventListener('click', async () => {
  if (installPrompt) {
    installPrompt.prompt();
    await installPrompt.userChoice;
    installPrompt = null;
    installButton.hidden = true;
    return;
  }
  setPwaStatus('Dal menu del browser scegli “Aggiungi alla schermata Home”.');
});

window.addEventListener('appinstalled', () => {
  if (installButton) installButton.hidden = true;
  setPwaStatus('App installata.');
});

pushButton?.addEventListener('click', async () => {
  pushButton.disabled = true;
  setPwaStatus('Aggiornamento notifiche…');
  try {
    const subscription = await serviceWorkerRegistration.pushManager.getSubscription();
    if (subscription) {
      await disablePush(subscription);
    } else {
      await enablePush();
    }
    await refreshPushButton();
  } catch (error) {
    setPwaStatus(error.message || 'Notifiche non disponibili.');
  } finally {
    pushButton.disabled = false;
  }
});

(async () => {
  const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  if (!standalone && installButton) {
    window.setTimeout(() => { installButton.hidden = false; }, 1200);
  }
  if (!('serviceWorker' in navigator)) {
    setPwaStatus('Installazione PWA non supportata da questo browser.');
    return;
  }
  try {
    serviceWorkerRegistration = await navigator.serviceWorker.register('sw.js');
    await navigator.serviceWorker.ready;
    if ('PushManager' in window && 'Notification' in window && publicKey) {
      await refreshPushButton();
    } else if (!publicKey) {
      setPwaStatus('Notifiche push non ancora configurate dal server.');
    }
  } catch (error) {
    setPwaStatus('Service worker non disponibile.');
  }
})();
