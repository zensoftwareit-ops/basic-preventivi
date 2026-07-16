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
            'users' => $this->fetchAll('SELECT id, username, display_name, role, active FROM users' . $active . ' ORDER BY display_name'),
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
        if (($input['quote_deadline'] ?? '') === '') {
            $errors['quote_deadline'] = 'Indica la scadenza del preventivo.';
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
                    :priority_id, :status_id, :quote_deadline, :date_sent, :estimated_value, :probability,
                    NOW(), :next_followup_at, :outcome_id, :loss_notes, :external_link, NOW(), :created_by_user_id
                 )'
            );
            $statement->execute($data);
            $id = (int) $this->pdo->lastInsertId();
            $year = (new DateTimeImmutable($data['request_date']))->format('Y');
            $code = sprintf('BAS-%s-%06d', $year, $id);
            $this->pdo->prepare('UPDATE quotes SET practice_code = :code WHERE id = :id')
                ->execute(['code' => $code, 'id' => $id]);

            $this->addActivity($id, $actorId, 'created', 'Pratica creata.');
            if ($data['date_sent']) {
                $this->scheduleStandardFollowups($id, $data['date_sent'], $actorId);
            } elseif ($data['next_followup_at']) {
                $this->scheduleManualFollowup($id, $data['next_followup_at'], $actorId);
            }
            if ($this->isClosedStatus((int) $data['status_id'])) {
                $this->pdo->prepare("UPDATE followups SET status = 'skipped' WHERE quote_id = :id AND status = 'pending'")
                    ->execute(['id' => $id]);
            }
            $this->syncNextFollowup($id);
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
        unset($data['created_by_user_id']);
        $data['id'] = $id;
        $data['status_id_check'] = $data['status_id'];

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'UPDATE quotes SET
                    request_date = :request_date, request_time = :request_time,
                    customer_name = :customer_name, customer_contact = :customer_contact,
                    phone = :phone, email = :email, channel_id = :channel_id, service_id = :service_id,
                    request_description = :request_description, received_by_user_id = :received_by_user_id,
                    responsible_user_id = :responsible_user_id, priority_id = :priority_id,
                    status_changed_at = IF(status_id <> :status_id_check, NOW(), status_changed_at),
                    status_id = :status_id, quote_deadline = :quote_deadline, date_sent = :date_sent,
                    estimated_value = :estimated_value, probability = :probability,
                    last_update_at = NOW(), next_followup_at = :next_followup_at,
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
            if (
                (int) $previous['status_id'] !== (int) $data['status_id']
                || (int) $previous['responsible_user_id'] !== (int) $data['responsible_user_id']
            ) {
                $this->resolveReminders($id);
            }
            $note = trim((string) ($input['activity_note'] ?? ''));
            if ($note !== '') {
                $this->addActivity($id, $actorId, 'note', $note);
            }
            if (!$previous['date_sent'] && $data['date_sent']) {
                $this->scheduleStandardFollowups($id, $data['date_sent'], $actorId);
            } elseif (!$data['date_sent'] && $data['next_followup_at'] && $data['next_followup_at'] !== $previous['next_followup_at']) {
                $this->scheduleManualFollowup($id, $data['next_followup_at'], $actorId);
            }

            if ($this->isClosedStatus((int) $data['status_id'])) {
                $this->pdo->prepare("UPDATE followups SET status = 'skipped' WHERE quote_id = :id AND status = 'pending'")
                    ->execute(['id' => $id]);
            }
            $this->syncNextFollowup($id);
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
            'SELECT a.*, u.display_name, old_status.name AS old_status_name, new_status.name AS new_status_name
             FROM quote_activities a
             LEFT JOIN users u ON u.id = a.user_id
             LEFT JOIN statuses old_status ON old_status.id = a.old_status_id
             LEFT JOIN statuses new_status ON new_status.id = a.new_status_id
             WHERE a.quote_id = :id ORDER BY a.created_at DESC, a.id DESC'
        );
        $activity->execute(['id' => $id]);
        $quote['activities'] = $activity->fetchAll();

        $followups = $this->pdo->prepare(
            'SELECT f.*, creator.display_name AS creator_name, completer.display_name AS completer_name
             FROM followups f
             LEFT JOIN users creator ON creator.id = f.created_by_user_id
             LEFT JOIN users completer ON completer.id = f.completed_by_user_id
             WHERE f.quote_id = :id ORDER BY f.due_at, f.id'
        );
        $followups->execute(['id' => $id]);
        $quote['followups'] = $followups->fetchAll();

        return $quote;
    }

    public function listQuotes(array $filters, int $page = 1, int $perPage = 25): array
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
            $conditions[] = '(q.practice_code LIKE :search OR q.customer_name LIKE :search OR q.customer_contact LIKE :search OR q.request_description LIKE :search)';
            $params['search'] = '%' . trim((string) $filters['q']) . '%';
        }
        foreach (['responsible_user_id' => 'q.responsible_user_id', 'status_id' => 'q.status_id', 'priority_id' => 'q.priority_id'] as $key => $column) {
            if ((int) ($filters[$key] ?? 0) > 0) {
                $conditions[] = $column . ' = :' . $key;
                $params[$key] = (int) $filters[$key];
            }
        }
        $deadline = $filters['deadline'] ?? '';
        if ($deadline === 'overdue') {
            $conditions[] = 'st.is_closed = 0 AND q.quote_deadline < NOW()';
        } elseif ($deadline === 'today') {
            $conditions[] = 'st.is_closed = 0 AND DATE(q.quote_deadline) = CURDATE()';
        } elseif ($deadline === 'week') {
            $conditions[] = 'st.is_closed = 0 AND q.quote_deadline >= NOW() AND q.quote_deadline < DATE_ADD(CURDATE(), INTERVAL 7 DAY)';
        }

        $where = ' WHERE ' . implode(' AND ', $conditions);
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

    public function dashboard(int $userId): array
    {
        $this->generateStaleStatusReminders();
        $metrics = $this->fetchOne(
            "SELECT
                SUM(CASE WHEN st.is_closed = 0 THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN st.is_closed = 0 AND q.quote_deadline < NOW() THEN 1 ELSE 0 END) AS overdue_count,
                SUM(CASE WHEN st.is_closed = 0 AND DATE(q.quote_deadline) = CURDATE() THEN 1 ELSE 0 END) AS due_today_count,
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
            "SELECT u.id, u.display_name,
                SUM(CASE WHEN st.is_closed = 0 AND q.archived_at IS NULL THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN st.is_closed = 0 AND q.archived_at IS NULL AND q.quote_deadline < NOW() THEN 1 ELSE 0 END) AS overdue_count,
                COALESCE(SUM(CASE WHEN st.is_closed = 0 AND q.archived_at IS NULL THEN q.estimated_value ELSE 0 END), 0) AS open_value
             FROM users u
             LEFT JOIN quotes q ON q.responsible_user_id = u.id
             LEFT JOIN statuses st ON st.id = q.status_id
             WHERE u.active = 1
             GROUP BY u.id, u.display_name
             ORDER BY open_count DESC, u.display_name"
        );

        $followups = $this->followups('due', 8);
        $reminders = $this->remindersForUser($userId, 8);
        $recent = $this->fetchAll($this->baseSelect() . ' WHERE q.archived_at IS NULL ORDER BY q.created_at DESC LIMIT 8');

        return compact('metrics', 'workload', 'followups', 'reminders', 'recent');
    }

    public function generateStaleStatusReminders(): int
    {
        $hours = max(1, min(8760, (int) config('reminders.stale_after_hours', 72)));
        $statement = $this->pdo->prepare(
            "INSERT IGNORE INTO status_reminders (
                quote_id, user_id, status_id, status_changed_at_snapshot, triggered_at
             )
             SELECT q.id, q.responsible_user_id, q.status_id, q.status_changed_at, NOW()
             FROM quotes q
             JOIN statuses st ON st.id = q.status_id
             JOIN users u ON u.id = q.responsible_user_id
             WHERE q.archived_at IS NULL
               AND st.is_closed = 0
               AND u.active = 1
               AND q.status_changed_at <= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)"
        );
        $statement->execute();
        return $statement->rowCount();
    }

    public function remindersForUser(int $userId, int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        $statement = $this->pdo->prepare(
            "SELECT r.*, q.practice_code, q.customer_name, q.status_changed_at,
                    srv.name AS service_name, st.name AS status_name, st.color AS status_color,
                    TIMESTAMPDIFF(HOUR, q.status_changed_at, NOW()) AS stale_hours
             FROM status_reminders r
             JOIN quotes q ON q.id = r.quote_id
             JOIN services srv ON srv.id = q.service_id
             JOIN statuses st ON st.id = q.status_id
             WHERE r.user_id = :user_id
               AND r.acknowledged_at IS NULL
               AND r.resolved_at IS NULL
               AND q.archived_at IS NULL
               AND q.responsible_user_id = r.user_id
               AND q.status_id = r.status_id
               AND q.status_changed_at = r.status_changed_at_snapshot
             ORDER BY r.triggered_at ASC
             LIMIT :limit"
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function acknowledgeReminder(int $reminderId, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE status_reminders
             SET acknowledged_at = NOW()
             WHERE id = :id AND user_id = :user_id
               AND acknowledged_at IS NULL AND resolved_at IS NULL'
        );
        $statement->execute(['id' => $reminderId, 'user_id' => $userId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Reminder non trovato o già gestito.');
        }
    }

    public function dispatchReminderEmails(): array
    {
        $result = ['enabled' => (bool) config('reminders.email_enabled', false), 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        if (!$result['enabled']) {
            return $result;
        }

        $emails = (array) config('reminders.operator_emails', []);
        $from = str_replace(["\r", "\n"], '', trim((string) config('reminders.email_from', '')));
        $statement = $this->pdo->query(
            "SELECT r.id, q.id AS quote_id, q.practice_code, q.customer_name, q.status_changed_at,
                    u.username, u.display_name, st.name AS status_name
             FROM status_reminders r
             JOIN quotes q ON q.id = r.quote_id
             JOIN users u ON u.id = r.user_id
             JOIN statuses st ON st.id = r.status_id
             WHERE r.acknowledged_at IS NULL AND r.resolved_at IS NULL
               AND r.email_sent_at IS NULL
               AND q.archived_at IS NULL
               AND q.responsible_user_id = r.user_id
               AND q.status_id = r.status_id
               AND q.status_changed_at = r.status_changed_at_snapshot
             ORDER BY r.triggered_at ASC"
        );

        foreach ($statement->fetchAll() as $reminder) {
            $username = mb_strtolower((string) $reminder['username']);
            $to = trim((string) ($emails[$username] ?? $emails[$reminder['username']] ?? ''));
            if ($to === '' && filter_var($reminder['username'], FILTER_VALIDATE_EMAIL)) {
                $to = (string) $reminder['username'];
            }
            if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
                $result['skipped']++;
                continue;
            }

            $subject = 'Reminder preventivo fermo: ' . $reminder['practice_code'];
            $detailUrl = url('index.php', ['page' => 'quote_view', 'id' => $reminder['quote_id']]);
            $body = "Ciao {$reminder['display_name']},\n\n"
                . "il preventivo {$reminder['practice_code']} per {$reminder['customer_name']} "
                . "è ancora nello stato \"{$reminder['status_name']}\" da oltre 3 giorni.\n\n"
                . "Apri la pratica: {$detailUrl}\n";
            $headers = ['Content-Type: text/plain; charset=UTF-8'];
            if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
                $headers[] = 'From: ' . $from;
            }

            try {
                if (!mail($to, $subject, $body, implode("\r\n", $headers))) {
                    throw new RuntimeException('Il server di posta ha rifiutato il messaggio.');
                }
                $this->pdo->prepare('UPDATE status_reminders SET email_sent_at = NOW(), email_error = NULL WHERE id = :id')
                    ->execute(['id' => $reminder['id']]);
                $result['sent']++;
            } catch (Throwable $exception) {
                $this->pdo->prepare('UPDATE status_reminders SET email_error = :error WHERE id = :id')
                    ->execute(['error' => mb_substr($exception->getMessage(), 0, 500), 'id' => $reminder['id']]);
                $result['failed']++;
            }
        }

        return $result;
    }

    public function followups(string $view = 'due', int $limit = 200): array
    {
        $condition = match ($view) {
            'today' => 'DATE(f.due_at) = CURDATE()',
            'upcoming' => 'f.due_at > NOW()',
            'all' => '1 = 1',
            default => 'f.due_at <= NOW()',
        };
        $statement = $this->pdo->prepare(
            "SELECT f.*, q.practice_code, q.customer_name, q.phone, q.email,
                    u.display_name AS responsible_name, st.name AS status_name, st.color AS status_color
             FROM followups f
             JOIN quotes q ON q.id = f.quote_id
             JOIN users u ON u.id = q.responsible_user_id
             JOIN statuses st ON st.id = q.status_id
             WHERE f.status = 'pending' AND q.archived_at IS NULL AND {$condition}
             ORDER BY f.due_at ASC LIMIT :limit"
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function completeFollowup(int $followupId, int $actorId, string $note = ''): void
    {
        $statement = $this->pdo->prepare("SELECT quote_id FROM followups WHERE id = :id AND status = 'pending'");
        $statement->execute(['id' => $followupId]);
        $quoteId = (int) $statement->fetchColumn();
        if ($quoteId < 1) {
            throw new RuntimeException('Follow-up non trovato o già completato.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                "UPDATE followups SET status = 'done', completed_at = NOW(), completed_by_user_id = :user_id, notes = :notes WHERE id = :id"
            )->execute(['user_id' => $actorId, 'notes' => trim($note) ?: null, 'id' => $followupId]);
            $this->addActivity($quoteId, $actorId, 'followup', trim($note) ?: 'Follow-up completato.');
            $this->syncNextFollowup($quoteId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function postponeFollowup(int $followupId, int $days, int $actorId): void
    {
        $days = max(1, min(30, $days));
        $statement = $this->pdo->prepare("SELECT quote_id, due_at FROM followups WHERE id = :id AND status = 'pending'");
        $statement->execute(['id' => $followupId]);
        $followup = $statement->fetch();
        if (!$followup) {
            throw new RuntimeException('Follow-up non trovato o già completato.');
        }
        $dueAt = (new DateTimeImmutable($followup['due_at']))->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
        $this->pdo->prepare('UPDATE followups SET due_at = :due_at WHERE id = :id')
            ->execute(['due_at' => $dueAt, 'id' => $followupId]);
        $this->addActivity((int) $followup['quote_id'], $actorId, 'followup', "Follow-up posticipato di {$days} giorni.");
        $this->syncNextFollowup((int) $followup['quote_id']);
    }

    public function archive(int $quoteId, int $actorId): void
    {
        $this->pdo->prepare('UPDATE quotes SET archived_at = NOW() WHERE id = :id')->execute(['id' => $quoteId]);
        $this->resolveReminders($quoteId);
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
            'quote_deadline' => $this->dateTime($input['quote_deadline']),
            'date_sent' => $this->dateTime($input['date_sent'] ?? null),
            'estimated_value' => max(0, (float) str_replace(',', '.', (string) ($input['estimated_value'] ?? 0))),
            'probability' => max(0, min(100, (int) ($input['probability'] ?? 0))),
            'next_followup_at' => $this->dateTime($input['next_followup_at'] ?? null),
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
                    responsible.display_name AS responsible_name, receiver.display_name AS received_by_name,
                    DATEDIFF(COALESCE(q.date_sent, NOW()), q.request_date) AS days_open,
                    q.estimated_value * q.probability / 100 AS weighted_value,
                    CASE
                        WHEN st.is_closed = 1 THEN 'CHIUSO'
                        WHEN q.quote_deadline < NOW() THEN 'SCADUTO'
                        WHEN DATE(q.quote_deadline) = CURDATE() THEN 'OGGI'
                        ELSE 'NEI TEMPI'
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

    private function rawQuote(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM quotes WHERE id = :id');
        $statement->execute(['id' => $id]);
        return $statement->fetch() ?: null;
    }

    private function scheduleStandardFollowups(int $quoteId, string $dateSent, int $actorId): void
    {
        $sent = new DateTimeImmutable($dateSent);
        $statement = $this->pdo->prepare(
            "INSERT IGNORE INTO followups (quote_id, sequence_number, due_at, status, created_by_user_id)
             VALUES (:quote_id, :sequence_number, :due_at, 'pending', :created_by)"
        );
        foreach ([1 => 3, 2 => 7, 3 => 15] as $sequence => $days) {
            $statement->execute([
                'quote_id' => $quoteId,
                'sequence_number' => $sequence,
                'due_at' => $sent->modify('+' . $days . ' days')->format('Y-m-d H:i:s'),
                'created_by' => $actorId,
            ]);
        }
    }

    private function scheduleManualFollowup(int $quoteId, string $dueAt, int $actorId): void
    {
        $sequence = (int) $this->scalar('SELECT COALESCE(MAX(sequence_number), 99) + 1 FROM followups WHERE quote_id = ' . $quoteId);
        $statement = $this->pdo->prepare(
            "INSERT INTO followups (quote_id, sequence_number, due_at, status, created_by_user_id)
             VALUES (:quote_id, :sequence_number, :due_at, 'pending', :created_by)"
        );
        $statement->execute([
            'quote_id' => $quoteId,
            'sequence_number' => $sequence,
            'due_at' => $dueAt,
            'created_by' => $actorId,
        ]);
    }

    private function syncNextFollowup(int $quoteId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE quotes SET next_followup_at = (
                SELECT MIN(due_at) FROM followups WHERE quote_id = :source_id AND status = 'pending'
             ) WHERE id = :target_id"
        );
        $statement->execute(['source_id' => $quoteId, 'target_id' => $quoteId]);
    }

    private function resolveReminders(int $quoteId): void
    {
        $this->pdo->prepare(
            'UPDATE status_reminders SET resolved_at = NOW() WHERE quote_id = :quote_id AND resolved_at IS NULL'
        )->execute(['quote_id' => $quoteId]);
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
