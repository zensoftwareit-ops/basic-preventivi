<?php

declare(strict_types=1);

function config(?string $path = null, mixed $default = null): mixed
{
    global $config;
    if ($path === null) {
        return $config;
    }

    $value = $config;
    foreach (explode('.', $path) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }
    return $value;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = '', array $query = []): string
{
    $base = rtrim((string) config('app.url'), '/');
    $target = $base . '/' . ltrim($path, '/');
    return $query === [] ? $target : $target . '?' . http_build_query($query);
}

function redirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $provided = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals(csrf_token(), $provided)) {
        http_response_code(419);
        render_public_error('Sessione scaduta', 'Ricarica la pagina e riprova.');
        exit;
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function pull_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function old(string $key, mixed $fallback = ''): mixed
{
    return $_SESSION['old'][$key] ?? $fallback;
}

function store_old(array $data): void
{
    unset($data['csrf_token']);
    $_SESSION['old'] = $data;
}

function clear_old(): void
{
    unset($_SESSION['old']);
}

function store_errors(array $errors): void
{
    $_SESSION['errors'] = $errors;
}

function pull_errors(): array
{
    $errors = $_SESSION['errors'] ?? [];
    unset($_SESSION['errors']);
    return $errors;
}

function form_value(?array $record, string $key, mixed $default = ''): mixed
{
    if (isset($_SESSION['old']) && array_key_exists($key, $_SESSION['old'])) {
        return $_SESSION['old'][$key];
    }
    return $record[$key] ?? $default;
}

function datetime_input(mixed $value): string
{
    if (!$value) {
        return '';
    }
    try {
        return (new DateTimeImmutable((string) $value))->format('Y-m-d\\TH:i');
    } catch (Throwable) {
        return '';
    }
}

function money(mixed $value): string
{
    return '€ ' . number_format((float) $value, 2, ',', '.');
}

function date_it(?string $value, bool $withTime = false): string
{
    if (!$value) {
        return '—';
    }
    try {
        return (new DateTimeImmutable($value))->format($withTime ? 'd/m/Y H:i' : 'd/m/Y');
    } catch (Throwable) {
        return $value;
    }
}

function quote_filters(array $input): array
{
    $view = (string) ($input['view'] ?? 'active');
    if (!in_array($view, ['active', 'open', 'closed', 'archived'], true)) {
        $view = 'active';
    }
    $deadline = (string) ($input['deadline'] ?? '');
    if (!in_array($deadline, ['', 'overdue', 'today', 'week'], true)) {
        $deadline = '';
    }

    return [
        'view' => $view,
        'q' => mb_substr(trim((string) ($input['q'] ?? '')), 0, 160),
        'responsible_user_id' => max(0, (int) ($input['responsible_user_id'] ?? 0)),
        'status_id' => max(0, (int) ($input['status_id'] ?? 0)),
        'priority_id' => max(0, (int) ($input['priority_id'] ?? 0)),
        'deadline' => $deadline,
    ];
}

function selected(mixed $value, mixed $expected): string
{
    return (string) $value === (string) $expected ? ' selected' : '';
}

function checked(bool $condition): string
{
    return $condition ? ' checked' : '';
}

function render_public_error(string $title, string $message): void
{
    $appName = e(config('app.name'));
    $titleSafe = e($title);
    $messageSafe = e($message);
    echo <<<HTML
<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#2f7df4"><link rel="manifest" href="manifest.webmanifest"><link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
<title>{$titleSafe} · {$appName}</title><link rel="stylesheet" href="assets/app.css?v=20260717f"></head>
<body class="public-page"><main class="public-card"><div class="brand-mark">B</div><h1>{$titleSafe}</h1><p>{$messageSafe}</p></main></body></html>
HTML;
}
