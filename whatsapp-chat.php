<?php
/**
 * UniChat - Chat Universal com Polling Otimizado (1 requisição = tudo)
 * Tudo em um único arquivo index.php
 * SQLite embutido
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
session_start();

define('DB_FILE', __DIR__ . '/unichat.db');
define('MAX_FILE_SIZE', 20 * 1024 * 1024);
define('MAX_IMAGE_SIZE', 10 * 1024 * 1024);
define('MAX_AUDIO_SIZE', 20 * 1024 * 1024);
define('MAX_TEXT_LENGTH', 1000);
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('AVATAR_DIR', __DIR__ . '/uploads/avatars/');

if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
if (!is_dir(AVATAR_DIR)) mkdir(AVATAR_DIR, 0755, true);

function getDB() {
    static $db = null;
    if ($db === null) {
        $db = new PDO('sqlite:' . DB_FILE);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("PRAGMA foreign_keys = ON;");
    }
    return $db;
}

function initDB() {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        pin TEXT NOT NULL,
        avatar TEXT DEFAULT NULL,
        description TEXT DEFAULT '',
        country TEXT DEFAULT '',
        language TEXT DEFAULT '',
        status TEXT DEFAULT 'online',
        last_seen INTEGER DEFAULT 0,
        created_at INTEGER DEFAULT 0,
        is_banned INTEGER DEFAULT 0
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        username TEXT NOT NULL,
        content TEXT NOT NULL,
        type TEXT DEFAULT 'text',
        file_path TEXT DEFAULT NULL,
        file_name TEXT DEFAULT NULL,
        file_size INTEGER DEFAULT 0,
        reply_to INTEGER DEFAULT NULL,
        reply_username TEXT DEFAULT NULL,
        reply_content TEXT DEFAULT NULL,
        created_at INTEGER DEFAULT 0,
        deleted_for_all INTEGER DEFAULT 0
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS message_views (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        message_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        viewed_at INTEGER DEFAULT 0,
        UNIQUE(message_id, user_id)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS message_deletions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        message_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        deleted_at INTEGER DEFAULT 0,
        UNIQUE(message_id, user_id)
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_messages_created ON messages(created_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_msg_views ON message_views(message_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_msg_del ON message_deletions(message_id, user_id)");
}
initDB();

function sanitize($str) { return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8'); }
function generateToken() { return bin2hex(random_bytes(32)); }
function now() { return time(); }
function formatBytes($size) {
    $units = ['B','KB','MB','GB']; $u = 0;
    while ($size >= 1024 && $u < 3) { $size /= 1024; $u++; }
    return round($size, 2) . ' ' . $units[$u];
}
function formatTime($ts) {
    $diff = time() - $ts;
    if ($diff < 60) return 'agora';
    if ($diff < 3600) return floor($diff/60) . ' min';
    if ($diff < 86400) return floor($diff/3600) . ' h';
    return date('d/m/Y H:i', $ts);
}
function formatLastSeen($ts) {
    $diff = time() - $ts;
    if ($diff < 60) return 'online agora';
    if ($diff < 3600) return 'visto por último há ' . floor($diff/60) . ' min';
    if ($diff < 7200) return 'visto por último há 1 hora';
    if ($diff < 86400) return 'visto por último há ' . floor($diff/3600) . ' horas';
    if ($diff < 172800) return 'visto por último ontem às ' . date('H:i', $ts);
    return 'visto por último ' . date('d/m/Y \à\s H:i', $ts);
}
function formatDateSeparator($ts) {
    $today = strtotime('today');
    $yesterday = strtotime('yesterday');
    if ($ts >= $today) return 'Hoje';
    if ($ts >= $yesterday) return 'Ontem';
    $days = ['Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado'];
    $months = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
    $date = getdate($ts);
    if (time() - $ts < 604800) return $days[$date['wday']];
    return $date['mday'] . ' de ' . $months[$date['mon']] . ' de ' . $date['year'];
}
function makeLinksClickable($text) {
    return preg_replace('/(https?:\/\/[^\s<]+)/i', '<a href="$1" target="_blank" rel="noopener noreferrer" class="chat-link">$1</a>', $text);
}
function isLoggedIn() { return isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0; }
function getCurrentUser() {
    if (!isLoggedIn()) return null;
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND is_banned = 0");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
function requireAuth() {
    if (!isLoggedIn()) { header('Content-Type: application/json'); echo json_encode(['error'=>'Não autenticado']); exit; }
}
function updateLastSeen() {
    if (isLoggedIn()) {
        $db = getDB();
        $db->prepare("UPDATE users SET last_seen = ?, status = 'online' WHERE id = ?")->execute([now(), $_SESSION['user_id']]);
    }
}

$action = $_GET['action'] ?? '';

if ($action === 'register') {
    header('Content-Type: application/json');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $pin = $_POST['pin'] ?? '';
    $description = trim($_POST['description'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $language = trim($_POST['language'] ?? '');
    if (strlen($username) < 3 || strlen($username) > 20) { echo json_encode(['error'=>'Usuário deve ter 3-20 caracteres']); exit; }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) { echo json_encode(['error'=>'Usuário só pode conter letras, números e _']); exit; }
    if (strlen($password) < 6) { echo json_encode(['error'=>'Senha deve ter no mínimo 6 caracteres']); exit; }
    if (strlen($pin) < 4 || strlen($pin) > 10) { echo json_encode(['error'=>'PIN deve ter 4-10 dígitos']); exit; }
    if (!ctype_digit($pin)) { echo json_encode(['error'=>'PIN deve conter apenas números']); exit; }
    $db = getDB();
    $check = $db->prepare("SELECT id FROM users WHERE username = ?");
    $check->execute([$username]);
    if ($check->fetch()) { echo json_encode(['error'=>'Usuário já existe']); exit; }
    $avatar = null;
    if (!empty($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $f = $_FILES['avatar'];
        if ($f['size'] > MAX_IMAGE_SIZE) { echo json_encode(['error'=>'Avatar deve ser menor que 10MB']); exit; }
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) { echo json_encode(['error'=>'Formato de imagem inválido']); exit; }
        $avatarName = uniqid() . '.' . $ext;
        move_uploaded_file($f['tmp_name'], AVATAR_DIR . $avatarName);
        $avatar = $avatarName;
    }
    $db->prepare("INSERT INTO users (username, password_hash, pin, avatar, description, country, language, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
       ->execute([$username, password_hash($password, PASSWORD_BCRYPT), $pin, $avatar, $description, $country, $language, now()]);
    echo json_encode(['success'=>true, 'message'=>'Conta criada! Faça login.']);
    exit;
}

if ($action === 'login') {
    header('Content-Type: application/json');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND is_banned = 0");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || !password_verify($password, $user['password_hash'])) { echo json_encode(['error'=>'Usuário ou senha incorretos']); exit; }
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['token'] = generateToken();
    $db->prepare("UPDATE users SET status = 'online', last_seen = ? WHERE id = ?")->execute([now(), $user['id']]);
    echo json_encode(['success'=>true]);
    exit;
}

if ($action === 'login_pin') {
    header('Content-Type: application/json');
    $username = trim($_POST['username'] ?? '');
    $pin = $_POST['pin'] ?? '';
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND pin = ? AND is_banned = 0");
    $stmt->execute([$username, $pin]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) { echo json_encode(['error'=>'Usuário ou PIN incorretos']); exit; }
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['token'] = generateToken();
    $db->prepare("UPDATE users SET status = 'online', last_seen = ? WHERE id = ?")->execute([now(), $user['id']]);
    echo json_encode(['success'=>true]);
    exit;
}

if ($action === 'logout') {
    if (isLoggedIn()) {
        $db = getDB();
        $db->prepare("UPDATE users SET status = 'offline' WHERE id = ?")->execute([$_SESSION['user_id']]);
    }
    session_destroy();
    header('Content-Type: application/json');
    echo json_encode(['success'=>true]);
    exit;
}

if ($action === 'recover') {
    header('Content-Type: application/json');
    $username = trim($_POST['username'] ?? '');
    $pin = $_POST['pin'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    if (strlen($newPassword) < 6) { echo json_encode(['error'=>'Nova senha deve ter no mínimo 6 caracteres']); exit; }
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND pin = ?");
    $stmt->execute([$username, $pin]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) { echo json_encode(['error'=>'Usuário ou PIN incorretos']); exit; }
    $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($newPassword, PASSWORD_BCRYPT), $user['id']]);
    echo json_encode(['success'=>true, 'message'=>'Senha alterada com sucesso!']);
    exit;
}

if ($action === 'send_message') {
    requireAuth();
    header('Content-Type: application/json');
    updateLastSeen();
    $user = getCurrentUser();
    $content = trim($_POST['content'] ?? '');
    $replyTo = intval($_POST['reply_to'] ?? 0);
    $type = 'text'; $filePath = null; $fileName = null; $fileSize = 0; $replyUsername = null; $replyContent = null;
    if ($replyTo > 0) {
        $db = getDB();
        $stmt = $db->prepare("SELECT username, content FROM messages WHERE id = ?");
        $stmt->execute([$replyTo]);
        $replyMsg = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($replyMsg) { $replyUsername = $replyMsg['username']; $replyContent = $replyMsg['content']; }
    }
    if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $f = $_FILES['file'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $imgExts = ['jpg','jpeg','png','gif','webp','bmp'];
        $audioExts = ['mp3','wav','ogg','m4a','webm'];
        if (in_array($ext, $imgExts)) {
            if ($f['size'] > MAX_IMAGE_SIZE) { echo json_encode(['error'=>'Imagem deve ser menor que 10MB']); exit; }
            $type = 'image';
        } elseif (in_array($ext, $audioExts)) {
            if ($f['size'] > MAX_AUDIO_SIZE) { echo json_encode(['error'=>'Áudio deve ser menor que 20MB']); exit; }
            $type = 'audio';
        } else {
            if ($f['size'] > MAX_FILE_SIZE) { echo json_encode(['error'=>'Arquivo deve ser menor que 20MB']); exit; }
            $type = 'file';
        }
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $f['name']);
        $fileName = $safeName; $fileSize = $f['size'];
        $filePath = uniqid() . '_' . $safeName;
        move_uploaded_file($f['tmp_name'], UPLOAD_DIR . $filePath);
    }
    if ($type === 'text') {
        if (strlen($content) === 0 || strlen($content) > MAX_TEXT_LENGTH) { echo json_encode(['error'=>'Mensagem deve ter 1-1000 caracteres']); exit; }
    }
    $db = getDB();
    $db->prepare("INSERT INTO messages (user_id, username, content, type, file_path, file_name, file_size, reply_to, reply_username, reply_content, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
       ->execute([$user['id'], $user['username'], $content, $type, $filePath, $fileName, $fileSize, $replyTo ?: null, $replyUsername, $replyContent, now()]);
    $msgId = $db->lastInsertId();
    $db->prepare("INSERT OR IGNORE INTO message_views (message_id, user_id, viewed_at) VALUES (?, ?, ?)")->execute([$msgId, $user['id'], now()]);
    echo json_encode(['success'=>true, 'message_id'=>$msgId]);
    exit;
}

// === UMA UNICA REQUISICAO: MENSAGENS + USUARIOS ONLINE/OFFLINE + TUDO ===
if ($action === 'poll') {
    requireAuth();
    header('Content-Type: application/json');
    updateLastSeen();
    $lastId = intval($_GET['last_id'] ?? 0);
    $user = getCurrentUser();
    $db = getDB();

    // 1. Mensagens novas
    $stmt = $db->prepare("
        SELECT m.*, u.avatar, u.country, u.language, u.description,
               (SELECT GROUP_CONCAT(v.user_id) FROM message_views v WHERE v.message_id = m.id) as viewers
        FROM messages m
        JOIN users u ON m.user_id = u.id
        WHERE m.id > ? AND m.deleted_for_all = 0
          AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = m.id AND md.user_id = ?)
        ORDER BY m.created_at ASC LIMIT 100
    ");
    $stmt->execute([$lastId, $user['id']]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($messages as $msg) {
        if ($msg['user_id'] != $user['id']) {
            $db->prepare("INSERT OR IGNORE INTO message_views (message_id, user_id, viewed_at) VALUES (?, ?, ?)")->execute([$msg['id'], $user['id'], now()]);
        }
    }

    // 2. Todos os usuarios (online e offline) com ultimo acesso
    $threshold = now() - 60;
    $stmt = $db->prepare("
        SELECT id, username, avatar, status, last_seen, created_at 
        FROM users 
        WHERE is_banned = 0 
        ORDER BY 
            CASE WHEN last_seen >= ? THEN 0 ELSE 1 END,
            last_seen DESC,
            username ASC
    ");
    $stmt->execute([$threshold]);
    $allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Contagem online
    $onlineCount = count(array_filter($allUsers, fn($u) => $u['last_seen'] >= $threshold));

    echo json_encode([
        'messages' => $messages,
        'user_id' => $user['id'],
        'all_users' => $allUsers,
        'online_count' => $onlineCount
    ]);
    exit;
}

if ($action === 'get_messages') {
    requireAuth();
    header('Content-Type: application/json');
    updateLastSeen();
    $lastId = intval($_GET['last_id'] ?? 0);
    $user = getCurrentUser();
    $db = getDB();
    $stmt = $db->prepare("
        SELECT m.*, u.avatar, u.country, u.language, u.description,
               (SELECT GROUP_CONCAT(v.user_id) FROM message_views v WHERE v.message_id = m.id) as viewers
        FROM messages m
        JOIN users u ON m.user_id = u.id
        WHERE m.id > ? AND m.deleted_for_all = 0
          AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = m.id AND md.user_id = ?)
        ORDER BY m.created_at ASC LIMIT 100
    ");
    $stmt->execute([$lastId, $user['id']]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($messages as $msg) {
        if ($msg['user_id'] != $user['id']) {
            $db->prepare("INSERT OR IGNORE INTO message_views (message_id, user_id, viewed_at) VALUES (?, ?, ?)")->execute([$msg['id'], $user['id'], now()]);
        }
    }
    echo json_encode(['messages'=>$messages, 'user_id'=>$user['id']]);
    exit;
}

if ($action === 'get_views') {
    requireAuth();
    header('Content-Type: application/json');
    $msgId = intval($_GET['message_id'] ?? 0);
    $user = getCurrentUser();
    $db = getDB();
    $stmt = $db->prepare("SELECT user_id FROM messages WHERE id = ?");
    $stmt->execute([$msgId]);
    $msg = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$msg || $msg['user_id'] != $user['id']) { echo json_encode(['error'=>'Acesso negado']); exit; }
    $stmt = $db->prepare("
        SELECT u.username, u.avatar, v.viewed_at FROM message_views v
        JOIN users u ON v.user_id = u.id
        WHERE v.message_id = ? AND v.user_id != ? ORDER BY v.viewed_at DESC
    ");
    $stmt->execute([$msgId, $user['id']]);
    $views = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['views'=>$views]);
    exit;
}

if ($action === 'delete_message') {
    requireAuth();
    header('Content-Type: application/json');
    $msgId = intval($_POST['message_id'] ?? 0);
    $forAll = isset($_POST['for_all']) && $_POST['for_all'] === '1';
    $user = getCurrentUser();
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM messages WHERE id = ?");
    $stmt->execute([$msgId]);
    $msg = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$msg) { echo json_encode(['error'=>'Mensagem não encontrada']); exit; }
    if ($forAll) {
        if ($msg['user_id'] != $user['id']) { echo json_encode(['error'=>'Você só pode deletar suas próprias mensagens para todos']); exit; }
        $db->prepare("UPDATE messages SET deleted_for_all = 1 WHERE id = ?")->execute([$msgId]);
    } else {
        $db->prepare("INSERT OR IGNORE INTO message_deletions (message_id, user_id, deleted_at) VALUES (?, ?, ?)")->execute([$msgId, $user['id'], now()]);
    }
    echo json_encode(['success'=>true]);
    exit;
}

if ($action === 'get_profile') {
    requireAuth();
    header('Content-Type: application/json');
    $userId = intval($_GET['user_id'] ?? 0);
    if ($userId === 0) $userId = $_SESSION['user_id'];
    $db = getDB();
    $stmt = $db->prepare("SELECT id, username, avatar, description, country, language, status, last_seen, created_at FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$profile) { echo json_encode(['error'=>'Usuário não encontrado']); exit; }
    echo json_encode(['profile'=>$profile]);
    exit;
}

if ($action === 'update_profile') {
    requireAuth();
    header('Content-Type: application/json');
    updateLastSeen();
    $user = getCurrentUser();
    $description = trim($_POST['description'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $language = trim($_POST['language'] ?? '');
    $avatar = $user['avatar'];
    if (!empty($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $f = $_FILES['avatar'];
        if ($f['size'] > MAX_IMAGE_SIZE) { echo json_encode(['error'=>'Avatar deve ser menor que 10MB']); exit; }
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) { echo json_encode(['error'=>'Formato de imagem inválido']); exit; }
        $avatarName = uniqid() . '.' . $ext;
        move_uploaded_file($f['tmp_name'], AVATAR_DIR . $avatarName);
        $avatar = $avatarName;
    }
    $db = getDB();
    $db->prepare("UPDATE users SET description = ?, country = ?, language = ?, avatar = ? WHERE id = ?")->execute([$description, $country, $language, $avatar, $user['id']]);
    echo json_encode(['success'=>true, 'avatar'=>$avatar]);
    exit;
}

if ($action === 'all_users') {
    requireAuth();
    header('Content-Type: application/json');
    updateLastSeen();
    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, username, avatar, status, last_seen, created_at 
        FROM users 
        WHERE is_banned = 0 
        ORDER BY 
            CASE WHEN last_seen >= ? THEN 0 ELSE 1 END,
            last_seen DESC,
            username ASC
    ");
    $threshold = now() - 60;
    $stmt->execute([$threshold]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['users'=>$users]);
    exit;
}

if ($action === 'online_users') {
    requireAuth();
    header('Content-Type: application/json');
    updateLastSeen();
    $db = getDB();
    $threshold = now() - 60;
    $stmt = $db->prepare("SELECT id, username, avatar, status FROM users WHERE last_seen >= ? ORDER BY username");
    $stmt->execute([$threshold]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['users'=>$users]);
    exit;
}

if ($action === 'file') {
    $file = basename($_GET['f'] ?? '');
    $path = UPLOAD_DIR . $file;
    if (file_exists($path)) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = 'application/octet-stream';
        if (in_array($ext, ['jpg','jpeg'])) $mime = 'image/jpeg';
        elseif ($ext === 'png') $mime = 'image/png';
        elseif ($ext === 'gif') $mime = 'image/gif';
        elseif ($ext === 'webp') $mime = 'image/webp';
        elseif ($ext === 'mp3') $mime = 'audio/mpeg';
        elseif ($ext === 'wav') $mime = 'audio/wav';
        elseif ($ext === 'ogg') $mime = 'audio/ogg';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=86400');
        readfile($path);
    } else { http_response_code(404); echo 'Arquivo não encontrado'; }
    exit;
}

if ($action === 'avatar') {
    $file = basename($_GET['f'] ?? '');
    $path = AVATAR_DIR . $file;
    if (file_exists($path)) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = 'image/jpeg';
        if ($ext === 'png') $mime = 'image/png';
        elseif ($ext === 'gif') $mime = 'image/gif';
        elseif ($ext === 'webp') $mime = 'image/webp';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=86400');
        readfile($path);
    } else {
        header('Content-Type: image/svg+xml');
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect width="100" height="100" fill="#ddd"/><text x="50" y="55" text-anchor="middle" font-size="40" fill="#999">?</text></svg>';
    }
    exit;
}

if ($action === 'ping') {
    requireAuth();
    updateLastSeen();
    header('Content-Type: application/json');
    echo json_encode(['ok'=>true]);
    exit;
}

?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>WHATSAPP CHAT</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --whatsapp-green: #128C7E; --whatsapp-dark: #075E54; --whatsapp-light: #DCF8C6;
    --whatsapp-bg: #E5DDD5; --whatsapp-bg-dark: #0B141A;
    --msg-bg-me: #DCF8C6; --msg-bg-other: #FFFFFF;
    --msg-bg-dark-me: #005C4B; --msg-bg-dark-other: #202C33;
    --text-primary: #111B21; --text-secondary: #667781; --text-dark: #E9EDEF;
    --border: #E9EDEF; --online: #00A884; --offline: #8696A0;
    --check-single: #8696A0; --check-double: #8696A0; --check-blue: #53BDEB;
    --danger: #EA0038; --radius: 7.5px;
    --shadow: 0 1px 0.5px rgba(0,0,0,0.13);
}
html, body {
    height: 100%; width: 100%;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    background: var(--whatsapp-bg);
    overflow: hidden;
    -webkit-tap-highlight-color: transparent;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
}

@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
@keyframes slideUp { from { transform: translateY(100%); } to { transform: translateY(0); } }
@keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.5; } }
@keyframes bounce { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-4px); } }
@keyframes recordPulse { 0% { transform: scale(1); } 50% { transform: scale(1.15); } 100% { transform: scale(1); } }
@keyframes wave { 0%,100% { height: 4px; } 50% { height: 20px; } }

.auth-screen { position: fixed; inset: 0; background: linear-gradient(135deg, var(--whatsapp-dark) 0%, var(--whatsapp-green) 100%); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 20px; overflow-y: auto; }
.auth-box { background: #fff; border-radius: 16px; padding: 32px 24px; width: 100%; max-width: 400px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); animation: fadeIn 0.4s ease; }
.auth-logo { text-align: center; margin-bottom: 24px; }
.auth-logo h1 { color: var(--whatsapp-green); font-size: 28px; font-weight: 700; }
.auth-logo p { color: var(--text-secondary); font-size: 13px; margin-top: 4px; }
.auth-tabs { display: flex; margin-bottom: 20px; border-bottom: 1px solid var(--border); }
.auth-tab { flex: 1; padding: 12px; text-align: center; cursor: pointer; color: var(--text-secondary); font-size: 14px; font-weight: 600; border-bottom: 2px solid transparent; transition: all 0.2s; }
.auth-tab.active { color: var(--whatsapp-green); border-bottom-color: var(--whatsapp-green); }
.auth-form { display: none; }
.auth-form.active { display: block; animation: fadeIn 0.3s; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; font-size: 12px; color: var(--text-secondary); margin-bottom: 4px; font-weight: 500; }
.form-group input, .form-group textarea, .form-group select { width: 100%; padding: 12px 14px; border: 1px solid var(--border); border-radius: 8px; font-size: 15px; outline: none; transition: border 0.2s; font-family: inherit; }
.form-group input:focus, .form-group textarea:focus, .form-group select:focus { border-color: var(--whatsapp-green); }
.form-group textarea { resize: vertical; min-height: 60px; }
.avatar-preview { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 2px solid var(--whatsapp-green); display: block; margin: 0 auto 8px; background: #f0f0f0; }
.btn { width: 100%; padding: 14px; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.2s; font-family: inherit; }
.btn-primary { background: var(--whatsapp-green); color: #fff; }
.btn-primary:hover { background: var(--whatsapp-dark); }
.btn-secondary { background: transparent; color: var(--whatsapp-green); border: 1px solid var(--whatsapp-green); margin-top: 8px; }
.btn-secondary:hover { background: rgba(18,140,126,0.05); }
.btn-danger { background: var(--danger); color: #fff; }
.error-msg { color: var(--danger); font-size: 13px; text-align: center; margin-bottom: 10px; display: none; }
.success-msg { color: var(--whatsapp-green); font-size: 13px; text-align: center; margin-bottom: 10px; display: none; }
.forgot-link { text-align: center; font-size: 13px; color: var(--whatsapp-green); cursor: pointer; margin-top: 12px; }
.forgot-link:hover { text-decoration: underline; }

.app { display: none; width: 100%; height: 100%; flex-direction: column; position: fixed; top: 0; left: 0; right: 0; bottom: 0; overflow: hidden; }
.app.active { display: flex; }

.chat-header { background: var(--whatsapp-dark); color: #fff; padding: 8px 12px; display: flex; align-items: center; gap: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.2); z-index: 10; flex-shrink: 0; height: 56px; min-height: 56px; }
.chat-header-avatar { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(255,255,255,0.2); cursor: pointer; background: #fff; }
.chat-header-info { flex: 1; min-width: 0; }
.chat-header-info h2 { font-size: 16px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.chat-header-info span { font-size: 12px; color: rgba(255,255,255,0.7); }
.header-actions { display: flex; gap: 8px; }
.header-btn { width: 36px; height: 36px; border-radius: 50%; border: none; background: rgba(255,255,255,0.1); color: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background 0.2s; }
.header-btn:hover { background: rgba(255,255,255,0.2); }
.header-btn svg { width: 20px; height: 20px; }

.chat-area { flex: 1; overflow-y: auto; overflow-x: hidden; padding: 10px 8px; background: linear-gradient(rgba(229,221,213,0.9), rgba(229,221,213,0.9)), url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect fill="%23d5d5d5" width="100" height="100"/><circle fill="%23c5c5c5" cx="25" cy="25" r="2"/><circle fill="%23c5c5c5" cx="75" cy="75" r="2"/><circle fill="%23c5c5c5" cx="25" cy="75" r="2"/><circle fill="%23c5c5c5" cx="75" cy="25" r="2"/></svg>'); background-size: cover, 400px; scroll-behavior: smooth; -webkit-overflow-scrolling: touch; }
.chat-area::-webkit-scrollbar { width: 6px; }
.chat-area::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.2); border-radius: 3px; }

.date-separator { display: flex; align-items: center; justify-content: center; margin: 16px 0 8px; }
.date-separator span { background: #E1F2FB; color: #1DA1F2; font-size: 12px; font-weight: 600; padding: 6px 14px; border-radius: 12px; box-shadow: 0 1px 2px rgba(0,0,0,0.08); text-transform: capitalize; }

.message-wrapper { display: flex; margin-bottom: 4px; animation: fadeIn 0.3s ease; position: relative; }
.message-wrapper.own { justify-content: flex-end; }
.message-wrapper.own .message { background: var(--msg-bg-me); }
.message-wrapper.other .message { background: var(--msg-bg-other); }
.message { max-width: 75%; padding: 6px 8px 4px 8px; border-radius: var(--radius); box-shadow: var(--shadow); position: relative; word-wrap: break-word; font-size: 14.2px; line-height: 1.4; color: var(--text-primary); }
.message-wrapper.own .message { border-top-right-radius: 0; }
.message-wrapper.other .message { border-top-left-radius: 0; }
.message-wrapper.own .message::after { content: ''; position: absolute; top: 0; right: -6px; width: 10px; height: 16px; background: var(--msg-bg-me); clip-path: polygon(0 0, 0% 100%, 100% 0); }
.message-wrapper.other .message::after { content: ''; position: absolute; top: 0; left: -6px; width: 10px; height: 16px; background: var(--msg-bg-other); clip-path: polygon(100% 0, 0% 0, 100% 100%); }

.msg-avatar { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; margin-right: 6px; align-self: flex-end; cursor: pointer; border: 1px solid rgba(0,0,0,0.1); background: #fff; flex-shrink: 0; }
.msg-sender { font-size: 12.5px; font-weight: 600; color: var(--whatsapp-green); margin-bottom: 2px; cursor: pointer; display: inline-block; }
.msg-sender:hover { text-decoration: underline; }
.msg-content { margin-bottom: 2px; }
.msg-content a.chat-link { color: #027EB5; text-decoration: none; }
.msg-content a.chat-link:hover { text-decoration: underline; }

.msg-reply { background: rgba(0,0,0,0.05); border-left: 3px solid var(--whatsapp-green); padding: 4px 8px; border-radius: 4px; margin-bottom: 4px; font-size: 12px; cursor: pointer; }
.msg-reply-name { font-weight: 600; color: var(--whatsapp-green); }
.msg-reply-text { color: var(--text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.msg-image { max-width: 100%; border-radius: 6px; cursor: pointer; display: block; margin-bottom: 4px; }
.msg-audio { display: flex; align-items: center; gap: 8px; padding: 4px 0; }
.msg-audio audio { max-width: 200px; height: 36px; }
.audio-icon { color: var(--whatsapp-green); }

.msg-file { display: flex; align-items: center; gap: 10px; background: rgba(0,0,0,0.03); padding: 8px; border-radius: 6px; margin-bottom: 4px; cursor: pointer; text-decoration: none; color: inherit; }
.msg-file:hover { background: rgba(0,0,0,0.06); }
.file-icon { font-size: 28px; }
.file-info { flex: 1; min-width: 0; }
.file-name { font-size: 13px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.file-size { font-size: 11px; color: var(--text-secondary); }

.msg-footer { display: flex; align-items: center; justify-content: flex-end; gap: 4px; margin-top: 2px; }
.msg-time { font-size: 11px; color: var(--text-secondary); white-space: nowrap; }
.msg-status { display: flex; align-items: center; color: var(--check-single); cursor: pointer; }
.msg-status svg { width: 14px; height: 14px; }
.msg-status.viewed { color: var(--check-blue); }
.msg-status.sending { animation: pulse 1s infinite; }
.msg-deleted { font-style: italic; color: var(--text-secondary); font-size: 13px; }

.context-menu { position: absolute; background: #fff; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.2); z-index: 100; min-width: 160px; overflow: hidden; display: none; animation: fadeIn 0.15s; }
.context-menu-item { padding: 12px 16px; font-size: 14px; cursor: pointer; display: flex; align-items: center; gap: 10px; transition: background 0.15s; }
.context-menu-item:hover { background: #f5f5f5; }
.context-menu-item.danger { color: var(--danger); }
.context-menu-divider { height: 1px; background: var(--border); margin: 4px 0; }

.typing-indicator { display: none; align-items: center; padding: 8px 12px; margin-bottom: 8px; }
.typing-indicator.active { display: flex; }
.typing-bubble { background: var(--msg-bg-other); padding: 10px 14px; border-radius: var(--radius); border-top-left-radius: 0; display: flex; gap: 4px; box-shadow: var(--shadow); }
.typing-dot { width: 7px; height: 7px; background: var(--text-secondary); border-radius: 50%; animation: bounce 1.4s infinite ease-in-out; }
.typing-dot:nth-child(1) { animation-delay: 0s; }
.typing-dot:nth-child(2) { animation-delay: 0.2s; }
.typing-dot:nth-child(3) { animation-delay: 0.4s; }

.input-area { background: #F0F2F5; padding: 8px 10px; display: flex; align-items: flex-end; gap: 6px; border-top: 1px solid var(--border); flex-shrink: 0; min-height: 56px; position: relative; z-index: 5; }
.input-btn { width: 40px; height: 40px; border-radius: 50%; border: none; background: transparent; color: var(--text-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: all 0.2s; padding: 0; }
.input-btn:hover { background: rgba(0,0,0,0.05); color: var(--text-primary); }
.input-btn svg { width: 22px; height: 22px; display: block; }
.input-btn.send-btn { background: var(--whatsapp-green); color: #fff; }
.input-btn.send-btn:hover { background: var(--whatsapp-dark); }
.input-btn.recording { background: var(--danger); color: #fff; animation: recordPulse 0.8s infinite; }
.input-btn.recording svg { animation: recordPulse 0.8s infinite; }

.input-wrapper { flex: 1; background: #fff; border-radius: 20px; padding: 8px 14px; display: flex; align-items: center; gap: 8px; min-height: 40px; max-height: 120px; overflow: hidden; }
.input-wrapper textarea { flex: 1; border: none; outline: none; font-size: 15px; font-family: inherit; resize: none; min-height: 24px; max-height: 100px; background: transparent; line-height: 1.4; width: 100%; display: block; padding: 0; margin: 0; }
.input-wrapper textarea::placeholder { color: var(--text-secondary); }
.char-count { font-size: 11px; color: var(--text-secondary); white-space: nowrap; flex-shrink: 0; }

.recording-overlay { display: none; position: fixed; bottom: 70px; left: 50%; transform: translateX(-50%); background: #fff; padding: 12px 24px; border-radius: 24px; box-shadow: 0 4px 20px rgba(0,0,0,0.2); z-index: 100; align-items: center; gap: 12px; }
.recording-overlay.active { display: flex; }
.recording-dot { width: 12px; height: 12px; background: var(--danger); border-radius: 50%; animation: pulse 1s infinite; }
.recording-time { font-size: 14px; font-weight: 600; color: var(--text-primary); font-variant-numeric: tabular-nums; }
.recording-wave { display: flex; align-items: center; gap: 2px; height: 20px; }
.recording-wave span { width: 3px; background: var(--danger); border-radius: 2px; animation: wave 0.5s infinite ease-in-out; }
.recording-wave span:nth-child(1) { animation-delay: 0s; height: 8px; }
.recording-wave span:nth-child(2) { animation-delay: 0.1s; height: 14px; }
.recording-wave span:nth-child(3) { animation-delay: 0.2s; height: 10px; }
.recording-wave span:nth-child(4) { animation-delay: 0.15s; height: 16px; }
.recording-wave span:nth-child(5) { animation-delay: 0.05s; height: 6px; }

.camera-overlay { display: none; position: fixed; inset: 0; background: #000; z-index: 2000; flex-direction: column; }
.camera-overlay.active { display: flex; }
.camera-preview { flex: 1; display: flex; align-items: center; justify-content: center; position: relative; overflow: hidden; }
.camera-preview video { width: 100%; height: 100%; object-fit: cover; }
.camera-preview canvas { display: none; }
.camera-controls { display: flex; align-items: center; justify-content: center; gap: 40px; padding: 20px; background: rgba(0,0,0,0.8); }
.camera-btn { width: 60px; height: 60px; border-radius: 50%; border: 3px solid #fff; background: transparent; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.camera-btn.shutter { background: #fff; width: 70px; height: 70px; }
.camera-btn.shutter::after { content: ''; width: 54px; height: 54px; border-radius: 50%; background: #fff; border: 2px solid #333; }
.camera-btn.cancel { border-color: var(--danger); color: var(--danger); font-size: 14px; font-weight: 600; }
.camera-btn svg { width: 24px; height: 24px; color: #fff; }

.reply-preview { display: none; background: #F0F2F5; padding: 6px 12px; border-left: 3px solid var(--whatsapp-green); align-items: center; gap: 8px; }
.reply-preview.active { display: flex; }
.reply-preview-text { flex: 1; font-size: 13px; color: var(--text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.reply-preview-close { cursor: pointer; color: var(--text-secondary); font-size: 16px; }

.file-preview { display: none; background: #F0F2F5; padding: 6px 12px; align-items: center; gap: 8px; }
.file-preview.active { display: flex; }
.file-preview-name { flex: 1; font-size: 13px; color: var(--text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.file-preview-close { cursor: pointer; color: var(--text-secondary); font-size: 16px; }

.image-viewer { position: fixed; inset: 0; background: rgba(0,0,0,0.9); z-index: 2000; display: none; align-items: center; justify-content: center; padding: 20px; }
.image-viewer.active { display: flex; }
.image-viewer img { max-width: 100%; max-height: 90vh; border-radius: 4px; }
.image-viewer-close { position: absolute; top: 20px; right: 20px; color: #fff; font-size: 32px; cursor: pointer; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.5); border-radius: 50%; }

.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 1500; display: none; align-items: center; justify-content: center; padding: 20px; }
.modal-overlay.active { display: flex; }
.modal-box { background: #fff; border-radius: 16px; width: 100%; max-width: 400px; max-height: 90vh; overflow-y: auto; animation: slideUp 0.3s ease; }
.modal-header { background: var(--whatsapp-green); color: #fff; padding: 24px; text-align: center; position: relative; }
.modal-avatar { width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 4px solid rgba(255,255,255,0.3); background: #fff; }
.modal-header h3 { margin-top: 10px; font-size: 20px; }
.modal-body { padding: 20px; }
.modal-field { margin-bottom: 16px; }
.modal-field label { font-size: 12px; color: var(--whatsapp-green); font-weight: 600; text-transform: uppercase; }
.modal-field p { font-size: 15px; color: var(--text-primary); margin-top: 4px; }
.modal-close { position: absolute; top: 12px; right: 12px; color: #fff; font-size: 24px; cursor: pointer; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 50%; }
.modal-close:hover { background: rgba(255,255,255,0.2); }

.views-modal .modal-body { padding: 0; }
.views-list { max-height: 300px; overflow-y: auto; }
.view-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-bottom: 1px solid var(--border); }
.view-item img { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; }
.view-item-info { flex: 1; }
.view-item-name { font-size: 14px; font-weight: 500; }
.view-item-time { font-size: 12px; color: var(--text-secondary); }

.edit-profile-form { padding: 20px; }

.toast { position: fixed; bottom: 80px; left: 50%; transform: translateX(-50%) translateY(100px); background: #323739; color: #fff; padding: 10px 20px; border-radius: 20px; font-size: 13px; z-index: 3000; opacity: 0; transition: all 0.3s; pointer-events: none; white-space: nowrap; }
.toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

.scroll-bottom { position: fixed; bottom: 80px; right: 16px; width: 44px; height: 44px; border-radius: 50%; background: #fff; border: none; box-shadow: 0 2px 8px rgba(0,0,0,0.2); cursor: pointer; display: none; align-items: center; justify-content: center; z-index: 50; color: var(--text-secondary); }
.scroll-bottom.show { display: flex; }
.scroll-bottom:hover { background: #f5f5f5; }
.scroll-bottom .badge { position: absolute; top: -2px; right: -2px; background: var(--danger); color: #fff; font-size: 10px; min-width: 18px; height: 18px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-weight: 600; }

.side-menu { position: fixed; top: 0; left: 0; width: 280px; height: 100%; background: #fff; z-index: 1200; transform: translateX(-100%); transition: transform 0.3s ease; box-shadow: 2px 0 10px rgba(0,0,0,0.1); display: flex; flex-direction: column; }
.side-menu.open { transform: translateX(0); }
.side-menu-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1100; display: none; }
.side-menu-overlay.active { display: block; }
.side-menu-header { background: var(--whatsapp-dark); color: #fff; padding: 40px 16px 16px; }
.side-menu-avatar { width: 60px; height: 60px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(255,255,255,0.3); cursor: pointer; background: #fff; }
.side-menu-header h3 { margin-top: 10px; font-size: 18px; }
.side-menu-header p { font-size: 13px; opacity: 0.8; }
.side-menu-items { flex: 1; overflow-y: auto; padding: 8px 0; }
.side-menu-item { display: flex; align-items: center; gap: 16px; padding: 14px 16px; cursor: pointer; transition: background 0.15s; color: var(--text-primary); text-decoration: none; }
.side-menu-item:hover { background: #f5f5f5; }
.side-menu-item svg { width: 22px; height: 22px; color: var(--text-secondary); }
.side-menu-item span { font-size: 15px; }

.all-users-list { max-height: 400px; overflow-y: auto; }
.all-user-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-bottom: 1px solid var(--border); cursor: pointer; transition: background 0.15s; }
.all-user-item:hover { background: #f5f5f5; }
.all-user-item img { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; flex-shrink: 0; }
.all-user-info { flex: 1; min-width: 0; }
.all-user-name { font-size: 15px; font-weight: 500; color: var(--text-primary); }
.all-user-status { font-size: 12px; color: var(--text-secondary); margin-top: 2px; }
.all-user-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.all-user-dot.online { background: var(--online); }
.all-user-dot.offline { background: var(--offline); }

@media (max-width: 480px) {
    .auth-box { padding: 24px 16px; border-radius: 12px; }
    .message { max-width: 85%; font-size: 14px; }
    .chat-header-info h2 { font-size: 15px; }
    .input-wrapper textarea { font-size: 16px; }
    .input-area { padding: 6px 8px; }
    .input-btn { width: 36px; height: 36px; }
    .input-btn svg { width: 20px; height: 20px; }
}

@media (prefers-color-scheme: dark) {
    body { background: var(--whatsapp-bg-dark); }
    .chat-area { background: var(--whatsapp-bg-dark); }
    .message-wrapper.own .message { background: var(--msg-bg-dark-me); color: var(--text-dark); }
    .message-wrapper.other .message { background: var(--msg-bg-dark-other); color: var(--text-dark); }
    .message-wrapper.own .message::after { background: var(--msg-bg-dark-me); }
    .message-wrapper.other .message::after { background: var(--msg-bg-dark-other); }
    .input-area { background: #1F2C34; border-color: #2A3942; }
    .input-wrapper { background: #2A3942; }
    .input-wrapper textarea { color: var(--text-dark); }
    .input-wrapper textarea::placeholder { color: #8696A0; }
    .reply-preview { background: #1F2C34; }
    .file-preview { background: #1F2C34; }
    .typing-bubble { background: var(--msg-bg-dark-other); }
    .msg-reply { background: rgba(255,255,255,0.05); }
    .msg-file { background: rgba(255,255,255,0.05); }
    .side-menu { background: #111B21; }
    .side-menu-item:hover { background: #202C33; }
    .modal-box { background: #111B21; }
    .modal-field p { color: var(--text-dark); }
    .context-menu { background: #233138; }
    .context-menu-item:hover { background: #182229; }
    .context-menu-item { color: var(--text-dark); }
    .auth-box { background: #1F2C34; }
    .form-group input, .form-group textarea, .form-group select { background: #2A3942; color: var(--text-dark); border-color: #2A3942; }
    .form-group label { color: #8696A0; }
    .auth-logo h1 { color: var(--whatsapp-green); }
    .auth-tab { color: #8696A0; }
    .auth-tab.active { color: var(--whatsapp-green); }
    .forgot-link { color: #53BDEB; }
    .date-separator span { background: #182229; color: #53BDEB; }
    .recording-overlay { background: #1F2C34; }
    .recording-time { color: var(--text-dark); }
    .all-user-item:hover { background: #202C33; }
    .all-user-name { color: var(--text-dark); }
}
</style>
</head>
<body>

<!-- AUTH SCREEN -->
<div class="auth-screen" id="authScreen">
    <div class="auth-box">
        <div class="auth-logo">
            <h1>💬 WHATSAPP CHAT</h1>
            <p>Chat Universal - Converse com o mundo</p>
        </div>
        <div class="auth-tabs">
            <div class="auth-tab active" onclick="showTab('login', this)">Entrar</div>
            <div class="auth-tab" onclick="showTab('register', this)">Criar Conta</div>
        </div>
        <form class="auth-form active" id="loginForm" onsubmit="return doLogin(event)">
            <div class="error-msg" id="loginError"></div>
            <div class="form-group">
                <label>Usuário</label>
                <input type="text" name="username" placeholder="Seu usuário" required autocomplete="username">
            </div>
            <div class="form-group">
                <label>Senha</label>
                <input type="password" name="password" placeholder="Sua senha" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-primary">Entrar</button>
            <div class="forgot-link" onclick="showRecover()">Esqueceu a senha? Use seu PIN</div>
        </form>
        <form class="auth-form" id="registerForm" onsubmit="return doRegister(event)" enctype="multipart/form-data">
            <div class="error-msg" id="registerError"></div>
            <div class="success-msg" id="registerSuccess"></div>
            <div class="form-group">
                <img src="" class="avatar-preview" id="regAvatarPreview" style="display:none">
                <label>Foto de Perfil (opcional)</label>
                <input type="file" name="avatar" accept="image/*" onchange="previewAvatar(this, 'regAvatarPreview')">
            </div>
            <div class="form-group">
                <label>Usuário *</label>
                <input type="text" name="username" placeholder="3-20 caracteres" required minlength="3" maxlength="20" pattern="[a-zA-Z0-9_]+">
            </div>
            <div class="form-group">
                <label>Senha *</label>
                <input type="password" name="password" placeholder="Mínimo 6 caracteres" required minlength="6">
            </div>
            <div class="form-group">
                <label>PIN de Recuperação *</label>
                <input type="text" name="pin" placeholder="4-10 dígitos numéricos" required minlength="4" maxlength="10" pattern="\d+">
            </div>
            <div class="form-group">
                <label>Descrição</label>
                <textarea name="description" placeholder="Fale um pouco sobre você" maxlength="200"></textarea>
            </div>
            <div class="form-group">
                <label>País</label>
                <input type="text" name="country" placeholder="Ex: Brasil" maxlength="50">
            </div>
            <div class="form-group">
                <label>Idioma Nativo</label>
                <input type="text" name="language" placeholder="Ex: Português" maxlength="50">
            </div>
            <button type="submit" class="btn btn-primary">Criar Conta</button>
        </form>
        <form class="auth-form" id="recoverForm" onsubmit="return doRecover(event)">
            <div class="error-msg" id="recoverError"></div>
            <div class="success-msg" id="recoverSuccess"></div>
            <div class="form-group">
                <label>Usuário</label>
                <input type="text" name="username" placeholder="Seu usuário" required>
            </div>
            <div class="form-group">
                <label>PIN de Recuperação</label>
                <input type="text" name="pin" placeholder="Seu PIN" required pattern="\d+">
            </div>
            <div class="form-group">
                <label>Nova Senha</label>
                <input type="password" name="new_password" placeholder="Mínimo 6 caracteres" required minlength="6">
            </div>
            <button type="submit" class="btn btn-primary">Redefinir Senha</button>
            <button type="button" class="btn btn-secondary" onclick="showRecoverBack()">Voltar ao Login</button>
        </form>
    </div>
</div>

<!-- APP -->
<div class="app" id="app">
    <div class="chat-header">
        <img src="" class="chat-header-avatar" id="headerAvatar" onclick="showMyProfile()" alt="">
        <div class="chat-header-info">
            <h2>💬 WHATSAPP CHAT</h2>
            <span id="onlineCount">Carregando...</span>
        </div>
        <div class="header-actions">
            <button class="header-btn" onclick="toggleSideMenu()" title="Menu">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg>
            </button>
        </div>
    </div>
    <div class="chat-area" id="chatArea">
        <div id="messagesContainer"></div>
        <div class="typing-indicator" id="typingIndicator">
            <div class="typing-bubble">
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
            </div>
        </div>
    </div>
    <div class="reply-preview" id="replyPreview">
        <div class="reply-preview-text" id="replyPreviewText"></div>
        <span class="reply-preview-close" onclick="cancelReply()">✕</span>
    </div>
    <div class="file-preview" id="filePreview">
        <span>📎</span>
        <span class="file-preview-name" id="filePreviewName"></span>
        <span class="file-preview-close" onclick="cancelFile()">✕</span>
    </div>
    <div class="input-area">
        <input type="file" id="fileInput" style="display:none" onchange="onFileSelected(this)">
        <button class="input-btn" onclick="document.getElementById('fileInput').click()" title="Anexar arquivo">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"/></svg>
        </button>
        <button class="input-btn" onclick="openCamera()" title="Tirar foto">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>
        </button>
        <div class="input-wrapper">
            <textarea id="messageInput" placeholder="Mensagem" rows="1" maxlength="1000" onkeydown="handleKeyDown(event)" oninput="autoResize(this); updateCharCount(this)"></textarea>
            <span class="char-count" id="charCount">0/1000</span>
        </div>
        <button class="input-btn send-btn" id="sendBtn" onclick="sendMessage()" title="Enviar">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
        </button>
        <button class="input-btn" id="recordBtn" onmousedown="startRecording()" ontouchstart="startRecording()" onmouseup="stopRecording()" ontouchend="stopRecording()" title="Segure para gravar áudio">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3z"/><path d="M17 11c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"/></svg>
        </button>
    </div>
</div>

<!-- Recording Overlay -->
<div class="recording-overlay" id="recordingOverlay">
    <div class="recording-dot"></div>
    <div class="recording-wave">
        <span></span><span></span><span></span><span></span><span></span>
    </div>
    <div class="recording-time" id="recordingTime">00:00</div>
</div>

<!-- Camera Overlay -->
<div class="camera-overlay" id="cameraOverlay">
    <div class="camera-preview">
        <video id="cameraVideo" autoplay playsinline></video>
        <canvas id="cameraCanvas"></canvas>
    </div>
    <div class="camera-controls">
        <button class="camera-btn cancel" onclick="closeCamera()">Cancelar</button>
        <button class="camera-btn shutter" onclick="takePhoto()"></button>
    </div>
</div>

<!-- Context Menu -->
<div class="context-menu" id="contextMenu">
    <div class="context-menu-item" onclick="replyToMessage()"><span>↩️</span> Responder</div>
    <div class="context-menu-item" onclick="copyMessage()"><span>📋</span> Copiar</div>
    <div class="context-menu-divider"></div>
    <div class="context-menu-item" onclick="deleteForMe()"><span>🗑️</span> Excluir para mim</div>
    <div class="context-menu-item danger" id="deleteForAllItem" onclick="deleteForAll()"><span>🗑️</span> Excluir para todos</div>
</div>

<!-- Image Viewer -->
<div class="image-viewer" id="imageViewer" onclick="closeImageViewer()">
    <span class="image-viewer-close">✕</span>
    <img src="" id="imageViewerImg" alt="">
</div>

<!-- Profile Modal -->
<div class="modal-overlay" id="profileModal" onclick="closeModal(event, 'profileModal')">
    <div class="modal-box" onclick="event.stopPropagation()">
        <div class="modal-header">
            <span class="modal-close" onclick="hideModal('profileModal')">✕</span>
            <img src="" class="modal-avatar" id="profileAvatar" alt="">
            <h3 id="profileName">Usuário</h3>
        </div>
        <div class="modal-body" id="profileBody">
            <div class="modal-field"><label>Descrição</label><p id="profileDescription">-</p></div>
            <div class="modal-field"><label>País</label><p id="profileCountry">-</p></div>
            <div class="modal-field"><label>Idioma</label><p id="profileLanguage">-</p></div>
            <div class="modal-field"><label>Membro desde</label><p id="profileJoined">-</p></div>
        </div>
    </div>
</div>

<!-- Edit Profile Modal -->
<div class="modal-overlay" id="editProfileModal" onclick="closeModal(event, 'editProfileModal')">
    <div class="modal-box" onclick="event.stopPropagation()">
        <div class="modal-header">
            <span class="modal-close" onclick="hideModal('editProfileModal')">✕</span>
            <h3>Editar Perfil</h3>
        </div>
        <form class="edit-profile-form" onsubmit="return saveProfile(event)">
            <div class="form-group">
                <img src="" class="avatar-preview" id="editAvatarPreview" style="display:none">
                <label>Nova Foto</label>
                <input type="file" name="avatar" accept="image/*" onchange="previewAvatar(this, 'editAvatarPreview')">
            </div>
            <div class="form-group">
                <label>Descrição</label>
                <textarea name="description" id="editDescription" maxlength="200"></textarea>
            </div>
            <div class="form-group">
                <label>País</label>
                <input type="text" name="country" id="editCountry" maxlength="50">
            </div>
            <div class="form-group">
                <label>Idioma Nativo</label>
                <input type="text" name="language" id="editLanguage" maxlength="50">
            </div>
            <button type="submit" class="btn btn-primary">Salvar Alterações</button>
        </form>
    </div>
</div>

<!-- Views Modal -->
<div class="modal-overlay views-modal" id="viewsModal" onclick="closeModal(event, 'viewsModal')">
    <div class="modal-box" onclick="event.stopPropagation()">
        <div class="modal-header">
            <span class="modal-close" onclick="hideModal('viewsModal')">✕</span>
            <h3>Visualizações</h3>
        </div>
        <div class="views-list" id="viewsList"></div>
    </div>
</div>

<!-- All Users Modal -->
<div class="modal-overlay" id="allUsersModal" onclick="closeModal(event, 'allUsersModal')">
    <div class="modal-box" onclick="event.stopPropagation()">
        <div class="modal-header">
            <span class="modal-close" onclick="hideModal('allUsersModal')">✕</span>
            <h3>Todos os Usuários</h3>
        </div>
        <div class="all-users-list" id="allUsersList"></div>
    </div>
</div>

<!-- Side Menu -->
<div class="side-menu-overlay" id="sideMenuOverlay" onclick="toggleSideMenu()"></div>
<div class="side-menu" id="sideMenu">
    <div class="side-menu-header">
        <img src="" class="side-menu-avatar" id="sideMenuAvatar" onclick="showMyProfile(); toggleSideMenu();" alt="">
        <h3 id="sideMenuName">Usuário</h3>
        <p id="sideMenuStatus">online</p>
    </div>
    <div class="side-menu-items">
        <div class="side-menu-item" onclick="showMyProfile(); toggleSideMenu();">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <span>Meu Perfil</span>
        </div>
        <div class="side-menu-item" onclick="showEditProfile(); toggleSideMenu();">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            <span>Editar Perfil</span>
        </div>
        <div class="side-menu-item" onclick="showAllUsers(); toggleSideMenu();">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
            <span>Todos os Usuários</span>
        </div>
        <div class="side-menu-item" onclick="doLogout(); toggleSideMenu();">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            <span>Sair</span>
        </div>
    </div>
</div>

<!-- Scroll Bottom -->
<button class="scroll-bottom" id="scrollBottom" onclick="scrollToBottom()">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg>
    <span class="badge" id="newMessagesBadge" style="display:none">0</span>
</button>

<!-- Toast -->
<div class="toast" id="toast"></div>

<script>
// ============================================================
// VARIAVEIS GLOBAIS
// ============================================================
let currentUser = null;
let lastMessageId = 0;
let replyToId = null;
let selectedFile = null;
let contextMsgId = null;
let contextMsgUserId = null;
let contextMsgIsOwn = false;
let newMessagesCount = 0;
let isAtBottom = true;
let pollInterval = null;
let cachedAllUsers = [];
let lastDateSeparator = null;

// Gravacao
let mediaRecorder = null;
let recordedChunks = [];
let recordingStartTime = 0;
let recordingTimer = null;
let isRecording = false;

// Camera
let cameraStream = null;

// ============================================================
// UTILITARIOS
// ============================================================
function $(id) { return document.getElementById(id); }

function showToast(msg) {
    const t = $('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3000);
}

function formatTime(ts) {
    const d = new Date(ts * 1000);
    return d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function makeLinks(text) {
    return text.replace(/(https?:\/\/[^\s<]+)/gi, '<a href="$1" target="_blank" rel="noopener noreferrer" class="chat-link">$1</a>');
}

function getAvatarUrl(avatar) {
    return avatar ? '?action=avatar&f=' + encodeURIComponent(avatar) : '?action=avatar&f=default';
}

function getFileUrl(filePath) {
    return '?action=file&f=' + encodeURIComponent(filePath);
}

function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function formatDateSeparator(ts) {
    const d = new Date(ts * 1000);
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);
    const msgDate = new Date(d.getFullYear(), d.getMonth(), d.getDate());

    if (msgDate.getTime() === today.getTime()) return 'Hoje';
    if (msgDate.getTime() === yesterday.getTime()) return 'Ontem';

    const days = ['Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado'];
    const months = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];

    if (now.getTime() - d.getTime() < 7 * 86400000) return days[d.getDay()];
    return d.getDate() + ' de ' + months[d.getMonth() + 1] + ' de ' + d.getFullYear();
}

function formatLastSeen(ts) {
    const diff = Math.floor((Date.now() / 1000) - ts);
    if (diff < 60) return 'online agora';
    if (diff < 3600) return 'visto por último ha ' + Math.floor(diff/60) + ' min';
    if (diff < 7200) return 'visto por último ha 1 hora';
    if (diff < 86400) return 'visto por último ha ' + Math.floor(diff/3600) + ' horas';
    if (diff < 172800) return 'visto por último ontem as ' + new Date(ts * 1000).toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'});
    return 'visto por último ' + new Date(ts * 1000).toLocaleDateString('pt-BR') + ' as ' + new Date(ts * 1000).toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'});
}

// ============================================================
// AUTENTICACAO
// ============================================================
function showTab(tab, el) {
    document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));
    if (el) el.classList.add('active');
    $(tab + 'Form').classList.add('active');
}

function showRecover() {
    document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));
    $('recoverForm').classList.add('active');
}

function showRecoverBack() {
    document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));
    $('loginForm').classList.add('active');
    document.querySelectorAll('.auth-tab')[0].classList.add('active');
}

function previewAvatar(input, previewId) {
    const file = input.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = e => {
            $(previewId).src = e.target.result;
            $(previewId).style.display = 'block';
        };
        reader.readAsDataURL(file);
    }
}

async function doLogin(e) {
    e.preventDefault();
    const form = e.target;
    const data = new FormData(form);
    try {
        const res = await fetch('?action=login', { method: 'POST', body: data });
        const json = await res.json();
        if (json.error) {
            $('loginError').textContent = json.error;
            $('loginError').style.display = 'block';
        } else {
            startApp();
        }
    } catch (err) {
        $('loginError').textContent = 'Erro de conexao';
        $('loginError').style.display = 'block';
    }
    return false;
}

async function doRegister(e) {
    e.preventDefault();
    const form = e.target;
    const data = new FormData(form);
    try {
        const res = await fetch('?action=register', { method: 'POST', body: data });
        const json = await res.json();
        if (json.error) {
            $('registerError').textContent = json.error;
            $('registerError').style.display = 'block';
            $('registerSuccess').style.display = 'none';
        } else {
            $('registerError').style.display = 'none';
            $('registerSuccess').textContent = json.message;
            $('registerSuccess').style.display = 'block';
            form.reset();
            $('regAvatarPreview').style.display = 'none';
            setTimeout(() => {
                const loginTab = document.querySelectorAll('.auth-tab')[0];
                showTab('login', loginTab);
            }, 2000);
        }
    } catch (err) {
        $('registerError').textContent = 'Erro de conexao';
        $('registerError').style.display = 'block';
    }
    return false;
}

async function doRecover(e) {
    e.preventDefault();
    const form = e.target;
    const data = new FormData(form);
    try {
        const res = await fetch('?action=recover', { method: 'POST', body: data });
        const json = await res.json();
        if (json.error) {
            $('recoverError').textContent = json.error;
            $('recoverError').style.display = 'block';
            $('recoverSuccess').style.display = 'none';
        } else {
            $('recoverError').style.display = 'none';
            $('recoverSuccess').textContent = json.message;
            $('recoverSuccess').style.display = 'block';
            form.reset();
        }
    } catch (err) {
        $('recoverError').textContent = 'Erro de conexao';
        $('recoverError').style.display = 'block';
    }
    return false;
}

async function doLogout() {
    await fetch('?action=logout');
    location.reload();
}

// ============================================================
// APP
// ============================================================
async function startApp() {
    $('authScreen').style.display = 'none';
    $('app').classList.add('active');

    const res = await fetch('?action=get_profile&user_id=0');
    const json = await res.json();
    if (json.profile) {
        currentUser = json.profile;
        updateUI();
    }

    // Carrega historico inicial
    await loadMessages();

    // UMA UNICA REQUISICAO a cada 1s (mensagens + usuarios + tudo)
    pollInterval = setInterval(doPoll, 1000);

    $('chatArea').addEventListener('scroll', () => {
        const area = $('chatArea');
        isAtBottom = area.scrollHeight - area.scrollTop - area.clientHeight < 50;
        if (isAtBottom) {
            newMessagesCount = 0;
            $('newMessagesBadge').style.display = 'none';
            $('scrollBottom').classList.remove('show');
        }
    });
}

function updateUI() {
    if (!currentUser) return;
    const avatarUrl = getAvatarUrl(currentUser.avatar);
    $('headerAvatar').src = avatarUrl;
    $('sideMenuAvatar').src = avatarUrl;
    $('sideMenuName').textContent = currentUser.username;
}

// ============================================================
// POLLING UNIFICADO (1 requisicao = tudo)
// ============================================================
async function doPoll() {
    try {
        const res = await fetch('?action=poll&last_id=' + lastMessageId);
        const json = await res.json();

        // 1. Mensagens
        if (json.messages && json.messages.length > 0) {
            const wasAtBottom = isAtBottom;
            json.messages.forEach(msg => {
                renderMessage(msg, json.user_id);
                lastMessageId = Math.max(lastMessageId, msg.id);
            });
            if (wasAtBottom) {
                scrollToBottom();
            } else {
                newMessagesCount += json.messages.length;
                $('newMessagesBadge').textContent = newMessagesCount;
                $('newMessagesBadge').style.display = 'flex';
                $('scrollBottom').classList.add('show');
            }
        }

        // 2. Contagem online no header
        if (json.online_count !== undefined) {
            $('onlineCount').textContent = json.online_count + ' online';
        }

        // 3. Cache de todos usuarios (para o modal)
        if (json.all_users) {
            cachedAllUsers = json.all_users;
        }

    } catch (e) {}
}

// ============================================================
// MENSAGENS
// ============================================================
async function loadMessages() {
    try {
        const res = await fetch('?action=get_messages&last_id=' + lastMessageId);
        const json = await res.json();
        if (json.messages && json.messages.length > 0) {
            json.messages.forEach(msg => {
                renderMessage(msg, json.user_id);
                lastMessageId = Math.max(lastMessageId, msg.id);
            });
            scrollToBottom();
        }
    } catch (e) {}
}

function renderMessage(msg, myUserId) {
    const isOwn = msg.user_id == myUserId;
    const msgDate = new Date(msg.created_at * 1000);
    const dateKey = msgDate.getFullYear() + '-' + msgDate.getMonth() + '-' + msgDate.getDate();

    // Adiciona separador de data se necessario
    if (lastDateSeparator !== dateKey) {
        lastDateSeparator = dateKey;
        const sep = document.createElement('div');
        sep.className = 'date-separator';
        sep.innerHTML = '<span>' + formatDateSeparator(msg.created_at) + '</span>';
        $('messagesContainer').appendChild(sep);
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'message-wrapper ' + (isOwn ? 'own' : 'other');
    wrapper.dataset.msgId = msg.id;
    wrapper.dataset.userId = msg.user_id;

    let contentHtml = '';

    if (msg.reply_to) {
        const replyContent = msg.reply_content || 'Mensagem';
        contentHtml += `<div class="msg-reply" onclick="scrollToMessage(${msg.reply_to})">
            <div class="msg-reply-name">${escapeHtml(msg.reply_username || 'Alguem')}</div>
            <div class="msg-reply-text">${escapeHtml(replyContent.substring(0, 60))}</div>
        </div>`;
    }

    if (msg.deleted_for_all == '1') {
        contentHtml += '<span class="msg-deleted">🗑️ Esta mensagem foi excluida</span>';
    } else if (msg.type === 'image') {
        contentHtml += `<img src="${getFileUrl(msg.file_path)}" class="msg-image" onclick="openImageViewer('${getFileUrl(msg.file_path)}')" alt="">`;
        if (msg.content) contentHtml += `<div class="msg-content">${makeLinks(escapeHtml(msg.content))}</div>`;
    } else if (msg.type === 'audio') {
        contentHtml += `<div class="msg-audio"><span class="audio-icon">🎵</span><audio controls src="${getFileUrl(msg.file_path)}"></audio></div>`;
        if (msg.content) contentHtml += `<div class="msg-content">${makeLinks(escapeHtml(msg.content))}</div>`;
    } else if (msg.type === 'file') {
        contentHtml += `<a href="${getFileUrl(msg.file_path)}" download="${escapeHtml(msg.file_name)}" class="msg-file">
            <span class="file-icon">📄</span>
            <div class="file-info"><div class="file-name">${escapeHtml(msg.file_name)}</div><div class="file-size">${formatBytes(msg.file_size)}</div></div>
        </a>`;
        if (msg.content) contentHtml += `<div class="msg-content">${makeLinks(escapeHtml(msg.content))}</div>`;
    } else {
        contentHtml += `<div class="msg-content">${makeLinks(escapeHtml(msg.content))}</div>`;
    }

    let statusHtml = '';
    if (isOwn && msg.deleted_for_all != '1') {
        const viewers = msg.viewers ? msg.viewers.split(',') : [];
        const isViewed = viewers.length > 1;
        statusHtml = `<span class="msg-status ${isViewed ? 'viewed' : ''}" onclick="showViews(${msg.id}, event)" title="Ver quem visualizou">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                ${isViewed 
                    ? '<path d="M18 6L6 18M6 6l12 12" stroke-linecap="round" stroke-linejoin="round"/><path d="M20 6L8 18" stroke-linecap="round" stroke-linejoin="round"/>' 
                    : '<path d="M18 6L6 18M6 6l12 12" stroke-linecap="round" stroke-linejoin="round"/>'}
            </svg>
        </span>`;
    }

    const avatarHtml = !isOwn ? `<img src="${getAvatarUrl(msg.avatar)}" class="msg-avatar" onclick="showUserProfile(${msg.user_id})" alt="">` : '';
    const senderHtml = !isOwn ? `<div class="msg-sender" onclick="showUserProfile(${msg.user_id})">${escapeHtml(msg.username)}</div>` : '';

    wrapper.innerHTML = avatarHtml + `
        <div class="message" oncontextmenu="showContextMenu(event, ${msg.id}, ${msg.user_id}, ${isOwn ? 1 : 0})">
            ${senderHtml}
            ${contentHtml}
            <div class="msg-footer">
                <span class="msg-time">${formatTime(msg.created_at)}</span>
                ${statusHtml}
            </div>
        </div>
    `;

    $('messagesContainer').appendChild(wrapper);
}

// ============================================================
// ENVIO DE MENSAGENS
// ============================================================
async function sendMessage() {
    const input = $('messageInput');
    const content = input.value.trim();

    if (!content && !selectedFile) return;
    if (content.length > 1000) {
        showToast('Mensagem muito longa (max 1000 caracteres)');
        return;
    }

    const formData = new FormData();
    formData.append('content', content);
    if (replyToId) formData.append('reply_to', replyToId);
    if (selectedFile) formData.append('file', selectedFile);

    input.value = '';
    input.style.height = 'auto';
    updateCharCount(input);
    cancelReply();
    cancelFile();

    try {
        const res = await fetch('?action=send_message', { method: 'POST', body: formData });
        const json = await res.json();
        if (json.error) showToast(json.error);
    } catch (e) {
        showToast('Erro ao enviar mensagem');
    }
}

function handleKeyDown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
}

function autoResize(textarea) {
    textarea.style.height = 'auto';
    textarea.style.height = Math.min(textarea.scrollHeight, 100) + 'px';
}

function updateCharCount(textarea) {
    $('charCount').textContent = textarea.value.length + '/1000';
}

// ============================================================
// GRAVACAO DE AUDIO
// ============================================================
async function startRecording() {
    if (isRecording) return;

    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        mediaRecorder = new MediaRecorder(stream);
        recordedChunks = [];

        mediaRecorder.ondataavailable = (e) => {
            if (e.data.size > 0) recordedChunks.push(e.data);
        };

        mediaRecorder.onstop = () => {
            const blob = new Blob(recordedChunks, { type: 'audio/webm' });
            if (blob.size > 0) {
                const file = new File([blob], 'audio_' + Date.now() + '.webm', { type: 'audio/webm' });
                sendAudioFile(file);
            }
            stream.getTracks().forEach(t => t.stop());
        };

        mediaRecorder.start();
        isRecording = true;
        recordingStartTime = Date.now();

        $('recordBtn').classList.add('recording');
        $('recordingOverlay').classList.add('active');
        $('sendBtn').style.display = 'none';

        recordingTimer = setInterval(updateRecordingTime, 1000);

    } catch (err) {
        showToast('Permissao de microfone negada');
    }
}

function stopRecording() {
    if (!isRecording || !mediaRecorder) return;

    isRecording = false;
    clearInterval(recordingTimer);

    if (mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop();
    }

    $('recordBtn').classList.remove('recording');
    $('recordingOverlay').classList.remove('active');
    $('sendBtn').style.display = 'flex';
    $('recordingTime').textContent = '00:00';
}

function updateRecordingTime() {
    const elapsed = Math.floor((Date.now() - recordingStartTime) / 1000);
    const mins = String(Math.floor(elapsed / 60)).padStart(2, '0');
    const secs = String(elapsed % 60).padStart(2, '0');
    $('recordingTime').textContent = mins + ':' + secs;
}

async function sendAudioFile(file) {
    if (file.size > 20 * 1024 * 1024) {
        showToast('Audio muito grande (max 20MB)');
        return;
    }

    const formData = new FormData();
    formData.append('content', '');
    formData.append('file', file);
    if (replyToId) formData.append('reply_to', replyToId);

    cancelReply();

    try {
        const res = await fetch('?action=send_message', { method: 'POST', body: formData });
        const json = await res.json();
        if (json.error) showToast(json.error);
    } catch (e) {
        showToast('Erro ao enviar audio');
    }
}

// ============================================================
// CAMERA / TIRAR FOTO
// ============================================================
async function openCamera() {
    try {
        cameraStream = await navigator.mediaDevices.getUserMedia({ 
            video: { facingMode: 'environment' }, 
            audio: false 
        });
        $('cameraVideo').srcObject = cameraStream;
        $('cameraOverlay').classList.add('active');
    } catch (err) {
        showToast('Permissao de camera negada');
    }
}

function closeCamera() {
    if (cameraStream) {
        cameraStream.getTracks().forEach(t => t.stop());
        cameraStream = null;
    }
    $('cameraOverlay').classList.remove('active');
}

function takePhoto() {
    const video = $('cameraVideo');
    const canvas = $('cameraCanvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0);

    canvas.toBlob((blob) => {
        const file = new File([blob], 'photo_' + Date.now() + '.jpg', { type: 'image/jpeg' });
        closeCamera();

        if (file.size > 10 * 1024 * 1024) {
            showToast('Foto muito grande (max 10MB)');
            return;
        }

        const formData = new FormData();
        formData.append('content', '');
        formData.append('file', file);
        if (replyToId) formData.append('reply_to', replyToId);

        cancelReply();

        fetch('?action=send_message', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(json => { if (json.error) showToast(json.error); })
            .catch(() => showToast('Erro ao enviar foto'));
    }, 'image/jpeg', 0.9);
}

// ============================================================
// ARQUIVOS
// ============================================================
function onFileSelected(input) {
    const file = input.files[0];
    if (!file) return;
    const maxSize = file.type.startsWith('image/') ? 10 * 1024 * 1024 : 20 * 1024 * 1024;
    if (file.size > maxSize) {
        showToast('Arquivo muito grande! Max: ' + (maxSize / 1024 / 1024) + 'MB');
        input.value = '';
        return;
    }
    selectedFile = file;
    $('filePreviewName').textContent = file.name + ' (' + formatBytes(file.size) + ')';
    $('filePreview').classList.add('active');
}

function cancelFile() {
    selectedFile = null;
    $('filePreview').classList.remove('active');
    $('fileInput').value = '';
}

// ============================================================
// REPLY
// ============================================================
function replyToMessage() {
    if (!contextMsgId) return;
    replyToId = contextMsgId;
    const msgEl = document.querySelector(`[data-msg-id="${contextMsgId}"]`);
    if (msgEl) {
        const content = msgEl.querySelector('.msg-content')?.textContent || '';
        const sender = msgEl.querySelector('.msg-sender')?.textContent || 'Alguem';
        $('replyPreviewText').textContent = sender + ': ' + content.substring(0, 50);
        $('replyPreview').classList.add('active');
    }
    hideContextMenu();
    $('messageInput').focus();
}

function cancelReply() {
    replyToId = null;
    $('replyPreview').classList.remove('active');
}

function scrollToMessage(msgId) {
    const el = document.querySelector(`[data-msg-id="${msgId}"]`);
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el.style.background = 'rgba(18,140,126,0.2)';
        setTimeout(() => el.style.background = '', 1500);
    }
}

// ============================================================
// CONTEXT MENU
// ============================================================
function showContextMenu(e, msgId, userId, isOwn) {
    e.preventDefault();
    contextMsgId = msgId;
    contextMsgUserId = userId;
    contextMsgIsOwn = isOwn;

    const menu = $('contextMenu');
    const deleteAllItem = $('deleteForAllItem');

    if (isOwn) {
        deleteAllItem.style.display = 'flex';
    } else {
        deleteAllItem.style.display = 'none';
    }

    menu.style.display = 'block';
    menu.style.left = e.pageX + 'px';
    menu.style.top = e.pageY + 'px';

    const rect = menu.getBoundingClientRect();
    if (rect.right > window.innerWidth) menu.style.left = (e.pageX - rect.width) + 'px';
    if (rect.bottom > window.innerHeight) menu.style.top = (e.pageY - rect.height) + 'px';
}

function hideContextMenu() {
    $('contextMenu').style.display = 'none';
}

document.addEventListener('click', hideContextMenu);

function copyMessage() {
    if (!contextMsgId) return;
    const msgEl = document.querySelector(`[data-msg-id="${contextMsgId}"]`);
    if (msgEl) {
        const text = msgEl.querySelector('.msg-content')?.textContent || '';
        navigator.clipboard.writeText(text).then(() => showToast('Copiado!'));
    }
    hideContextMenu();
}

async function deleteForMe() {
    if (!contextMsgId) return;
    try {
        const formData = new FormData();
        formData.append('message_id', contextMsgId);
        formData.append('for_all', '0');
        await fetch('?action=delete_message', { method: 'POST', body: formData });
        const el = document.querySelector(`[data-msg-id="${contextMsgId}"]`);
        if (el) el.remove();
        showToast('Mensagem excluida');
    } catch (e) {}
    hideContextMenu();
}

async function deleteForAll() {
    if (!contextMsgId) return;
    try {
        const formData = new FormData();
        formData.append('message_id', contextMsgId);
        formData.append('for_all', '1');
        await fetch('?action=delete_message', { method: 'POST', body: formData });
        const el = document.querySelector(`[data-msg-id="${contextMsgId}"]`);
        if (el) {
            const content = el.querySelector('.message');
            content.innerHTML = '<span class="msg-deleted">🗑️ Esta mensagem foi excluida</span>';
        }
        showToast('Mensagem excluida para todos');
    } catch (e) {}
    hideContextMenu();
}

// ============================================================
// VISUALIZACOES
// ============================================================
async function showViews(msgId, e) {
    e.stopPropagation();
    try {
        const res = await fetch('?action=get_views&message_id=' + msgId);
        const json = await res.json();
        const list = $('viewsList');
        list.innerHTML = '';

        if (json.views && json.views.length > 0) {
            json.views.forEach(v => {
                const item = document.createElement('div');
                item.className = 'view-item';
                item.innerHTML = `
                    <img src="${getAvatarUrl(v.avatar)}" alt="">
                    <div class="view-item-info">
                        <div class="view-item-name">${escapeHtml(v.username)}</div>
                        <div class="view-item-time">${formatTime(v.viewed_at)}</div>
                    </div>
                `;
                list.appendChild(item);
            });
        } else {
            list.innerHTML = '<div style="padding:20px;text-align:center;color:#999">Ninguem visualizou ainda</div>';
        }

        $('viewsModal').classList.add('active');
    } catch (e) {}
}

// ============================================================
// PERFIS
// ============================================================
async function showUserProfile(userId) {
    try {
        const res = await fetch('?action=get_profile&user_id=' + userId);
        const json = await res.json();
        if (json.profile) {
            renderProfile(json.profile);
            $('profileModal').classList.add('active');
        }
    } catch (e) {}
}

async function showMyProfile() {
    if (!currentUser) return;
    try {
        const res = await fetch('?action=get_profile&user_id=' + currentUser.id);
        const json = await res.json();
        if (json.profile) {
            renderProfile(json.profile);
            $('profileModal').classList.add('active');
        }
    } catch (e) {}
}

function renderProfile(profile) {
    $('profileAvatar').src = getAvatarUrl(profile.avatar);
    $('profileName').textContent = escapeHtml(profile.username);
    $('profileDescription').textContent = profile.description || 'Sem descricao';
    $('profileCountry').textContent = profile.country || 'Nao informado';
    $('profileLanguage').textContent = profile.language || 'Nao informado';
    $('profileJoined').textContent = new Date(profile.created_at * 1000).toLocaleDateString('pt-BR');
}

function showEditProfile() {
    if (!currentUser) return;
    $('editDescription').value = currentUser.description || '';
    $('editCountry').value = currentUser.country || '';
    $('editLanguage').value = currentUser.language || '';
    if (currentUser.avatar) {
        $('editAvatarPreview').src = getAvatarUrl(currentUser.avatar);
        $('editAvatarPreview').style.display = 'block';
    }
    $('editProfileModal').classList.add('active');
}

async function saveProfile(e) {
    e.preventDefault();
    const form = e.target;
    const data = new FormData(form);
    try {
        const res = await fetch('?action=update_profile', { method: 'POST', body: data });
        const json = await res.json();
        if (json.success) {
            showToast('Perfil atualizado!');
            hideModal('editProfileModal');
            const pres = await fetch('?action=get_profile&user_id=' + currentUser.id);
            const pjson = await pres.json();
            if (pjson.profile) {
                currentUser = pjson.profile;
                updateUI();
            }
        }
    } catch (e) {}
    return false;
}

// ============================================================
// TODOS OS USUARIOS (online e offline) - usa cache do poll
// ============================================================
function showAllUsers() {
    const list = $('allUsersList');
    list.innerHTML = '';

    if (cachedAllUsers && cachedAllUsers.length > 0) {
        cachedAllUsers.forEach(u => {
            const isOnline = (Date.now() / 1000) - u.last_seen < 60;
            const item = document.createElement('div');
            item.className = 'all-user-item';
            item.innerHTML = `
                <img src="${getAvatarUrl(u.avatar)}" alt="">
                <div class="all-user-info">
                    <div class="all-user-name">${escapeHtml(u.username)}</div>
                    <div class="all-user-status">${isOnline ? 'online' : formatLastSeen(u.last_seen)}</div>
                </div>
                <span class="all-user-dot ${isOnline ? 'online' : 'offline'}"></span>
            `;
            item.onclick = () => { hideModal('allUsersModal'); showUserProfile(u.id); };
            list.appendChild(item);
        });
    } else {
        list.innerHTML = '<div style="padding:20px;text-align:center;color:#999">Nenhum usuario encontrado</div>';
    }

    $('allUsersModal').classList.add('active');
}

// ============================================================
// MODAIS E UI
// ============================================================
function hideModal(id) {
    if (id) $(id).classList.remove('active');
}

function closeModal(e, id) {
    if (e.target === $(id)) $(id).classList.remove('active');
}

function openImageViewer(src) {
    $('imageViewerImg').src = src;
    $('imageViewer').classList.add('active');
}

function closeImageViewer() {
    $('imageViewer').classList.remove('active');
}

function scrollToBottom() {
    const area = $('chatArea');
    area.scrollTop = area.scrollHeight;
    isAtBottom = true;
    newMessagesCount = 0;
    $('newMessagesBadge').style.display = 'none';
    $('scrollBottom').classList.remove('show');
}

function toggleSideMenu() {
    $('sideMenu').classList.toggle('open');
    $('sideMenuOverlay').classList.toggle('active');
}

// ============================================================
// INICIALIZACAO
// ============================================================
document.addEventListener('DOMContentLoaded', () => {
    fetch('?action=ping')
        .then(r => r.json())
        .then(json => {
            if (json.ok) startApp();
        })
        .catch(() => {});
});

window.addEventListener('beforeunload', () => {
    if (pollInterval) clearInterval(pollInterval);
    if (isRecording) stopRecording();
    if (cameraStream) cameraStream.getTracks().forEach(t => t.stop());
    navigator.sendBeacon('?action=logout');
});
</script>
</body>
</html>
