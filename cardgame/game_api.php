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
        
        // 如果指定了user_id，计算拥有数量和是否解锁
        if ($user_id > 0) {
            // 当前拥有数量
            $stmt2 = $db->prepare('SELECT COUNT(*) FROM user_cards WHERE user_id = :uid AND card_id = :cid');
            $stmt2->bindValue(':uid', $user_id);
            $stmt2->bindValue(':cid', $row['id']);
            $res2 = $stmt2->execute();
            $count = $res2->fetchArray();
            $row['owned_count'] = $count ? $count[0] : 0;
            
            // 检查是否曾解锁（图鉴是否点亮）
            $stmt3 = $db->prepare('SELECT COUNT(*) FROM user_album WHERE user_id = :uid AND card_id = :cid');
            $stmt3->bindValue(':uid', $user_id);
            $stmt3->bindValue(':cid', $row['id']);
            $res3 = $stmt3->execute();
            $unlocked = $res3->fetchArray();
            $row['is_unlocked'] = ($unlocked && $unlocked[0] > 0) ? 1 : 0;
        } else {
            $row['owned_count'] = 0;
            $row['is_unlocked'] = 0;
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
elseif ($action === 'get_user_wallet') {
    $user_id = intval(getParam('user_id', 0));
    $currency_id = intval(getParam('currency_id', 1));
    if (!$user_id) response(400, 'user_id required');
    $stmt = $db->prepare('SELECT amount FROM user_wallet WHERE user_id = :uid AND currency_id = :cid');
    $stmt->bindValue(':uid', $user_id);
    $stmt->bindValue(':cid', $currency_id);
    $result = $stmt->execute();
    $row = $result->fetchArray();
    response(200, 'ok', ['amount' => $row ? intval($row['amount']) : 0]);
}
elseif ($action === 'update_user_wallet') {
    $user_id = intval(getParam('user_id', 0));
    $currency_id = intval(getParam('currency_id', 1));
    $change = intval(getParam('change', 0));
    error_log("update_user_wallet: user_id=$user_id, currency_id=$currency_id, change=$change");
    if (!$user_id || !$currency_id) response(400, '参数错误');
    
    // 检查当前余额
    $stmt = $db->prepare('SELECT amount FROM user_wallet WHERE user_id = :uid AND currency_id = :cid');
    $stmt->bindValue(':uid', $user_id);
    $stmt->bindValue(':cid', $currency_id);
    $result = $stmt->execute();
    $row = $result->fetchArray();
    $currentAmount = $row ? intval($row['amount']) : 0;
    
    // 如果是扣款（change为负数），检查余额是否足够
    if ($change < 0 && $currentAmount + $change < 0) {
        response(400, '余额不足', ['current_amount' => $currentAmount]);
    }
    
    if ($row) {
        // 直接UPDATE
        $db->exec("UPDATE user_wallet SET amount = amount + $change WHERE user_id = $user_id AND currency_id = $currency_id");
    } else {
        // INSERT（只有余额足够才插入）
        if ($change >= 0) {
            $db->exec("INSERT INTO user_wallet (user_id, currency_id, amount) VALUES ($user_id, $currency_id, $change)");
        } else {
            response(400, '余额不足', ['current_amount' => 0]);
        }
    }
    
    // 返回新余额
    $result3 = $db->query("SELECT amount FROM user_wallet WHERE user_id = $user_id AND currency_id = $currency_id");
    $row3 = $result3->fetchArray();
    $newAmount = $row3 ? intval($row3['amount']) : 0;
    
    response(200, 'ok', ['new_amount' => $newAmount]);
}
elseif ($action === 'get_currencies') {
    $stmt = $db->prepare('SELECT id, name FROM currency ORDER BY id');
    $result = $stmt->execute();
    $currencies = [];
    while ($row = $result->fetchArray()) {
        $currencies[] = $row;
    }
    response(200, 'ok', ['currencies' => $currencies]);
}
elseif ($action === 'get_draw_configs') {
    $stmt = $db->prepare('SELECT id, config_name, draw_cost, currency_id FROM draw_config ORDER BY id');
    $result = $stmt->execute();
    $configs = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        // 获取货币名称
        $stmt2 = $db->prepare('SELECT name FROM currency WHERE id = :id');
        $stmt2->bindValue(':id', $row['currency_id'] ?? 'gold');
        $res2 = $stmt2->execute();
        $currencyRow = $res2->fetchArray();
        $row['currency_name'] = $currencyRow ? $currencyRow['name'] : '元宝';
        unset($row['currency_id']);
        $configs[] = $row;
    }
    response(200, 'ok', ['configs' => $configs]);
}
elseif ($action === 'add_user_card') {
    $user_id = intval(getParam('user_id', 0));
    $card_id = intval(getParam('card_id', 0));
    if (!$user_id || !$card_id) response(400, '参数错误');
    
    error_log("add_user_card: user_id=$user_id, card_id=$card_id");
    
    // 每次抽卡都插入新记录（允许重复卡，用于强化升级）
    $db->exec("INSERT INTO user_cards (user_id, card_id, level, exp, enhance_count, is_favorite, first_get) VALUES ($user_id, $card_id, 1, 0, 0, 0, datetime('now'))");
    
    // 点亮图鉴（如果之前未点亮）
    $db->exec("INSERT OR IGNORE INTO user_album (user_id, card_id, unlocked_at) VALUES ($user_id, $card_id, datetime('now'))");
    
    response(200, 'ok', ['is_new' => true]);
}
elseif ($action === 'check_new_card') {
    $user_id = intval(getParam('user_id', 0));
    $card_id = intval(getParam('card_id', 0));
    if (!$user_id || !$card_id) response(400, '参数错误');
    
    // 检查是否在user_cards中已有记录（count > 0）
    $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM user_cards WHERE user_id = :uid AND card_id = :cid AND count > 0');
    $stmt->bindValue(':uid', $user_id);
    $stmt->bindValue(':cid', $card_id);
    $res = $stmt->execute();
    $row = $res->fetchArray();
    $has_card = $row && $row['cnt'] > 0;
    
    // 检查是否已点亮图鉴
    $stmt2 = $db->prepare('SELECT COUNT(*) FROM user_album WHERE user_id = :uid AND card_id = :cid');
    $stmt2->bindValue(':uid', $user_id);
    $stmt2->bindValue(':cid', $card_id);
    $res2 = $stmt2->execute();
    $row2 = $res2->fetchArray();
    $album_unlocked = $row2 && $row2[0] > 0;
    
    response(200, 'ok', ['has_card' => $has_card, 'album_unlocked' => $album_unlocked]);
}
elseif ($action === 'get_user_currency') {
    $user_id = intval(getParam('user_id', 0));
    $config_id = intval(getParam('config_id', 1));
    if (!$user_id) response(400, 'user_id required');
    
    // 先获取配置确定货币类型
    $stmt2 = $db->prepare('SELECT currency_id FROM draw_config WHERE id = :id');
    $stmt2->bindValue(':id', $config_id);
    $res2 = $stmt2->execute();
    $configRow = $res2->fetchArray();
    $currencyId = $configRow ? intval($configRow['currency_id']) : 1;
    
    // 从user_wallet表获取用户该货币的余额
    $stmt = $db->prepare('SELECT amount FROM user_wallet WHERE user_id = :uid AND currency_id = :cid');
    $stmt->bindValue(':uid', $user_id);
    $stmt->bindValue(':cid', $currencyId);
    $result = $stmt->execute();
    $row = $result->fetchArray();
    $amount = $row ? intval($row['amount']) : 0;
    
    // 获取货币名称
    $stmt3 = $db->prepare('SELECT name FROM currency WHERE id = :id');
    $stmt3->bindValue(':id', $currencyId);
    $res3 = $stmt3->execute();
    $currencyRow = $res3->fetchArray();
    
    response(200, 'ok', ['gold' => $amount, 'currency_id' => $currencyId]);
}

elseif ($action === 'get_config') {
    $configId = intval(getParam('config_id', 1));
    $stmt = $db->prepare('SELECT * FROM draw_config WHERE id = :id');
    $stmt->bindValue(':id', $configId);
    $result = $stmt->execute();
    $config = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($config) {
        $config['card_pool'] = json_decode($config['card_pool'] ?? '[]', true);
        $config['rarity_weight'] = json_decode($config['rarity_weight'] ?? '[]', true);
        $config['tag'] = json_decode($config['tag'] ?? '[]', true);
        $config['tag_weight'] = json_decode($config['tag_weight'] ?? '[]', true);
        
        // 获取货币名称
        $currencyId = $config['currency_id'] ?? 'gold';
        $stmt2 = $db->prepare('SELECT name FROM currency WHERE id = :id');
        $stmt2->bindValue(':id', $currencyId);
        $res2 = $stmt2->execute();
        $currencyRow = $res2->fetchArray();
        $config['currency_name'] = $currencyRow ? $currencyRow['name'] : '元宝';
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

elseif ($action === 'delete_user_data') {
    $user_id = intval(getParam('user_id', 0));
    if (!$user_id) response(400, 'user_id required');
    
    $db->exec("DELETE FROM user_cards WHERE user_id = $user_id");
    $db->exec("DELETE FROM user_album WHERE user_id = $user_id");
    
    response(200, 'ok');
}

elseif ($action === 'update_card_level') {
    $user_id = intval(getParam('user_id', 0));
    $level = intval(getParam('level', 1));
    $favorite_level = intval(getParam('favorite_level', 3));
    if (!$user_id) response(400, 'user_id required');
    
    // 收藏的设为 favorite_level，其余设为1
    $db->exec("UPDATE user_cards SET level = CASE WHEN is_favorite = 1 THEN $favorite_level ELSE 1 END WHERE user_id = $user_id");
    
    response(200, 'ok');
}

elseif ($action === 'delete_user_card') {
    $user_id = intval(getParam('user_id', 0));
    $card_id = intval(getParam('card_id', 0));
    if (!$user_id || !$card_id) response(400, '参数错误');
    
    $db->exec("DELETE FROM user_cards WHERE user_id = $user_id AND card_id = $card_id");
    
    response(200, 'ok');
}

elseif ($action === 'delete_cards_by_rarity') {
    $user_id = intval(getParam('user_id', 0));
    $rarity = intval(getParam('rarity', 0));
    if (!$user_id || !$rarity) response(400, '参数错误');
    
    // 获取该稀有度的所有卡牌ID
    $stmt = $db->prepare('SELECT id FROM cards WHERE rarity = :rarity');
    $stmt->bindValue(':rarity', $rarity);
    $result = $stmt->execute();
    
    $deleted = 0;
    while ($row = $result->fetchArray()) {
        $cid = $row['id'];
        $db->exec("DELETE FROM user_cards WHERE user_id = $user_id AND card_id = $cid");
        $deleted++;
    }
    
    response(200, 'ok', ['deleted' => $deleted]);
}

else {
    response(400, '未知操作');
}

$db->close();