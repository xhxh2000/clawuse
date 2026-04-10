<?php
/**
 * 战斗系统 - 火焰纹章类型
 * 1v1单挑，保留6v6扩展
 */

$db = new SQLite3(__DIR__ . '/data/game.sqlite');

// 读取角色数据 (使用 cards 表)
function getCard($db, $id, $level = 1) {
    $stmt = $db->prepare('SELECT * FROM cards WHERE id = :id');
    $stmt->bindValue(':id', $id);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    if (!$row) return null;
    
    $base = json_decode($row['base_stats'] ?? '{"HP":0,"ATK":0,"DEF":0,"SKL":0,"SPD":0}', true);
    $growth = json_decode($row['growth_stats'] ?? '{"HP":0,"ATK":0,"DEF":0,"SKL":0,"SPD":0}', true);
    
    return [
        'id' => $row['id'],
        'name' => $row['name'],
        'rarity' => $row['rarity'],
        'tags' => json_decode($row['tags'] ?? '[]', true),
        'hp' => intval($base['HP'] + $growth['HP'] * ($level - 1)),
        'atk' => intval($base['ATK'] + $growth['ATK'] * ($level - 1)),
        'def' => intval($base['DEF'] + $growth['DEF'] * ($level - 1)),
        'skl' => intval($base['SKL'] + $growth['SKL'] * ($level - 1)),
        'spd' => intval($base['SPD'] + $growth['SPD'] * ($level - 1)),
        'level' => $level,
    ];
}

// ========== 战斗公式 ==========

// 命中率 (由SKL主导80%权重)
function calcHitRate($atk, $def) {
    $base = 75;
    $sklDiff = max(0, $atk['skl'] - $def['skl']);
    $spdDiff = max(0, $atk['spd'] - $def['spd']);
    $hitRate = $base + $sklDiff * 0.8 + $spdDiff * 0.2;
    return min(95, max(75, $hitRate));
}

// 闪避率 (由SPD主导80%权重)
function calcDodgeRate($atk, $def) {
    $base = 5;
    $spdDiff = max(0, $def['spd'] - $atk['spd']);
    $sklDiff = max(0, $def['skl'] - $atk['skl']);
    $dodgeRate = $base + $spdDiff * 0.8 + $sklDiff * 0.2;
    return min(35, max(5, $dodgeRate));
}

// 暴击率
function calcCritRate($atk, $def) {
    $sklDiff = $atk['skl'] - $def['skl'];
    $spdDiff = $atk['spd'] - $def['spd'];
    $critRate = $sklDiff / 2 + $spdDiff / 4;
    return min(50, max(0, $critRate));
}

// 伤害计算
function calcDamage($atk, $def, $isCrit = false) {
    $base = $atk['atk'] - $def['def'];
    if ($isCrit) {
        $base = intval($atk['atk'] * 1.5) - $def['def'];
    }
    return max(1, $base);
}

// 能否两动 (SPD差值>100)
function canDoubleAction($atk, $def) {
    return ($atk['spd'] - $def['spd']) > 100;
}

// ========== 战斗主逻辑 ==========

function battle($cardA, $cardB) {
    $logs = [];
    $round = 0;
    $maxRounds = 10;
    
    // 初始化HP
    $hpA = $cardA['hp'];
    $hpB = $cardB['hp'];
    $maxHpA = $hpA;
    $maxHpB = $hpB;
    
    // 先手决定 (SPD + 乱数)
    $speedA = $cardA['spd'] + rand(-20, 20);
    $speedB = $cardB['spd'] + rand(-20, 20);
    
    if ($speedA >= $speedB) {
        $first = 'A';
    } else {
        $first = 'B';
    }
    
    $logs[] = "['回合0'] 先手: {$cardA['name']}(SPD{$cardA['spd']}) vs {$cardB['name']}(SPD{$cardB['spd']}) - " . ($first === 'A' ? $cardA['name'] : $cardB['name']) . "先手";
    
    // 战斗循环
    while ($round < $maxRounds && $hpA > 0 && $hpB > 0) {
        $round++;
        $logs[] = "";
        $logs[] = "=== 第{$round}回合 ===";
        
        // 决定攻击顺序
        if ($first === 'A') {
            $order = ['A', 'B'];
        } else {
            $order = ['B', 'A'];
        }
        
        // 两动判断
        $doubleA = canDoubleAction($cardA, $cardB);
        $doubleB = canDoubleAction($cardB, $cardA);
        
        if ($doubleA) $logs[] = "{$cardA['name']} 两动!";
        if ($doubleB) $logs[] = "{$cardB['name']} 两动!";
        
        // 攻击次数
        $attacksA = $doubleA ? 2 : 1;
        $attacksB = $doubleB ? 2 : 1;
        
        // A攻击B (如果B死了就停止)
        for ($i = 0; $i < $attacksA && $hpB > 0; $i++) {
            $hitRate = calcHitRate($cardA, $cardB);
            $dodgeRate = calcDodgeRate($cardA, $cardB);
            $critRate = calcCritRate($cardA, $cardB);
            
            // 命中判断
            $roll = rand(1, 100);
            $isHit = $roll <= $hitRate;
            $isDodge = $roll > $hitRate && $roll <= $hitRate + $dodgeRate;
            
            if ($isDodge) {
                $logs[] = "{$cardA['name']} 攻击 → {$cardB['name']} 闪避! (命中率{$hitRate}%, 闪避率{$dodgeRate}%)";
            } elseif (!$isHit) {
                $logs[] = "{$cardA['name']} 攻击 → 未命中! (命中率{$hitRate}%)";
            } else {
                // 暴击判断
                $critRoll = rand(1, 100);
                $isCrit = $critRoll <= $critRate;
                $damage = calcDamage($cardA, $cardB, $isCrit);
                $hpB -= $damage;
                $critText = $isCrit ? " 暴击!" : "";
                $logs[] = "{$cardA['name']} 攻击 → {$cardB['name']} 造成{$damage}伤害!{$critText} (HP: {$hpB})";
            }
            
            // A把B打死了，立即结束战斗
            if ($hpB <= 0) {
                $logs[] = "{$cardB['name']} 倒下!";
                break 2; // 跳出攻击循环和回合循环
            }
        }
        
        // B攻击A (只有B还活着才能还击)
        for ($i = 0; $i < $attacksB && $hpA > 0; $i++) {
            $hitRate = calcHitRate($cardB, $cardA);
            $dodgeRate = calcDodgeRate($cardB, $cardA);
            $critRate = calcCritRate($cardB, $cardA);
            
            $roll = rand(1, 100);
            $isHit = $roll <= $hitRate;
            $isDodge = $roll > $hitRate && $roll <= $hitRate + $dodgeRate;
            
            if ($isDodge) {
                $logs[] = "{$cardB['name']} 攻击 → {$cardA['name']} 闪避! (命中率{$hitRate}%, 闪避率{$dodgeRate}%)";
            } elseif (!$isHit) {
                $logs[] = "{$cardB['name']} 攻击 → 未命中! (命中率{$hitRate}%)";
            } else {
                $critRoll = rand(1, 100);
                $isCrit = $critRoll <= $critRate;
                $damage = calcDamage($cardB, $cardA, $isCrit);
                $hpA -= $damage;
                $critText = $isCrit ? " 暴击!" : "";
                $logs[] = "{$cardB['name']} 攻击 → {$cardA['name']} 造成{$damage}伤害!{$critText} (HP: {$hpA})";
            }
            
            // B把A打死了，立即结束战斗
            if ($hpA <= 0) {
                $logs[] = "{$cardA['name']} 倒下!";
                break 2; // 跳出攻击循环和回合循环
            }
        }
        
        // 回合结束状态
        $logs[] = "第{$round}回合结束: {$cardA['name']} HP{$hpA} vs {$cardB['name']} HP{$hpB}";
    }
    
    // 战斗结果
    $logs[] = "";
    $logs[] = "=== 战斗结束 ===";
    
    if ($hpA <= 0 && $hpB <= 0) {
        $result = "平手 (双输)";
    } elseif ($hpA <= 0) {
        $result = "{$cardB['name']} 胜利!";
    } elseif ($hpB <= 0) {
        $result = "{$cardA['name']} 胜利!";
    } else {
        // 平手判断总HP
        if ($hpA > $hpB) {
            $result = "平手 - {$cardA['name']} 总HP剩余{$hpA}";
        } elseif ($hpB > $hpA) {
            $result = "平手 - {$cardB['name']} 总HP剩余{$hpB}";
        } else {
            $result = "平手";
        }
    }
    $logs[] = $result;
    
    return [
        'cardA' => $cardA['name'],
        'cardB' => $cardB['name'],
        'result' => $result,
        'logs' => $logs
    ];
}

// ========== API ==========

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'fight') {
    $idA = intval($_GET['idA'] ?? $_POST['idA'] ?? 0);
    $idB = intval($_GET['idB'] ?? $_POST['idB'] ?? 0);
    $levelA = intval($_GET['levelA'] ?? $_POST['levelA'] ?? 1);
    $levelB = intval($_GET['levelB'] ?? $_POST['levelB'] ?? 1);
    
    if (!$idA || !$idB) {
        echo json_encode(['code'=>400, 'msg'=>'缺少角色ID']); exit;
    }
    
    $cardA = getCard($db, $idA, $levelA);
    $cardB = getCard($db, $idB, $levelB);
    
    if (!$cardA || !$cardB) {
        echo json_encode(['code'=>404, 'msg'=>'角色不存在']); exit;
    }
    
    $result = battle($cardA, $cardB);
    echo json_encode(['code'=>200, 'data'=>$result], JSON_UNESCAPED_UNICODE);
    
} elseif ($action === 'info') {
    $id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
    $card = getCard($db, $id);
    if ($card) {
        echo json_encode(['code'=>200, 'data'=>$card], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['code'=>404, 'msg'=>'角色不存在']); 
    }
} else {
    echo json_encode(['code'=>400, 'msg'=>'未知动作']);
}
