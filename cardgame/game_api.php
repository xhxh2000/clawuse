<?php
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

$db = new SQLite3(__DIR__ . '/data/game.sqlite');
if (!$db) {
    echo json_encode(['code'=>500,'msg'=>'数据库连接失败']); exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// 获取抽卡配置和全部卡牌
if ($action === 'get_all') {
    $stmt = $db->prepare('SELECT * FROM rarity ORDER BY rarity');
    $result = $stmt->execute();
    $rarity = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rarity[] = $row;
    }
    
    $stmt = $db->prepare('SELECT * FROM draw_config WHERE id = 1');
    $result = $stmt->execute();
    $config = $result->fetchArray(SQLITE3_ASSOC);
    if ($config) {
        $config['card_pool'] = json_decode($config['card_pool'] ?? '[]', true);
        $config['rarity_weight'] = json_decode($config['rarity_weight'] ?? '[]', true);
    }
    
    $stmt = $db->prepare('SELECT * FROM cards ORDER BY rarity DESC, name');
    $result = $stmt->execute();
    $cards = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $cards[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'rarity' => $row['rarity'],
            'image' => $row['image'],
            'description' => $row['description'],
            'baseStats' => json_decode($row['base_stats'] ?? '{}', true),
            'growthStats' => json_decode($row['growth_stats'] ?? '{}', true),
            'tags' => json_decode($row['tags'] ?? '[]', true),
            'draw_weight' => $row['draw_weight'],
            'pool_name' => $row['pool_name']
        ];
    }
    
    $drawCost = $config['draw_cost'] ?? 100;
    $settings = ['drawCost' => $drawCost, 'currencyName' => '元宝'];
    
    echo json_encode(['code'=>200,'settings'=>$settings,'rarity'=>$rarity,'config'=>$config,'characters'=>$cards], JSON_UNESCAPED_UNICODE);
} elseif ($action === 'get_config') {
    $configId = intval($_GET['config_id'] ?? $_POST['config_id'] ?? 1);
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
        $stmt2 = $db->prepare('SELECT name FROM currency WHERE id = :id');
        $stmt2->bindValue(':id', $config['currency_id'] ?? 1);
        $res2 = $stmt2->execute();
        $currencyRow = $res2->fetchArray();
        $config['currency_name'] = $currencyRow ? $currencyRow['name'] : '元宝';
    }
    echo json_encode(['code'=>200,'config'=>$config], JSON_UNESCAPED_UNICODE);
} elseif ($action === 'get_cards') {
    $stmt = $db->prepare('SELECT * FROM cards ORDER BY rarity DESC, name');
    $result = $stmt->execute();
    $cards = [];
    while($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $cards[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'rarity' => $row['rarity'],
            'image' => $row['image'],
            'description' => $row['description'],
            'baseStats' => json_decode($row['base_stats'] ?? '{}', true),
            'growthStats' => json_decode($row['growth_stats'] ?? '{}', true),
            'tags' => json_decode($row['tags'] ?? '[]', true),
            'draw_weight' => $row['draw_weight'],
            'pool_name' => $row['pool_name']
        ];
    }
    echo json_encode(['code'=>200,'characters'=>$cards], JSON_UNESCAPED_UNICODE);
} elseif ($action === 'get_rarity') {
    $stmt = $db->prepare('SELECT * FROM rarity ORDER BY rarity');
    $result = $stmt->execute();
    $rarity = [];
    while($row = $result->fetchArray(SQLITE3_ASSOC)) $rarity[] = $row;
    echo json_encode(['code'=>200,'rarity'=>$rarity], JSON_UNESCAPED_UNICODE);
} elseif ($action === 'get_pools') {
    $stmt = $db->prepare('SELECT DISTINCT pool_name FROM cards ORDER BY pool_name');
    $result = $stmt->execute();
    $pools = [];
    while($row = $result->fetchArray(SQLITE3_ASSOC)) $pools[] = $row['pool_name'];
    echo json_encode(['code'=>200,'pools'=>$pools], JSON_UNESCAPED_UNICODE);
} elseif ($action === 'save_card') {
    $id = intval($_POST['id'] ?? 0);
    $name = $_POST['name'] ?? '';
    $rarity = intval($_POST['rarity'] ?? 1);
    $image = $_POST['image'] ?? '';
    $description = $_POST['description'] ?? '';
    $base_stats = $_POST['base_stats'] ?? '{}';
    $growth_stats = $_POST['growth_stats'] ?? '{}';
    $tags = $_POST['tags'] ?? '[]';
    $draw_weight = intval($_POST['draw_weight'] ?? 100);
    $pool_name = $_POST['pool_name'] ?? 'NIKKE';
    
    if(!$id || !$name) { 
        echo json_encode(['code'=>400,'msg'=>'id and name required']); 
        exit;
    }
    
    $stmt = $db->prepare("SELECT id FROM cards WHERE id=:id");
    $stmt->bindValue(':id', $id);
    $exists = $stmt->execute()->fetchArray();
    
    if($exists) {
        $stmt = $db->prepare("UPDATE cards SET name=:n,rarity=:r,image=:i,description=:d,base_stats=:bs,growth_stats=:gs,tags=:t,draw_weight=:dw,pool_name=:p WHERE id=:id");
    } else {
        $stmt = $db->prepare("INSERT INTO cards (id,name,rarity,image,description,base_stats,growth_stats,tags,draw_weight,pool_name) VALUES(:id,:n,:r,:i,:d,:bs,:gs,:t,:dw,:p)");
    }
    $stmt->bindValue(':id', $id);
    $stmt->bindValue(':n', $name);
    $stmt->bindValue(':r', $rarity);
    $stmt->bindValue(':i', $image);
    $stmt->bindValue(':d', $description);
    $stmt->bindValue(':bs', $base_stats);
    $stmt->bindValue(':gs', $growth_stats);
    $stmt->bindValue(':t', $tags);
    $stmt->bindValue(':dw', $draw_weight);
    $stmt->bindValue(':p', $pool_name);
    $stmt->execute();
    
    echo json_encode(['code'=>200,'msg'=>'保存成功']);
} elseif ($action === 'get_currencies') {
    $stmt = $db->prepare('SELECT id, name FROM currency ORDER BY id');
    $result = $stmt->execute();
    $currencies = [];
    while ($row = $result->fetchArray()) {
        $currencies[] = $row;
    }
    echo json_encode(['code'=>200,'currencies'=>$currencies], JSON_UNESCAPED_UNICODE);
} elseif ($action === 'get_draw_configs') {
    $stmt = $db->prepare('SELECT id, config_name, draw_cost, currency_id FROM draw_config ORDER BY id');
    $result = $stmt->execute();
    $configs = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $stmt2 = $db->prepare('SELECT name FROM currency WHERE id = :id');
        $stmt2->bindValue(':id', $row['currency_id'] ?? 'gold');
        $res2 = $stmt2->execute();
        $currencyRow = $res2->fetchArray();
        $row['currency_name'] = $currencyRow ? $currencyRow['name'] : '元宝';
        unset($row['currency_id']);
        $configs[] = $row;
    }
    echo json_encode(['code'=>200,'configs'=>$configs], JSON_UNESCAPED_UNICODE);
} elseif ($action === 'get_my_cards') {
    $user_id = intval($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
    if (!$user_id) { echo json_encode(['code'=>400,'msg'=>'user_id required']); exit; }
    
    $stmt = $db->prepare('
        SELECT c.*, uc.id as instance_id, uc.level, uc.exp, uc.enhance_count, uc.is_favorite, uc.first_get as obtained_at
        FROM user_cards uc
        JOIN cards c ON uc.card_id = c.id
        WHERE uc.user_id = :user_id
        ORDER BY c.rarity DESC, c.name
    ');
    $stmt->bindValue(':user_id', $user_id);
    $result = $stmt->execute();
    
    $cards = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['baseStats'] = json_decode($row['base_stats'] ?? '{}', true);
        $row['growthStats'] = json_decode($row['growth_stats'] ?? '{}', true);
        $row['tags'] = json_decode($row['tags'] ?? '[]', true);
        $cards[] = $row;
    }
    
    echo json_encode(['code'=>200,'cards'=>$cards], JSON_UNESCAPED_UNICODE);
} elseif ($action === 'get_user_currency') {
    $user_id = intval($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
    $config_id = intval($_GET['config_id'] ?? $_POST['config_id'] ?? 1);
    if (!$user_id) { echo json_encode(['code'=>400,'msg'=>'user_id required']); exit; }
    
    $stmt2 = $db->prepare('SELECT currency_id FROM draw_config WHERE id = :id');
    $stmt2->bindValue(':id', $config_id);
    $res2 = $stmt2->execute();
    $configRow = $res2->fetchArray();
    $currencyId = $configRow ? intval($configRow['currency_id']) : 1;
    
    $stmt = $db->prepare('SELECT amount FROM user_wallet WHERE user_id = :uid AND currency_id = :cid');
    $stmt->bindValue(':uid', $user_id);
    $stmt->bindValue(':cid', $currencyId);
    $result = $stmt->execute();
    $row = $result->fetchArray();
    $amount = $row ? intval($row['amount']) : 0;
    
    echo json_encode(['code'=>200,'gold'=>$amount,'currency_id'=>$currencyId], JSON_UNESCAPED_UNICODE);
} elseif ($action === 'update_user_wallet') {
    $user_id = intval($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
    $currency_id = intval($_GET['currency_id'] ?? $_POST['currency_id'] ?? 1);
    $change = intval($_GET['change'] ?? $_POST['change'] ?? 0);
    if (!$user_id || !$currency_id) { echo json_encode(['code'=>400,'msg'=>'参数错误']); exit; }
    
    $stmt = $db->prepare('SELECT amount FROM user_wallet WHERE user_id = :uid AND currency_id = :cid');
    $stmt->bindValue(':uid', $user_id);
    $stmt->bindValue(':cid', $currency_id);
    $result = $stmt->execute();
    $row = $result->fetchArray();
    $currentAmount = $row ? intval($row['amount']) : 0;
    
    if ($change < 0 && $currentAmount + $change < 0) {
        echo json_encode(['code'=>400,'msg'=>'余额不足','current_amount'=>$currentAmount], JSON_UNESCAPED_UNICODE); exit;
    }
    
    if ($row) {
        $db->exec("UPDATE user_wallet SET amount = amount + $change WHERE user_id = $user_id AND currency_id = $currency_id");
    } else {
        if ($change >= 0) {
            $db->exec("INSERT INTO user_wallet (user_id, currency_id, amount) VALUES ($user_id, $currency_id, $change)");
        } else {
            echo json_encode(['code'=>400,'msg'=>'余额不足','current_amount'=>0], JSON_UNESCAPED_UNICODE); exit;
        }
    }
    
    $result3 = $db->query("SELECT amount FROM user_wallet WHERE user_id = $user_id AND currency_id = $currency_id");
    $row3 = $result3->fetchArray();
    $newAmount = $row3 ? intval($row3['amount']) : 0;
    
    echo json_encode(['code'=>200,'new_amount'=>$newAmount], JSON_UNESCAPED_UNICODE);
} elseif ($action === 'add_user_card') {
    $user_id = intval($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
    $card_id = intval($_GET['card_id'] ?? $_POST['card_id'] ?? 0);
    $config_id = intval($_GET['config_id'] ?? $_POST['config_id'] ?? 0);
    $draw_count = intval($_GET['draw_count'] ?? $_POST['draw_count'] ?? 1);
    if (!$user_id || !$card_id) { echo json_encode(['code'=>400,'msg'=>'参数错误']); exit; }
    
    $db->exec("INSERT INTO user_cards (user_id, card_id, level, exp, enhance_count, is_favorite, first_get) VALUES ($user_id, $card_id, 1, 0, 0, 0, datetime('now'))");
    $db->exec("INSERT OR IGNORE INTO user_album (user_id, card_id, unlocked_at) VALUES ($user_id, $card_id, datetime('now'))");
    
    $stmt = $db->prepare('SELECT draw_count FROM user_draw_info WHERE user_id = :uid AND config_id = :cid');
    $stmt->bindValue(':uid', $user_id);
    $stmt->bindValue(':cid', $config_id);
    $res = $stmt->execute();
    $row = $res->fetchArray();
    
    if ($row) {
        $db->exec("UPDATE user_draw_info SET draw_count = draw_count + $draw_count, last_draw_at = datetime('now') WHERE user_id = $user_id AND config_id = $config_id");
    } else {
        $db->exec("INSERT INTO user_draw_info (user_id, config_id, draw_count, last_draw_at) VALUES ($user_id, $config_id, $draw_count, datetime('now'))");
    }
    
    echo json_encode(['code'=>200,'msg'=>'ok'], JSON_UNESCAPED_UNICODE);
} elseif ($action === 'get_owned_card_ids') {
    $user_id = intval($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
    if (!$user_id) { echo json_encode(['code'=>400,'msg'=>'user_id required']); exit; }
    
    $stmt = $db->prepare('SELECT DISTINCT card_id FROM user_cards WHERE user_id = :uid');
    $stmt->bindValue(':uid', $user_id);
    $result = $stmt->execute();
    
    $card_ids = [];
    while ($row = $result->fetchArray()) {
        $card_ids[] = intval($row['card_id']);
    }
    
    echo json_encode(['code'=>200,'card_ids'=>$card_ids], JSON_UNESCAPED_UNICODE);
} elseif ($action === 'get_user_album') {
    $user_id = intval($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
    if (!$user_id) { echo json_encode(['code'=>400,'msg'=>'user_id required']); exit; }
    
    $stmt = $db->prepare('SELECT card_id, unlocked_at FROM user_album WHERE user_id = :uid');
    $stmt->bindValue(':uid', $user_id);
    $result = $stmt->execute();
    
    $album = [];
    while ($row = $result->fetchArray()) {
        $album[$row['card_id']] = ['unlocked' => true, 'unlocked_at' => $row['unlocked_at']];
    }
    
    echo json_encode(['code'=>200,'album'=>$album], JSON_UNESCAPED_UNICODE);
} elseif ($action === 'batch_update_growth') {
    $field = $_GET['field'] ?? $_POST['field'] ?? '';
    $multiplier = floatval($_GET['multiplier'] ?? $_POST['multiplier'] ?? 1.0);
    
    if (!in_array($field, ['ATK', 'DEF', 'HP', 'SKL', 'SPD'])) {
        echo json_encode(['code'=>400,'msg'=>'无效字段']); exit;
    }
    
    $stmt = $db->prepare('SELECT id, growth_stats FROM cards');
    $result = $stmt->execute();
    $count = 0;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $growth = json_decode($row['growth_stats'], true);
        if (isset($growth[$field])) {
            $growth[$field] = round($growth[$field] * $multiplier, 2);
            $stmt2 = $db->prepare('UPDATE cards SET growth_stats = :growth WHERE id = :id');
            $stmt2->bindValue(':growth', json_encode($growth));
            $stmt2->bindValue(':id', $row['id']);
            $stmt2->execute();
            $count++;
        }
    }
    echo json_encode(['code'=>200,'msg'=>"更新{$count}张卡的{$field}成长，倍数:{$multiplier}"], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['code'=>400,'msg'=>'未知操作: ' . $action]);
}
