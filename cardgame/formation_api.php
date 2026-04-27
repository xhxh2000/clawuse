<?php
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

$db = new SQLite3(__DIR__ . '/data/game.sqlite');
if (!$db) {
    echo json_encode(['code'=>500,'msg'=>'数据库连接失败']); exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ---- 编队相关 ----

// 获取稀有度列表
if ($action === 'get_rarity_list') {
    $result = $db->query('SELECT rarity, name, color FROM rarity ORDER BY rarity');
    $rarities = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rarities[] = $row;
    }
    echo json_encode(['code'=>200,'rarities'=>$rarities], JSON_UNESCAPED_UNICODE);
}

// 获取用户所有编队（带卡牌信息）
if ($action === 'get_formations') {
    $user_id = intval($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
    if (!$user_id) { echo json_encode(['code'=>400,'msg'=>'user_id required']); exit; }

    $stmt = $db->prepare('SELECT * FROM user_formations WHERE user_id = :uid ORDER BY formation_slot');
    $stmt->bindValue(':uid', $user_id);
    $result = $stmt->execute();

    $formations = [];
    while ($f = $result->fetchArray(SQLITE3_ASSOC)) {
        $fid = intval($f['id']);
        // 取编队里的卡牌
        $stmt2 = $db->prepare('
            SELECT uc.id as instance_id, uc.card_id, uc.level, uc.exp, uc.is_favorite,
                   c.name, c.rarity, c.image, c.base_stats, c.growth_stats, c.tags,
                   ufc.position
            FROM user_formation_cards ufc
            JOIN user_cards uc ON ufc.user_card_id = uc.id
            JOIN cards c ON uc.card_id = c.id
            WHERE ufc.formation_id = :fid
            ORDER BY ufc.position
        ');
        $stmt2->bindValue(':fid', $fid);
        $res2 = $stmt2->execute();
        $cards = [];
        while ($row = $res2->fetchArray(SQLITE3_ASSOC)) {
            $row['baseStats'] = json_decode($row['base_stats'] ?? '{}', true);
            $row['growthStats'] = json_decode($row['growth_stats'] ?? '{}', true);
            $row['tags'] = json_decode($row['tags'] ?? '[]', true);
            unset($row['base_stats'], $row['growth_stats'], $row['tags']);
            $cards[] = $row;
        }
        $f['cards'] = $cards;
        unset($f['user_id']);
        $formations[] = $f;
    }

    echo json_encode(['code'=>200,'formations'=>$formations], JSON_UNESCAPED_UNICODE);

// 保存/创建编队（整体替换）
} elseif ($action === 'save_formation') {
    $data = json_decode(file_get_contents('php://input'), true);
    $user_id = intval($data['user_id'] ?? 0);
    $slot = intval($data['formation_slot'] ?? 1);
    $name = trim($data['name'] ?? '');
    if ($name === '') { $name = '编队' . $slot; }
    $card_ids = $data['card_ids'] ?? []; // array of {instance_id, position}

    if (!$user_id) { echo json_encode(['code'=>400,'msg'=>'user_id required']); exit; }
    if ($slot < 1 || $slot > 5) { echo json_encode(['code'=>400,'msg'=>'编队序号超出范围(1-5)']); exit; }

    // 检查每个 user_card_id 属于该用户，且不在其他编队中
    $used_ids = array_column($card_ids, 'instance_id');
    $used_ids = array_map('intval', $used_ids);
    if (count(array_unique($used_ids)) !== count($used_ids)) {
        echo json_encode(['code'=>400,'msg'=>'同一张卡牌不能放在多个位置']); exit;
    }
    if (count($used_ids) > 6) {
        echo json_encode(['code'=>400,'msg'=>'每个编队最多6张卡']); exit;
    }

    $db->exec('BEGIN');
    try {
        // 删除旧编队卡牌
        $stmt = $db->prepare('DELETE FROM user_formation_cards WHERE formation_id IN
            (SELECT id FROM user_formations WHERE user_id = :uid AND formation_slot = :slot)');
        $stmt->bindValue(':uid', $user_id);
        $stmt->bindValue(':slot', $slot);
        $stmt->execute();

        // 插入/更新编队
        $stmt = $db->prepare('INSERT INTO user_formations (user_id, formation_slot, name)
            VALUES (:uid, :slot, :name)
            ON CONFLICT(user_id, formation_slot) DO UPDATE SET name = :name2');
        $stmt->bindValue(':uid', $user_id);
        $stmt->bindValue(':slot', $slot);
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':name2', $name);
        $stmt->execute();

        // 重新获取 formation_id
        $stmt = $db->prepare('SELECT id FROM user_formations WHERE user_id = :uid AND formation_slot = :slot');
        $stmt->bindValue(':uid', $user_id);
        $stmt->bindValue(':slot', $slot);
        $fid = intval($stmt->execute()->fetchArray()[0]);

        // 插入卡牌，设置收藏
        foreach ($card_ids as $entry) {
            $ucid = intval($entry['instance_id']);
            $pos = intval($entry['position']);
            if ($ucid <= 0 || $pos < 1 || $pos > 6) continue;

            $stmt = $db->prepare('INSERT INTO user_formation_cards (formation_id, user_card_id, position) VALUES (?, ?, ?)');
            $stmt->bindValue(1, $fid);
            $stmt->bindValue(2, $ucid);
            $stmt->bindValue(3, $pos);
            $stmt->execute();

            // 自动设为收藏
            $stmt = $db->prepare('UPDATE user_cards SET is_favorite = 1 WHERE id = ?');
            $stmt->bindValue(1, $ucid);
            $stmt->execute();
        }

        $db->exec('COMMIT');
        echo json_encode(['code'=>200,'msg'=>'保存成功'], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        echo json_encode(['code'=>500,'msg'=>'保存失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }

// 删除编队（仅清空卡牌，保留编队槽位）
} elseif ($action === 'clear_formation') {
    $data = json_decode(file_get_contents('php://input'), true);
    $user_id = intval($data['user_id'] ?? $_GET['user_id'] ?? $_POST['user_id'] ?? 0);
    $slot = intval($data['slot'] ?? $_GET['slot'] ?? $_POST['slot'] ?? 0);
    if (!$user_id || !$slot) { echo json_encode(['code'=>400,'msg'=>'参数错误']); exit; }

    $db->exec('BEGIN');
    try {
        $stmt = $db->prepare('DELETE FROM user_formation_cards WHERE formation_id IN
            (SELECT id FROM user_formations WHERE user_id = :uid AND formation_slot = :slot)');
        $stmt->bindValue(':uid', $user_id);
        $stmt->bindValue(':slot', $slot);
        $stmt->execute();

        $db->exec('COMMIT');
        echo json_encode(['code'=>200,'msg'=>'已清空'], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        echo json_encode(['code'=>500,'msg'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
    }

// 获取用户可用卡牌（用于编队选择）
} elseif ($action === 'get_formation_cards') {
    $user_id = intval($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
    $current_slot = intval($_GET['current_slot'] ?? 0);
    $filter = $_GET['filter'] ?? 'favorite'; // 'all' or 'favorite'
    $rarity = isset($_GET['rarity']) ? intval($_GET['rarity']) : 0;
    if (!$user_id) { echo json_encode(['code'=>400,'msg'=>'user_id required']); exit; }

    // 收集所有已在其他编队中的 instance_id
    $stmt = $db->prepare('SELECT ufc.user_card_id FROM user_formation_cards ufc
        JOIN user_formations uf ON ufc.formation_id = uf.id
        WHERE uf.user_id = :uid AND uf.formation_slot != :slot');
    $stmt->bindValue(':uid', $user_id);
    $stmt->bindValue(':slot', $current_slot);
    $res = $stmt->execute();
    $used_ids = [];
    while ($row = $res->fetchArray()) $used_ids[] = intval($row['user_card_id']);

    // 根据 filter 参数决定是否只返回收藏的卡
    $where = 'uc.user_id = :uid';
    if ($filter === 'favorite') {
        $where .= ' AND uc.is_favorite = 1';
    }
    if ($rarity > 0) {
        $where .= ' AND c.rarity = :rarity';
    }

    $stmt = $db->prepare("SELECT uc.id as instance_id, uc.card_id, uc.level, uc.exp, uc.is_favorite,
        c.name, c.rarity, c.image, c.base_stats, c.growth_stats, c.tags
        FROM user_cards uc
        JOIN cards c ON uc.card_id = c.id
        WHERE $where
        ORDER BY c.rarity DESC, uc.level DESC, c.name");
    $stmt->bindValue(':uid', $user_id);
    if ($rarity > 0) {
        $stmt->bindValue(':rarity', $rarity);
    }
    $res = $stmt->execute();
    $cards = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $in_other = in_array(intval($row['instance_id']), $used_ids);
        $row['in_other_formation'] = $in_other;
        $row['baseStats'] = json_decode($row['base_stats'] ?? '{}', true);
        $row['growthStats'] = json_decode($row['growth_stats'] ?? '{}', true);
        $row['tags'] = json_decode($row['tags'] ?? '[]', true);
        unset($row['base_stats'], $row['growth_stats'], $row['tags']);
        $cards[] = $row;
    }

    echo json_encode(['code'=>200,'cards'=>$cards,'used_ids'=>$used_ids], JSON_UNESCAPED_UNICODE);

// 取消收藏检查（如果卡在编队中，拒绝）
} elseif ($action === 'toggle_favorite') {
    $data = json_decode(file_get_contents('php://input'), true);
    $instance_id = intval($data['instance_id'] ?? 0);
    $is_favorite = intval($data['is_favorite'] ?? 0);
    $user_id = intval($data['user_id'] ?? 0);

    if (!$instance_id || !$user_id) { echo json_encode(['code'=>400,'msg'=>'参数错误']); exit; }

    // 如果是要取消收藏，检查是否在编队中
    if ($is_favorite === 0) {
        $stmt = $db->prepare('SELECT ufc.id FROM user_formation_cards ufc
            JOIN user_formations uf ON ufc.formation_id = uf.id
            WHERE ufc.user_card_id = :uid AND uf.user_id = :uid2 LIMIT 1');
        $stmt->bindValue(':uid', $instance_id);
        $stmt->bindValue(':uid2', $user_id);
        $inFormation = $stmt->execute()->fetchArray();
        if ($inFormation) {
            echo json_encode(['code'=>400,'msg'=>'该卡牌已在编队中，无法取消收藏，请先从编队移除'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $stmt = $db->prepare('UPDATE user_cards SET is_favorite = :f WHERE id = :id');
    $stmt->bindValue(':f', $is_favorite);
    $stmt->bindValue(':id', $instance_id);
    $stmt->execute();
    echo json_encode(['code'=>200,'msg'=>'OK'], JSON_UNESCAPED_UNICODE);

} else {
    echo json_encode(['code'=>400,'msg'=>'未知操作: ' . $action]);
}
