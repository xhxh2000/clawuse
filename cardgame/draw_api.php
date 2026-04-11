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

function response($code, $msg, $data = null) {
    if ($code, $msg, $data === null) $code, $msg, $data = []; {
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

// 获取用户元宝
if ($action === 'get_gold') {
    $userId = intval(getParam('user_id', 0));
    
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
    
    response(200, 'ok', ['gold' => $row['gold']]);
}

// 消费元宝
if ($action === 'spend_gold') {
    $userId = intval(getParam('user_id', 0));
    $amount = intval(getParam('amount', 0));
    
    if ($userId <= 0 || $amount <= 0) {
        response(400, '参数错误');
    }
    
    $stmt = $db->prepare('SELECT gold FROM users WHERE id = :id');
    $stmt->bindValue(':id', $userId);
    $result = $stmt->execute();
    $row = $result->fetchArray();
    
    if (!$row) {
        response(400, '用户不存在');
    }
    
    $newGold = $row['gold'] - $amount;
    if ($newGold < 0) {
        response(400, '元宝不足');
    }
    
    $stmt = $db->prepare('UPDATE users SET gold = :gold WHERE id = :id');
    $stmt->bindValue(':gold', $newGold);
    $stmt->bindValue(':id', $userId);
    $stmt->execute();
    
    response(200, 'ok', ['gold' => $newGold]);
}

// 增加元宝
if ($action === 'add_gold') {
    $userId = intval(getParam('user_id', 0));
    $amount = intval(getParam('amount', 0));
    
    if ($userId <= 0 || $amount <= 0) {
        response(400, '参数错误');
    }
    
    $stmt = $db->prepare('SELECT gold FROM users WHERE id = :id');
    $stmt->bindValue(':id', $userId);
    $result = $stmt->execute();
    $row = $result->fetchArray();
    
    if (!$row) {
        response(400, '用户不存在');
    }
    
    $newGold = $row['gold'] + $amount;
    
    $stmt = $db->prepare('UPDATE users SET gold = :gold WHERE id = :id');
    $stmt->bindValue(':gold', $newGold);
    $stmt->bindValue(':id', $userId);
    $stmt->execute();
    
    response(200, 'ok', ['gold' => $newGold]);
}

// 保存抽到的卡牌
if ($action === 'save_card') {
    $userId = intval(getParam('user_id', 0));
    $cardId = intval(getParam('card_id', 0));
    
    if ($userId <= 0 || $cardId <= 0) {
        response(400, '参数错误');
    }
    
    // 检查是否已有此卡
    $stmt = $db->prepare('SELECT * FROM user_cards WHERE user_id = :user_id AND card_id = :card_id');
    $stmt->bindValue(':user_id', $userId);
    $stmt->bindValue(':card_id', $cardId);
    $result = $stmt->execute();
    $existing = $result->fetchArray(SQLITE3_ASSOC);
    
    $now = date('Y-m-d H:i:s');
    
    if ($existing) {
        // 已存在，更新数量
        $newCount = $existing['count'] + 1;
        $stmt = $db->prepare('UPDATE user_cards SET count = :count, last_get = :last_get WHERE id = :id');
        $stmt->bindValue(':count', $newCount);
        $stmt->bindValue(':last_get', $now);
        $stmt->bindValue(':id', $existing['id']);
        $stmt->execute();
    } else {
        // 新卡
        $stmt = $db->prepare('INSERT INTO user_cards (user_id, card_id, count, first_get, last_get) VALUES (:user_id, :card_id, 1, :first_get, :last_get)');
        $stmt->bindValue(':user_id', $userId);
        $stmt->bindValue(':card_id', $cardId);
        $stmt->bindValue(':first_get', $now);
        $stmt->bindValue(':last_get', $now);
        $stmt->execute();
    }
    
    // 记录抽卡历史
    $stmt = $db->prepare('INSERT INTO draw_history (user_id, card_id, cost, is_free) VALUES (:user_id, :card_id, :cost, 0)');
    
    // 写入图鉴解锁记录（如果不存在）
    $stmt = $db->prepare('INSERT OR IGNORE INTO user_album (user_id, card_id) VALUES (:user_id, :card_id)');
    $stmt->bindValue(':user_id', $userId);
    $stmt->bindValue(':card_id', $cardId);
    $stmt->execute();
    $stmt->bindValue(':user_id', $userId);
    $stmt->bindValue(':card_id', $cardId);
    $stmt->bindValue(':cost', 0); // 稍后在调用时传入
    $stmt->execute();
    
    response(200, 'ok');
}

// 获取用户所有卡牌
if ($action === 'get_cards') {
    $userId = intval(getParam('user_id', 0));
    
    if ($userId <= 0) {
        response(400, '参数错误');
    }
    
    // 获取用户卡牌
    $stmt = $db->prepare('SELECT uc.*, c.name, c.rarity, c.image, c.description, c.base_stats, c.growth_stats, c.tags FROM user_cards uc JOIN cards c ON uc.card_id = c.id WHERE uc.user_id = :user_id ORDER BY c.rarity DESC, c.name');
    $stmt->bindValue(':user_id', $userId);
    $result = $stmt->execute();
    
    $cards = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $cards[] = $row;
    }
    
    response(200, 'ok', ['cards' => $cards]);
}

// 获取用户卡牌数量统计
if ($action === 'get_stats') {
    $userId = intval(getParam('user_id', 0));
    
    if ($userId <= 0) {
        response(400, '参数错误');
    }
    
    // 总卡数
    $stmt = $db->prepare('SELECT COUNT(*) as total, SUM(count) as total_cards FROM user_cards WHERE user_id = :user_id');
    $stmt->bindValue(':user_id', $userId);
    $result = $stmt->execute();
    $stats = $result->fetchArray(SQLITE3_ASSOC);
    
    // 各稀有度统计
    $stmt = $db->prepare('SELECT c.rarity, COUNT(*) as count, SUM(uc.count) as cards FROM user_cards uc JOIN cards c ON uc.card_id = c.id WHERE uc.user_id = :user_id GROUP BY c.rarity ORDER BY c.rarity DESC');
    $result = $stmt->execute();
    $byRarity = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $byRarity[] = $row;
    }
    
    response(200, 'ok', [
        'total' => $stats['total'] ?: 0,
        'total_cards' => $stats['total_cards'] ?: 0,
        'by_rarity' => $byRarity
    ]);
}

else {
    response(400, '未知操作');
}

$db->close();