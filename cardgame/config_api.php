<?php
header('Content-Type: application/json; charset=utf-8');

$db = new SQLite3(__DIR__ . '/data/game.sqlite');

function out($code, $msg, $data=[]) {
    echo json_encode(array_merge(['code'=>$code,'msg'=>$msg],$data));
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
if(!$action) out(400,'action required');

if($action=='list') {
    // 配置列表
    $stmt = $db->prepare('SELECT * FROM draw_config ORDER BY id');
    $result = $stmt->execute();
    $configs = [];
    while($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['card_pool'] = json_decode($row['card_pool']??'[]',true);
        $row['rarity_weight'] = json_decode($row['rarity_weight']??'[]',true);
        $row['tag'] = json_decode($row['tag']??'[]',true);
        $row['tag_weight'] = json_decode($row['tag_weight']??'[]',true);
        $configs[] = $row;
    }
    
    // 从数据库 cards.pool_name 获取唯一卡池
    $stmt = $db->prepare('SELECT DISTINCT pool_name FROM cards WHERE pool_name != "" ORDER BY pool_name');
    $result = $stmt->execute();
    $pools = [];
    while($row = $result->fetchArray()) {
        $pools[] = $row[0];
    }
    
    out(200,'ok',['configs'=>$configs,'pools'=>$pools]);
}

elseif($action=='get') {
    $name = $_POST['config_name'] ?? '';
    if(!$name) out(400,'config_name required');
    
    $stmt = $db->prepare('SELECT * FROM draw_config WHERE config_name = :n');
    $stmt->bindValue(':n',$name);
    $cfg = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if(!$cfg) out(400,'not found');
    
    $cfg['card_pool'] = json_decode($cfg['card_pool']??'[]',true);
    $cfg['rarity_weight'] = json_decode($cfg['rarity_weight']??'[]',true);
    $cfg['tag'] = json_decode($cfg['tag']??'[]',true);
    $cfg['tag_weight'] = json_decode($cfg['tag_weight']??'[]',true);
    out(200,'ok',['config'=>$cfg]);
}

elseif($action=='save') {
    $name = $_POST['config_name'] ?? '';
    $cp = $_POST['card_pool'] ?? '["NIKKE"]';
    $rw = $_POST['rarity_weight'] ?? '[5,10,50,40,0.5,1]';
    $tag = $_POST['tag'] ?? '[]';
    $tw = $_POST['tag_weight'] ?? '[]';
    $dc = intval($_POST['draw_cost'] ?? 100);
    
    if(!$name) out(400,'config_name required');
    
    $stmt = $db->prepare('SELECT id FROM draw_config WHERE config_name = :n');
    $stmt->bindValue(':n',$name);
    $exists = $stmt->execute()->fetchArray();
    
    if($exists) {
        $stmt = $db->prepare('UPDATE draw_config SET card_pool=:cp,rarity_weight=:rw,tag=:tag,tag_weight=:tw,draw_cost=:dc WHERE config_name=:n');
    } else {
        $stmt = $db->prepare('INSERT INTO draw_config (config_name,card_pool,rarity_weight,tag,tag_weight,draw_cost) VALUES (:n,:cp,:rw,:tag,:tw,:dc)');
    }
    $stmt->bindValue(':n',$name);
    $stmt->bindValue(':cp',$cp);
    $stmt->bindValue(':rw',$rw);
    $stmt->bindValue(':tag',$tag);
    $stmt->bindValue(':tw',$tw);
    $stmt->bindValue(':dc',$dc);
    $stmt->execute();
    out(200,'保存成功');
}

elseif($action=='delete') {
    $name = $_POST['config_name'] ?? '';
    if(!$name) out(400,'config_name required');
    if($name=='default') out(400,'默认配置不能删除');
    $stmt = $db->prepare('DELETE FROM draw_config WHERE config_name = :n');
    $stmt->bindValue(':n',$name);
    $stmt->execute();
    out(200,'删除成功');
}

else out(400,'unknown action');

$db->close();