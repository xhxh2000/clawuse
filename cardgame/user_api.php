<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

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

if (!$action) {
    response(400, '缺少action参数');
}

// 获取所有用户列表
if ($action === 'list') {
    $stmt = $db->prepare('SELECT id, username, role, level, gold, exp, status, created_at FROM users ORDER BY id');
    $result = $stmt->execute();
    
    $users = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $users[] = $row;
    }
    
    // 获取会员等级列表
    $stmt = $db->prepare('SELECT * FROM member_level ORDER BY level');
    $result = $stmt->execute();
    $memberLevels = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $memberLevels[] = $row;
    }
    
    response(200, 'ok', ['users' => $users, 'member_levels' => $memberLevels]);
}

// 获取单个用户详情
elseif ($action === 'get') {
    $userId = intval(getParam('user_id', 0));
    
    if ($userId <= 0) {
        response(400, '参数错误');
    }
    
    $stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->bindValue(':id', $userId);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$user) {
        response(400, '用户不存在');
    }
    
    response(200, 'ok', ['user' => $user]);
}

// 修改用户（元宝）
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
    
    if (!$row) {
        response(400, '用户不存在');
    }
    
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

// 修改用户资料（角色、等级）
elseif ($action === 'update_user') {
    $userId = intval(getParam('user_id', 0));
    $role = getParam('role', '');
    $level = intval(getParam('level', 1));
    $status = intval(getParam('status', 1));
    
    if ($userId <= 0) {
        response(400, '参数错误');
    }
    
    // 验证用户存在
    $stmt = $db->prepare('SELECT id FROM users WHERE id = :id');
    $stmt->bindValue(':id', $userId);
    $result = $stmt->execute();
    if (!$result->fetchArray()) {
        response(400, '用户不存在');
    }
    
    // 更新
    $stmt = $db->prepare('UPDATE users SET role = :role, level = :level, status = :status WHERE id = :id');
    $stmt->bindValue(':role', $role);
    $stmt->bindValue(':level', $level);
    $stmt->bindValue(':status', $status);
    $stmt->bindValue(':id', $userId);
    $stmt->execute();
    
    response(200, '更新成功');
}

// 删除用户
elseif ($action === 'delete') {
    $userId = intval(getParam('user_id', 0));
    
    if ($userId <= 0) {
        response(400, '参数错误');
    }
    
    // 不能删除自己
    $currentUser = getParam('current_user', '');
    $stmt = $db->prepare('SELECT username FROM users WHERE id = :id');
    $stmt->bindValue(':id', $userId);
    $result = $stmt->execute();
    $row = $result->fetchArray();
    
    if ($row && $row['username'] === $currentUser) {
        response(400, '不能删除自己');
    }
    
    $stmt = $db->prepare('DELETE FROM users WHERE id = :id');
    $stmt->bindValue(':id', $userId);
    $stmt->execute();
    
    // 删除用户的卡片
    $stmt = $db->prepare('DELETE FROM user_cards WHERE user_id = :id');
    $stmt->bindValue(':id', $userId);
    $stmt->execute();
    
    response(200, '删除成功');
}

else {
    response(400, '未知操作');
}

$db->close();