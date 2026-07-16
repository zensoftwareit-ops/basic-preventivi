<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

Auth::requireLogin();
$repository = new QuoteRepository();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'save_quote':
                $errors = $repository->validate($_POST);
                $id = (int) ($_POST['id'] ?? 0);
                if ($errors !== []) {
                    store_old($_POST);
                    store_errors($errors);
                    flash('error', 'Controlla i campi evidenziati.');
                    redirect(url('index.php', ['page' => $id > 0 ? 'quote_edit' : 'quote_new', 'id' => $id ?: null]));
                }
                if ($id > 0) {
                    $repository->update($id, $_POST, (int) Auth::id());
                    flash('success', 'Pratica aggiornata.');
                } else {
                    $id = $repository->create($_POST, (int) Auth::id());
                    flash('success', 'Richiesta registrata e numero pratica assegnato.');
                }
                clear_old();
                redirect(url('index.php', ['page' => 'quote_view', 'id' => $id]));

            case 'acknowledge_notification':
                $repository->acknowledgeNotification((int) $_POST['id'], (int) Auth::id());
                flash('success', 'Notifica presa in carico.');
                redirect(url('index.php'));

            case 'archive_quote':
                $repository->archive((int) $_POST['id'], (int) Auth::id());
                flash('success', 'Pratica archiviata.');
                redirect(url('index.php', ['page' => 'quotes', 'view' => 'archived']));

            case 'add_master':
            case 'toggle_master':
                if ($action === 'add_master') {
                    $repository->addMasterItem((string) $_POST['type'], (string) $_POST['name']);
                    flash('success', 'Elemento aggiunto.');
                } else {
                    $repository->toggleMasterItem((string) $_POST['type'], (int) $_POST['id']);
                    flash('success', 'Elenco aggiornato.');
                }
                redirect(url('index.php', ['page' => 'settings']));

            default:
                throw new RuntimeException('Azione non riconosciuta.');
        }
    } catch (Throwable $exception) {
        flash('error', 'Operazione non completata: ' . $exception->getMessage());
        redirect(url('index.php'));
    }
}

$page = (string) ($_GET['page'] ?? 'dashboard');
try {
    switch ($page) {
        case 'dashboard':
            render_dashboard($repository->dashboard((int) Auth::id()));
            break;

        case 'quotes':
            $filters = [
                'view' => (string) ($_GET['view'] ?? 'active'),
                'q' => trim((string) ($_GET['q'] ?? '')),
                'responsible_user_id' => (int) ($_GET['responsible_user_id'] ?? 0),
                'status_id' => (int) ($_GET['status_id'] ?? 0),
                'priority_id' => (int) ($_GET['priority_id'] ?? 0),
                'deadline' => (string) ($_GET['deadline'] ?? ''),
            ];
            render_quotes($repository->listQuotes($filters, (int) ($_GET['p'] ?? 1)), $filters, $repository->masterData());
            break;

        case 'quote_new':
            render_quote_form(null, $repository->masterData(), pull_errors());
            break;

        case 'quote_edit':
            $quote = $repository->find((int) ($_GET['id'] ?? 0));
            if (!$quote) {
                throw new RuntimeException('Pratica non trovata.');
            }
            render_quote_form($quote, $repository->masterData(), pull_errors());
            break;

        case 'quote_view':
            $quote = $repository->find((int) ($_GET['id'] ?? 0));
            if (!$quote) {
                throw new RuntimeException('Pratica non trovata.');
            }
            render_quote_detail($quote);
            break;

        case 'followups':
            $repository->generateNotifications();
            render_followups($repository->followups((int) Auth::id()));
            break;

        case 'settings':
            render_settings($repository->masterData(true));
            break;

        default:
            http_response_code(404);
            throw new RuntimeException('Pagina non trovata.');
    }
} catch (Throwable $exception) {
    render_header('Errore');
    echo '<div class="panel empty-state"><strong>' . e($exception->getMessage()) . '</strong><span>Torna alla dashboard e riprova.</span><a class="button primary" href="' . e(url('index.php')) . '">Dashboard</a></div>';
    render_footer();
}
