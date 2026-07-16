SET NAMES utf8mb4;

-- Eseguire una volta sui database che hanno gia la colonna users.role.
-- La modifica conserva i ruoli esistenti e aggiunge il ruolo di supervisione non operativo.
ALTER TABLE users
    MODIFY COLUMN role ENUM('operator', 'admin', 'super') NOT NULL DEFAULT 'operator';

-- Esempio: sostituire 1 con l'ID dell'utente di supervisione.
-- UPDATE users SET role = 'super' WHERE id = 1;
