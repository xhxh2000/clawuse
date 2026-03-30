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

// 获取抽卡配置和全部卡牌
if ($action === 'get_all') {
    // 获取 rarity 配置
    $stmt = $db->prepare('SELECT * FROM rarity ORDER BY rarity');
    $result = $stmt->execute();
    $rarity = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rarity[] = $row;
    }
    
    // 获取 draw_config
    $stmt = $db->prepare('SELECT * FROM draw_config WHERE id = 1');
    $result = $stmt->execute();
    $config = $result->fetchArray(SQLITE3_ASSOC);
    
    // 解 JSON
    if ($config) {
        $config['card_pool'] = json_decode($config['card_pool'] ?? '[]', true);
        $config['rarity_weight'] = json_decode($config['rarity_weight'] ?? '[]', true);
        $config['tag'] = json_decode($config['tag'] ?? '[]', true);
        $config['tag_weight'] = json_decode($config['tag_weight'] ?? '[]', true);
    }
    
    // 获取所有卡牌
    $stmt = $db->prepare('SELECT * FROM cards ORDER BY rarity DESC, name');
    $result = $stmt->execute();
    $cards = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['base_stats'] = json_decode($row['base_stats'] ?? '{}', true);
        $row['growth_stats'] = json_decode($row['growth_stats'] ?? '{}', true);
        $row['tags'] = json_decode($row['tags'] ?? '[]', true);
        $cards[] = $row;
    }
    
    // settings
    $settings = [
        'drawCost' => 100,
        'currencyName' => '元宝'
    ];
    
    response(200, 'ok', [
        'settings' => $settings,
        'rarity' => $rarity,
        'config' => $config,
        'characters' => $cards
    ]);
}

// 获取稀有度配置
elseif ($action === 'get_rarity') {
    $stmt = $db->prepare('SELECT * FROM rarity ORDER BY rarity');
    $result = $stmt->execute();
    $rarity = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rarity[] = $row;
    }
    response(200, 'ok', ['rarity' => $rarity]);
}

// 获取卡牌列表
elseif ($action === 'get_cards') {
    $pool = getParam('pool', '');
    
    if ($pool) {
        $stmt = $db->prepare("SELECT * FROM cards WHERE tags LIKE :pool ORDER BY rarity DESC, name");
        $stmt->bindValue(':pool', '%' . $pool . '%');
    } else {
        $stmt = $db->prepare('SELECT * FROM cards ORDER BY rarity DESC, name');
    }
    $result = $stmt->execute();
    $cards = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['base_stats'] = json_decode($row['base_stats'] ?? '{}', true);
        $row['growth_stats'] = json_decode($row['growth_stats'] ?? '{}', true);
        $row['tags'] = json_decode($row['tags'] ?? '[]', true);
        $cards[] = $row;
    }
    response(200, 'ok', ['characters' => $cards]);
}

// 获取抽卡配置
elseif ($action === 'get_config') {
    $stmt = $db->prepare('SELECT * FROM draw_config WHERE id = 1');
    $result = $stmt->execute();
    $config = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($config) {
        $config['card_pool'] = json_decode($config['card_pool'] ?? '[]', true);
        $config['rarity_weight'] = json_decode($config['rarity_weight'] ?? '[]', true);
        $config['tag'] = json_decode($config['tag'] ?? '[]', true);
        $config['tag_weight'] = json_decode($config['tag_weight'] ?? '[]', true);
    }
    
    response(200, 'ok', ['config' => $config]);
}

else {
    response(400, '未知操作');
}

$db->close();