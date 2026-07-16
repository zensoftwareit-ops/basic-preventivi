# Basic – Gestione Preventivi

Applicazione web PHP + MySQL per registrare, assegnare e seguire i preventivi Basic. Non usa Docker, Composer, npm o servizi esterni.

Repository: <https://github.com/zensoftwareit-ops/basic-preventivi>

## Funzioni principali

- accesso SSO senza form: il **token condiviso è l’unica credenziale**;
- il parametro `operator` identifica chi sta lavorando, ma non concede permessi;
- numero pratica automatico (`BAS-2026-000001`);
- registro preventivi con stati, responsabili, priorità, scadenze e semaforo;
- dashboard con carico, ritardi, valore e conversione;
- follow-up automatici a 3, 7 e 15 giorni dall’invio;
- reminder personale dopo 72 ore senza cambio di stato;
- email di reminder opzionali;
- cronologia, archiviazione e liste configurabili;
- interfaccia responsive senza dipendenze front-end esterne.

## Requisiti

- Plesk Obsidian con estensione **Git**;
- PHP 8.2 o successivo;
- estensioni PHP `pdo_mysql` e `mbstring`;
- MySQL 5.5.3 o successivo, oppure una versione MariaDB compatibile;
- certificato HTTPS attivo sul sottodominio.

## Installazione in Plesk, passo per passo

Negli esempi:

- `preventivi.example.it` è il sottodominio da sostituire;
- `<APP_ROOT>` è la cartella in cui Plesk pubblica il repository;
- la document root del sottodominio deve essere sempre `<APP_ROOT>/public`.

### 1. Creare il sottodominio

1. Aprire **Siti web e domini** in Plesk.
2. Fare clic su **Aggiungi sottodominio**.
3. Inserire il nome desiderato, per esempio `preventivi`.
4. Annotare la cartella proposta da Plesk: servirà nel passaggio Git.
5. Attivare un certificato Let’s Encrypt e il reindirizzamento permanente da HTTP a HTTPS.

### 2. Collegare il repository GitHub

1. Nel sottodominio aprire **Git**. Se la voce non esiste, installare o abilitare l’estensione Git di Plesk.
2. Fare clic su **Aggiungi repository**.
3. Scegliere **Hosting Git remoto**.
4. Incollare questo URL:

   ```text
   https://github.com/zensoftwareit-ops/basic-preventivi.git
   ```

5. Selezionare il branch `main`.
6. Impostare **Distribuzione manuale**.
7. Come percorso di distribuzione scegliere `<APP_ROOT>`, cioè la cartella che deve contenere `app`, `bin`, `database` e `public`. Non scegliere direttamente la cartella `public`.
8. Salvare, quindi fare clic su **Pull Updates** e infine su **Deploy from Repository**.

Il repository è pubblico, quindi al momento Plesk non richiede credenziali GitHub.

### 3. Impostare la document root corretta

1. Aprire **Siti web e domini → Impostazioni di hosting** del sottodominio.
2. Nel campo **Document root** indicare la sottocartella `public` del progetto: `<APP_ROOT>/public`.
3. Salvare.
4. Controllare con File Manager che nella document root siano visibili `index.php`, `sso.php`, `health.php` e `assets`.

La cartella `app` e il file di configurazione devono restare un livello sopra la document root e non devono essere pubblicamente scaricabili.

### 4. Selezionare PHP

1. Aprire **Siti web e domini → PHP**.
2. Selezionare PHP 8.2 o 8.3.
3. Verificare che `pdo_mysql` e `mbstring` siano attive.
4. Lasciare `display_errors` disattivato in produzione.

### 5. Creare e inizializzare il database

1. Aprire **Siti web e domini → Database → Aggiungi database**.
2. Creare un database MySQL/MariaDB e un utente dedicato.
3. Conservare nome database, host, nome utente e password.
4. Aprire **phpMyAdmin** per quel database.
5. In File Manager scaricare dal progetto il file `database/schema.sql`, oppure scaricarlo dal repository GitHub.
6. In phpMyAdmin aprire **Importa**, scegliere `schema.sql` e avviare l’importazione.

Se un precedente tentativo si è interrotto con l’errore MySQL `#1293`, eliminare le eventuali tabelle create parzialmente e importare di nuovo il file `database/schema.sql` aggiornato. Lo schema usa una sola colonna `TIMESTAMP` automatica per tabella ed è compatibile anche con i server Plesk meno recenti.

Per un’installazione già esistente, non reimportare tutto lo schema: eseguire soltanto `database/migrations/20260716_add_status_reminders.sql`.

### 6. Creare la configurazione privata

1. In **File Manager** aprire `<APP_ROOT>/app`.
2. Copiare `config.local.example.php` con il nome `config.local.php`.
3. Modificare esclusivamente `config.local.php`:

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
       'reminders' => [
           'stale_after_hours' => 72,
           'email_enabled' => false,
           'email_from' => 'preventivi@example.it',
           'operator_emails' => [],
       ],
   ];
   ```

`app/config.local.php` è escluso da Git: password e token non vengono pubblicati e il file rimane separato dagli aggiornamenti del repository.

### 7. Attivare il controllo reminder

Il reminder viene comunque creato quando l’operatore apre la dashboard. L’attività pianificata serve per generarlo puntualmente e, se abilitate, inviare le email.

1. Aprire **Attività pianificate** del dominio. Come amministratore Plesk la stessa funzione è disponibile anche in **Strumenti e impostazioni → Attività pianificate**.
2. Fare clic su **Aggiungi attività**.
3. Scegliere **Esegui uno script PHP**.
4. Selezionare la stessa versione PHP usata dal sito.
5. Indicare il percorso completo:

   ```text
   <APP_ROOT>/bin/check_stale_reminders.php
   ```

6. Impostare l’esecuzione **ogni ora**, per esempio al minuto `10`.
7. Salvare e usare **Esegui ora**: il risultato deve contenere `"ok": true`.

Se Plesk offre soltanto “Esegui un comando”, su Linux usare il binario PHP della versione scelta:

```text
/opt/plesk/php/8.3/bin/php <APP_ROOT>/bin/check_stale_reminders.php
```

### 8. Verificare l’applicazione

1. Aprire `https://preventivi.example.it/health.php`: deve rispondere con stato `ok`.
2. Provare il collegamento SSO descritto sotto.
3. Creare una pratica di prova e verificare dashboard, modifica e follow-up.

## Servizio SSO senza form

Endpoint preferito:

```text
GET /sso.php?operator=<operatore>&token=<token-condiviso>
```

Esempio:

```text
https://preventivi.example.it/sso.php?operator=marco.rossi&token=TOKEN_CONDIVISO
```

Il sistema controlla **solo il token**. `operator` serve a creare o riconoscere l’operatore, assegnargli le pratiche e mostrargli i reminder. Per compatibilità è accettato anche il vecchio parametro `username`, ma non viene usato per autorizzare l’accesso.

Se il token è valido, il servizio crea o aggiorna l’operatore, apre la sessione, registra l’evento e reindirizza con risposta `303` alla dashboard. Il software chiamante deve far navigare il browser dell’utente verso l’endpoint; una richiesta eseguita soltanto dal proprio backend non trasferisce il cookie al browser.

### POST consigliato

Il GET è supportato, ma può lasciare il token nei log o nella cronologia. È preferibile un form POST auto-inviato:

```html
<form id="basic-sso" method="post" action="https://preventivi.example.it/sso.php">
  <input type="hidden" name="operator" value="marco.rossi">
  <input type="hidden" name="token" value="TOKEN_CONDIVISO">
</form>
<script>document.getElementById('basic-sso').submit()</script>
```

È supportato anche l’header `Authorization: Bearer <token>`, mantenendo `operator` come parametro.

> Il token unico per tutti è stato implementato come richiesto, ma chiunque lo conosca può entrare indicando un operatore arbitrario. Va trattato come una password e cambiato se viene esposto.

## Reminder dopo tre giorni

- una pratica aperta senza cambio stato per 72 ore genera un reminder per il responsabile corrente;
- il reminder compare nella dashboard personale;
- “Presa in carico” lo nasconde senza cambiare lo stato del preventivo;
- un cambio di stato, un cambio di responsabile o l’archiviazione risolve il reminder;
- per ogni fase invariata viene generato un solo reminder, quindi l’attività oraria non crea duplicati.

Per abilitare anche le email, modificare `config.local.php`:

```php
'reminders' => [
    'stale_after_hours' => 72,
    'email_enabled' => true,
    'email_from' => 'preventivi@example.it',
    'operator_emails' => [
        'marco.rossi' => 'marco.rossi@example.it',
        'anna.bianchi' => 'anna.bianchi@example.it',
    ],
],
```

L’invio usa la funzione `mail()` di PHP e richiede che la posta in uscita del server Plesk sia configurata.

## Aggiornamenti futuri da GitHub

1. Aprire **Siti web e domini → Git**.
2. Fare clic su **Pull Updates**.
3. Controllare il commit ricevuto.
4. Fare clic su **Deploy from Repository**.
5. Se nelle note di rilascio è indicata una nuova migration SQL, importarla nel database.
6. Verificare `health.php` e un accesso SSO.

Database e `app/config.local.php` non vengono salvati nel repository e non devono essere sostituiti durante il deploy.

## Riferimenti Plesk

- [Supporto Git e pulsante Pull Updates](https://docs.plesk.com/en-US/obsidian/administrator-guide/website-management/git-support.75824/)
- [Modifica della document root](https://docs.plesk.com/en-US/obsidian/quick-start-guide/plesk-functionality-explained/managing-web-hosting.74401/)
- [Importazione dei dump database](https://docs.plesk.com/en-US/obsidian/administrator-guide/website-management/website-databases/exporting-and-importing-database-dumps.69538/)
- [Attività pianificate e script PHP](https://docs.plesk.com/en-US/obsidian/administrator-guide/server-administration/scheduling-tasks.64993/)
