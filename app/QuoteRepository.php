<?php

declare(strict_types=1);

final class QuoteRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    public function masterData(bool $includeInactive = false): array
    {
        $active = $includeInactive ? '' : ' WHERE active = 1';
        return [
            'services' => $this->fetchAll('SELECT id, name, active FROM services' . $active . ' ORDER BY sort_order, name'),
            'channels' => $this->fetchAll('SELECT id, name, active FROM channels' . $active . ' ORDER BY sort_order, name'),
            'priorities' => $this->fetchAll('SELECT id, name, color, weight, active FROM priorities' . $active . ' ORDER BY weight DESC, name'),
            'statuses' => $this->fetchAll('SELECT id, code, name, color, is_closed, active FROM statuses' . $active . ' ORDER BY sort_order, name'),
            'outcomes' => $this->fetchAll('SELECT id, code, name, is_success, active FROM outcomes' . $active . ' ORDER BY sort_order, name'),
            'users' => $this->fetchAll("SELECT id, username, first_name, last_name, email, role, active,
                TRIM(CONCAT(first_name, ' ', last_name)) AS display_name
                FROM users" . $active . " ORDER BY last_name, first_name"),
        ];
    }

    public function defaults(): array
    {
        return [
            'status_id' => $this->scalar("SELECT id FROM statuses WHERE code = 'NEW' LIMIT 1"),
            'priority_id' => $this->scalar("SELECT id FROM priorities WHERE name = 'Media' LIMIT 1"),
        ];
    }

    public function validate(array $input): array
    {
        $errors = [];
        if (($input['request_date'] ?? '') === '') {
            $errors['request_date'] = 'Indica la data della richiesta.';
        }
        if (trim((string) ($input['customer_name'] ?? '')) === '') {
            $errors['customer_name'] = 'Indica il cliente.';
        }
        if ((int) ($input['service_id'] ?? 0) < 1) {
            $errors['service_id'] = 'Seleziona il servizio.';
        }
        if ((int) ($input['responsible_user_id'] ?? 0) < 1) {
            $errors['responsible_user_id'] = 'Seleziona il responsabile.';
        }
        if ((int) ($input['priority_id'] ?? 0) < 1) {
            $errors['priority_id'] = 'Seleziona la priorità.';
        }
        if ((int) ($input['status_id'] ?? 0) < 1) {
            $errors['status_id'] = 'Seleziona lo stato.';
        }
        $probability = (int) ($input['probability'] ?? 0);
        if ($probability < 0 || $probability > 100) {
            $errors['probability'] = 'La probabilità deve essere compresa tra 0 e 100.';
        }
        $link = trim((string) ($input['external_link'] ?? ''));
        if ($link !== '' && filter_var($link, FILTER_VALIDATE_URL) === false) {
            $errors['external_link'] = 'Il link deve essere un URL valido.';
        }
        return $errors;
    }

    public function create(array $input, int $actorId): int
    {
        $data = $this->normalize($input, $actorId);
        if (!$data['date_sent'] && $this->isSentStatus((int) $data['status_id'])) {
            $data['date_sent'] = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        }
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO quotes (
                    request_date, request_time, customer_name, customer_contact, phone, email,
                    channel_id, service_id, request_description, received_by_user_id, responsible_user_id,
                    priority_id, status_id, quote_deadline, date_sent, estimated_value, probability,
                    last_update_at, next_followup_at, outcome_id, loss_notes, external_link,
                    status_changed_at, created_by_user_id
                 ) VALUES (
                    :request_date, :request_time, :customer_name, :customer_contact, :phone, :email,
                    :channel_id, :service_id, :request_description, :received_by_user_id, :responsible_user_id,
                    :priority_id, :status_id, DATE_ADD(NOW(), INTERVAL 24 HOUR), :date_sent, :estimated_value, :probability,
                    NOW(), DATE_ADD(NOW(), INTERVAL 3 DAY), :outcome_id, :loss_notes, :external_link, NOW(), :created_by_user_id
                 )'
            );
            $statement->execute($data);
            $id = (int) $this->pdo->lastInsertId();
            $year = (new DateTimeImmutable($data['request_date']))->format('Y');
            $code = sprintf('BAS-%s-%06d', $year, $id);
            $this->pdo->prepare('UPDATE quotes SET practice_code = :code WHERE id = :id')
                ->execute(['code' => $code, 'id' => $id]);

            $this->addActivity($id, $actorId, 'created', 'Pratica creata.');
            if ($this->isClosedStatus((int) $data['status_id'])) {
                $this->pdo->prepare('UPDATE quotes SET next_followup_at = NULL WHERE id = :id')->execute(['id' => $id]);
            }
            $this->pdo->commit();
            return $id;
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function update(int $id, array $input, int $actorId): void
    {
        $previous = $this->rawQuote($id);
        if (!$previous) {
            throw new RuntimeException('Pratica non trovata.');
        }
        $data = $this->normalize($input, $actorId);
        if (!$data['date_sent'] && $this->isSentStatus((int) $data['status_id'])) {
            $data['date_sent'] = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        }
        unset($data['created_by_user_id']);
        $data['id'] = $id;
        $data['status_id_followup_check'] = $data['status_id'];
        $data['status_id_changed_check'] = $data['status_id'];

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'UPDATE quotes SET
                    request_date = :request_date, request_time = :request_time,
                    customer_name = :customer_name, customer_contact = :customer_contact,
                    phone = :phone, email = :email, channel_id = :channel_id, service_id = :service_id,
                    request_description = :request_description, received_by_user_id = :received_by_user_id,
                    responsible_user_id = :responsible_user_id, priority_id = :priority_id,
                    next_followup_at = IF(status_id <> :status_id_followup_check, DATE_ADD(NOW(), INTERVAL 3 DAY), next_followup_at),
                    status_changed_at = IF(status_id <> :status_id_changed_check, NOW(), status_changed_at),
                    status_id = :status_id, date_sent = :date_sent,
                    estimated_value = :estimated_value, probability = :probability,
                    last_update_at = NOW(),
                    outcome_id = :outcome_id, loss_notes = :loss_notes, external_link = :external_link
                 WHERE id = :id'
            );
            $statement->execute($data);

            if ((int) $previous['status_id'] !== (int) $data['status_id']) {
                $this->addActivity(
                    $id,
                    $actorId,
                    'status_change',
                    'Stato aggiornato.',
                    (int) $previous['status_id'],
                    (int) $data['status_id']
                );
            }
            $statusChanged = (int) $previous['status_id'] !== (int) $data['status_id'];
            $responsibleChanged = (int) $previous['responsible_user_id'] !== (int) $data['responsible_user_id'];
            if ($responsibleChanged) {
                $this->resolveNotifications($id);
            } elseif ($statusChanged) {
                $this->resolveNotifications($id, ['stale_3d']);
            }
            $note = trim((string) ($input['activity_note'] ?? ''));
            if ($note !== '') {
                $this->addActivity($id, $actorId, 'note', $note);
            }
            if (!$previous['date_sent'] && $data['date_sent']) {
                $this->resolveNotifications($id, ['deadline_12h', 'deadline_24h']);
            }

            if ($this->isClosedStatus((int) $data['status_id'])) {
                $this->pdo->prepare('UPDATE quotes SET next_followup_at = NULL WHERE id = :id')->execute(['id' => $id]);
                $this->resolveNotifications($id);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare($this->baseSelect() . ' WHERE q.id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $quote = $statement->fetch();
        if (!$quote) {
            return null;
        }

        $activity = $this->pdo->prepare(
            "SELECT a.*, TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS display_name,
                    old_status.name AS old_status_name, new_status.name AS new_status_name
             FROM quote_activities a
             LEFT JOIN users u ON u.id = a.user_id
             LEFT JOIN statuses old_status ON old_status.id = a.old_status_id
             LEFT JOIN statuses new_status ON new_status.id = a.new_status_id
             WHERE a.quote_id = :id ORDER BY a.created_at DESC, a.id DESC"
        );
        $activity->execute(['id' => $id]);
        $quote['activities'] = $activity->fetchAll();

        $followups = $this->pdo->prepare(
            "SELECT n.*, st.name AS status_name
             FROM operator_notifications n
             LEFT JOIN statuses st ON st.id = n.status_id_snapshot
             WHERE n.quote_id = :id AND n.notification_type = 'stale_3d'
             ORDER BY n.due_at DESC, n.id DESC"
        );
        $followups->execute(['id' => $id]);
        $quote['followups'] = $followups->fetchAll();

        return $quote;
    }

    public function listQuotes(array $filters, int $page = 1, int $perPage = 25): array
    {
        [$where, $params] = $this->quoteFilter($filters);
        $countStatement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM quotes q JOIN statuses st ON st.id = q.status_id' . $where
        );
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $statement = $this->pdo->prepare(
            $this->baseSelect() . $where . ' ORDER BY st.is_closed, q.quote_deadline, q.created_at DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return ['items' => $statement->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public function exportQuotes(array $filters, int $maxRows = 20000): array
    {
        [$where, $params] = $this->quoteFilter($filters);
        $countStatement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM quotes q JOIN statuses st ON st.id = q.status_id' . $where
        );
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();
        if ($total > $maxRows) {
            throw new RuntimeException('L\'export supera ' . number_format($maxRows, 0, ',', '.') . ' righe: applicare filtri piu specifici.');
        }

        $statement = $this->pdo->prepare(
            $this->baseSelect() . $where . ' ORDER BY st.is_closed, q.quote_deadline, q.created_at DESC'
        );
        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();
        return $statement->fetchAll();
    }

    public function deleteQuote(int $quoteId, int $actorId): void
    {
        $adminStatement = $this->pdo->prepare('SELECT role FROM users WHERE id = :id AND active = 1');
        $adminStatement->execute(['id' => $actorId]);
        if ($adminStatement->fetchColumn() !== 'admin') {
            throw new RuntimeException('Solo un amministratore può eliminare definitivamente un preventivo.');
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('DELETE FROM quotes WHERE id = :id');
            $statement->execute(['id' => $quoteId]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Preventivo non trovato.');
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function dashboard(int $userId): array
    {
        $this->generateNotifications();
        $metrics = $this->fetchOne(
            "SELECT
                SUM(CASE WHEN st.is_closed = 0 THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN st.is_closed = 0 AND q.date_sent IS NULL AND q.quote_deadline < NOW() THEN 1 ELSE 0 END) AS overdue_count,
                SUM(CASE WHEN st.is_closed = 0 AND q.date_sent IS NULL AND DATE(q.quote_deadline) = CURDATE() THEN 1 ELSE 0 END) AS due_today_count,
                SUM(CASE WHEN q.request_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 ELSE 0 END) AS received_month_count,
                SUM(CASE WHEN q.date_sent >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 ELSE 0 END) AS sent_month_count,
                SUM(CASE WHEN st.code = 'CONFIRMED' AND q.status_changed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 ELSE 0 END) AS confirmed_month_count,
                SUM(CASE WHEN st.code = 'LOST' AND q.status_changed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 ELSE 0 END) AS lost_month_count,
                SUM(CASE WHEN st.is_closed = 0 THEN q.estimated_value ELSE 0 END) AS open_value,
                AVG(CASE WHEN q.date_sent IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, TIMESTAMP(q.request_date, COALESCE(q.request_time, '00:00:00')), q.date_sent) / 60 END) AS avg_response_hours
             FROM quotes q JOIN statuses st ON st.id = q.status_id WHERE q.archived_at IS NULL"
        );
        $conversion = $this->fetchOne(
            "SELECT
                SUM(CASE WHEN st.code = 'CONFIRMED' THEN 1 ELSE 0 END) AS won,
                SUM(CASE WHEN st.code = 'LOST' THEN 1 ELSE 0 END) AS lost
             FROM quotes q JOIN statuses st ON st.id = q.status_id WHERE q.archived_at IS NULL"
        );
        $closed = (int) ($conversion['won'] ?? 0) + (int) ($conversion['lost'] ?? 0);
        $metrics['conversion_rate'] = $closed > 0 ? ((int) $conversion['won'] / $closed) * 100 : 0;

        $workload = $this->fetchAll(
            "SELECT u.id, TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS display_name,
                SUM(CASE WHEN st.is_closed = 0 AND q.archived_at IS NULL THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN st.is_closed = 0 AND q.archived_at IS NULL AND q.date_sent IS NULL AND q.quote_deadline < NOW() THEN 1 ELSE 0 END) AS overdue_count,
                COALESCE(SUM(CASE WHEN st.is_closed = 0 AND q.archived_at IS NULL THEN q.estimated_value ELSE 0 END), 0) AS open_value
             FROM users u
             LEFT JOIN quotes q ON q.responsible_user_id = u.id
             LEFT JOIN statuses st ON st.id = q.status_id
             WHERE u.active = 1
             GROUP BY u.id, u.first_name, u.last_name
             ORDER BY open_count DESC, u.last_name, u.first_name"
        );

        $followups = $this->followups($userId, 8);
        $notifications = $this->notificationsForUser($userId, 12);
        $recent = $this->fetchAll($this->baseSelect() . ' WHERE q.archived_at IS NULL ORDER BY q.created_at DESC LIMIT 8');

        return compact('metrics', 'workload', 'followups', 'notifications', 'recent');
    }

    public function generateNotifications(): int
    {
        $generated = 0;
        $this->pdo->exec(
            "UPDATE operator_notifications n
             JOIN quotes q ON q.id = n.quote_id
             JOIN statuses st ON st.id = q.status_id
             JOIN users u ON u.id = n.user_id
             SET n.resolved_at = NOW()
             WHERE n.resolved_at IS NULL AND (
                q.archived_at IS NOT NULL OR st.is_closed = 1 OR u.active = 0 OR q.responsible_user_id <> n.user_id
                OR (n.notification_type IN ('deadline_12h', 'deadline_24h') AND q.date_sent IS NOT NULL)
                OR (n.notification_type = 'stale_3d' AND (
                    q.status_id <> n.status_id_snapshot OR q.status_changed_at <> n.status_changed_at_snapshot
                ))
             )"
        );

        foreach (['deadline_12h' => 12, 'deadline_24h' => 24] as $type => $hours) {
            $statement = $this->pdo->prepare(
                "INSERT IGNORE INTO operator_notifications (
                    quote_id, user_id, notification_type, sequence_number,
                    status_id_snapshot, status_changed_at_snapshot, dedupe_key, due_at, triggered_at
                 )
                 SELECT q.id, q.responsible_user_id, '{$type}', 1,
                        q.status_id, q.status_changed_at,
                        CONCAT('{$type}:u', q.responsible_user_id),
                        DATE_ADD(q.created_at, INTERVAL {$hours} HOUR), NOW()
                 FROM quotes q
                 JOIN statuses st ON st.id = q.status_id
                 JOIN users u ON u.id = q.responsible_user_id
                 WHERE q.archived_at IS NULL AND q.date_sent IS NULL
                   AND st.is_closed = 0 AND u.active = 1
                   AND q.created_at <= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)"
            );
            $statement->execute();
            $generated += $statement->rowCount();
        }

        $interval = 72;
        $sequence = "FLOOR(TIMESTAMPDIFF(HOUR, q.status_changed_at, NOW()) / {$interval})";
        $statement = $this->pdo->prepare(
            "INSERT IGNORE INTO operator_notifications (
                quote_id, user_id, notification_type, sequence_number,
                status_id_snapshot, status_changed_at_snapshot, dedupe_key, due_at, triggered_at
             )
             SELECT q.id, q.responsible_user_id, 'stale_3d', {$sequence},
                    q.status_id, q.status_changed_at,
                    CONCAT('stale:u', q.responsible_user_id, ':s', q.status_id,
                           ':t', UNIX_TIMESTAMP(q.status_changed_at), ':n', {$sequence}),
                    TIMESTAMPADD(HOUR, {$sequence} * {$interval}, q.status_changed_at), NOW()
             FROM quotes q
             JOIN statuses st ON st.id = q.status_id
             JOIN users u ON u.id = q.responsible_user_id
             WHERE q.archived_at IS NULL AND st.is_closed = 0 AND u.active = 1
               AND TIMESTAMPDIFF(HOUR, q.status_changed_at, NOW()) >= {$interval}"
        );
        $statement->execute();
        $generated += $statement->rowCount();

        $this->pdo->exec(
            "UPDATE quotes q
             JOIN statuses st ON st.id = q.status_id
             SET q.next_followup_at = TIMESTAMPADD(
                 HOUR,
                 (FLOOR(TIMESTAMPDIFF(HOUR, q.status_changed_at, NOW()) / {$interval}) + 1) * {$interval},
                 q.status_changed_at
             )
             WHERE q.archived_at IS NULL AND st.is_closed = 0"
        );

        return $generated;
    }

    public function notificationsForUser(int $userId, int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        $statement = $this->pdo->prepare(
            "SELECT n.*, q.practice_code, q.customer_name, q.status_changed_at,
                    srv.name AS service_name, st.name AS status_name, st.color AS status_color,
                    TIMESTAMPDIFF(HOUR, q.status_changed_at, NOW()) AS stale_hours
             FROM operator_notifications n
             JOIN quotes q ON q.id = n.quote_id
             JOIN services srv ON srv.id = q.service_id
             JOIN statuses st ON st.id = q.status_id
             WHERE n.user_id = :user_id
               AND n.acknowledged_at IS NULL AND n.resolved_at IS NULL
               AND q.archived_at IS NULL AND st.is_closed = 0
               AND q.responsible_user_id = n.user_id
               AND (
                    (n.notification_type IN ('deadline_12h', 'deadline_24h') AND q.date_sent IS NULL)
                    OR (n.notification_type = 'stale_3d'
                        AND q.status_id = n.status_id_snapshot
                        AND q.status_changed_at = n.status_changed_at_snapshot)
               )
             ORDER BY n.due_at ASC, n.id ASC
             LIMIT :limit"
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function acknowledgeNotification(int $notificationId, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE operator_notifications
             SET acknowledged_at = NOW(), updated_at = NOW()
             WHERE id = :id AND user_id = :user_id
               AND acknowledged_at IS NULL AND resolved_at IS NULL'
        );
        $statement->execute(['id' => $notificationId, 'user_id' => $userId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Notifica non trovata o già gestita.');
        }
    }

    public function dispatchNotificationEmails(): array
    {
        $mailConfig = (array) config('mail', []);
        $result = [
            'enabled' => (bool) ($mailConfig['enabled'] ?? true),
            'transport' => 'smtp',
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];
        if (!$result['enabled']) {
            return $result;
        }

        $mailer = new SmtpMailer($mailConfig);
        $bcc = (array) ($mailConfig['bcc'] ?? []);

        $statement = $this->pdo->query(
            "SELECT n.*, q.id AS quote_id, q.practice_code, q.customer_name,
                    u.email AS operator_email,
                    TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS display_name,
                    st.name AS status_name
             FROM operator_notifications n
             JOIN quotes q ON q.id = n.quote_id
             JOIN users u ON u.id = n.user_id
             JOIN statuses st ON st.id = q.status_id
             WHERE n.resolved_at IS NULL AND n.email_sent_at IS NULL AND u.active = 1
               AND q.archived_at IS NULL AND st.is_closed = 0
               AND q.responsible_user_id = n.user_id
               AND (
                    (n.notification_type IN ('deadline_12h', 'deadline_24h') AND q.date_sent IS NULL)
                    OR (n.notification_type = 'stale_3d'
                        AND q.status_id = n.status_id_snapshot
                        AND q.status_changed_at = n.status_changed_at_snapshot)
               )
             ORDER BY n.due_at ASC, n.id ASC"
        );

        foreach ($statement->fetchAll() as $notification) {
            $to = trim((string) $notification['operator_email']);
            if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
                $this->pdo->prepare('UPDATE operator_notifications SET email_error = :error WHERE id = :id')
                    ->execute(['error' => 'Email operatore assente o non valida.', 'id' => $notification['id']]);
                $result['skipped']++;
                continue;
            }

            [$subjectPrefix, $message] = match ($notification['notification_type']) {
                'deadline_12h' => ['Primo alert scadenza', 'Sono trascorse 12 ore dall’inserimento e il preventivo non risulta ancora inviato.'],
                'deadline_24h' => ['Secondo alert scadenza', 'Sono trascorse 24 ore dall’inserimento: la scadenza di invio è stata superata.'],
                default => ['Follow-up automatico', 'Il preventivo è rimasto senza avanzamento di stato per altri 3 giorni.'],
            };
            $subject = $subjectPrefix . ': ' . $notification['practice_code'];
            $detailUrl = url('index.php', ['page' => 'quote_view', 'id' => $notification['quote_id']]);
            $body = "Ciao {$notification['display_name']},\n\n{$message}\n\n"
                . "Pratica: {$notification['practice_code']}\n"
                . "Cliente: {$notification['customer_name']}\n"
                . "Stato: {$notification['status_name']}\n\n"
                . "Apri la pratica: {$detailUrl}\n";
            try {
                $mailer->send($to, $bcc, $subject, $body);
                $this->pdo->prepare('UPDATE operator_notifications SET email_sent_at = NOW(), email_error = NULL, updated_at = NOW() WHERE id = :id')
                    ->execute(['id' => $notification['id']]);
                $result['sent']++;
            } catch (Throwable $exception) {
                $this->pdo->prepare('UPDATE operator_notifications SET email_error = :error, updated_at = NOW() WHERE id = :id')
                    ->execute(['error' => mb_substr($exception->getMessage(), 0, 500), 'id' => $notification['id']]);
                $result['failed']++;
            }
        }

        return $result;
    }

    public function followups(int $userId, int $limit = 200): array
    {
        $limit = max(1, min(200, $limit));
        $statement = $this->pdo->prepare(
            "SELECT n.*, q.practice_code, q.customer_name, q.phone, q.email,
                    TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS responsible_name,
                    st.name AS status_name, st.color AS status_color
             FROM operator_notifications n
             JOIN quotes q ON q.id = n.quote_id
             JOIN users u ON u.id = q.responsible_user_id
             JOIN statuses st ON st.id = q.status_id
             WHERE n.user_id = :user_id AND n.notification_type = 'stale_3d'
               AND n.acknowledged_at IS NULL AND n.resolved_at IS NULL
               AND q.archived_at IS NULL AND st.is_closed = 0
               AND q.responsible_user_id = n.user_id
               AND q.status_id = n.status_id_snapshot
               AND q.status_changed_at = n.status_changed_at_snapshot
             ORDER BY n.due_at ASC LIMIT :limit"
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function archive(int $quoteId, int $actorId): void
    {
        $this->pdo->prepare('UPDATE quotes SET archived_at = NOW() WHERE id = :id')->execute(['id' => $quoteId]);
        $this->resolveNotifications($quoteId);
        $this->addActivity($quoteId, $actorId, 'archive', 'Pratica archiviata.');
    }

    public function addMasterItem(string $type, string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Il nome non può essere vuoto.');
        }
        $allowed = ['services', 'channels'];
        if (!in_array($type, $allowed, true)) {
            throw new InvalidArgumentException('Tipo di elenco non valido.');
        }
        $statement = $this->pdo->prepare("INSERT INTO {$type} (name, sort_order, active) VALUES (:name, 999, 1)");
        $statement->execute(['name' => mb_substr($name, 0, 120)]);
    }

    public function toggleMasterItem(string $type, int $id): void
    {
        $allowed = ['services', 'channels', 'priorities'];
        if (!in_array($type, $allowed, true)) {
            throw new InvalidArgumentException('Tipo di elenco non valido.');
        }
        $this->pdo->prepare("UPDATE {$type} SET active = 1 - active WHERE id = :id")->execute(['id' => $id]);
    }

    private function normalize(array $input, int $actorId): array
    {
        return [
            'request_date' => $input['request_date'],
            'request_time' => $this->nullable($input['request_time'] ?? null),
            'customer_name' => mb_substr(trim((string) $input['customer_name']), 0, 180),
            'customer_contact' => $this->nullable($input['customer_contact'] ?? null, 180),
            'phone' => $this->nullable($input['phone'] ?? null, 50),
            'email' => $this->nullable($input['email'] ?? null, 180),
            'channel_id' => $this->nullableInt($input['channel_id'] ?? null),
            'service_id' => (int) $input['service_id'],
            'request_description' => $this->nullable($input['request_description'] ?? null, 10000),
            'received_by_user_id' => $this->nullableInt($input['received_by_user_id'] ?? null) ?: $actorId,
            'responsible_user_id' => (int) $input['responsible_user_id'],
            'priority_id' => (int) $input['priority_id'],
            'status_id' => (int) $input['status_id'],
            'date_sent' => $this->dateTime($input['date_sent'] ?? null),
            'estimated_value' => max(0, (float) str_replace(',', '.', (string) ($input['estimated_value'] ?? 0))),
            'probability' => max(0, min(100, (int) ($input['probability'] ?? 0))),
            'outcome_id' => $this->nullableInt($input['outcome_id'] ?? null),
            'loss_notes' => $this->nullable($input['loss_notes'] ?? null, 10000),
            'external_link' => $this->nullable($input['external_link'] ?? null, 500),
            'created_by_user_id' => $actorId,
        ];
    }

    private function baseSelect(): string
    {
        return "SELECT q.*, srv.name AS service_name, ch.name AS channel_name,
                    p.name AS priority_name, p.color AS priority_color,
                    st.code AS status_code, st.name AS status_name, st.color AS status_color, st.is_closed,
                    outc.name AS outcome_name,
                    TRIM(CONCAT(responsible.first_name, ' ', responsible.last_name)) AS responsible_name,
                    TRIM(CONCAT(receiver.first_name, ' ', receiver.last_name)) AS received_by_name,
                    DATEDIFF(COALESCE(q.date_sent, NOW()), q.request_date) AS days_open,
                    q.estimated_value * q.probability / 100 AS weighted_value,
                    CASE
                        WHEN st.is_closed = 1 THEN 'CHIUSO'
                        WHEN COALESCE(q.date_sent, NOW()) <= q.quote_deadline THEN 'NEI TEMPI'
                        WHEN COALESCE(q.date_sent, NOW()) < DATE_ADD(q.quote_deadline, INTERVAL 24 HOUR) THEN 'SCADUTO_24'
                        ELSE 'SCADUTO_48'
                    END AS traffic_light
             FROM quotes q
             JOIN services srv ON srv.id = q.service_id
             LEFT JOIN channels ch ON ch.id = q.channel_id
             JOIN priorities p ON p.id = q.priority_id
             JOIN statuses st ON st.id = q.status_id
             LEFT JOIN outcomes outc ON outc.id = q.outcome_id
             JOIN users responsible ON responsible.id = q.responsible_user_id
             LEFT JOIN users receiver ON receiver.id = q.received_by_user_id";
    }

    private function quoteFilter(array $filters): array
    {
        $conditions = [];
        $params = [];
        $view = $filters['view'] ?? 'active';
        if ($view === 'open') {
            $conditions[] = 'st.is_closed = 0 AND q.archived_at IS NULL';
        } elseif ($view === 'closed') {
            $conditions[] = 'st.is_closed = 1 AND q.archived_at IS NULL';
        } elseif ($view === 'archived') {
            $conditions[] = 'q.archived_at IS NOT NULL';
        } else {
            $conditions[] = 'q.archived_at IS NULL';
        }

        if (($filters['q'] ?? '') !== '') {
            $search = '%' . trim((string) $filters['q']) . '%';
            $conditions[] = '(q.practice_code LIKE :search_practice
                OR q.customer_name LIKE :search_customer
                OR q.customer_contact LIKE :search_contact
                OR q.request_description LIKE :search_description)';
            $params['search_practice'] = $search;
            $params['search_customer'] = $search;
            $params['search_contact'] = $search;
            $params['search_description'] = $search;
        }
        foreach (['responsible_user_id' => 'q.responsible_user_id', 'status_id' => 'q.status_id', 'priority_id' => 'q.priority_id'] as $key => $column) {
            if ((int) ($filters[$key] ?? 0) > 0) {
                $conditions[] = $column . ' = :' . $key;
                $params[$key] = (int) $filters[$key];
            }
        }
        $deadline = $filters['deadline'] ?? '';
        if ($deadline === 'overdue') {
            $conditions[] = 'st.is_closed = 0 AND q.date_sent IS NULL AND q.quote_deadline < NOW()';
        } elseif ($deadline === 'today') {
            $conditions[] = 'st.is_closed = 0 AND q.date_sent IS NULL AND DATE(q.quote_deadline) = CURDATE()';
        } elseif ($deadline === 'week') {
            $conditions[] = 'st.is_closed = 0 AND q.date_sent IS NULL AND q.quote_deadline >= NOW() AND q.quote_deadline < DATE_ADD(CURDATE(), INTERVAL 7 DAY)';
        }

        return [' WHERE ' . implode(' AND ', $conditions), $params];
    }

    private function rawQuote(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM quotes WHERE id = :id');
        $statement->execute(['id' => $id]);
        return $statement->fetch() ?: null;
    }

    private function resolveNotifications(int $quoteId, ?array $types = null): void
    {
        $sql = 'UPDATE operator_notifications
                SET resolved_at = NOW(), updated_at = NOW()
                WHERE quote_id = :quote_id AND resolved_at IS NULL';
        $params = ['quote_id' => $quoteId];
        if ($types !== null && $types !== []) {
            $placeholders = [];
            foreach (array_values($types) as $index => $type) {
                $key = 'type_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $type;
            }
            $sql .= ' AND notification_type IN (' . implode(', ', $placeholders) . ')';
        }
        $this->pdo->prepare($sql)->execute($params);
    }

    private function addActivity(
        int $quoteId,
        int $actorId,
        string $type,
        string $note,
        ?int $oldStatusId = null,
        ?int $newStatusId = null
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO quote_activities (quote_id, user_id, activity_type, note, old_status_id, new_status_id)
             VALUES (:quote_id, :user_id, :activity_type, :note, :old_status_id, :new_status_id)'
        );
        $statement->execute([
            'quote_id' => $quoteId,
            'user_id' => $actorId,
            'activity_type' => $type,
            'note' => mb_substr($note, 0, 10000),
            'old_status_id' => $oldStatusId,
            'new_status_id' => $newStatusId,
        ]);
    }

    private function isClosedStatus(int $statusId): bool
    {
        $statement = $this->pdo->prepare('SELECT is_closed FROM statuses WHERE id = :id');
        $statement->execute(['id' => $statusId]);
        return (bool) $statement->fetchColumn();
    }

    private function isSentStatus(int $statusId): bool
    {
        $statement = $this->pdo->prepare("SELECT code = 'SENT' FROM statuses WHERE id = :id");
        $statement->execute(['id' => $statusId]);
        return (bool) $statement->fetchColumn();
    }

    private function nullable(mixed $value, int $maxLength = 255): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = (int) $value;
        return $value > 0 ? $value : null;
    }

    private function dateTime(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        return (new DateTimeImmutable((string) $value))->format('Y-m-d H:i:s');
    }

    private function fetchAll(string $sql): array
    {
        return $this->pdo->query($sql)->fetchAll();
    }

    private function fetchOne(string $sql): array
    {
        return $this->pdo->query($sql)->fetch() ?: [];
    }

    private function scalar(string $sql): mixed
    {
        return $this->pdo->query($sql)->fetchColumn();
    }
}
