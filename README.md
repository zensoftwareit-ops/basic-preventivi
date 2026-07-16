# Basic – Gestione Preventivi

Applicazione PHP + MySQL per registrare, assegnare e seguire i preventivi Basic. Non usa Docker, Composer o npm.

Repository: <https://github.com/zensoftwareit-ops/basic-preventivi>

## Regole operative

- SSO senza form con `id` utente e token condiviso;
- l’utente deve esistere nella tabella `users` ed essere attivo;
- scadenza di invio non modificabile, fissata a 24 ore dalla creazione della pratica;
- primo alert interno ed email dopo 12 ore se il preventivo non risulta inviato;
- secondo alert interno ed email dopo 24 ore se il preventivo non risulta inviato;
- follow-up interno ed email ogni 3 giorni di stato invariato;
- il conteggio dei 3 giorni riparte da zero a ogni cambio di stato;
- email inviata all’indirizzo dell’operatore presente in `users.email`;
- invio tramite server SMTP esterno autenticato;
- una o più copie nascoste BCC configurabili in `app/config.local.php`;
- semaforo scadenza: verde entro 24 ore, giallo tra 24 e 48 ore dalla creazione, rosso oltre 48 ore;
- follow-up e scadenze non possono essere posticipati o modificati dall’interfaccia.

## Requisiti

- Plesk Obsidian con estensione Git;
- PHP 8.2 o successivo;
- estensioni PHP `pdo_mysql`, `mbstring` e `openssl`;
- MySQL 5.5.3 o successivo oppure MariaDB compatibile;
- HTTPS attivo sul sottodominio;
- accesso in uscita dal server Plesk al server SMTP scelto (normalmente porta 587 o 465).

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
3. Verificare che `pdo_mysql`, `mbstring` e `openssl` siano abilitate.
4. Attivare HTTPS e il reindirizzamento da HTTP a HTTPS.

### 3. Database

1. In **Siti web e domini → Database** creare un database MySQL/MariaDB e un utente dedicato.
2. Aprire phpMyAdmin.
3. Importare `database/schema.sql`.
4. Se un tentativo precedente è terminato con l’errore MySQL `#1293`, eliminare le tabelle create parzialmente prima di ripetere l’importazione.

### 4. Creare il primo operatore

L’SSO non crea automaticamente gli utenti. In phpMyAdmin aprire la tabella `users`, scegliere **Inserisci** e compilare:

- `id`: lasciare vuoto, viene assegnato automaticamente;
- `username`: identificativo interno dell’operatore;
- `first_name`: nome;
- `last_name`: cognome;
- `email`: indirizzo che riceverà alert e follow-up;
- `active`: `1` per attivo, `0` per disattivo.

Annotare l’`id` assegnato: sarà il valore passato a `sso.php`.

Esempio SQL equivalente:

```sql
INSERT INTO users (username, first_name, last_name, email, active)
VALUES ('mario.rossi', 'Mario', 'Rossi', 'mario.rossi@example.it', 1);
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

### 6. Attività pianificata

Gli alert compaiono anche quando viene aperta la dashboard, ma l’attività pianificata è necessaria per generarli puntualmente e inviare le email.

1. Aprire **Attività pianificate → Aggiungi attività**.
2. Scegliere **Esegui uno script PHP**.
3. Selezionare la stessa versione PHP del sito.
4. Indicare:

   ```text
   <APP_ROOT>/bin/check_notifications.php
   ```

5. Programmare l’esecuzione ogni 5 minuti.
6. Usare **Esegui ora**: l’output deve contenere `"ok": true`; la sezione `email` indica quanti messaggi sono stati inviati, saltati o non riusciti.

Se è disponibile solo “Esegui un comando” su Plesk Linux:

```text
/opt/plesk/php/8.3/bin/php <APP_ROOT>/bin/check_notifications.php
```

### 7. Verifica

1. Aprire `https://preventivi.example.it/health.php`: deve rispondere con stato `ok` e `smtp_configured: true`.
2. Eseguire un accesso SSO usando l’ID del primo operatore.
3. Creare una pratica e verificare che la scadenza mostrata sia 24 ore dopo la creazione.

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
3. Importare una sola volta:

   ```text
   database/migrations/20260716_users_sso_and_notifications.sql
   ```

4. La migration converte la precedente tabella utenti e crea `operator_notifications`.
5. Aggiornare manualmente tutte le email provvisorie `@example.invalid` con gli indirizzi reali degli operatori.
6. Aggiornare il software chiamante affinché passi `id` invece di `operator` o `username`.

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
