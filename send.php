<?php
/**
 * ТСМ ДВ — обработчик формы заявки.
 *
 * Принимает POST (multipart/form-data) из js/main.js, проверяет данные и файлы
 * и отправляет заявку на почту (SMTP Яндекса) и/или в Telegram.
 * Отвечает JSON: {"ok": bool, "message": string, "errors"?: {поле: текст}}.
 *
 * Настройки лежат в config.php (образец — config.example.php).
 * Нужен PHP 7.4+ с расширениями mbstring и openssl, для Telegram ещё curl.
 */

declare(strict_types=1);

ini_set('display_errors', '0'); // предупреждения PHP не должны ломать JSON-ответ
date_default_timezone_set('Asia/Vladivostok');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const PHONE_TEXT = '+7 (914) 338-60-71';
const MSG_SUCCESS = 'Мы свяжемся с вами, чтобы уточнить детали и стоимость.';
const MSG_FAIL = 'Попробуйте ещё раз чуть позже или позвоните нам: ' . PHONE_TEXT . '.';

// Должны совпадать с <option> в index.html
const SERVICES = [
    'Лазерная резка листового металла',
    'Лазерная резка труб',
    'Гибка металла',
    'Изготовление металлоконструкций',
    'Другое',
];

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(int $status, string $message, array $errors = []): void
{
    $payload = ['ok' => false, 'message' => $message];
    if ($errors) {
        $payload['errors'] = $errors;
    }
    respond($status, $payload);
}

/** Текст из POST: только корректный UTF-8, без управляющих символов, с ограничением длины. */
function field(string $key, int $maxLength, bool $multiline = false): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
        return '';
    }
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    if (!$multiline) {
        $value = str_replace(["\n", "\t"], ' ', $value);
    }
    $value = (string) preg_replace('/[^\P{C}\n\t]/u', '', $value);
    return mb_substr(trim($value), 0, $maxLength);
}

/** Делит имя файла на основу и расширение (pathinfo() зависит от локали и портит кириллицу). */
function splitFileName(string $name): array
{
    $dot = mb_strrpos($name, '.');
    if ($dot === false || $dot === 0) {
        return [$name, ''];
    }
    return [mb_substr($name, 0, $dot), mb_strtolower(mb_substr($name, $dot + 1))];
}

/** Имя файла, безопасное для заголовков письма. */
function cleanFileName(string $name): string
{
    if (!mb_check_encoding($name, 'UTF-8')) {
        $name = '';
    }
    $name = (string) preg_replace('/[^\P{C}]|["\\\\\/:*?<>|]/u', '_', $name);
    $name = trim($name, " .\t");
    [$base, $ext] = splitFileName($name);
    $base = mb_substr($base, 0, 90);
    $ext = mb_substr($ext, 0, 10);
    if ($base === '') {
        $base = 'file';
    }
    return $ext === '' ? $base : $base . '.' . $ext;
}

/** Файлы из $_FILES['files'] в виде плоского списка. */
function uploadedFiles(): array
{
    $raw = $_FILES['files'] ?? null;
    if (!is_array($raw) || !isset($raw['name'])) {
        return [];
    }
    $list = [];
    foreach ((array) $raw['name'] as $i => $name) {
        $list[] = [
            'name'  => (string) $name,
            'tmp'   => (string) ((array) $raw['tmp_name'])[$i],
            'size'  => (int) ((array) $raw['size'])[$i],
            'error' => (int) ((array) $raw['error'])[$i],
        ];
    }
    return $list;
}

function formatSize(int $bytes): string
{
    if ($bytes < 1024 * 1024) {
        return max(1, (int) round($bytes / 1024)) . ' КБ';
    }
    return str_replace('.', ',', (string) round($bytes / 1024 / 1024, 1)) . ' МБ';
}

/* ======================================================================
   Почта: минимальный SMTP-клиент (SSL или STARTTLS, AUTH LOGIN)
   ====================================================================== */

/** Кодирование заголовка по RFC 2047 (UTF-8, base64), с переносами. */
function encodeHeader(string $value): string
{
    if (preg_match('/^[\x20-\x7E]*$/', $value) && strpos($value, '=?') === false) {
        return $value;
    }
    $words = [];
    $buffer = '';
    foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
        if (strlen($buffer . $char) > 45) {
            $words[] = '=?UTF-8?B?' . base64_encode($buffer) . '?=';
            $buffer = '';
        }
        $buffer .= $char;
    }
    if ($buffer !== '') {
        $words[] = '=?UTF-8?B?' . base64_encode($buffer) . '?=';
    }
    return implode("\r\n ", $words);
}

final class SmtpMailer
{
    private array $cfg;
    /** @var resource|null */
    private $socket = null;
    private array $log = [];

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    /**
     * @param string[] $recipients
     * @param array<int, string|array{file: string}> $parts  строки пишутся как есть, ['file' => путь] — файл в base64
     */
    public function send(string $from, array $recipients, array $parts): void
    {
        $host = (string) ($this->cfg['host'] ?? '');
        $port = (int) ($this->cfg['port'] ?? 465);
        $secure = (string) ($this->cfg['secure'] ?? 'ssl');
        $timeout = (int) ($this->cfg['timeout'] ?? 20);
        $user = (string) ($this->cfg['username'] ?? '');
        $pass = (string) ($this->cfg['password'] ?? '');
        if ($host === '' || $user === '' || $pass === '') {
            throw new RuntimeException('SMTP: не заданы host, username или password в config.php');
        }

        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $context = stream_context_create(['ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
            'peer_name'        => $host,
        ]]);
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new RuntimeException("SMTP: нет соединения с $remote: $errstr ($errno)");
        }
        $this->socket = $socket;
        stream_set_timeout($socket, $timeout);

        try {
            $ehlo = 'EHLO ' . $this->heloName();
            $this->expect([220]);
            $this->command($ehlo, [250]);
            if ($secure === 'tls') {
                $this->command('STARTTLS', [220]);
                $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                }
                if (stream_socket_enable_crypto($socket, true, $method) !== true) {
                    throw new RuntimeException('SMTP: не удалось включить TLS');
                }
                $this->command($ehlo, [250]);
            }
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($user), [334], true);
            $this->command(base64_encode($pass), [235], true);
            $this->command('MAIL FROM:<' . $from . '>', [250]);
            foreach ($recipients as $rcpt) {
                $this->command('RCPT TO:<' . $rcpt . '>', [250, 251]);
            }
            $this->command('DATA', [354]);
            // Все части письма закодированы в base64, а заголовки собираем сами,
            // поэтому строк, начинающихся с точки, в письме нет и dot-stuffing не нужен.
            foreach ($parts as $part) {
                if (is_array($part)) {
                    $this->writeFileBase64($part['file']);
                } else {
                    $this->write($part);
                }
            }
            $this->write("\r\n.\r\n");
            $this->expect([250]);
            try {
                $this->command('QUIT', [221]);
            } catch (Throwable $e) {
                // Письмо уже принято сервером, ошибка на QUIT не важна
            }
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage() . "\n" . implode("\n", $this->log), 0, $e);
        } finally {
            fclose($socket);
            $this->socket = null;
        }
    }

    private function heloName(): string
    {
        $name = (string) preg_replace('/[^A-Za-z0-9.-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? ''));
        return $name !== '' ? $name : 'localhost';
    }

    private function command(string $line, array $codes, bool $secret = false): void
    {
        $this->log[] = 'C: ' . ($secret ? '***' : $line);
        $this->write($line . "\r\n");
        $this->expect($codes);
    }

    private function write(string $data): void
    {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $n = fwrite($this->socket, substr($data, $written, 65536));
            if ($n === false || $n === 0) {
                throw new RuntimeException('SMTP: ошибка записи в соединение');
            }
            $written += $n;
        }
    }

    /** Файл кодируется в base64 частями, чтобы не держать его целиком в памяти. */
    private function writeFileBase64(string $path): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('SMTP: не удалось прочитать вложение');
        }
        try {
            $buffer = '';
            while (!feof($handle)) {
                $chunk = fread($handle, 57 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('SMTP: ошибка чтения вложения');
                }
                $buffer .= $chunk;
                // 57 байт = ровно одна строка base64 длиной 76 символов
                $usable = strlen($buffer) - strlen($buffer) % 57;
                if ($usable > 0) {
                    $this->write(chunk_split(base64_encode(substr($buffer, 0, $usable)), 76, "\r\n"));
                    $buffer = (string) substr($buffer, $usable);
                }
            }
            if ($buffer !== '') {
                $this->write(chunk_split(base64_encode($buffer), 76, "\r\n"));
            }
        } finally {
            fclose($handle);
        }
    }

    private function expect(array $codes): void
    {
        $response = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $response .= $line;
            // Многострочный ответ: "250-...", последняя строка — "250 ..."
            if (!isset($line[3]) || $line[3] !== '-') {
                break;
            }
        }
        $this->log[] = 'S: ' . trim($response);
        if ($response === '') {
            $meta = stream_get_meta_data($this->socket);
            throw new RuntimeException('SMTP: ' . (!empty($meta['timed_out']) ? 'таймаут ответа' : 'сервер закрыл соединение'));
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('SMTP: неожиданный ответ: ' . trim($response));
        }
    }
}

/**
 * Собирает письмо: заголовки, текст и вложения.
 * @return array<int, string|array{file: string}>
 */
function buildMail(array $mailCfg, array $recipients, string $subject, string $text, string $replyTo, array $files): array
{
    $from = (string) $mailCfg['from'];
    $fromName = (string) ($mailCfg['from_name'] ?? '');
    $domain = substr((string) strrchr($from, '@'), 1) ?: 'localhost';

    $headers = [
        'Date: ' . date('r'),
        'From: ' . ($fromName !== '' ? encodeHeader($fromName) . ' ' : '') . '<' . $from . '>',
        'To: ' . implode(', ', $recipients),
        'Subject: ' . encodeHeader($subject),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
    ];
    if ($replyTo !== '') {
        $headers[] = 'Reply-To: ' . $replyTo;
    }
    $body = chunk_split(base64_encode(str_replace("\n", "\r\n", $text)), 76, "\r\n");

    if (!$files) {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        return [implode("\r\n", $headers) . "\r\n\r\n" . $body];
    }

    $boundary = '=_tsm_' . bin2hex(random_bytes(12));
    $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
    $parts = [
        implode("\r\n", $headers) . "\r\n\r\n"
        . "--$boundary\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . $body,
    ];
    foreach ($files as $file) {
        $encoded = preg_match('/^[\x20-\x7E]*$/', $file['name'])
            ? $file['name']
            : '=?UTF-8?B?' . base64_encode($file['name']) . '?=';
        $parts[] = "--$boundary\r\n"
            . 'Content-Type: application/octet-stream; name="' . $encoded . '"' . "\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: attachment;\r\n"
            . ' filename="' . $encoded . '";' . "\r\n"
            . " filename*=UTF-8''" . rawurlencode($file['name']) . "\r\n\r\n";
        $parts[] = ['file' => $file['tmp']];
    }
    $parts[] = "--$boundary--\r\n";
    return $parts;
}

function sendMail(array $cfg, string $subject, string $text, string $replyTo, array $files): void
{
    $recipients = array_values(array_filter((array) ($cfg['to'] ?? []), 'is_string'));
    if (!$recipients || empty($cfg['from'])) {
        throw new RuntimeException('Почта: не заданы to или from в config.php');
    }
    $mailer = new SmtpMailer((array) ($cfg['smtp'] ?? []));
    $mailer->send((string) $cfg['from'], $recipients, buildMail($cfg, $recipients, $subject, $text, $replyTo, $files));
}

/* ======================================================================
   Telegram (опция, по умолчанию выключена в config.php)
   ====================================================================== */

function telegramRequest(string $token, string $method, array $fields): void
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Telegram: на хостинге нет расширения curl');
    }
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // curl_close() не нужен: с PHP 8 дескриптор закрывается сам, а в 8.5 функция устарела

    if ($body === false) {
        throw new RuntimeException("Telegram ($method): $error");
    }
    $data = json_decode((string) $body, true);
    if ($code !== 200 || empty($data['ok'])) {
        throw new RuntimeException("Telegram ($method): HTTP $code " . ($data['description'] ?? ''));
    }
}

function sendTelegram(array $cfg, string $text, array $files): void
{
    $token = (string) ($cfg['bot_token'] ?? '');
    $chatId = (string) ($cfg['chat_id'] ?? '');
    if ($token === '' || $chatId === '') {
        throw new RuntimeException('Telegram: не заданы bot_token или chat_id в config.php');
    }
    telegramRequest($token, 'sendMessage', [
        'chat_id' => $chatId,
        'text'    => mb_substr($text, 0, 4000),
    ]);
    if (!empty($cfg['send_files'])) {
        foreach ($files as $file) {
            telegramRequest($token, 'sendDocument', [
                'chat_id'  => $chatId,
                'document' => new CURLFile($file['tmp'], 'application/octet-stream', $file['name']),
            ]);
        }
    }
}

/* ======================================================================
   Обработка запроса
   ====================================================================== */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    fail(405, 'Метод не поддерживается.');
}

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    error_log('[send.php] Нет config.php — скопируйте config.example.php и заполните');
    fail(500, 'Форма временно не работает. Позвоните нам: ' . PHONE_TEXT . '.');
}
$config = require $configPath;

$limits = (array) ($config['limits'] ?? []);
$maxFiles = (int) ($limits['max_files'] ?? 10);
$maxTotal = (int) ($limits['max_total_bytes'] ?? 20 * 1024 * 1024);
$allowedExt = array_map('strtolower', (array) ($limits['allowed_ext'] ?? ['dxf', 'step', 'stp', 'iges', 'igs']));
$maxTotalText = formatSize($maxTotal);

// Если запрос больше post_max_size, PHP молча отбрасывает и поля, и файлы
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && !$_POST && !$_FILES) {
    fail(413, "Файлы слишком большие: суммарно не больше $maxTotalText.", ['files' => "Суммарный размер файлов — не больше $maxTotalText."]);
}

// Антиспам 1: поле-ловушка. Боту отвечаем «успехом», чтобы он не подбирал обход.
if (field('website', 200) !== '') {
    respond(200, ['ok' => true, 'message' => MSG_SUCCESS]);
}

// Антиспам 2: время с загрузки страницы (заполняет js/main.js)
$minSeconds = (int) ($config['antispam']['min_seconds'] ?? 3);
$elapsed = (int) ($_POST['t'] ?? -1);
if ($elapsed < $minSeconds) {
    fail(400, 'Не удалось отправить форму. Обновите страницу и попробуйте ещё раз или позвоните нам: ' . PHONE_TEXT . '.');
}

// Поля
$errors = [];

$name = field('name', 100);
if ($name === '') {
    $errors['name'] = 'Укажите имя.';
}

$digits = (string) preg_replace('/\D/', '', field('phone', 40));
if (strlen($digits) === 11 && $digits[0] === '8') {
    $digits = '7' . substr($digits, 1);
} elseif (strlen($digits) === 10) {
    $digits = '7' . $digits;
}
$phone = '';
if (preg_match('/^7(\d{3})(\d{3})(\d{2})(\d{2})$/', $digits, $m)) {
    $phone = "+7 ({$m[1]}) {$m[2]}-{$m[3]}-{$m[4]}";
} else {
    $errors['phone'] = 'Укажите номер телефона полностью.';
}

$email = field('email', 254);
if ($email !== '' && !preg_match('/^[^\s@<>,;"]+@[^\s@<>,;"]+\.[^\s@<>,;"]+$/u', $email)) {
    $errors['email'] = 'Проверьте адрес почты.';
}

$service = field('service', 100);
if (!in_array($service, SERVICES, true)) {
    $service = '';
}

$comment = field('comment', 3000, true);

if (($_POST['consent'] ?? '') !== '1') {
    $errors['consent'] = 'Нужно согласие на обработку персональных данных.';
}

// Файлы
$files = [];
$totalSize = 0;
foreach (uploadedFiles() as $upload) {
    if ($upload['error'] === UPLOAD_ERR_NO_FILE) {
        continue;
    }
    $fileName = cleanFileName($upload['name']);
    if ($upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE) {
        $errors['files'] = "Файл «{$fileName}» слишком большой.";
        break;
    }
    if ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp'])) {
        $errors['files'] = "Не удалось загрузить файл «{$fileName}». Попробуйте ещё раз.";
        break;
    }
    $ext = splitFileName($fileName)[1];
    if (!in_array($ext, $allowedExt, true)) {
        $errors['files'] = "Формат не подходит: {$fileName}. Принимаем: " . mb_strtoupper(implode(', ', $allowedExt)) . '.';
        break;
    }
    $totalSize += $upload['size'];
    $files[] = ['name' => $fileName, 'tmp' => $upload['tmp'], 'size' => $upload['size']];
}
if (!isset($errors['files'])) {
    if (count($files) > $maxFiles) {
        $errors['files'] = "Можно прикрепить не больше $maxFiles файлов.";
    } elseif ($totalSize > $maxTotal) {
        $errors['files'] = "Суммарный размер файлов — не больше $maxTotalText.";
    }
}

if ($errors) {
    fail(422, 'Проверьте поля формы.', $errors);
}

// Текст заявки
$fileLines = [];
foreach ($files as $file) {
    $fileLines[] = '• ' . $file['name'] . ' (' . formatSize($file['size']) . ')';
}
$text = implode("\n", [
    'Новая заявка с сайта',
    '',
    'Имя: ' . $name,
    'Телефон: ' . $phone,
    'E-mail: ' . ($email !== '' ? $email : '—'),
    'Услуга: ' . ($service !== '' ? $service : '—'),
    '',
    'Комментарий:',
    $comment !== '' ? $comment : '—',
    '',
    'Файлы: ' . ($files ? count($files) . "\n" . implode("\n", $fileLines) : 'нет'),
    '',
    'Отправлено: ' . date('d.m.Y H:i') . ' (время Владивостока)',
]);
$subject = 'Заявка с сайта' . ($service !== '' ? ': ' . $service : '') . ' — ' . $name;
// Reply-To только для ASCII-адресов, кириллический адрес останется в тексте письма
$replyTo = ($email !== '' && preg_match('/^[\x21-\x7E]+$/', $email)) ? $email : '';

// Отправка
@set_time_limit(120);
$channels = 0;
$delivered = 0;

if (!empty($config['mail']['enabled'])) {
    $channels++;
    try {
        sendMail((array) $config['mail'], $subject, $text, $replyTo, $files);
        $delivered++;
    } catch (Throwable $e) {
        error_log('[send.php] Почта: ' . $e->getMessage());
    }
}

if (!empty($config['telegram']['enabled'])) {
    $channels++;
    try {
        sendTelegram((array) $config['telegram'], $text, $files);
        $delivered++;
    } catch (Throwable $e) {
        error_log('[send.php] ' . $e->getMessage());
    }
}

if ($channels === 0) {
    error_log('[send.php] В config.php не включён ни один канал (mail / telegram)');
}
if ($delivered === 0) {
    fail(500, MSG_FAIL);
}

respond(200, ['ok' => true, 'message' => MSG_SUCCESS]);
