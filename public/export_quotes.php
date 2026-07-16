<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

Auth::requireLogin();

try {
    $filters = quote_filters($_GET);
    $repository = new QuoteRepository();
    $master = $repository->masterData(true);
    $users = array_column($master['users'], 'display_name', 'id');
    $statuses = array_column($master['statuses'], 'name', 'id');
    $priorities = array_column($master['priorities'], 'name', 'id');
    $views = ['active' => 'Tutti', 'open' => 'Aperti', 'closed' => 'Storico', 'archived' => 'Archiviati'];
    $deadlines = ['overdue' => 'Scaduti', 'today' => 'Oggi', 'week' => 'Prossimi 7 giorni'];
    $summary = ['Vista: ' . ($views[$filters['view']] ?? 'Tutti')];
    if ($filters['q'] !== '') {
        $summary[] = 'Ricerca: ' . $filters['q'];
    }
    if ($filters['responsible_user_id'] > 0) {
        $summary[] = 'Responsabile: ' . ($users[$filters['responsible_user_id']] ?? ('ID ' . $filters['responsible_user_id']));
    }
    if ($filters['status_id'] > 0) {
        $summary[] = 'Stato: ' . ($statuses[$filters['status_id']] ?? ('ID ' . $filters['status_id']));
    }
    if ($filters['priority_id'] > 0) {
        $summary[] = 'Priorità: ' . ($priorities[$filters['priority_id']] ?? ('ID ' . $filters['priority_id']));
    }
    if ($filters['deadline'] !== '') {
        $summary[] = 'Scadenza: ' . ($deadlines[$filters['deadline']] ?? $filters['deadline']);
    }

    (new XlsxExporter())->download($repository->exportQuotes($filters), implode(' | ', $summary));
} catch (Throwable $exception) {
    http_response_code(500);
    render_public_error('Export non riuscito', $exception->getMessage());
}
