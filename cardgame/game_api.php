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
    $user_id = intval(getParam('user_id', 0));
    
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
        
        // 如果指定了user_id，计算拥有数量
        if ($user_id > 0) {
            $stmt2 = $db->prepare('SELECT COUNT(*) FROM user_cards WHERE user_id = :uid AND card_id = :cid');
            $stmt2->bindValue(':uid', $user_id);
            $stmt2->bindValue(':cid', $row['id']);
            $res2 = $stmt2->execute();
            $count = $res2->fetchArray();
            $row['owned_count'] = $count ? $count[0] : 0;
        } else {
            $row['owned_count'] = 0;
        }
        
        $cards[] = $row;
    }
    
    // settings - 从配置读取抽卡消耗
    $drawCost = $config['draw_cost'] ?? 100;
    $settings = [
        'drawCost' => $drawCost,
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

// 保存卡牌信息
elseif ($action === 'save_card') {
    // 支持 FormData 和 JSON 两种格式
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        $data = json_decode(file_get_contents('php://input'), true);
    } else {
        // FormData 格式
        $data = [
            'id' => $_POST['id'] ?? 0,
            'name' => $_POST['name'] ?? '',
            'rarity' => $_POST['rarity'] ?? 1,
            'image' => $_POST['image'] ?? '',
            'description' => $_POST['description'] ?? '',
            'base_stats' => $_POST['base_stats'] ?? '{}',
            'growth_stats' => $_POST['growth_stats'] ?? '{}',
            'tags' => $_POST['tags'] ?? '[]',
            'draw_weight' => $_POST['draw_weight'] ?? 100
        ];
    }
    
    $id = intval($data['id'] ?? 0);
    $name = $data['name'] ?? '';
    $rarity = intval($data['rarity'] ?? 1);
    $image = $data['image'] ?? '';
    $description = $data['description'] ?? '';
    $baseStats = is_array($data['base_stats']) ? json_encode($data['base_stats']) : $data['base_stats'];
    $growthStats = is_array($data['growth_stats']) ? json_encode($data['growth_stats']) : $data['growth_stats'];
    $tags = is_array($data['tags']) ? json_encode($data['tags']) : $data['tags'];
    $drawWeight = intval($data['draw_weight'] ?? 100);
    
    if (!$name) response(400, '名称不能为空');
    
    if ($id > 0) {
        // 更新
        $stmt = $db->prepare('UPDATE cards SET name=:name, rarity=:rarity, image=:image, description=:description, base_stats=:base_stats, growth_stats=:growth_stats, tags=:tags, draw_weight=:draw_weight WHERE id=:id');
        $stmt->bindValue(':id', $id);
    } else {
        // 新增
        $id = time();
        $stmt = $db->prepare('INSERT INTO cards (id, name, rarity, image, description, base_stats, growth_stats, tags, draw_weight) VALUES (:id, :name, :rarity, :image, :description, :base_stats, :growth_stats, :tags, :draw_weight)');
        $stmt->bindValue(':id', $id);
    }
    $stmt->bindValue(':name', $name);
    $stmt->bindValue(':rarity', $rarity);
    $stmt->bindValue(':image', $image);
    $stmt->bindValue(':description', $description);
    $stmt->bindValue(':base_stats', $baseStats);
    $stmt->bindValue(':growth_stats', $growthStats);
    $stmt->bindValue(':tags', $tags);
    $stmt->bindValue(':draw_weight', $drawWeight);
    $stmt->execute();
    
    response(200, 'ok');
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

elseif ($action === 'get_my_cards') {
    $user_id = intval(getParam('user_id', 0));
    if (!$user_id) response(400, 'user_id required');
    
    $stmt = $db->prepare('
        SELECT c.*, uc.id as instance_id, uc.level, uc.exp, uc.enhance_count, uc.is_favorite
        FROM user_cards uc
        JOIN cards c ON uc.card_id = c.id
        WHERE uc.user_id = :user_id
        ORDER BY c.rarity DESC, c.name
    ');
    $stmt->bindValue(':user_id', $user_id);
    $result = $stmt->execute();
    
    $cards = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['base_stats'] = json_decode($row['base_stats'] ?? '{}', true);
        $row['growth_stats'] = json_decode($row['growth_stats'] ?? '{}', true);
        $row['tags'] = json_decode($row['tags'] ?? '[]', true);
        $cards[] = $row;
    }
    
    response(200, 'ok', ['cards' => $cards]);
}

elseif ($action === 'update_favorite') {
    $data = json_decode(file_get_contents('php://input'), true);
    $instance_id = intval($data['instance_id'] ?? 0);
    $is_favorite = intval($data['is_favorite'] ?? 0);
    
    if (!$instance_id) response(400, 'instance_id required');
    
    $stmt = $db->prepare('UPDATE user_cards SET is_favorite = :fav WHERE id = :id');
    $stmt->bindValue(':fav', $is_favorite);
    $stmt->bindValue(':id', $instance_id);
    $stmt->execute();
    
    response(200, 'ok');
}

else {
    response(400, '未知操作');
}

$db->close();