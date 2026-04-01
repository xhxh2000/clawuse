#!/usr/bin/env python3
import sqlite3
import hashlib
import json
import os
from http.server import HTTPServer, BaseHTTPRequestHandler
from urllib.parse import parse_qs, urlparse

DB_PATH = os.path.join(os.path.dirname(__file__), 'data', 'game.sqlite')

def get_db():
    db = sqlite3.connect(DB_PATH)
    db.row_factory = sqlite3.Row
    return db

def response(handler, code, msg, data=None):
    handler.send_response(code)
    handler.send_header('Content-Type', 'application/json; charset=utf-8')
    handler.send_header('Access-Control-Allow-Origin', '*')
    handler.end_headers()
    result = {"code": code, "msg": msg}
    if data:
        result.update(data)
    handler.wfile.write(json.dumps(result, ensure_ascii=False).encode())

def get_params(handler):
    parsed = urlparse(handler.path)
    query = parse_qs(parsed.query)
    return {k: v[0] if len(v) == 1 else v for k, v in query.items()}

class Handler(BaseHTTPRequestHandler):
    def do_OPTIONS(self):
        self.send_response(200)
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        self.send_header('Access-Control-Allow-Headers', 'Content-Type')
        self.end_headers()
    
    def do_GET(self):
        params = get_params(self)
        action = params.get('action', '')
        
        if action == 'login' or action == 'register':
            self.do_POST()
            return
        
        response(self, 400, '只支持POST')
    
    def do_POST(self):
        length = int(self.headers.get('Content-Length', 0))
        body = self.rfile.read(length).decode()
        params = parse_qs(body)
        params = {k: v[0] if len(v) == 1 else v for k, v in params.items()}
        
        action = params.get('action', '')
        
        if not action:
            response(self, 400, '缺少action参数')
            return
        
        db = get_db()
        c = db.cursor()
        
        try:
            if action == 'register':
                username = params.get('username', '').strip()
                password = params.get('password', '')
                
                if not username or not password:
                    response(self, 400, '用户名和密码不能为空')
                    return
                if len(username) < 2 or len(username) > 20:
                    response(self, 400, '用户名2-20字符')
                    return
                if len(password) < 6:
                    response(self, 400, '密码至少6位')
                    return
                
                c.execute("SELECT id FROM users WHERE username = ?", (username,))
                if c.fetchone():
                    response(self, 400, '用户名已存在')
                    return
                
                password_hash = hashlib.sha256(password.encode()).hexdigest()
                c.execute("INSERT INTO users (username, password, gold, role, level, exp, status) VALUES (?, ?, 1000, 'user', 1, 0, 1)",
                         (username, password_hash))
                db.commit()
                user_id = c.lastrowid
                
                response(self, 200, '注册成功', {
                    "user_id": user_id,
                    "username": username,
                    "gold": 1000,
                    "level": 1
                })
            
            elif action == 'login':
                username = params.get('username', '').strip()
                password = params.get('password', '')
                
                if not username or not password:
                    response(self, 400, '用户名和密码不能为空')
                    return
                
                c.execute("SELECT * FROM users WHERE username = ?", (username,))
                user = c.fetchone()
                
                if not user:
                    response(self, 401, '用户名或密码错误')
                    return
                
                password_hash = hashlib.sha256(password.encode()).hexdigest()
                if user['password'] != password_hash:
                    response(self, 401, '用户名或密码错误')
                    return
                
                if user['status'] == 0:
                    response(self, 403, '账号已被禁用')
                    return
                
                c.execute("SELECT * FROM member_level WHERE level = ?", (user['level'],))
                member = c.fetchone()
                
                response(self, 200, '登录成功', {
                    "user_id": user['id'],
                    "username": user['username'],
                    "gold": user['gold'],
                    "role": user['role'],
                    "level": user['level'],
                    "exp": user['exp'],
                    "member": dict(member) if member else None
                })
            
            elif action == 'update_gold':
                user_id = int(params.get('user_id', 0))
                change = int(params.get('change', 0))
                
                if user_id <= 0:
                    response(self, 400, '参数错误')
                    return
                
                c.execute("SELECT gold FROM users WHERE id = ?", (user_id,))
                row = c.fetchone()
                if not row:
                    response(self, 400, '用户不存在')
                    return
                
                new_gold = row['gold'] + change
                if new_gold < 0:
                    response(self, 400, '金币不足')
                    return
                
                c.execute("UPDATE users SET gold = ? WHERE id = ?", (new_gold, user_id))
                db.commit()
                
                response(self, 200, '更新成功', {"gold": new_gold})
            
            else:
                response(self, 400, '未知操作')
        
        except Exception as e:
            response(self, 500, str(e))
        finally:
            db.close()

if __name__ == '__main__':
    # 从端口38081开始尝试
    port = 38081
    for p in range(port, port + 10):
        try:
            server = HTTPServer(('0.0.0.0', p), Handler)
            print(f"启动成功: http://0.0.0.0:{p}/auth.py")
            server.serve_forever()
        except:
            continue