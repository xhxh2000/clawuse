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
} elseif ($action === 'save_rarity') {
    $rarityData = $_POST['rarity'] ?? '';
    if (!$rarityData) {
        echo json_encode(['code'=>400,'msg'=>'数据不能为空']);
        exit;
    }
    $rarityList = json_decode($rarityData, true);
    if (!$rarityList) {
        echo json_encode(['code'=>400,'msg'=>'数据格式错误']);
        exit;
    }
    // 先清空表，再重新插入（支持删除稀有度）
    $db->exec('DELETE FROM rarity');
    foreach ($rarityList as $r) {
        $id = intval($r['rarity']);
        $name = $r['name'] ?? '';
        $color = $r['color'] ?? '#888888';
        
        $stmt = $db->prepare("INSERT INTO rarity (rarity, name, color) VALUES (:id, :n, :c)");
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':n', $name);
        $stmt->bindValue(':c', $color);
        $stmt->execute();
    }
    echo json_encode(['code'=>200,'msg'=>'保存成功']);
} elseif ($action === 'get_pools') {
    $stmt = $db->prepare('SELECT DISTINCT pool_name FROM cards ORDER BY pool_name');
    $result = $stmt->execute();
    $pools = [];
    while($row = $result->fetchArray(SQLITE3_ASSOC)) $pools[] = $row['pool_name'];
    echo json_encode(['code'=>200,'pools'=>$pools], JSON_UNESCAPED_UNICODE);
} elseif ($action === 'check_rarity_cards') {
    $rarity = intval($_GET['rarity'] ?? $_POST['rarity'] ?? 0);
    $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM cards WHERE rarity = :r');
    $stmt->bindValue(':r', $rarity);
    $result = $stmt->execute()->fetchArray();
    echo json_encode(['code'=>200,'count'=>intval($result[0])], JSON_UNESCAPED_UNICODE);
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
} elseif ($action === 'delete_card') {
    $data = json_decode(file_get_contents('php://input'), true);
    $instance_id = intval($data['instance_id'] ?? 0);
    $user_id = intval($data['user_id'] ?? 0);
    if (!$instance_id || !$user_id) { echo json_encode(['code'=>400,'msg'=>'参数错误']); exit; }
    
    // Verify card belongs to user
    $stmt = $db->prepare('SELECT uc.id, uc.card_id, uc.is_favorite, c.name FROM user_cards uc JOIN cards c ON uc.card_id=c.id WHERE uc.id = :id AND uc.user_id = :uid');
    $stmt->bindValue(':id', $instance_id);
    $stmt->bindValue(':uid', $user_id);
    $result = $stmt->execute();
    $cardRow = $result->fetchArray(SQLITE3_ASSOC);
    if (!$cardRow) { echo json_encode(['code'=>404,'msg'=>'卡牌不存在或不属于该用户']); exit; }
    
    $card_id = intval($cardRow['card_id']);
    $card_name = $cardRow['name'];
    $was_favorite = intval($cardRow['is_favorite']);
    
    // Count how many of this card the user owns
    $stmt2 = $db->prepare('SELECT COUNT(*) as cnt FROM user_cards WHERE user_id = :uid AND card_id = :cid');
    $stmt2->bindValue(':uid', $user_id);
    $stmt2->bindValue(':cid', $card_id);
    $cntResult = $stmt2->execute()->fetchArray();
    $owned_count = intval($cntResult['cnt']);
    $is_last = ($owned_count <= 1);
    
    // Delete the card instance
    $stmt3 = $db->prepare('DELETE FROM user_cards WHERE id = :id AND user_id = :uid');
    $stmt3->bindValue(':id', $instance_id);
    $stmt3->bindValue(':uid', $user_id);
    $stmt3->execute();
    
    // Give currency reward (default: 100 元宝, currency_id=2)
    $reward_currency_id = intval($data['currency_id'] ?? 2);
    $reward_amount = intval($data['reward_amount'] ?? 100);
    
    // Get currency name
    $stmt4 = $db->prepare('SELECT name FROM currency WHERE id = :cid');
    $stmt4->bindValue(':cid', $reward_currency_id);
    $currResult = $stmt4->execute()->fetchArray();
    $currency_name = $currResult ? $currResult['name'] : '元宝';
    
    // Update user wallet
    $stmt5 = $db->prepare('SELECT amount FROM user_wallet WHERE user_id = :uid AND currency_id = :cid');
    $stmt5->bindValue(':uid', $user_id);
    $stmt5->bindValue(':cid', $reward_currency_id);
    $walletResult = $stmt5->execute()->fetchArray();
    if ($walletResult) {
        $db->exec("UPDATE user_wallet SET amount = amount + $reward_amount WHERE user_id = $user_id AND currency_id = $reward_currency_id");
    } else {
        $db->exec("INSERT INTO user_wallet (user_id, currency_id, amount) VALUES ($user_id, $reward_currency_id, $reward_amount)");
    }
    
    // Get new wallet amount
    $stmt6 = $db->prepare('SELECT amount FROM user_wallet WHERE user_id = :uid AND currency_id = :cid');
    $stmt6->bindValue(':uid', $user_id);
    $stmt6->bindValue(':cid', $reward_currency_id);
    $newWalletResult = $stmt6->execute()->fetchArray();
    $new_amount = $newWalletResult ? intval($newWalletResult['amount']) : $reward_amount;
    
    // Note: user_album is NOT affected - album stays lit even after deleting last card
    
    echo json_encode([
        'code' => 200,
        'msg' => '删除成功',
        'card_name' => $card_name,
        'was_favorite' => $was_favorite,
        'is_last' => $is_last,
        'reward' => [
            'currency_id' => $reward_currency_id,
            'currency_name' => $currency_name,
            'amount' => $reward_amount
        ],
        'new_wallet_amount' => $new_amount
    ], JSON_UNESCAPED_UNICODE);
} elseif ($action === 'level_up_card') {
    $data = json_decode(file_get_contents('php://input'), true);
    $target_instance_id = intval($data['target_instance_id'] ?? 0);
    $material_instance_ids = $data['material_instance_ids'] ?? [];
    $user_id = intval($data['user_id'] ?? 0);
    
    if (!$target_instance_id || !$material_instance_ids || !$user_id) {
        echo json_encode(['code'=>400,'msg'=>'参数错误']); exit;
    }
    if (!is_array($material_instance_ids) || count($material_instance_ids) === 0) {
        echo json_encode(['code'=>400,'msg'=>'请选择材料卡牌']); exit;
    }
    // Prevent feeding the card to itself
    if (in_array($target_instance_id, $material_instance_ids)) {
        echo json_encode(['code'=>400,'msg'=>'不能吃自己']); exit;
    }
    
    // Load game config
    $cfg = [];
    $cfgResult = $db->query('SELECT key, value FROM game_config');
    while ($cfgRow = $cfgResult->fetchArray(SQLITE3_ASSOC)) {
        $cfg[$cfgRow['key']] = $cfgRow['value'];
    }
    $max_level = intval($cfg['max_level'] ?? 100);
    $exp_per_level = intval($cfg['exp_per_level'] ?? 100);
    $material_base_exp = floatval($cfg['material_base_exp'] ?? 5);
    $material_rarity_factor = floatval($cfg['material_rarity_factor'] ?? 1.3);
    
    // Get target card
    $stmt = $db->prepare('SELECT uc.id, uc.card_id, uc.level, uc.exp, c.rarity, c.name FROM user_cards uc JOIN cards c ON uc.card_id=c.id WHERE uc.id=:id AND uc.user_id=:uid');
    $stmt->bindValue(':id', $target_instance_id);
    $stmt->bindValue(':uid', $user_id);
    $targetRow = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$targetRow) { echo json_encode(['code'=>404,'msg'=>'目标卡牌不存在']); exit; }
    if (intval($targetRow['level']) >= $max_level) { echo json_encode(['code'=>400,'msg'=>'已达最高等级']); exit; }
    
    $target_rarity = intval($targetRow['rarity']);
    $target_level = intval($targetRow['level']);
    $target_exp = intval($targetRow['exp']);
    $target_name = $targetRow['name'];
    
    // Calculate total exp from materials
    $total_exp_gained = 0;
    $material_details = [];
    $duplicate_check = [];
    
    foreach ($material_instance_ids as $mid) {
        $mid = intval($mid);
        if ($mid <= 0) continue;
        if (isset($duplicate_check[$mid])) { echo json_encode(['code'=>400,'msg'=>'材料卡重复']); exit; }
        $duplicate_check[$mid] = true;
        
        $stmt = $db->prepare('SELECT uc.id, uc.card_id, uc.level, uc.is_favorite, c.rarity, c.name FROM user_cards uc JOIN cards c ON uc.card_id=c.id WHERE uc.id=:id AND uc.user_id=:uid');
        $stmt->bindValue(':id', $mid);
        $stmt->bindValue(':uid', $user_id);
        $matRow = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$matRow) { echo json_encode(['code'=>404,'msg'=>'材料卡 '.$mid.' 不存在']); exit; }
        if (intval($matRow['is_favorite'])) { echo json_encode(['code'=>400,'msg'=>'收藏的卡牌不能作为材料：'.$matRow['name']]); exit; }
        
        $mat_level = intval($matRow['level']);
        $mat_rarity = intval($matRow['rarity']);
        $rarity_diff = $mat_rarity - $target_rarity;
        
        // exp = base × material_level × factor^(diff)
        $exp_gained = $material_base_exp * $mat_level * pow($material_rarity_factor, $rarity_diff);
        $exp_gained = max(1, intval(round($exp_gained))); // at least 1 exp
        
        $total_exp_gained += $exp_gained;
        $material_details[] = [
            'instance_id' => $mid,
            'name' => $matRow['name'],
            'rarity' => $mat_rarity,
            'level' => $mat_level,
            'exp_gained' => $exp_gained
        ];
    }
    
    // Apply exp to target card, handle level ups
    $new_exp = $target_exp + $total_exp_gained;
    $new_level = $target_level;
    $levels_gained = 0;
    
    while ($new_exp >= $exp_per_level && $new_level < $max_level) {
        $new_exp -= $exp_per_level;
        $new_level++;
        $levels_gained++;
    }
    // If max level, cap exp at 0
    if ($new_level >= $max_level) {
        $new_exp = 0;
    }
    
    // Update target card
    $stmt = $db->prepare('UPDATE user_cards SET level=:lv, exp=:exp WHERE id=:id AND user_id=:uid');
    $stmt->bindValue(':lv', $new_level);
    $stmt->bindValue(':exp', $new_exp);
    $stmt->bindValue(':id', $target_instance_id);
    $stmt->bindValue(':uid', $user_id);
    $stmt->execute();
    
    // Delete material cards
    $id_placeholders = implode(',', array_fill(0, count($material_instance_ids), '?'));
    $stmt = $db->prepare('DELETE FROM user_cards WHERE id IN (' . $id_placeholders . ') AND user_id = ?');
    $i = 1;
    foreach ($material_instance_ids as $mid) {
        $stmt->bindValue($i++, intval($mid));
    }
    $stmt->bindValue($i, $user_id);
    $stmt->execute();
    
    echo json_encode([
        'code' => 200,
        'msg' => '升级成功',
        'card_name' => $target_name,
        'old_level' => $target_level,
        'new_level' => $new_level,
        'levels_gained' => $levels_gained,
        'exp_gained' => $total_exp_gained,
        'exp_current' => $new_exp,
        'exp_per_level' => $exp_per_level,
        'materials_consumed' => count($material_details),
        'material_details' => $material_details
    ], JSON_UNESCAPED_UNICODE);

} elseif ($action === 'get_game_config') {
    $cfg = [];
    $result = $db->query('SELECT key, value FROM game_config');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $cfg[$row['key']] = $row['value'];
    }
    echo json_encode(['code'=>200, 'config'=>$cfg], JSON_UNESCAPED_UNICODE);

} elseif ($action === 'update_favorite') {
    $data = json_decode(file_get_contents('php://input'), true);
    $instance_id = intval($data['instance_id'] ?? 0);
    $is_favorite = intval($data['is_favorite'] ?? 0);
    $stmt = $db->prepare('UPDATE user_cards SET is_favorite = :f WHERE id = :id');
    $stmt->bindValue(':f', $is_favorite);
    $stmt->bindValue(':id', $instance_id);
    $stmt->execute();
    echo json_encode(['code'=>200,'msg'=>'OK']);
} else {
    echo json_encode(['code'=>400,'msg'=>'未知操作: ' . $action]);
}
