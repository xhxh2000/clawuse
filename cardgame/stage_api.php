<?php
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

$db = new SQLite3(__DIR__ . '/data/game.sqlite');
if (!$db) {
    echo json_encode(['code'=>500,'msg'=>'数据库连接失败']); exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// 获取所有世界和关卡数据
if ($action === 'get_worlds') {
    $stmt = $db->prepare('SELECT * FROM stage_worlds ORDER BY sort_order, id');
    $result = $stmt->execute();
    $worlds = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $worlds[] = $row;
    }
    // Also get all stage IDs (lightweight, for progress calculation)
    $stmt2 = $db->prepare('SELECT id, world_id FROM stages ORDER BY world_id, stage_num');
    $result2 = $stmt2->execute();
    $all_stages = [];
    while ($row = $result2->fetchArray(SQLITE3_ASSOC)) {
        $all_stages[] = $row;
    }
    echo json_encode(['code'=>200, 'worlds'=>$worlds, 'all_stages'=>$all_stages], JSON_UNESCAPED_UNICODE);

} elseif ($action === 'get_stages') {
    $world_id = intval($_GET['world_id'] ?? $_POST['world_id'] ?? 0);
    $user_id = intval($_GET['user_id'] ?? $_POST['user_id'] ?? 0);

    if (!$world_id) { echo json_encode(['code'=>400,'msg'=>'world_id required']); exit; }

    // Get stages for this world
    $stmt = $db->prepare('SELECT * FROM stages WHERE world_id = :wid ORDER BY stage_num');
    $stmt->bindValue(':wid', $world_id);
    $result = $stmt->execute();
    $stages = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['enemy_card_ids'] = json_decode($row['enemy_card_ids'] ?? '[]', true);
        $row['enemy_positions'] = json_decode($row['enemy_positions'] ?? '[]', true);
        $stages[] = $row;
    }

    // Get enemy details for these stages
    $stmt2 = $db->prepare('SELECT * FROM stage_enemies ORDER BY id');
    $result2 = $stmt2->execute();
    $enemies = [];
    while ($row = $result2->fetchArray(SQLITE3_ASSOC)) {
        $enemies[$row['id']] = $row;
    }

    // Get user progress for this world
    $progress = [];
    if ($user_id) {
        $stmt3 = $db->prepare('SELECT sp.*, s.stage_num FROM stage_progress sp JOIN stages s ON sp.stage_id = s.id WHERE sp.user_id = :uid AND s.world_id = :wid');
        $stmt3->bindValue(':uid', $user_id);
        $stmt3->bindValue(':wid', $world_id);
        $result3 = $stmt3->execute();
        while ($row = $result3->fetchArray(SQLITE3_ASSOC)) {
            $progress[$row['stage_num']] = $row;
        }
    }

    echo json_encode(['code'=>200, 'stages'=>$stages, 'enemies'=>$enemies, 'progress'=>$progress], JSON_UNESCAPED_UNICODE);

} elseif ($action === 'get_stage_detail') {
    $stage_id = intval($_GET['stage_id'] ?? $_POST['stage_id'] ?? 0);
    $user_id = intval($_GET['user_id'] ?? $_POST['user_id'] ?? 0);

    if (!$stage_id) { echo json_encode(['code'=>400,'msg'=>'stage_id required']); exit; }

    $stmt = $db->prepare('SELECT * FROM stages WHERE id = :id');
    $stmt->bindValue(':id', $stage_id);
    $stage = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$stage) { echo json_encode(['code'=>404,'msg'=>'关卡不存在']); exit; }

    $stage['enemy_card_ids'] = json_decode($stage['enemy_card_ids'] ?? '[]', true);
    $stage['enemy_positions'] = json_decode($stage['enemy_positions'] ?? '[]', true);

    // Get enemy details
    $enemies = [];
    foreach ($stage['enemy_card_ids'] as $eid) {
        $stmt2 = $db->prepare('SELECT * FROM stage_enemies WHERE id = :id');
        $stmt2->bindValue(':id', intval($eid));
        $row = $stmt2->execute()->fetchArray(SQLITE3_ASSOC);
        if ($row) $enemies[] = $row;
    }

    // Get progress
    $progress = null;
    if ($user_id) {
        $stmt3 = $db->prepare('SELECT * FROM stage_progress WHERE user_id = :uid AND stage_id = :sid');
        $stmt3->bindValue(':uid', $user_id);
        $stmt3->bindValue(':sid', $stage_id);
        $progress = $stmt3->execute()->fetchArray(SQLITE3_ASSOC);
    }

    // Calculate exp reward
    $base_exp = intval($stage['base_exp']);
    if ($base_exp === 0) {
        $base_exp = intval(round(50 * pow($stage['stage_num'], 1.3)));
    }

    echo json_encode(['code'=>200, 'stage'=>$stage, 'enemies'=>$enemies, 'progress'=>$progress, 'exp_reward'=>$base_exp], JSON_UNESCAPED_UNICODE);

} elseif ($action === 'save_progress') {
    $data = json_decode(file_get_contents('php://input'), true);
    $user_id = intval($data['user_id'] ?? 0);
    $stage_id = intval($data['stage_id'] ?? 0);
    $status = $data['status'] ?? 'cleared';
    $turns = intval($data['turns'] ?? 0);

    if (!$user_id || !$stage_id) { echo json_encode(['code'=>400,'msg'=>'参数错误']); exit; }

    // Check existing progress
    $stmt = $db->prepare('SELECT id, status, best_turns FROM stage_progress WHERE user_id = :uid AND stage_id = :sid');
    $stmt->bindValue(':uid', $user_id);
    $stmt->bindValue(':sid', $stage_id);
    $existing = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    $first_clear = false;
    if ($existing) {
        $old_status = $existing['status'];
        $old_turns = intval($existing['best_turns']);
        $new_best = ($turns > 0 && ($old_turns === 0 || $turns < $old_turns)) ? $turns : $old_turns;

        $stmt2 = $db->prepare('UPDATE stage_progress SET status = :s, best_turns = :bt, cleared_at = datetime("now") WHERE user_id = :uid AND stage_id = :sid');
        $stmt2->bindValue(':s', $status);
        $stmt2->bindValue(':bt', $new_best);
        $stmt2->bindValue(':uid', $user_id);
        $stmt2->bindValue(':sid', $stage_id);
        $stmt2->execute();

        $first_clear = ($old_status !== 'cleared' && $status === 'cleared');
    } else {
        $new_best = $turns;  // INSERT 分支：首次记录，best_turns 就是本次回合数
        $stmt2 = $db->prepare('INSERT INTO stage_progress (user_id, stage_id, status, best_turns, cleared_at) VALUES (:uid, :sid, :s, :bt, datetime("now"))');
        $stmt2->bindValue(':uid', $user_id);
        $stmt2->bindValue(':sid', $stage_id);
        $stmt2->bindValue(':s', $status);
        $stmt2->bindValue(':bt', $turns);
        $stmt2->execute();
        $first_clear = ($status === 'cleared');
    }

    // Calculate exp reward
    $stmt3 = $db->prepare('SELECT stage_num, base_exp FROM stages WHERE id = :id');
    $stmt3->bindValue(':id', $stage_id);
    $stageRow = $stmt3->execute()->fetchArray(SQLITE3_ASSOC);
    $base_exp = 0;
    if ($stageRow) {
        $base_exp = intval($stageRow['base_exp']);
        if ($base_exp === 0) {
            $base_exp = intval(round(50 * pow($stageRow['stage_num'], 1.3)));
        }
    }

    $exp_reward = 0;
    if ($first_clear && $status === 'cleared') {
        $exp_reward = $base_exp * 2; // 首通双倍
    } elseif ($status === 'cleared') {
        $exp_reward = $base_exp;
    }

    // Distribute exp to battle cards
    $card_ids = $data['card_ids'] ?? [];
    $card_rewards = [];
    if ($exp_reward > 0 && !empty($card_ids) && is_array($card_ids)) {
        $per_card = intval($exp_reward / count($card_ids));
        foreach ($card_ids as $cid) {
            $cid = intval($cid);
            if ($cid <= 0) continue;

            // Get card info before exp
            $beforeResult = $db->query("SELECT uc.id, uc.level, uc.exp, c.name, c.rarity FROM user_cards uc JOIN cards c ON uc.card_id = c.id WHERE uc.id = $cid");
            $beforeRow = $beforeResult->fetchArray(SQLITE3_ASSOC);
            if (!$beforeRow) continue;

            $oldLv = intval($beforeRow['level']);
            $oldExp = intval($beforeRow['exp']);
            $newExp = $oldExp + $per_card;
            $newLv = $oldLv;
            // Level-up curve: gentle early, steeper later
            // Lv1->2: 30, Lv2->3: 45, Lv3->4: 60, ... formula: 15 + 15*Lv
            $expNeeded = 15 + 15 * $newLv;
            while ($newExp >= $expNeeded && $newLv < 100) {
                $newExp -= $expNeeded;
                $newLv++;
                $expNeeded = 15 + 15 * $newLv;
            }

            // Save final state
            $db->exec("UPDATE user_cards SET level = $newLv, exp = $newExp WHERE id = $cid");

            $leveled = $newLv > $oldLv;
            $card_rewards[] = [
                'id'       => $cid,
                'name'     => $beforeRow['name'],
                'rarity'   => $beforeRow['rarity'],
                'old_lv'   => $oldLv,
                'new_lv'   => $newLv,
                'exp_gain' => $per_card,
                'leveled'  => $leveled,
            ];
        }
    }

    echo json_encode(['code'=>200, 'first_clear'=>$first_clear, 'exp_reward'=>$exp_reward, 'card_rewards'=>$card_rewards, 'best_turns'=>$new_best, 'msg'=>'ok'], JSON_UNESCAPED_UNICODE);

} elseif ($action === 'get_all_progress') {
    $user_id = intval($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
    if (!$user_id) { echo json_encode(['code'=>400,'msg'=>'user_id required']); exit; }

    $stmt = $db->prepare('SELECT sp.*, s.world_id, s.stage_num, s.name FROM stage_progress sp JOIN stages s ON sp.stage_id = s.id WHERE sp.user_id = :uid');
    $stmt->bindValue(':uid', $user_id);
    $result = $stmt->execute();
    $progress = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $progress[] = $row;
    }
    echo json_encode(['code'=>200, 'progress'=>$progress], JSON_UNESCAPED_UNICODE);

} else {
    echo json_encode(['code'=>400,'msg'=>'未知操作: ' . $action]);
}
