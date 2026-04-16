"""
CardGame 卡牌图片统一处理脚本 V3
===============================
功能：将不同尺寸的卡牌角色图片统一为标准画布大小（560×800，2x分辨率）
处理流程：
  1. 裁剪透明边缘 — 自动去掉图片四周多余的透明空白（getbbox + crop）
  2. Premultiplied Alpha 缩放 — 缩放前先把 RGB 乘以 alpha，避免半透明边缘混入背景色，
     缩放后再除以 alpha 恢复正确颜色
  3. 等比缩放 — 以高度800px为基准缩放，若宽度超过560px则改用宽度为基准，不拉伸
  4. 透明 padding 居中 — 创建560×800透明画布，图片居中放置，窄图左右补透明，矮图上下补透明
  5. 保存 — jpg自动改为png保留透明度

版本迭代历史：
  V1: 280×400 + LANCZOS → 边缘锯齿严重
  V2: 超采样 + alpha通道高斯模糊 → 有进步但模糊过度
  V3: 2x分辨率(560×800) + premultiplied alpha → 清晰度大幅提升 ✅

网页显示容器参考：
  缩略图: 80×80px 正方形容器，object-fit: contain
  弹窗大图: 280×400px 竖形容器，object-fit: contain
  → 输出560×800（2倍），CSS仍显示280×400，高DPI屏幕更清晰
"""

import sqlite3, os, urllib.parse
from PIL import Image

db = r'Z:\openclaw\openclawdata\cardgame\data\game.sqlite'
base_dir = r'Z:\openclaw\openclawdata\cardgame'
out_dir = r'Z:\openclaw\openclawdata\images_processed'

os.makedirs(out_dir, exist_ok=True)

# 2倍分辨率输出，CSS仍显示280×400
TARGET_WIDTH = 560
TARGET_HEIGHT = 800

conn = sqlite3.connect(db)
cur = conn.cursor()
cur.execute('SELECT id, name, image FROM cards ORDER BY id')
rows = cur.fetchall()
conn.close()

print(f"开始处理 {len(rows)} 张图片（2x分辨率 + premultiplied alpha）...")
print(f"输出尺寸: {TARGET_WIDTH} x {TARGET_HEIGHT}")
print()

success = 0
failed = 0

for i, (card_id, name, img_path) in enumerate(rows):
    if not img_path:
        continue
    
    decoded_path = urllib.parse.unquote(img_path)
    full_path = os.path.join(base_dir, decoded_path).replace('/', '\\')
    
    if not os.path.exists(full_path):
        print(f"[跳过] {name} - 文件不存在")
        failed += 1
        continue
    
    try:
        img = Image.open(full_path)
        
        if img.mode != 'RGBA':
            img = img.convert('RGBA')
        
        # ---- 步骤1: 裁剪透明边缘 ----
        # getbbox() 返回非透明区域的最小包围盒 (left, upper, right, lower)
        # 自动去掉四周多余的透明空白，只保留有内容的区域
        bbox = img.getbbox()
        if bbox:
            img = img.crop(bbox)
        
        orig_w, orig_h = img.size
        
        # ---- 步骤2: Premultiplied Alpha ----
        # 问题：直接缩放含透明区域的图片时，半透明边缘像素会与透明背景混合，
        #        导致边缘出现不该有的颜色（如白色/黑色光晕）
        # 解决：缩放前先把 RGB 乘以 alpha（premultiply），缩放后再除以 alpha（un-premultiply）
        #        这样缩放时半透明边缘不会混入背景色，保持干净的边缘
        import numpy as np
        r, g, b, a = img.split()
        # 将alpha归一化到0-1
        arr_r = np.array(r, dtype=np.float32)
        arr_g = np.array(g, dtype=np.float32)
        arr_b = np.array(b, dtype=np.float32)
        arr_a = np.array(a, dtype=np.float32) / 255.0
        
        # Premultiply: RGB * alpha
        arr_r_premul = (arr_r * arr_a + 0.5).astype(np.uint8)
        arr_g_premul = (arr_g * arr_a + 0.5).astype(np.uint8)
        arr_b_premul = (arr_b * arr_a + 0.5).astype(np.uint8)
        
        img_premul = Image.merge('RGBA', (
            Image.fromarray(arr_r_premul, mode='L'),
            Image.fromarray(arr_g_premul, mode='L'),
            Image.fromarray(arr_b_premul, mode='L'),
            a  # alpha保持原样
        ))
        
        # ---- 步骤3: 等比缩放 ----
        # 策略：以高度为基准缩放，如果宽度超出画布则改用宽度为基准
        # 效果：图片保持原始比例不变形，大图缩小适配画布，小图放大到画布高度或宽度
        scale_h = TARGET_HEIGHT / orig_h
        if orig_w * scale_h > TARGET_WIDTH:
            scale = TARGET_WIDTH / orig_w
        else:
            scale = scale_h
        
        new_w = int(orig_w * scale)
        new_h = int(orig_h * scale)
        
        # 安全检查：确保不超出画布
        if new_w > TARGET_WIDTH:
            new_w = TARGET_WIDTH
            new_h = int(orig_h * (TARGET_WIDTH / orig_w))
        if new_h > TARGET_HEIGHT:
            new_h = TARGET_HEIGHT
            new_w = int(orig_w * (TARGET_HEIGHT / orig_h))
        
        # 缩放（LANCZOS高质量插值）
        img_resized = img_premul.resize((new_w, new_h), Image.Resampling.LANCZOS)
        
        # ---- 步骤2后半: Un-premultiply ----
        # 缩放完成后，把 RGB 除以 alpha 恢复正确颜色
        # 这时缩放已经完成，半透明边缘不会混色了
        r2, g2, b2, a2 = img_resized.split()
        arr_r2 = np.array(r2, dtype=np.float32)
        arr_g2 = np.array(g2, dtype=np.float32)
        arr_b2 = np.array(b2, dtype=np.float32)
        arr_a2 = np.array(a2, dtype=np.float32) / 255.0
        
        # 避免除以0：alpha为0的像素，alpha_safe取最小值1/255
        arr_a2_safe = np.maximum(arr_a2, 1.0/255.0)
        
        arr_r_final = np.clip(arr_r2 / arr_a2_safe, 0, 255).astype(np.uint8)
        arr_g_final = np.clip(arr_g2 / arr_a2_safe, 0, 255).astype(np.uint8)
        arr_b_final = np.clip(arr_b2 / arr_a2_safe, 0, 255).astype(np.uint8)
        
        img_final = Image.merge('RGBA', (
            Image.fromarray(arr_r_final, mode='L'),
            Image.fromarray(arr_g_final, mode='L'),
            Image.fromarray(arr_b_final, mode='L'),
            a2
        ))
        
        # ---- 步骤4: 创建透明画布，居中放置 ----
        # 创建560×800全透明画布，将缩放后的图片居中粘贴
        # 窄图（人物偏瘦）→ 左右出现透明边距
        # 矮图（人物偏矮/方图）→ 上下出现透明边距
        # 效果：所有人物视觉大小一致，不拉伸不变形
        canvas = Image.new('RGBA', (TARGET_WIDTH, TARGET_HEIGHT), (0, 0, 0, 0))
        paste_x = (TARGET_WIDTH - new_w) // 2
        paste_y = (TARGET_HEIGHT - new_h) // 2
        canvas.paste(img_final, (paste_x, paste_y), img_final)
        
        # ---- 步骤5: 保存 ----
        # jpg改为png保留透明度，原文件名保存
        fname = os.path.basename(decoded_path)
        if fname.lower().endswith('.jpg') or fname.lower().endswith('.jpeg'):
            fname = os.path.splitext(fname)[0] + '.png'
        
        out_path = os.path.join(out_dir, fname)
        canvas.save(out_path)
        
        success += 1
        if (i + 1) % 20 == 0:
            print(f"已处理: {i + 1}/{len(rows)}")
        
    except Exception as e:
        print(f"[错误] {name} - {e}")
        failed += 1

print()
print(f"完成！成功: {success}, 失败: {failed}")
print(f"图片保存在: {out_dir}")
