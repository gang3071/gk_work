# ATG多运营商后台配置指南

## 概述

完成代码部署后，需要在后台管理系统（gk_admin）中完成以下配置。

## 一、数据库配置

### 1. 创建平台记录

执行SQL脚本创建ATG_1和ATG_2平台：

```bash
mysql -h10.59.177.7 -ujin -p'^%q0[%Y&2_-yedt>' -Djin < create_atg_platforms.sql
```

**验证结果：**

```sql
SELECT id, code, name, status, created_at
FROM game_platforms
WHERE code IN ('ATG', 'ATG_1', 'ATG_2')
ORDER BY code;
```

应该看到3条记录：
- ATG（原有）
- ATG_1（新建）
- ATG_2（新建）

记录下3个平台的ID，后续配置需要使用。

---

## 二、后台管理界面配置

### 1. 访问游戏平台管理

登录后台管理系统 → **游戏管理** → **游戏平台管理**

应该能看到：
- ✅ ATG（原有平台）
- ✅ ATG运营商2（ATG_1）
- ✅ ATG运营商3（ATG_2）

### 2. 配置限红组

#### 为什么需要配置限红组？

ATG平台使用限红组来管理不同运营商账号：
- 每个限红组 = 一个ATG运营商账号
- 不同店家可以分配到不同的运营商账号
- 实现数据隔离和独立统计

#### 配置步骤

**A. ATG平台（运营商1 - 已有）**

1. 进入 **游戏管理** → **限红组管理**
2. 找到ATG平台的限红组（可能已存在）
3. 编辑限红组配置，确保包含：
   ```json
   {
     "operator": "jinzun",
     "key": "59eceb441f2b41f18035b7065e59920b",
     "providerId": "4"
   }
   ```
4. 保存

**B. ATG_1平台（运营商2 - 新建）**

1. 点击 **添加限红组**
2. 选择平台：**ATG运营商2**
3. 填写限红组名称：`ATG_1默认组` 或其他名称
4. 配置JSON（替换为真实凭据）：
   ```json
   {
     "operator": "your_operator2_name",
     "key": "your_operator2_key",
     "providerId": "5"
   }
   ```
5. 保存

**C. ATG_2平台（运营商3 - 新建）**

1. 点击 **添加限红组**
2. 选择平台：**ATG运营商3**
3. 填写限红组名称：`ATG_2默认组` 或其他名称
4. 配置JSON（替换为真实凭据）：
   ```json
   {
     "operator": "your_operator3_name",
     "key": "your_operator3_key",
     "providerId": "6"
   }
   ```
5. 保存

### 3. 分配限红组给店家

**场景：不同店家使用不同运营商**

#### 店家A → 使用ATG（运营商1）

1. 进入 **店家管理** → 选择店家A
2. 找到 **游戏限红组配置** 或 **游戏平台设置**
3. 为ATG平台选择对应的限红组
4. 保存

#### 店家B → 使用ATG_1（运营商2）

1. 进入 **店家管理** → 选择店家B
2. 找到 **游戏限红组配置**
3. 为ATG_1平台选择对应的限红组
4. 保存

#### 店家C → 使用ATG_2（运营商3）

1. 进入 **店家管理** → 选择店家C
2. 找到 **游戏限红组配置**
3. 为ATG_2平台选择对应的限红组
4. 保存

---

## 三、运营商后台配置

需要在每个ATG运营商的后台配置回调地址。

### 运营商1（ATG）

**回调地址：**
```
余额查询: https://api.jinzun.org/single-wallet/atg-channel/balance
下注:     https://api.jinzun.org/single-wallet/atg-channel/betting
结算:     https://api.jinzun.org/single-wallet/atg-channel/settlement
退款:     https://api.jinzun.org/single-wallet/atg-channel/refund
```

### 运营商2（ATG_1）

**回调地址：**
```
余额查询: https://api.jinzun.org/single-wallet/atg1-channel/balance
下注:     https://api.jinzun.org/single-wallet/atg1-channel/betting
结算:     https://api.jinzun.org/single-wallet/atg1-channel/settlement
退款:     https://api.jinzun.org/single-wallet/atg1-channel/refund
```

### 运营商3（ATG_2）

**回调地址：**
```
余额查询: https://api.jinzun.org/single-wallet/atg2-channel/balance
下注:     https://api.jinzun.org/single-wallet/atg2-channel/betting
结算:     https://api.jinzun.org/single-wallet/atg2-channel/settlement
退款:     https://api.jinzun.org/single-wallet/atg2-channel/refund
```

**⚠️ 重要：**
- 每个运营商必须配置自己的回调地址
- 不能混用（运营商2不能用atg-channel）
- 配置后通知ATG运营商激活

---

## 四、前端配置

前端需要显示3个独立的ATG图标。

### 游戏列表配置

确保前端能够区分3个平台：

```javascript
// 游戏平台列表
platforms: [
  { code: 'ATG', name: 'ATG', logo: '/images/platforms/atg.png' },
  { code: 'ATG_1', name: 'ATG运营商2', logo: '/images/platforms/atg1.png' },
  { code: 'ATG_2', name: 'ATG运营商3', logo: '/images/platforms/atg2.png' }
]
```

### 进入游戏API

前端调用进入游戏时，传入对应的平台code：

```javascript
// 进入ATG运营商2
POST /api/v1/enter-game
{
  "platform_code": "ATG_1",
  "game_code": "xxx",
  "lang": "zh-TW"
}
```

---

## 五、验证测试

### 1. 测试玩家注册

```sql
-- 查看玩家在各平台的注册情况
SELECT
    p.uuid as player_uuid,
    gp.code as platform_code,
    pgp.operator,
    pgp.created_at
FROM player_game_platform pgp
JOIN players p ON pgp.player_id = p.id
JOIN game_platforms gp ON pgp.platform_id = gp.id
WHERE p.uuid = 'test_player_uuid'
  AND gp.code IN ('ATG', 'ATG_1', 'ATG_2')
ORDER BY pgp.created_at DESC;
```

**预期结果：**
- 玩家在ATG平台有一条记录（operator=jinzun）
- 玩家在ATG_1平台有一条记录（operator=operator2）
- 玩家在ATG_2平台有一条记录（operator=operator3）

### 2. 测试游戏记录分离

```sql
-- 查看各平台游戏记录统计
SELECT
    gp.code as platform,
    COUNT(*) as record_count,
    SUM(bet) as total_bet,
    SUM(win) as total_win,
    SUM(diff) as total_diff
FROM play_game_record gr
JOIN game_platforms gp ON gr.platform_id = gp.id
WHERE gp.code IN ('ATG', 'ATG_1', 'ATG_2')
  AND gr.created_at >= CURDATE()
GROUP BY gp.code;
```

**预期结果：**
- 3个平台的数据完全独立
- 不同平台的记录互不影响

### 3. 测试回调路由

使用Postman或curl测试回调是否正确路由：

```bash
# 测试ATG_1余额查询（需要真实token）
curl -X POST https://api.jinzun.org/single-wallet/atg1-channel/balance \
  -H "token: xxx" \
  -H "timestamp: xxx" \
  -d '{"data": "encrypted_data"}'
```

**预期结果：**
- 返回200，能正确解密
- 日志显示使用ATG_1配置

### 4. 监控日志

```bash
# 实时监控ATG日志
tail -f D:/gk_work/runtime/logs/atg_server-*.log

# 观察关键信息：
# - platform_code: ATG_1 或 ATG_2
# - operator: 不同运营商名称
# - api_domain: 对应平台的域名
```

---

## 六、常见问题

### Q1: 后台看不到ATG_1/ATG_2平台？

**解决：**
1. 确认SQL已执行：`SELECT * FROM game_platforms WHERE code LIKE 'ATG%';`
2. 清除后台缓存
3. 刷新浏览器

### Q2: 配置限红组时找不到平台？

**解决：**
1. 确认平台status=1（启用状态）
2. 确认平台有分类（cate_id不为空）
3. 检查用户权限

### Q3: 玩家进入游戏失败，提示"游戏平台未配置"？

**解决：**
1. 确认该店家已配置对应平台的限红组
2. 确认限红组配置包含operator/key/providerId
3. 检查.env文件中对应平台的配置

### Q4: 回调报"解密失败"？

**解决：**
1. 确认运营商后台配置的回调地址正确
2. 确认运营商凭据（operator/key）正确
3. 检查日志确认是否尝试了所有配置

### Q5: 数据统计混乱，3个平台数据串了？

**解决：**
1. 检查play_game_record表的platform_id是否正确
2. 确认前端传入的platform_code正确
3. 验证decrypt方法是否正确设置platform

---

## 七、扩展到第4、5个运营商

当需要添加更多运营商时，重复以下步骤：

### 1. 代码层面

```bash
# 复制ATG2ServiceInterface.php → ATG3ServiceInterface.php
# 修改类名和平台code为ATG_3

# 复制ATG2GameController.php → ATG3GameController.php
# 修改类名和service type为TYPE_ATG_3

# 在GameServiceFactory添加：
const TYPE_ATG_3 = 'ATG_3';
case self::TYPE_ATG_3:
    return new ATG3ServiceInterface($player);

# 在route.php添加：
Route::group('/atg3-channel', function () { ... });

# 在game_platform.php添加：
'ATG_3' => [ ... ]

# 在.env添加：
ATG_3_OPERATOR=...
ATG_3_KEY=...
ATG_3_PROVIDERID=...
```

### 2. 数据库层面

```sql
INSERT INTO game_platforms (code, name, ...)
SELECT 'ATG_3', 'ATG运营商4', ...
FROM game_platforms WHERE code = 'ATG';
```

### 3. 后台配置

按照本指南第二节，为ATG_3配置限红组和分配店家。

---

## 八、完成检查清单

部署完成后，按此清单逐项验证：

- [ ] SQL已执行，3个平台都存在于game_platforms表
- [ ] .env已配置ATG_1和ATG_2的真实凭据
- [ ] 后台能看到3个ATG平台
- [ ] 为每个平台配置了至少1个限红组
- [ ] 至少1个店家分配了ATG_1或ATG_2限红组
- [ ] 3个运营商后台都配置了正确的回调地址
- [ ] 服务已重启（php windows.php restart）
- [ ] 测试玩家能正常进入3个平台的游戏
- [ ] 游戏记录正确写入对应平台
- [ ] 日志显示正确的平台code和operator

---

## 总结

**关键点：**
1. 每个平台 = 独立的数据库记录
2. 每个限红组 = 一个运营商账号
3. 每个回调地址 = 独立的路由路径
4. 数据通过platform_id完全隔离

**好处：**
- ✅ 前端3个独立图标
- ✅ 独立统计和报表
- ✅ 灵活的店家分配
- ✅ 易于扩展（添加第4、5个运营商）

**注意事项：**
- ⚠️ 运营商凭据保密
- ⚠️ 回调地址不能混用
- ⚠️ 修改配置后需重启服务
