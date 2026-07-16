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
                    :channel_id, :service_id, :request_d…28485 tokens truncated…u0027);
try {
    Database::connection()->query('SELECT 1');
    echo json_encode(['status' => 'ok', 'time' => date(DATE_ATOM)], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(503);
    echo json_encode(['status' => 'error'], JSON_THROW_ON_ERROR);
}
