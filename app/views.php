<?php

declare(strict_types=1);

function render_header(string $title, string $active = 'dashboard'): void
{
    $user = Auth::user();
    $appName = e(config('app.name'));
    $titleSafe = e($title);
    $flashes = pull_flashes();
    $items = [
        'dashboard' => ['Dashboard', ['page' => 'dashboard']],
        'quotes' => ['Preventivi', ['page' => 'quotes']],
        'followups' => ['Follow-up', ['page' => 'followups']],
    ];
    if (Auth::isAdmin()) {
        $items['settings'] = ['Dati base', ['page' => 'settings']];
    }

    echo '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta name="color-scheme" content="light"><title>' . $titleSafe . ' · ' . $appName . '</title>';
    echo '<link rel="stylesheet" href="assets/app.css"></head><body>';
    echo '<aside class="sidebar" id="sidebar"><a class="brand" href="' . e(url('index.php')) . '"><span class="brand-mark">B</span><span><strong>Basic</strong><small>Gestione preventivi</small></span></a>';
    echo '<nav class="nav">';
    foreach ($items as $key => [$label, $query]) {
        $class = $active === $key ? 'nav-link active' : 'nav-link';
        echo '<a class="' . $class . '" href="' . e(url('index.php', $query)) . '"><span>' . e($label) . '</span></a>';
    }
    $roleLabel = Auth::isAdmin() ? 'Amministratore' : 'Operatore';
    echo '</nav><div class="sidebar-footer"><span class="avatar">' . e(mb_strtoupper(mb_substr($user['display_name'] ?? 'U', 0, 1))) . '</span><div><strong>' . e($user['display_name'] ?? '') . '</strong><small>' . e($roleLabel) . '</small></div><a class="logout" href="' . e(url('logout.php')) . '" title="Esci">Esci</a></div></aside>';
    echo '<div class="app-shell"><header class="topbar"><button class="menu-button" type="button" data-menu aria-label="Apri menu">☰</button><div><h1>' . $titleSafe . '</h1><p>' . e((new DateTimeImmutable())->format('d/m/Y')) . '</p></div><a class="button primary compact" href="' . e(url('index.php', ['page' => 'quote_new'])) . '">+ Nuova richiesta</a></header><main class="content">';
    foreach ($flashes as $flash) {
        echo '<div class="alert ' . e($flash['type']) . '">' . e($flash['message']) . '</div>';
    }
}

function render_footer(): void
{
    echo '</main></div><div class="backdrop" data-backdrop></div><script src="assets/app.js"></script></body></html>';
}

function status_badge(string $name, ?string $color): string
{
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $color) ? $color : '#64748b';
    return '<span class="status-pill" style="--pill-color:' . e($color) . '">' . e($name) . '</span>';
}

function traffic_badge(string $traffic): string
{
    $labels = ['SCADUTO_24' => 'Scaduto 24–48 h', 'SCADUTO_48' => 'Scaduto oltre 48 h', 'NEI TEMPI' => 'Nei tempi', 'CHIUSO' => 'Chiuso'];
    $class = strtolower(str_replace(['_', ' '], '-', $traffic));
    return '<span class="traffic ' . e($class) . '"><i></i>' . e($labels[$traffic] ?? $traffic) . '</span>';
}

function render_dashboard(array $data): void
{
    $m = $data['metrics'];
    render_header('Dashboard', 'dashboard');
    echo '<section class="hero-row"><div><span class="eyebrow">Controllo operativo</span><h2>La situazione dei preventivi, adesso.</h2><p>Ritardi, scadenze, carico e valore economico in un’unica vista.</p></div><a class="button secondary" href="' . e(url('index.php', ['page' => 'quotes', 'view' => 'open'])) . '">Vedi pratiche aperte</a></section>';
    if ($data['notifications'] !== []) {
        echo '<section class="reminder-panel"><div class="reminder-heading"><div><span class="eyebrow">Richiede attenzione</span><h3>Alert e follow-up assegnati a te</h3></div><span class="reminder-count">' . count($data['notifications']) . '</span></div><div class="reminder-list">';
        foreach ($data['notifications'] as $notification) {
            [$mark, $detail] = match ($notification['notification_type']) {
                'deadline_12h' => ['12h', 'Primo alert: il preventivo non risulta ancora inviato.'],
                'deadline_24h' => ['24h', 'Secondo alert: la scadenza di invio è stata superata.'],
                default => ['3g', 'Follow-up automatico: stato invariato da altri 3 giorni.'],
            };
            echo '<article class="reminder-item"><span class="reminder-mark">' . e($mark) . '</span><div><a href="' . e(url('index.php', ['page' => 'quote_view', 'id' => $notification['quote_id']])) . '">' . e($notification['practice_code'] . ' · ' . $notification['customer_name']) . '</a><small>' . e($notification['service_name'] . ' · ' . $detail) . '</small></div><form method="post" action="index.php">' . csrf_field() . '<input type="hidden" name="action" value="acknowledge_notification"><input type="hidden" name="id" value="' . (int) $notification['id'] . '"><button class="button warning compact" type="submit">Presa in carico</button></form></article>';
        }
        echo '</div></section>';
    }
    echo '<section class="kpi-grid">';
    kpi('Preventivi aperti', (int) ($m['open_count'] ?? 0), 'blue', ['page' => 'quotes', 'view' => 'open']);
    kpi('Scaduti', (int) ($m['overdue_count'] ?? 0), 'red', ['page' => 'quotes', 'view' => 'open', 'deadline' => 'overdue']);
    kpi('Da inviare oggi', (int) ($m['due_today_count'] ?? 0), 'amber', ['page' => 'quotes', 'view' => 'open', 'deadline' => 'today']);
    kpi('Ricevuti nel mese', (int) ($m['received_month_count'] ?? 0), 'teal', ['page' => 'quotes']);
    kpi('Inviati nel mese', (int) ($m['sent_month_count'] ?? 0), 'blue', ['page' => 'quotes']);
    kpi('Confermati nel mese', (int) ($m['confirmed_month_count'] ?? 0), 'green', ['page' => 'quotes', 'view' => 'closed']);
    kpi('Persi nel mese', (int) ($m['lost_month_count'] ?? 0), 'red', ['page' => 'quotes', 'view' => 'closed']);
    kpi('Valore aperto', money($m['open_value'] ?? 0), 'violet', ['page' => 'quotes', 'view' => 'open']);
    kpi('Tempo medio risposta', ($m['avg_response_hours'] ?? null) === null ? '—' : number_format((float) $m['avg_response_hours'], 1, ',', '.') . ' h', 'amber', ['page' => 'quotes']);
    kpi('Conversione', number_format((float) ($m['conversion_rate'] ?? 0), 1, ',', '.') . '%', 'teal', ['page' => 'quotes', 'view' => 'closed']);
    echo '</section>';

    echo '<section class="two-columns"><article class="panel"><div class="panel-head"><div><span class="eyebrow">Team</span><h3>Carico per responsabile</h3></div></div><div class="table-wrap"><table><thead><tr><th>Responsabile</th><th>Aperti</th><th>Scaduti</th><th>Valore aperto</th></tr></thead><tbody>';
    foreach ($data['workload'] as $row) {
        echo '<tr><td><a class="strong-link" href="' . e(url('index.php', ['page' => 'quotes', 'view' => 'open', 'responsible_user_id' => $row['id']])) . '">' . e($row['display_name']) . '</a></td><td>' . (int) $row['open_count'] . '</td><td><span class="number-danger">' . (int) $row['overdue_count'] . '</span></td><td>' . e(money($row['open_value'])) . '</td></tr>';
    }
    if ($data['workload'] === []) {
        echo '<tr><td colspan="4" class="empty-cell">Nessun responsabile ancora disponibile.</td></tr>';
    }
    echo '</tbody></table></div></article>';

    echo '<article class="panel"><div class="panel-head"><div><span class="eyebrow">Da fare</span><h3>Follow-up urgenti</h3></div><a href="' . e(url('index.php', ['page' => 'followups'])) . '">Vedi tutti</a></div><div class="stack-list">';
    foreach ($data['followups'] as $followup) {
        echo '<a class="stack-item" href="' . e(url('index.php', ['page' => 'quote_view', 'id' => $followup['quote_id']])) . '"><span class="date-tile"><strong>' . e((new DateTimeImmutable($followup['due_at']))->format('d')) . '</strong><small>' . e((new DateTimeImmutable($followup['due_at']))->format('M')) . '</small></span><span><strong>' . e($followup['customer_name']) . '</strong><small>' . e($followup['practice_code'] . ' · ' . $followup['responsible_name']) . '</small></span><span class="chevron">›</span></a>';
    }
    if ($data['followups'] === []) {
        echo '<div class="empty-state compact"><strong>Nessun follow-up urgente</strong><span>La coda è sotto controllo.</span></div>';
    }
    echo '</div></article></section>';

    echo '<section class="panel"><div class="panel-head"><div><span class="eyebrow">Ultimi inserimenti</span><h3>Pratiche recenti</h3></div><a href="' . e(url('index.php', ['page' => 'quotes'])) . '">Registro completo</a></div>';
    render_quotes_table($data['recent'], false);
    echo '</section>';
    render_footer();
}

function kpi(string $label, string|int $value, string $tone, array $query): void
{
    echo '<a class="kpi-card ' . e($tone) . '" href="' . e(url('index.php', $query)) . '"><span>' . e($label) . '</span><strong>' . e($value) . '</strong><small>Apri dettaglio <b>→</b></small></a>';
}

function render_quotes(array $result, array $filters, array $master): void
{
    render_header('Preventivi', 'quotes');
    $views = ['active' => 'Tutti', 'open' => 'Aperti', 'closed' => 'Storico', 'archived' => 'Archiviati'];
    echo '<div class="page-actions"><div class="tabs">';
    foreach ($views as $key => $label) {
        $class = ($filters['view'] ?? 'active') === $key ? 'active' : '';
        echo '<a class="' . $class . '" href="' . e(url('index.php', ['page' => 'quotes', 'view' => $key])) . '">' . e($label) . '</a>';
    }
    $exportFilters = array_filter($filters, static fn (mixed $value): bool => $value !== '' && $value !== 0 && $value !== null);
    echo '</div><div class="page-actions-meta"><span class="result-count">' . (int) $result['total'] . ' pratiche</span><a class="button secondary compact" href="' . e(url('export_quotes.php', $exportFilters)) . '">Esporta XLSX</a></div></div>';
    echo '<form class="filter-panel" method="get" action="index.php"><input type="hidden" name="page" value="quotes"><input type="hidden" name="view" value="' . e($filters['view'] ?? 'active') . '"><label class="search-field"><span>Cerca</span><input type="search" name="q" value="' . e($filters['q'] ?? '') . '" placeholder="Cliente, pratica, descrizione…"></label>';
    echo '<label><span>Responsabile</span><select name="responsible_user_id"><option value="">Tutti</option>';
    foreach ($master['users'] as $item) {
        echo '<option value="' . (int) $item['id'] . '"' . selected($filters['responsible_user_id'] ?? '', $item['id']) . '>' . e($item['display_name']) . '</option>';
    }
    echo '</select></label><label><span>Stato</span><select name="status_id"><option value="">Tutti</option>';
    foreach ($master['statuses'] as $item) {
        echo '<option value="' . (int) $item['id'] . '"' . selected($filters['status_id'] ?? '', $item['id']) . '>' . e($item['name']) . '</option>';
    }
    echo '</select></label><label><span>Scadenza</span><select name="deadline"><option value="">Qualsiasi</option><option value="overdue"' . selected($filters['deadline'] ?? '', 'overdue') . '>Scaduti</option><option value="today"' . selected($filters['deadline'] ?? '', 'today') . '>Oggi</option><option value="week"' . selected($filters['deadline'] ?? '', 'week') . '>Prossimi 7 giorni</option></select></label><button class="button secondary" type="submit">Filtra</button></form>';
    echo '<section class="panel">';
    render_quotes_table($result['items'], true);
    echo '</section>';
    render_pagination($result, $filters);
    render_footer();
}

function render_quotes_table(array $quotes, bool $full): void
{
    echo '<div class="table-wrap"><table class="quotes-table"><thead><tr><th>Pratica</th><th>Cliente / servizio</th><th>Responsabile</th><th>Stato</th><th>Scadenza</th><th>Valore</th><th></th></tr></thead><tbody>';
    foreach ($quotes as $quote) {
        echo '<tr><td><a class="strong-link" href="' . e(url('index.php', ['page' => 'quote_view', 'id' => $quote['id']])) . '">' . e($quote['practice_code'] ?: 'In creazione') . '</a><small>' . e(date_it($quote['request_date'])) . '</small></td><td><strong>' . e($quote['customer_name']) . '</strong><small>' . e($quote['service_name']) . '</small></td><td>' . e($quote['responsible_name']) . '</td><td>' . status_badge($quote['status_name'], $quote['status_color']) . '</td><td>' . traffic_badge($quote['traffic_light']) . '<small>' . e(date_it($quote['quote_deadline'], true)) . '</small></td><td><strong>' . e(money($quote['estimated_value'])) . '</strong><small>Pond. ' . e(money($quote['weighted_value'])) . '</small></td><td><a class="icon-button" href="' . e(url('index.php', ['page' => 'quote_edit', 'id' => $quote['id']])) . '" aria-label="Modifica">Modifica</a></td></tr>';
    }
    if ($quotes === []) {
        echo '<tr><td colspan="7"><div class="empty-state"><strong>Nessuna pratica trovata</strong><span>Modifica i filtri o registra una nuova richiesta.</span></div></td></tr>';
    }
    echo '</tbody></table></div>';
}

function render_pagination(array $result, array $filters): void
{
    if ($result['pages'] <= 1) {
        return;
    }
    echo '<nav class="pagination">';
    for ($page = 1; $page <= $result['pages']; $page++) {
        $query = array_merge(['page' => 'quotes'], $filters, ['p' => $page]);
        echo '<a class="' . ($page === $result['page'] ? 'active' : '') . '" href="' . e(url('index.php', $query)) . '">' . $page . '</a>';
    }
    echo '</nav>';
}

function render_quote_form(?array $quote, array $master, array $errors): void
{
    $editing = $quote !== null;
    $title = $editing ? 'Modifica ' . $quote['practice_code'] : 'Nuova richiesta';
    $defaults = (new QuoteRepository())->defaults();
    render_header($title, 'quotes');
    echo '<form class="quote-form" method="post" action="index.php">' . csrf_field() . '<input type="hidden" name="action" value="save_quote"><input type="hidden" name="id" value="' . (int) ($quote['id'] ?? 0) . '">';
    echo '<div class="form-toolbar"><div><span class="eyebrow">' . ($editing ? e($quote['practice_code']) : 'Inserimento rapido') . '</span><h2>' . e($title) . '</h2><p>I campi con * sono obbligatori. La pratica riceverà un numero automatico.</p></div><div><a class="button ghost" href="' . e($editing ? url('index.php', ['page' => 'quote_view', 'id' => $quote['id']]) : url('index.php', ['page' => 'quotes'])) . '">Annulla</a><button class="button primary" type="submit">Salva pratica</button></div></div>';

    echo '<section class="form-section"><div class="section-intro"><span>01</span><div><h3>Richiesta e cliente</h3><p>Registra la richiesta entro 15 minuti dalla ricezione.</p></div></div><div class="form-grid">';
    input_field('request_date', 'Data richiesta *', 'date', form_value($quote, 'request_date', date('Y-m-d')), $errors);
    input_field('request_time', 'Ora', 'time', form_value($quote, 'request_time', date('H:i')), $errors);
    input_field('customer_name', 'Cliente *', 'text', form_value($quote, 'customer_name'), $errors, 'Ragione sociale o nominativo', 'span-2');
    input_field('customer_contact', 'Referente cliente', 'text', form_value($quote, 'customer_contact'), $errors);
    input_field('phone', 'Telefono', 'tel', form_value($quote, 'phone'), $errors);
    input_field('email', 'Email', 'email', form_value($quote, 'email'), $errors, '', 'span-2');
    select_field('channel_id', 'Canale', $master['channels'], form_value($quote, 'channel_id'), $errors, 'name');
    select_field('service_id', 'Servizio *', $master['services'], form_value($quote, 'service_id'), $errors, 'name', 'span-2');
    textarea_field('request_description', 'Descrizione richiesta', form_value($quote, 'request_description'), $errors, 'Dati essenziali, luogo, durata, esigenze…', 'span-2');
    echo '</div></section>';

    echo '<section class="form-section"><div class="section-intro"><span>02</span><div><h3>Gestione della pratica</h3><p>Assegna sempre responsabile, stato, priorità e scadenza.</p></div></div><div class="form-grid">';
    select_field('received_by_user_id', 'Ricevuto da', $master['users'], form_value($quote, 'received_by_user_id', Auth::id()), $errors, 'display_name');
    select_field('responsible_user_id', 'Responsabile *', $master['users'], form_value($quote, 'responsible_user_id', Auth::id()), $errors, 'display_name');
    select_field('priority_id', 'Priorità *', $master['priorities'], form_value($quote, 'priority_id', $defaults['priority_id']), $errors, 'name');
    select_field('status_id', 'Stato *', $master['statuses'], form_value($quote, 'status_id', $defaults['status_id']), $errors, 'name');
    input_field('quote_deadline', 'Scadenza invio automatica', 'datetime-local', datetime_input(form_value($quote, 'quote_deadline', (new DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s'))), $errors, 'Fissa a 24 ore dall’inserimento', '', 'readonly');
    input_field('date_sent', 'Data invio', 'datetime-local', datetime_input(form_value($quote, 'date_sent')), $errors);
    input_field('next_followup_at', 'Prossimo follow-up automatico', 'datetime-local', datetime_input(form_value($quote, 'next_followup_at', (new DateTimeImmutable('+3 days'))->format('Y-m-d H:i:s'))), $errors, 'Ogni 3 giorni senza cambio stato', '', 'readonly');
    input_field('external_link', 'Link gestionale / file', 'url', form_value($quote, 'external_link'), $errors, 'https://…');
    echo '</div></section>';

    echo '<section class="form-section"><div class="section-intro"><span>03</span><div><h3>Valore ed esito</h3><p>I valori alimentano dashboard, carico e conversione.</p></div></div><div class="form-grid">';
    input_field('estimated_value', 'Valore preventivo €', 'number', form_value($quote, 'estimated_value', 0), $errors, '', '', 'step="0.01" min="0"');
    input_field('probability', 'Probabilità %', 'number', form_value($quote, 'probability', 0), $errors, '', '', 'min="0" max="100"');
    select_field('outcome_id', 'Esito', $master['outcomes'], form_value($quote, 'outcome_id'), $errors, 'name', 'span-2');
    textarea_field('loss_notes', 'Motivo perdita / note', form_value($quote, 'loss_notes'), $errors, 'Motivazione dell’esito o informazioni utili…', 'span-2');
    if ($editing) {
        textarea_field('activity_note', 'Nota aggiornamento', '', $errors, 'Spiega cosa è cambiato; comparirà nella cronologia.', 'span-2');
    }
    echo '</div></section><div class="sticky-save"><span>Ogni modifica aggiorna automaticamente data e cronologia.</span><button class="button primary" type="submit">Salva pratica</button></div></form>';
    clear_old();
    render_footer();
}

function input_field(string $name, string $label, string $type, mixed $value, array $errors, string $placeholder = '', string $class = '', string $attributes = ''): void
{
    echo '<label class="field ' . e($class) . '"><span>' . e($label) . '</span><input type="' . e($type) . '" name="' . e($name) . '" value="' . e($value) . '" placeholder="' . e($placeholder) . '" ' . $attributes . '>';
    if (isset($errors[$name])) {
        echo '<small class="field-error">' . e($errors[$name]) . '</small>';
    }
    echo '</label>';
}

function select_field(string $name, string $label, array $items, mixed $value, array $errors, string $labelKey, string $class = ''): void
{
    echo '<label class="field ' . e($class) . '"><span>' . e($label) . '</span><select name="' . e($name) . '"><option value="">Seleziona…</option>';
    foreach ($items as $item) {
        echo '<option value="' . (int) $item['id'] . '"' . selected($value, $item['id']) . '>' . e($item[$labelKey]) . '</option>';
    }
    echo '</select>';
    if (isset($errors[$name])) {
        echo '<small class="field-error">' . e($errors[$name]) . '</small>';
    }
    echo '</label>';
}

function textarea_field(string $name, string $label, mixed $value, array $errors, string $placeholder = '', string $class = ''): void
{
    echo '<label class="field ' . e($class) . '"><span>' . e($label) . '</span><textarea name="' . e($name) . '" rows="4" placeholder="' . e($placeholder) . '">' . e($value) . '</textarea>';
    if (isset($errors[$name])) {
        echo '<small class="field-error">' . e($errors[$name]) . '</small>';
    }
    echo '</label>';
}

function render_quote_detail(array $quote): void
{
    render_header($quote['practice_code'], 'quotes');
    echo '<div class="detail-head"><div><div class="detail-badges">' . status_badge($quote['status_name'], $quote['status_color']) . traffic_badge($quote['traffic_light']) . '</div><h2>' . e($quote['customer_name']) . '</h2><p>' . e($quote['service_name']) . ' · Responsabile ' . e($quote['responsible_name']) . '</p></div><div class="detail-actions"><a class="button secondary" href="' . e(url('index.php', ['page' => 'quote_edit', 'id' => $quote['id']])) . '">Modifica</a>';
    if (Auth::isAdmin()) {
        echo '<form method="post" action="index.php" data-confirm="Eliminare definitivamente questo preventivo? L’operazione non può essere annullata."><input type="hidden" name="action" value="delete_quote"><input type="hidden" name="id" value="' . (int) $quote['id'] . '">' . csrf_field() . '<button class="button danger" type="submit">Elimina preventivo</button></form>';
    }
    echo '</div></div>';
    echo '<section class="detail-grid"><article class="panel detail-main"><div class="panel-head"><h3>Dati pratica</h3><span>Aggiornata ' . e(date_it($quote['last_update_at'], true)) . '</span></div><dl class="data-list">';
    detail_item('Data richiesta', date_it($quote['request_date']) . ($quote['request_time'] ? ' ' . substr($quote['request_time'], 0, 5) : ''));
    detail_item('Referente cliente', $quote['customer_contact'] ?: '—');
    detail_item('Telefono', $quote['phone'] ?: '—');
    detail_item('Email', $quote['email'] ?: '—');
    detail_item('Canale', $quote['channel_name'] ?: '—');
    detail_item('Ricevuto da', $quote['received_by_name'] ?: '—');
    detail_item('Priorità', $quote['priority_name']);
    detail_item('Scadenza', date_it($quote['quote_deadline'], true));
    detail_item('Data invio', date_it($quote['date_sent'], true));
    detail_item('Prossimo follow-up', date_it($quote['next_followup_at'], true));
    detail_item('Valore', money($quote['estimated_value']));
    detail_item('Valore ponderato', money($quote['weighted_value']) . ' (' . (int) $quote['probability'] . '%)');
    detail_item('Esito', $quote['outcome_name'] ?: '—');
    if ($quote['external_link']) {
        detail_item('Collegamento', '<a href="' . e($quote['external_link']) . '" target="_blank" rel="noopener">Apri file / gestionale ↗</a>', true);
    }
    echo '</dl>';
    if ($quote['request_description']) {
        echo '<div class="text-block"><span>Descrizione richiesta</span><p>' . nl2br(e($quote['request_description'])) . '</p></div>';
    }
    if ($quote['loss_notes']) {
        echo '<div class="text-block muted"><span>Esito / note</span><p>' . nl2br(e($quote['loss_notes'])) . '</p></div>';
    }
    echo '</article><aside class="detail-side"><article class="panel"><div class="panel-head"><h3>Follow-up</h3></div><div class="timeline">';
    foreach ($quote['followups'] as $followup) {
        $followupState = $followup['resolved_at'] ? 'Risolto dal cambio stato' : ($followup['acknowledged_at'] ? 'Preso in carico' : 'Notifica inviata');
        echo '<div class="timeline-item"><i></i><div><strong>Follow-up automatico #' . (int) $followup['sequence_number'] . '</strong><span>' . e(date_it($followup['due_at'], true) . ' · ' . $followupState) . '</span></div></div>';
    }
    if ($quote['followups'] === []) {
        echo '<div class="empty-state compact"><strong>Nessun follow-up</strong><span>Verranno creati dopo l’invio.</span></div>';
    }
    echo '</div></article><article class="panel"><div class="panel-head"><h3>Cronologia</h3></div><div class="timeline">';
    foreach ($quote['activities'] as $activity) {
        $text = $activity['activity_type'] === 'status_change'
            ? ($activity['old_status_name'] . ' → ' . $activity['new_status_name'])
            : $activity['note'];
        echo '<div class="timeline-item"><i></i><div><strong>' . e($text) . '</strong><span>' . e(($activity['display_name'] ?: 'Sistema') . ' · ' . date_it($activity['created_at'], true)) . '</span></div></div>';
    }
    echo '</div></article>';
    if (!$quote['archived_at']) {
        echo '<form method="post" action="index.php" data-confirm="Archiviare questa pratica?"><input type="hidden" name="action" value="archive_quote"><input type="hidden" name="id" value="' . (int) $quote['id'] . '">' . csrf_field() . '<button class="button danger full" type="submit">Archivia pratica</button></form>';
    }
    echo '</aside></section>';
    render_footer();
}

function detail_item(string $label, string $value, bool $raw = false): void
{
    echo '<div><dt>' . e($label) . '</dt><dd>' . ($raw ? $value : e($value)) . '</dd></div>';
}

function render_followups(array $items): void
{
    render_header('Follow-up', 'followups');
    echo '<section class="hero-row"><div><span class="eyebrow">Cadenza fissa</span><h2>Follow-up ogni 3 giorni</h2><p>Le scadenze sono automatiche e ripartono quando cambia lo stato del preventivo.</p></div><span class="result-count">' . count($items) . ' da gestire</span></section><section class="followup-grid">';
    foreach ($items as $item) {
        $late = new DateTimeImmutable($item['due_at']) < new DateTimeImmutable() ? ' late' : '';
        echo '<article class="followup-card' . $late . '"><div class="followup-top"><span class="date-tile"><strong>' . e((new DateTimeImmutable($item['due_at']))->format('d')) . '</strong><small>' . e((new DateTimeImmutable($item['due_at']))->format('M')) . '</small></span><div><a href="' . e(url('index.php', ['page' => 'quote_view', 'id' => $item['quote_id']])) . '">' . e($item['customer_name']) . '</a><small>' . e($item['practice_code'] . ' · Follow-up #' . $item['sequence_number']) . '</small></div>' . status_badge($item['status_name'], $item['status_color']) . '</div><div class="contact-row"><span>' . e($item['phone'] ?: 'Telefono non indicato') . '</span><span>' . e($item['email'] ?: 'Email non indicata') . '</span></div><form method="post" action="index.php">' . csrf_field() . '<input type="hidden" name="action" value="acknowledge_notification"><input type="hidden" name="id" value="' . (int) $item['id'] . '"><button class="button warning compact full" type="submit">Presa in carico</button></form></article>';
    }
    if ($items === []) {
        echo '<div class="panel empty-state"><strong>Nessun follow-up da gestire</strong><span>La prossima notifica verrà generata dopo 3 giorni senza cambio stato.</span></div>';
    }
    echo '</section>';
    render_footer();
}

function render_settings(array $master): void
{
    render_header('Dati base', 'settings');
    echo '<section class="hero-row"><div><span class="eyebrow">Configurazione</span><h2>Liste configurabili</h2><p>Servizi, canali e priorità usati nei menu della scheda preventivo.</p></div></section><section class="settings-grid">';
    foreach (['services' => 'Servizi', 'channels' => 'Canali'] as $type => $label) {
        echo '<article class="panel"><div class="panel-head"><h3>' . e($label) . '</h3></div><form class="inline-add" method="post" action="index.php">' . csrf_field() . '<input type="hidden" name="action" value="add_master"><input type="hidden" name="type" value="' . e($type) . '"><input name="name" placeholder="Nuovo elemento" required><button class="button primary compact" type="submit">Aggiungi</button></form><div class="master-list">';
        foreach ($master[$type] as $item) {
            echo '<div class="master-item"><span class="' . ((int) $item['active'] ? '' : 'inactive') . '">' . e($item['name']) . '</span><form method="post" action="index.php">' . csrf_field() . '<input type="hidden" name="action" value="toggle_master"><input type="hidden" name="type" value="' . e($type) . '"><input type="hidden" name="id" value="' . (int) $item['id'] . '"><button class="text-button" type="submit">' . ((int) $item['active'] ? 'Disattiva' : 'Attiva') . '</button></form></div>';
        }
        echo '</div></article>';
    }
    echo '<article class="panel"><div class="panel-head"><h3>Stati operativi</h3></div><div class="master-list">';
    foreach ($master['statuses'] as $item) {
        echo '<div class="master-item">' . status_badge($item['name'], $item['color']) . '<small>' . ((int) $item['is_closed'] ? 'Stato chiuso' : 'Stato aperto') . '</small></div>';
    }
    echo '</div></article><article class="panel"><div class="panel-head"><h3>Operatori SSO</h3></div><div class="master-list">';
    foreach ($master['users'] as $item) {
        echo '<div class="master-item"><span><strong>' . e($item['display_name']) . '</strong><small>ID ' . (int) $item['id'] . ' · ' . e($item['username']) . ' · ' . e($item['email']) . '</small></span><span class="role-pill">' . e($item['role']) . ' · ' . ((int) $item['active'] ? 'Attivo' : 'Disattivo') . '</span></div>';
    }
    echo '</div></article></section>';
    render_footer();
}
