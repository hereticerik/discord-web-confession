<?php
declare(strict_types=1);

 /**
 * 
 * Anonymous confession web form -> Discord webhook - v1.0
 * - Confession is posted (without any identifying info) to CONFESSION_WEBHOOK
 * - IP + timestamp + confession ID are posted to LOG_WEBHOOK (private mod channel)
 * 
 * Written By HereticErik - https://github.com/hereticerik
 * For educational/demonstration purposes, use with caution and secure this page if you use it for something.
 *
 * Requirements: PHP 7.4+ (or preferably above) with the cURL extension.
 */

// ---------------------------------------------------------------- CONFIG ---
const CONFESSION_WEBHOOK = 'https://discord.com/api/webhooks/xxx/zzz'; // public confessions channel webhook
const LOG_WEBHOOK        = 'https://discord.com/api/webhooks/xxx/zzz'; // private logging channel webhook

const MAX_LENGTH      = 1800;   // maximum character length (Discord embed limit is 4096)
const MIN_LENGTH      = 10;     // minimum character length
const COOLDOWN_SECS   = 600;     // per-IP cooldown between posts - 600 = 10 minutes
const TRUST_PROXY_HDR = false;  // true ONLY if behind Cloudflare (uses CF-Connecting-IP)
const EMBED_COLOR     = 0x702189;

// ------------------------------------------------------------- BOOTSTRAP ---
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

function client_ip(): string
{
    if (TRUST_PROXY_HDR && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function discord_post(string $webhook, array $payload): bool
{
    // Never let user text ping anyone (@everyone, roles, users)
    $payload['allowed_mentions'] = ['parse' => []];

    $ch = curl_init($webhook);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $code >= 200 && $code < 300;
}

function rate_limit_file(string $ip): string
{
    return sys_get_temp_dir() . '/confess_' . hash('sha256', $ip);
}

function seconds_until_allowed(string $ip): int
{
    $f = rate_limit_file($ip);
    if (is_file($f)) {
        $wait = COOLDOWN_SECS - (time() - (int) filemtime($f));
        return max(0, $wait);
    }
    return 0;
}

// ---------------------------------------------------------------- confession form in action ---
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$error = null;
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip   = client_ip();
    $text = trim((string) ($_POST['confession'] ?? ''));
    $text = preg_replace("/\r\n?/", "\n", $text);
    $text = preg_replace("/\n{4,}/", "\n\n\n", $text);

    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Session expired. Please reload the page and try again.';
    } elseif (!empty($_POST['website'])) {
        // Honeypot filled in: pretend success, drop silently
        $_SESSION['flash'] = 'Your confession has been submitted.';
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    } elseif (($wait = seconds_until_allowed($ip)) > 0) {
        $error = "Slow down! You can post again in {$wait} seconds.";
    } elseif (mb_strlen($text) < MIN_LENGTH) {
        $error = 'Your confession is too short.';
    } elseif (mb_strlen($text) > MAX_LENGTH) {
        $error = 'Your confession is too long (max ' . MAX_LENGTH . ' characters).';
    } else {
        $id  = strtoupper(bin2hex(random_bytes(3)));   // short ID that links the two messages
        $now = time();

        $ok = discord_post(CONFESSION_WEBHOOK, [
            'embeds' => [[
                'title'       => "Anonymous Confession #{$id}",
                'description' => $text,
                'color'       => EMBED_COLOR,
                'timestamp'   => gmdate('c', $now),
            ]],
        ]);

        if ($ok) {
            touch(rate_limit_file($ip));

            // Moderation log (separate channel). Failure here doesn't fail the user.
            discord_post(LOG_WEBHOOK, [
                'embeds' => [[
                    'title'  => "Confession Log #{$id}",
                    'color'  => 0x33003f,
                    'fields' => [
                        ['name' => 'IP Address', 'value' => '`' . $ip . '`',            'inline' => true],
                        ['name' => 'Posted',     'value' => "<t:{$now}:F> (<t:{$now}:R>)", 'inline' => true],
                        ['name' => 'Confession ID', 'value' => "`{$id}`",               'inline' => true],
                        ['name' => 'User Agent', 'value' => '```' . mb_substr(
                            str_replace('`', "'", $_SERVER['HTTP_USER_AGENT'] ?? 'n/a'), 0, 200
                        ) . '```'],
                    ],
                    'timestamp' => gmdate('c', $now),
                ]],
            ]);

            $_SESSION['flash'] = 'Your confession has been submitted.';
            $_SESSION['csrf']  = bin2hex(random_bytes(32));
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }

        $error = 'Could not send your confession right now. Please try again later.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Anonymous Confession</title>
    <style>
        /* web page style/color can be adjusted here */
        a, a:link, a:visited, a:hover, a:active { color: #a00b91; } 
        :root {
            --bg: #f4f5f8; --card: #ffffff; --text: #1f2330; --muted: #6b7280;
            --accent: #842dab; --accent-h: #622a6b; --border: #d9dce3;
            --ok-bg: #33003f; --ok: #da9cf2; --err-bg: #fdecec; --err: #b42318;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #1c1c1c; --card: #181818; --text: #e8e9ee; --muted: #9aa0ad;
                --border: #622a6b; --ok-bg: #33003f; --ok: #da9cf2; --err-bg: #3a1717; --err: #ff9b92;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1rem;
            background: var(--bg); color: var(--text);
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        .card {
            width: 100%; max-width: 560px; background: var(--card);
            border: 1px solid var(--border); border-radius: 14px; padding: 1.75rem;
        }
        h1 { margin: 0 0 .25rem; font-size: 1.5rem; }
        p.sub { margin: 0 0 1.25rem; color: var(--muted); }
        textarea {
            width: 100%; min-height: 180px; resize: vertical; padding: .85rem;
            border: 1px solid var(--border); border-radius: 10px;
            background: transparent; color: inherit; font: inherit; line-height: 1.5;
        }
        textarea:focus { outline: 2px solid var(--accent); border-color: transparent; }
        .row { display: flex; justify-content: space-between; align-items: center; margin-top: .6rem; }
        .count { font-size: .85rem; color: var(--muted); }
        button {
            margin-top: 1rem; width: 100%; padding: .85rem; border: 0; border-radius: 10px;
            background: var(--accent); color: #fff; font: inherit; font-weight: 600; cursor: pointer;
        }
        button:hover { background: var(--accent-h); }
        .msg { padding: .75rem 1rem; border-radius: 10px; margin-bottom: 1rem; font-size: .95rem; }
        .ok  { background: var(--ok-bg);  color: var(--ok); }
        .err { background: var(--err-bg); color: var(--err); }
        .notice { margin-top: 1rem; font-size: .8rem; color: var(--muted); line-height: 1.45; }
        .hp { position: absolute; left: -9999px; height: 0; overflow: hidden; }
    </style>
</head>
<body>
<main class="card">
    <h1>Anonymous Confession</h1>
    <p class="sub">Your name is never shown with your confession.</p>

    <?php if ($flash): ?><div class="msg ok"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="msg err"><?= h($error) ?></div><?php endif; ?>

    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <div class="hp" aria-hidden="true">
            <label>Leave this empty <input type="text" name="website" tabindex="-1"></label>
        </div>

        <textarea name="confession" id="c" maxlength="<?= MAX_LENGTH ?>"
                  placeholder="Type your confession here..." required><?= h($_POST['confession'] ?? '') ?></textarea>
        <div class="row"><span class="count" id="count">0 / <?= MAX_LENGTH ?></span></div>

        <button type="submit">Submit Confession</button>
    </form>

    <p class="notice">
        Confessions are posted publicly without your identity. For moderation and abuse
        prevention, your IP address and the time of submission are logged privately
        and visible only to discord moderators.
    </p>
</main>

<script>
    const t = document.getElementById('c'), n = document.getElementById('count');
    const upd = () => n.textContent = t.value.length + ' / <?= MAX_LENGTH ?>';
    t.addEventListener('input', upd); upd();
</script>
</body>
</html>
