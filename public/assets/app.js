const menuButton = document.querySelector('[data-menu]');
const backdrop = document.querySelector('[data-backdrop]');
const installButton = document.querySelector('[data-install-app]');
const pushButton = document.querySelector('[data-push-toggle]');
const pwaStatus = document.querySelector('[data-pwa-status]');
const pairButtons = document.querySelectorAll('[data-mobile-pair]');
const pairModal = document.querySelector('[data-pair-modal]');
const pairCloseButton = document.querySelector('[data-pair-close]');
const pairRefreshButton = document.querySelector('[data-pair-refresh]');
const pairQr = document.querySelector('[data-pair-qr]');
const pairStatus = document.querySelector('[data-pair-status]');
const pairCountdown = document.querySelector('[data-pair-countdown]');
const mobileSetupModal = document.querySelector('[data-mobile-setup-modal]');
const mobileSetupClose = document.querySelector('[data-mobile-setup-close]');
const mobileSetupInstall = document.querySelector('[data-mobile-setup-install]');
const mobileSetupPush = document.querySelector('[data-mobile-setup-push]');
const mobileSetupStatus = document.querySelector('[data-mobile-setup-status]');
const publicKey = document.body.dataset.pushPublicKey || '';
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
let installPrompt = null;
let serviceWorkerRegistration = null;
let pairTimer = null;

function isStandaloneApp() {
  return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
}

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

async function syncExistingPushSubscription() {
  if (!serviceWorkerRegistration || !publicKey) return;
  const subscription = await serviceWorkerRegistration.pushManager.getSubscription();
  if (!subscription) return;
  const payload = subscription.toJSON();
  payload.contentEncoding = PushManager.supportedContentEncodings?.[0] || 'aes128gcm';
  await sendSubscription('push_subscribe.php', payload);
}

async function enablePush() {
  if (!serviceWorkerRegistration || !publicKey || !('Notification' in window) || !('PushManager' in window)) {
    throw new Error('Installa l’app e riaprila dall’icona prima di attivare le notifiche.');
  }
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
  completeMobileSetup();
  setPwaStatus('Le scadenze arriveranno su questo dispositivo.');
}

async function disablePush(subscription) {
  await sendSubscription('push_unsubscribe.php', { endpoint: subscription.endpoint });
  await subscription.unsubscribe();
  setPwaStatus('Notifiche disattivate su questo dispositivo.');
}

function openModal(modal) {
  if (!modal) return;
  modal.hidden = false;
  document.body.classList.add('modal-open');
}

function closeModal(modal) {
  if (!modal) return;
  modal.hidden = true;
  document.body.classList.remove('modal-open');
}

function dismissMobileSetupForSession() {
  try {
    window.sessionStorage.setItem('basicMobileSetupDismissed', '1');
  } catch (error) {
    // Il cookie server continua a riproporre la configurazione al prossimo avvio.
  }
  closeModal(mobileSetupModal);
}

function completeMobileSetup() {
  const secure = window.location.protocol === 'https:' ? '; Secure' : '';
  document.cookie = `basic_preventivi_setup=; Max-Age=0; Path=/; SameSite=Lax${secure}`;
  try {
    window.sessionStorage.removeItem('basicMobileSetupDismissed');
  } catch (error) {
    // Nessuna azione necessaria.
  }
}

async function promptInstall(statusTarget = null) {
  if (installPrompt) {
    installPrompt.prompt();
    await installPrompt.userChoice;
    installPrompt = null;
    if (installButton) installButton.hidden = true;
    if (statusTarget) statusTarget.textContent = 'Richiesta di installazione completata.';
    return;
  }
  const message = 'Dal menu Condividi o dal menu del browser scegli “Aggiungi alla schermata Home”.';
  setPwaStatus(message);
  if (statusTarget) statusTarget.textContent = message;
}

function startPairCountdown(seconds) {
  if (pairTimer) window.clearInterval(pairTimer);
  let remaining = Math.max(0, Number(seconds) || 0);
  const update = () => {
    const minutes = Math.floor(remaining / 60);
    const secs = String(remaining % 60).padStart(2, '0');
    if (pairCountdown) pairCountdown.textContent = remaining > 0 ? `Scade tra ${minutes}:${secs}` : 'QR scaduto: generane uno nuovo.';
    if (remaining <= 0) {
      if (pairQr) pairQr.classList.add('expired');
      window.clearInterval(pairTimer);
      pairTimer = null;
      return;
    }
    remaining -= 1;
  };
  update();
  pairTimer = window.setInterval(update, 1000);
}

async function createMobilePairing() {
  if (!pairQr || !pairStatus) return;
  pairQr.classList.remove('expired');
  pairQr.innerHTML = '<span>Generazione QR…</span>';
  pairStatus.textContent = '';
  if (pairCountdown) pairCountdown.textContent = '';
  if (pairRefreshButton) pairRefreshButton.disabled = true;
  try {
    const response = await fetch('mobile_pair_create.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': csrfToken },
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || !result.ok) {
      throw new Error(result.error || 'Impossibile generare il QR.');
    }
    if (typeof qrcode !== 'function') {
      throw new Error('Generatore QR non disponibile.');
    }
    const code = qrcode(0, 'M');
    code.addData(result.activation_url);
    code.make();
    pairQr.innerHTML = code.createSvgTag(6, 4);
    pairQr.querySelector('svg')?.setAttribute('aria-label', 'QR di accesso smartphone');
    pairStatus.textContent = 'Inquadra ora il QR con lo smartphone.';
    startPairCountdown(result.expires_in || 600);
  } catch (error) {
    pairQr.innerHTML = '<span>QR non disponibile</span>';
    pairStatus.textContent = error.message || 'Operazione non riuscita.';
  } finally {
    if (pairRefreshButton) pairRefreshButton.disabled = false;
  }
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
  if (isStandaloneApp()) {
    installPrompt = null;
    if (installButton) installButton.hidden = true;
    return;
  }
  installPrompt = event;
  if (installButton) installButton.hidden = false;
});

installButton?.addEventListener('click', () => promptInstall());

pairButtons.forEach((button) => {
  button.addEventListener('click', () => {
    openModal(pairModal);
    createMobilePairing();
  });
});
pairCloseButton?.addEventListener('click', () => closeModal(pairModal));
pairRefreshButton?.addEventListener('click', createMobilePairing);
pairModal?.addEventListener('click', (event) => {
  if (event.target === pairModal) closeModal(pairModal);
});

mobileSetupClose?.addEventListener('click', dismissMobileSetupForSession);
mobileSetupModal?.addEventListener('click', (event) => {
  if (event.target === mobileSetupModal) dismissMobileSetupForSession();
});
mobileSetupInstall?.addEventListener('click', () => promptInstall(mobileSetupStatus));
mobileSetupPush?.addEventListener('click', async () => {
  mobileSetupPush.disabled = true;
  if (mobileSetupStatus) mobileSetupStatus.textContent = 'Attivazione notifiche…';
  try {
    await enablePush();
    await refreshPushButton();
    completeMobileSetup();
    if (mobileSetupStatus) mobileSetupStatus.textContent = 'Notifiche attive per questo operatore.';
  } catch (error) {
    if (mobileSetupStatus) mobileSetupStatus.textContent = error.message || 'Notifiche non disponibili.';
  } finally {
    mobileSetupPush.disabled = false;
  }
});

window.addEventListener('appinstalled', () => {
  installPrompt = null;
  if (installButton) installButton.hidden = true;
  completeMobileSetup();
  closeModal(mobileSetupModal);
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
  const standalone = isStandaloneApp();
  document.body.classList.toggle('pwa-standalone', standalone);
  if (standalone) {
    installPrompt = null;
    if (installButton) installButton.hidden = true;
    if (mobileSetupInstall) mobileSetupInstall.hidden = true;
    completeMobileSetup();
  }

  let setupDismissed = false;
  try {
    setupDismissed = window.sessionStorage.getItem('basicMobileSetupDismissed') === '1';
  } catch (error) {
    setupDismissed = false;
  }
  if (!standalone && document.body.dataset.mobileSetup === '1' && !setupDismissed) {
    openModal(mobileSetupModal);
  }
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
      if (mobileSetupPush) mobileSetupPush.disabled = false;
      try {
        await syncExistingPushSubscription();
      } catch (error) {
        setPwaStatus('Riconnessione notifiche in attesa. Ricarica la pagina.');
      }
      await refreshPushButton();
    } else if (!publicKey) {
      setPwaStatus('Notifiche push non ancora configurate dal server.');
    }
  } catch (error) {
    setPwaStatus('Service worker non disponibile.');
  }
})();
