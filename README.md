# Basic – Gestione Preventivi

Applicazione PHP + MySQL per registrare, assegnare e seguire i preventivi Basic. Non usa Docker, Composer o npm.

Repository: <https://github.com/zensoftwareit-ops/basic-preventivi>

## Regole operative

- SSO senza form con `id` utente e token condiviso;
- dopo il primo SSO, accesso persistente e revocabile sul singolo dispositivo per 180 giorni di inattività;
- l’utente deve esistere nella tabella `users` ed essere attivo;
- ogni utente ha ruolo `operator`, `admin` oppure `super`;
- `admin` e `super` vedono **Dati base** e possono eliminare definitivamente i preventivi;
- il ruolo `super` è di sola supervisione e non compare tra responsabili, ricevitori o filtri operativi;
- scadenza di invio non modificabile, fissata a 24 ore dalla creazione della pratica;
- primo alert interno ed email dopo 12 ore se il preventivo non risulta inviato;
- secondo alert interno ed email dopo 24 ore se il preventivo non risulta inviato;
- follow-up interno ed email ogni 3 giorni di stato invariato;
- gli stessi alert possono arrivare come notifiche push sui dispositivi autorizzati dall'operatore;
- il conteggio dei 3 giorni riparte da zero a ogni cambio di stato;
- email inviata all’indirizzo dell’operatore presente in `users.email`;
- invio tramite server SMTP esterno autenticato;
- una o più copie nascoste BCC configurabili in `app/config.local.php`;
- esportazione Excel `.xlsx` dell'intera tabella preventivi, rispettando i filtri applicati;
- semaforo scadenza: verde entro 24 ore, giallo tra 24 e 48 ore dalla creazione, rosso oltre 48 ore;
- follow-up e scadenze non possono essere posticipati o modificati dall’interfaccia.

## Requisiti

- Plesk Obsidian con estensione Git;
- PHP 8.2 o successivo;
- estensioni PHP `pdo_mysql`, `mbstring`, `openssl`, `curl` e `zip`;
- MySQL 5.5.3 o successivo oppure MariaDB compatibile;
- HTTPS attivo sul sottodominio;
- accesso in uscita dal server Plesk al server SMTP scelto (normalmente porta 587 o 465).
- accesso HTTPS in uscita sulla porta 443 verso i servizi Web Push dei browser.

## Installazione nuova in Plesk

### 1. Sottodominio e Git

1. In **Siti web e domini** creare il sottodominio, per esempio `preventivi.example.it`.
2. Aprire **Git → Aggiungi repository**.
3. Scegliere **Hosting Git remoto**.
4. Usare:

   ```text
   https://github.com/zensoftwareit-ops/basic-preventivi.git
   ```

5. Selezionare il branch `main` e la distribuzione manuale.
6. Distribuire il progetto in una cartella `<APP_ROOT>` che contenga `app`, `bin`, `database` e `public`.
7. Premere **Pull Updates** e **Deploy from Repository**.

### 2. Document root e PHP

1. In **Impostazioni di hosting** impostare la document root su `<APP_ROOT>/public`.
2. Selezionare PHP 8.2 o 8.3.
3. Verificare che `pdo_mysql`, `mbstring`, `openssl`, `curl` e `zip` siano abilitate.
4. Attivare HTTPS e il reindirizzamento da HTTP a HTTPS.

### 3. Database

1. In **Siti web e domini → Database** creare un database MySQL/MariaDB e un utente dedicato.
2. Aprire phpMyAdmin.
3. Importare `database/schema.sql`.
4. Se un tentativo precedente è terminato con l’errore MySQL `#1293`, eliminare le tabelle create parzialmente prima di ripetere l’importazione.

### 4. Creare il primo amministratore

L’SSO non crea automaticamente gli utenti. In phpMyAdmin aprire la tabella `users`, scegliere **Inserisci** e compilare:

- `id`: lasciare vuoto, viene assegnato automaticamente;
- `username`: identificativo interno dell’operatore;
- `first_name`: nome;
- `last_name`: cognome;
- `email`: indirizzo che riceverà alert e follow-up;
- `role`: `admin` per un amministratore operativo, `operator` per un operatore normale oppure `super` per un supervisore non operativo;
- `active`: `1` per attivo, `0` per disattivo.

Annotare l’`id` assegnato: sarà il valore passato a `sso.php`.

Esempio SQL equivalente:

```sql
INSERT INTO users (username, first_name, last_name, email, role, active)
VALUES ('mario.rossi', 'Mario', 'Rossi', 'mario.rossi@example.it', 'admin', 1);
```

### 5. Configurazione privata

1. Copiare `app/config.local.example.php` in `app/config.local.php`.
2. Modificare il nuovo file:

   ```php
   <?php

   declare(strict_types=1);

   return [
       'app' => [
           'url' => 'https://preventivi.example.it',
           'shared_token' => 'INSERIRE_QUI_UN_TOKEN_LUNGO_E_CASUALE',
           'session_secure' => true,
           'device_cookie_name' => 'basic_preventivi_device',
           'device_session_days' => 180,
       ],
       'db' => [
           'host' => 'localhost',
           'port' => 3306,
           'name' => 'NOME_DATABASE_PLESK',
           'user' => 'UTENTE_DATABASE_PLESK',
           'password' => 'PASSWORD_DATABASE_PLESK',
       ],
       'mail' => [
           'enabled' => true,
           'host' => 'smtp.example.it',
           'port' => 587,
           'encryption' => 'tls',
           'username' => 'preventivi@example.it',
           'password' => 'PASSWORD_SMTP',
           'from_email' => 'preventivi@example.it',
           'from_name' => 'Basic Preventivi',
           'timeout' => 15,
           'bcc' => [
               'responsabile@example.it',
               'direzione@example.it',
           ],
       ],
       'push' => [
           'enabled' => true,
           'vapid_subject' => 'mailto:preventivi@example.it',
           'vapid_public_key' => 'CHIAVE_PUBBLICA_VAPID',
           'vapid_private_key' => <<<'PEM'
   -----BEGIN PRIVATE KEY-----
   CHIAVE_PRIVATA_VAPID
   -----END PRIVATE KEY-----
   PEM,
           'ttl' => 86400,
           'timeout' => 20,
       ],
   ];
   ```

`app/config.local.php` è escluso da Git. Non inserire token o password nei file versionati.

Parametri SMTP:

- porta `587` con `encryption => 'tls'`: collegamento standard con STARTTLS;
- porta `465` con `encryption => 'ssl'`: TLS implicito;
- `encryption => 'none'`: solo per un relay interno fidato, mai per credenziali inviate via Internet;
- `username` e `password`: credenziali dell'account SMTP; alcuni fornitori richiedono una password per applicazioni;
- `from_email`: indirizzo mittente autorizzato dal fornitore SMTP;
- `bcc`: zero, uno o più indirizzi che ricevono la copia nascosta.

Il certificato TLS viene verificato. Non usare server con certificati scaduti o autofirmati. Se il provider impone un mittente uguale allo username, usare lo stesso indirizzo in `username` e `from_email`.

Per verificare subito le credenziali senza aspettare un alert, da **Attività pianificate** eseguire una volta questo comando sostituendo percorso e destinatario:

```text
/opt/plesk/php/8.3/bin/php <APP_ROOT>/bin/test_smtp.php destinatario@example.it
```

Il test invia solo all'indirizzo indicato e non usa i destinatari BCC configurati. L'esito positivo restituisce `"ok": true`.

#### Chiavi VAPID per le notifiche push

Generare una sola coppia di chiavi sul server usando la stessa versione PHP del sito:

```text
/opt/plesk/php/8.3/bin/php <APP_ROOT>/bin/generate_vapid_keys.php
```

Il comando stampa il blocco `push` completo. Copiarlo in `app/config.local.php` e sostituire `vapid_subject` con un indirizzo `mailto:` reale. La chiave privata non deve essere pubblicata su GitHub. Conservare sempre la stessa coppia: cambiandola, i dispositivi dovranno autorizzare nuovamente le notifiche.

### 6. Attività pianificata

Gli alert compaiono anche quando viene aperta la dashboard, ma l’attività pianificata è necessaria per generarli puntualmente e inviare email e notifiche push.

1. Aprire **Attività pianificate → Aggiungi attività**.
2. Scegliere **Esegui uno script PHP**.
3. Selezionare la stessa versione PHP del sito.
4. Indicare:

   ```text
   <APP_ROOT>/bin/check_notifications.php
   ```

5. Programmare l’esecuzione ogni 5 minuti.
6. Usare **Esegui ora**: l’output deve contenere `"ok": true`; le sezioni `email` e `push` indicano quanti messaggi sono stati inviati, saltati, scaduti o non riusciti.

Se è disponibile solo “Esegui un comando” su Plesk Linux:

```text
/opt/plesk/php/8.3/bin/php <APP_ROOT>/bin/check_notifications.php
```

### 7. Verifica

1. Aprire `https://preventivi.example.it/health.php`: deve rispondere con stato `ok`, `smtp_configured: true`, `xlsx_available: true`, `pwa_available: true`, `push_configured: true` e `device_login_available: true`.
2. Eseguire un accesso SSO usando l’ID del primo operatore.
3. Creare una pratica e verificare che la scadenza mostrata sia 24 ore dopo la creazione.

## Installazione su smartphone e notifiche push

L'applicazione è una PWA: si installa dalla stessa URL del sottodominio e non richiede App Store, Play Store o un account Apple Developer.

### Primo accesso sul telefono

Non esiste e non serve un form di login. La prima attivazione deve partire dal software aziendale sullo stesso telefono:

1. L'operatore apre il software aziendale dal telefono.
2. Preme il pulsante **Apri Preventivi**, che fa navigare il browser verso `sso.php` passando il token condiviso e il suo `id`.
3. `sso.php` verifica l'utente, crea la sessione dispositivo e apre la dashboard.
4. Dalla dashboard l'operatore installa la PWA e attiva le notifiche.

Da quel momento l'icona può essere aperta direttamente: il dispositivo riconosce l'operatore anche dopo la chiusura del browser o la scadenza della normale sessione PHP. La durata si rinnova all'uso ed è configurata con `device_session_days`; **Esci** revoca solamente il dispositivo corrente. Se il software aziendale non è utilizzabile sul telefono, deve mostrare un collegamento o QR che apra lo stesso endpoint SSO nel browser del telefono.

### Android

1. Aprire il sottodominio con Chrome ed entrare tramite SSO.
2. Premere **Installa app** nella barra laterale oppure usare il menu del browser → **Installa app**.
3. Aprire l'icona **Preventivi** dalla schermata Home.
4. Premere **Attiva notifiche** e autorizzare il browser.

### iPhone e iPad

Le notifiche Web Push richiedono iOS/iPadOS 16.4 o successivo e la web app aggiunta alla schermata Home.

Per copiare automaticamente nella nuova web app il cookie ottenuto con il primo SSO è consigliato iOS/iPadOS 17.2 o successivo. L'ordine corretto è sempre: accesso SSO in Safari, aggiunta alla schermata Home, apertura dall'icona. Se l'icona era stata creata prima dell'accesso, eliminarla e ripetere l'installazione dopo il login SSO.

1. Aprire il sottodominio in Safari ed entrare tramite SSO.
2. Usare **Condividi → Aggiungi alla schermata Home**.
3. Aprire **Preventivi** dalla nuova icona, non dalla scheda Safari.
4. Premere **Attiva notifiche** e accettare la richiesta di iOS.

Il consenso vale per singolo dispositivo. **Notifiche attive · Disattiva** rimuove soltanto il dispositivo corrente. Le push sono associate all'utente SSO e vengono inviate insieme agli alert delle 12 ore, 24 ore e ai follow-up dei 3 giorni.

La sottoscrizione del browser viene riallineata automaticamente all'ultimo `id` autenticato via SSO sul dispositivo. Se lo stesso telefono viene consegnato a un altro operatore, eseguire **Esci** e poi il nuovo accesso dal software aziendale.

Dopo aver autorizzato un dispositivo, è possibile inviare subito una notifica di prova da Plesk sostituendo l'ID utente:

```text
/opt/plesk/php/8.3/bin/php <APP_ROOT>/bin/test_push.php 12
```

## SSO senza username

Endpoint:

```text
GET /sso.php?id=<id-utente>&token=<token-condiviso>
```

Esempio:

```text
https://preventivi.example.it/sso.php?id=12&token=TOKEN_CONDIVISO
```

Il token è l’unica credenziale condivisa. L’`id` serve esclusivamente a individuare l’operatore nella tabella `users`. L’accesso fallisce se l’utente non esiste o ha `active = 0`.

Il software chiamante deve effettuare una navigazione reale del browser, preferibilmente con il form `POST` descritto sotto: una chiamata server-to-server o AJAX non può installare il cookie sul telefono dell'operatore. Dopo il primo accesso valido, nel cookie rimane solo un token casuale; nel database ne viene salvato esclusivamente l'hash. Disattivare l'utente invalida automaticamente tutte le sue sessioni dispositivo.

## Ruoli e permessi

- `operator`: usa dashboard, preventivi, follow-up ed esportazione Excel; non vede e non può aprire **Dati base**;
- `admin`: dispone delle stesse funzioni e può inoltre aprire **Dati base** ed eliminare definitivamente un preventivo;
- `super`: ha tutti i permessi di `admin`, accede via SSO e controlla l'intera applicazione, ma non è selezionabile come responsabile o ricevitore e non riceve notifiche operative;
- il controllo è applicato lato server: nascondere il collegamento non è l’unica protezione;
- l'eliminazione è definitiva e rimuove anche cronologia e notifiche collegate al preventivo.

Per modificare un ruolo da phpMyAdmin:

```sql
UPDATE users SET role = 'admin' WHERE id = 1;
UPDATE users SET role = 'operator' WHERE id = 2;
UPDATE users SET role = 'super' WHERE id = 3;
```

## Esportazione Excel

Nella pagina **Preventivi** usare il pulsante **Esporta XLSX**. Il file include tutte le pratiche che corrispondono ai filtri correnti, non soltanto le 25 righe della pagina visualizzata.

Sono mantenuti i filtri per vista, ricerca, responsabile, stato, priorità e scadenza. Il foglio contiene una tabella Excel con intestazioni bloccate, filtri di colonna, righe alternate, date ordinabili, percentuali e importi in euro. Per proteggere la memoria del server, una singola esportazione può contenere al massimo 20.000 pratiche; oltre tale limite occorre applicare filtri più specifici.

L'esportazione richiede l'estensione PHP `zip`, verificabile da `health.php` tramite `xlsx_available`.

POST consigliato:

```html
<form id="basic-sso" method="post" action="https://preventivi.example.it/sso.php">
  <input type="hidden" name="id" value="12">
  <input type="hidden" name="token" value="TOKEN_CONDIVISO">
</form>
<script>document.getElementById('basic-sso').submit()</script>
```

È supportato anche `Authorization: Bearer <token>`, mantenendo `id` come parametro. Il software chiamante deve far navigare il browser dell’utente verso `sso.php`, altrimenti il cookie di sessione non raggiunge il browser.

## Aggiornamento di un database esistente

1. Eseguire Pull e deploy del nuovo codice.
2. Fare un backup del database.
3. Se non è mai stata eseguita la migration utenti/notifiche, importare nell'ordine:

   ```text
   database/migrations/20260716_users_sso_and_notifications.sql
   database/migrations/20260716_add_user_roles.sql
   database/migrations/20260716_add_super_role.sql
   database/migrations/20260717_add_web_push.sql
   database/migrations/20260717_add_device_sessions.sql
   ```

4. Per aggiornare la versione immediatamente precedente, che include già il ruolo `super`, importare `20260717_add_web_push.sql` e poi `20260717_add_device_sessions.sql`.
5. Se la migration Web Push è già stata importata, importare soltanto `20260717_add_device_sessions.sql`.
6. Se `users.role` non accetta ancora il valore `super`, importare prima `20260716_add_super_role.sql`, poi le due migration del 17 luglio.
7. La migration ruoli iniziale imposta tutti come `operator` e promuove automaticamente ad `admin` il primo utente attivo per evitare di bloccare l'amministrazione.
8. Per creare il supervisore, impostare `role = 'super'` sull'utente desiderato dalla tabella `users` in phpMyAdmin.
9. Verificare e correggere gli altri ruoli dalla tabella `users` in phpMyAdmin.
10. Aggiornare manualmente tutte le email provvisorie `@example.invalid` con gli indirizzi reali degli operatori.
11. Aggiornare il software chiamante affinché passi `id` invece di `operator` o `username`.

## Aggiornamenti futuri

1. Aprire **Siti web e domini → Git**.
2. Premere **Pull Updates**.
3. Controllare il commit ricevuto.
4. Premere **Deploy from Repository**.
5. Importare eventuali nuove migration indicate nelle note di rilascio.
6. Verificare `health.php`, SSO e l’attività pianificata.

## Riferimenti Plesk

- [Supporto Git](https://docs.plesk.com/en-US/obsidian/administrator-guide/website-management/git-support.75824/)
- [Document root e impostazioni PHP](https://docs.plesk.com/en-US/obsidian/quick-start-guide/plesk-functionality-explained/managing-web-hosting.74401/)
- [Importazione database](https://docs.plesk.com/en-US/obsidian/administrator-guide/website-management/website-databases/exporting-and-importing-database-dumps.69538/)
- [Attività pianificate](https://docs.plesk.com/en-US/obsidian/administrator-guide/server-administration/scheduling-tasks.64993/)
