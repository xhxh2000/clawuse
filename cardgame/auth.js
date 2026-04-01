// auth.js - Node.js API (替换PHP)
const http = require('http');
const sqlite3 = require('sqlite3').verbose();
const path = require('path');
const url = require('url');
const crypto = require('crypto');

const DB_PATH = path.join(__dirname, 'data', 'game.sqlite');

// 创建数据库连接
function getDb() {
    return new sqlite3.Database(DB_PATH);
}

// 响应
function response(res, code, msg, data = {}) {
    res.writeHead(code, {
        'Content-Type': 'application/json; charset=utf-8',
        'Access-Control-Allow-Origin': '*',
        'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers': 'Content-Type'
    });
    const result = { code, msg, ...data };
    res.end(JSON.stringify(result));
}

// 解析参数
function parseParams(req) {
    return new Promise((resolve) => {
        let body = '';
        req.on('data', chunk => body += chunk);
        req.on('end', () => {
            const params = {};
            body.split('&').forEach(item => {
                const [k, v] = item.split('=');
                if (k) params[decodeURIComponent(k)] = decodeURIComponent(v || '');
            });
            resolve(params);
        });
    });
}

const server = http.createServer(async (req, res) => {
    // CORS
    if (req.method === 'OPTIONS') {
        res.writeHead(204);
        res.end();
        return;
    }
    
    const params = await parseParams(req);
    const action = params.action || '';
    
    if (!action) {
        response(res, 400, '缺少action参数');
        return;
    }
    
    const db = getDb();
    
    // Promise封装
    function query(sql, params = []) {
        return new Promise((resolve, reject) => {
            db.all(sql, params, (err, rows) => {
                if (err) reject(err);
                else resolve(rows);
            });
        });
    }
    
    function run(sql, params = []) {
        return new Promise((resolve, reject) => {
            db.run(sql, params, function(err) {
                if (err) reject(err);
                else resolve(this);
            });
        });
    }
    
    try {
        if (action === 'register') {
            const username = (params.username || '').trim();
            const password = params.password || '';
            
            if (!username || !password) {
                response(res, 400, '用户名和密码不能为空');
                return;
            }
            if (username.length < 2 || username.length > 20) {
                response(res, 400, '用户名2-20字符');
                return;
            }
            if (password.length < 6) {
                response(res, 400, '密码至少6位');
                return;
            }
            
            const existing = await query("SELECT id FROM users WHERE username = ?", [username]);
            if (existing.length > 0) {
                response(res, 400, '用户名已存在');
                return;
            }
            
            const passwordHash = crypto.createHash('sha256').update(password).digest('hex');
            const result = await run(
                "INSERT INTO users (username, password, gold, role, level, exp, status) VALUES (?, ?, 1000, 'user', 1, 0, 1)",
                [username, passwordHash]
            );
            
            response(res, 200, '注册成功', {
                user_id: result.lastID,
                username,
                gold: 1000,
                level: 1
            });
        }
        else if (action === 'login') {
            const username = (params.username || '').trim();
            const password = params.password || '';
            
            if (!username || !password) {
                response(res, 400, '用户名和密码不能为空');
                return;
            }
            
            const users = await query("SELECT * FROM users WHERE username = ?", [username]);
            const user = users[0];
            
            if (!user) {
                response(res, 401, '用户名或密码错误');
                return;
            }
            
            const passwordHash = crypto.createHash('sha256').update(password).digest('hex');
            if (user.password !== passwordHash) {
                response(res, 401, '用户名或密码错误');
                return;
            }
            
            if (user.status === 0) {
                response(res, 403, '账号已被禁用');
                return;
            }
            
            const members = await query("SELECT * FROM member_level WHERE level = ?", [user.level]);
            const member = members[0] || null;
            
            response(res, 200, '登录成功', {
                user_id: user.id,
                username: user.username,
                gold: user.gold,
                role: user.role,
                level: user.level,
                exp: user.exp,
                member
            });
        }
        else if (action === 'update_gold') {
            const userId = parseInt(params.user_id) || 0;
            const change = parseInt(params.change) || 0;
            
            if (userId <= 0) {
                response(res, 400, '参数错误');
                return;
            }
            
            const users = await query("SELECT gold FROM users WHERE id = ?", [userId]);
            if (users.length === 0) {
                response(res, 400, '用户不存在');
                return;
            }
            
            const newGold = users[0].gold + change;
            if (newGold < 0) {
                response(res, 400, '金币不足');
                return;
            }
            
            await run("UPDATE users SET gold = ? WHERE id = ?", [newGold, userId]);
            
            response(res, 200, '更新成功', { gold: newGold });
        }
        else {
            response(res, 400, '未知操作');
        }
    }
    catch (e) {
        response(res, 500, e.message);
    }
    finally {
        db.close();
    }
});

// 启动服务器
const PORT = 38081;
server.listen(PORT, '0.0.0.0', () => {
    console.log(`API服务器启动: http://0.0.0.0:${PORT}/auth.js`);
});