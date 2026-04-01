<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$db = new SQLite3(__DIR__ . '/data/game.sqlite');
$db->busyTimeout(5000);

function response($code, $msg, $data = []) {
    echo json_encode(array_merge(['code' => $code, 'msg' => $msg], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function getParam($key, $default = '') {
    return $_POST[$key] ?? ($_GET[$key] ?? $default);
}

$action = getParam('action', '');

if ($action === 'register') {
    $username = trim(getParam('username', ''));
    $password = getParam('password', '');
    
    if (empty($username) || empty($password)) {
        response(400, '用户名和密码不能为空');
    }
    if (strlen($password) < 6) {
        response(400, '密码至少6位');
    }
    
    $passwordHash = hash('sha256', $password);
    $stmt = $db->prepare('INSERT INTO users (username, password, gold, role, level, exp, status) VALUES (:username, :password, 1000, "user", 1, 0, 1)');
    $stmt->bindValue(':username', $username);
    $stmt->bindValue(':password', $passwordHash);
    try {
        $stmt->execute();
        response(200, '注册成功', ['user_id' => $db->lastInsertRowID(), 'username' => $username, 'gold' => 1000]);
    } catch (Exception $e) {
        response(400, '用户名已存在');
    }
}
elseif ($action === 'login') {
    $username = trim(getParam('username', ''));
    $password = getParam('password', '');
    
    if (empty($username) || empty($password)) {
        response(400, '用户名和密码不能为空');
    }
    
    $stmt = $db->prepare('SELECT * FROM users WHERE username = :username');
    $stmt->bindValue(':username', $username);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$user) {
        response(401, '用户名或密码错误');
    }
    
    $passwordHash = hash('sha256', $password);
    if ($user['password'] !== $passwordHash) {
        response(401, '用户名或密码错误');
    }
    
    if ($user['status'] == 0) {
        response(403, '账号已被禁用');
    }
    
    $stmt = $db->prepare('SELECT * FROM member_level WHERE level = :level');
    $stmt->bindValue(':level', $user['level']);
    $result = $stmt->execute();
    $member = $result->fetchArray(SQLITE3_ASSOC);
    
    response(200, '登录成功', [
        'user_id' => $user['id'],
        'username' => $user['username'],
        'gold' => $user['gold'],
        'role' => $user['role'],
        'level' => $user['level'],
        'exp' => $user['exp'],
        'member' => $member
    ]);
}
elseif ($action === 'get_gold') {
    $userId = intval(getParam('user_id', 0));
    
    if ($userId <= 0) {
        response(400, 'user_id required');
    }
    
    $stmt = $db->prepare('SELECT gold FROM users WHERE id = :id');
    $stmt->bindValue(':id', $userId);
    $result = $stmt->execute();
    $row = $result->fetchArray();
    
    if ($row) {
        response(200, 'ok', ['gold' => $row['gold']]);
    } else {
        response(400, '用户不存在');
    }
}
elseif ($action === 'update_gold') {
    $userId = intval(getParam('user_id', 0));
    $change = intval(getParam('change', 0));
    
    if ($userId <= 0) {
        response(400, '参数错误');
    }
    
    $stmt = $db->prepare('SELECT gold FROM users WHERE id = :id');
    $stmt->bindValue(':id', $userId);
    $result = $stmt->execute();
    $row = $result->fetchArray();
    
    $newGold = $row['gold'] + $change;
    if ($newGold < 0) {
        response(400, '元宝不足');
    }
    
    $stmt = $db->prepare('UPDATE users SET gold = :gold WHERE id = :id');
    $stmt->bindValue(':gold', $newGold);
    $stmt->bindValue(':id', $userId);
    $stmt->execute();
    
    response(200, '更新成功', ['gold' => $newGold]);
}
elseif ($action === 'check') {
    // 检查登录状态 - 从 localStorage 获取 user_id，或者从 cookie 获取
    $userId = intval($_GET['user_id'] ?? ($_COOKIE['user_id'] ?? 0));
    
    if (!$userId) {
        response(200, 'ok', ['user' => null]);
    }
    
    $stmt = $db->prepare('SELECT id, username, gold, role, level, exp FROM users WHERE id = :id');
    $stmt->bindValue(':id', $userId);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($user) {
        response(200, 'ok', ['user' => $user]);
    } else {
        response(200, 'ok', ['user' => null]);
    }
}
elseif ($action === 'check_login') {
    $userId = intval(getParam('user_id', 0));
    if (!$userId) response(400, 'user_id required');
    
    $stmt = $db->prepare('SELECT id, username, gold, role, level, exp FROM users WHERE id = :id');
    $stmt->bindValue(':id', $userId);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($user) {
        response(200, 'ok', ['user' => $user]);
    } else {
        response(401, '未登录');
    }
}
else {
    response(400, '未知操作');
}

$db->close();