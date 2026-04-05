# RSG 缓存时序问题优化说明

## 问题背景

### 日志现象
```
[14:29:59] RSG下注请求（异步）
  - Amount: 100.0
  - beforeBalance: 100070658.74
  - estimatedBalance: 100070558.74  ✓ 正常扣减

[14:29:59] RSG结算请求（异步）
  - BetAmount: 100.0
  - Amount: 0.0
  - beforeBalance: 100070658.74  ✗ 又回到下注前的余额！
  - estimatedBalance: 100070658.74
```

### 问题分析

**时序图：**
```
时间轴：14:29:59.000
├─ 下注请求到达 → 入队（2.57ms）→ 返回预估余额（100070558.74）
│  └─ 队列异步处理中...
│
├─ 结算请求到达（几乎同时）→ 入队（2.51ms）
│  └─ 读取缓存 → 获得旧值（100070658.74）✗
│
└─ 下注队列处理完成 → 数据库扣款 → 触发模型事件 → 更新缓存
   └─ 但为时已晚，结算已经读取了旧缓存
```

**根本原因：**
1. **异步队列处理延迟**：Controller 快速返回（2-3ms），但队列处理需要更多时间
2. **缓存更新时机**：缓存在队列处理完成后才更新，存在时间窗口
3. **并发请求冲突**：下注和结算几乎同时到达，结算读取到下注前的旧缓存

## 解决方案

### 双重缓存更新机制

**第一重：Controller 立即更新（快速响应）**
- 在接口返回预估余额时，立即更新缓存
- 优点：消除时序窗口，确保后续请求获取到预估值
- 时机：下注/结算/取消/Jackpot/prepay/refund 返回前

**第二重：队列处理完成后更新（精确修正）**
- 在队列处理完成后，强制更新缓存为实际值
- 优点：确保缓存与数据库最终一致
- 时机：数据库操作完成后

### 架构优势

```
请求流程（优化后）：
┌─────────────┐
│  Controller │
└──────┬──────┘
       │ 1. 获取当前余额（缓存）
       │ 2. 计算预估余额
       │ 3. ✅ 立即更新缓存为预估值（新增）
       │ 4. 入队
       │ 5. 返回预估余额
       ↓
┌─────────────┐
│  队列处理   │
└──────┬──────┘
       │ 1. 开启事务
       │ 2. 数据库操作（扣款/加款）
       │ 3. 触发模型事件（自动更新缓存）
       │ 4. 提交事务
       │ 5. ✅ 强制更新缓存（新增 - 双重保险）
       ↓
    完成
```

## 代码变更

### 1. Controller 层优化（6处）

#### 下注接口（bet）
```php
// 5.5 立即更新缓存为预估余额（解决时序问题）
\app\service\WalletService::updateCache($player->id, 1, $estimatedBalance);
```

#### 结算接口（betResult）
```php
// 5.5 立即更新缓存为预估余额（解决时序问题）
if ($winAmount > 0) {
    \app\service\WalletService::updateCache($player->id, 1, $estimatedBalance);
}
```

#### 取消下注（cancelBet）、Jackpot、prepay、refund
- 同样的逻辑：立即更新缓存

### 2. 队列处理器优化（3处）

#### RsgPlatformHandler::processBet
```php
// 6. 强制更新缓存（确保缓存与数据库一致 - 双重保险）
try {
    \app\service\WalletService::updateCache($player->id, 1, (float)$wallet->money);
} catch (\Throwable $e) {
    $this->log->warning("RSG: 下注后缓存更新失败", [...]);
}
```

#### RsgPlatformHandler::processSettle
```php
// 6. 强制更新缓存（确保缓存与数据库一致 - 双重保险）
try {
    $latestWallet = \app\model\PlayerPlatformCash::where('player_id', $player->id)->first();
    if ($latestWallet) {
        \app\service\WalletService::updateCache($player->id, 1, (float)$latestWallet->money);
    }
} catch (\Throwable $e) {
    $this->log->warning("RSG: 结算后缓存更新失败", [...]);
}
```

#### RsgPlatformHandler::processRSGRefund
- 同样添加缓存更新逻辑

## 技术要点

### 1. 为什么需要双重更新？

| 更新时机 | 优势 | 劣势 |
|---------|------|------|
| Controller 立即更新 | ✅ 快速（2-3ms）<br>✅ 消除时序窗口 | ⚠️ 使用预估值，可能与实际有微小差异 |
| 队列处理后更新 | ✅ 精确（实际数据库值）<br>✅ 最终一致性 | ⚠️ 延迟（队列处理时间） |

**结论：** 两者结合，既快速又精确！

### 2. 为什么不使用数据库事务后钩子？

- Webman 使用的是 ThinkORM，模型事件在 `save()` 后立即触发
- 事务提交前就触发了缓存更新，仍然存在时序问题
- 直接在业务逻辑中更新更可控

### 3. 异常处理

- 缓存更新失败**不阻塞主流程**（用 try-catch 包裹）
- 模型事件中已有自动更新机制作为兜底
- 队列处理中的强制更新作为最后保障

## 测试验证

### 测试场景
1. **并发测试**：下注和结算同时到达（< 5ms 间隔）
2. **压力测试**：1000次/秒的下注和结算请求
3. **边界测试**：余额不足、重复请求、网络延迟

### 预期结果
- ✅ 缓存始终反映最新的预估或实际余额
- ✅ 下注后立即结算不会读取到旧余额
- ✅ 最终缓存与数据库一致

## 其他平台推广

此优化方案可推广到其他使用异步队列的平台：
- DG（DGGameController）
- KT（KTGameController）
- O8（O8GameController）
- BTG（BTGGameController）
- MT（MtGameController）
- 等所有异步平台

### 通用模式
```php
// Controller 返回前
$estimatedBalance = /* 计算预估余额 */;
\app\service\WalletService::updateCache($player->id, 1, $estimatedBalance);

// 队列处理完成后
$latestWallet = \app\model\PlayerPlatformCash::where('player_id', $player->id)->first();
\app\service\WalletService::updateCache($player->id, 1, (float)$latestWallet->money);
```

## 监控与告警

### 日志增强
- Controller 日志中已包含 `beforeBalance` 和 `estimatedBalance`
- 队列日志中可添加缓存更新成功/失败的记录

### 告警条件
- 缓存更新失败率 > 1%
- 缓存与数据库差异 > 阈值（如 10 元）
- 队列处理延迟 > 1 秒

## 优化效果

| 指标 | 优化前 | 优化后 |
|-----|-------|-------|
| 并发请求缓存一致性 | ❌ 可能不一致 | ✅ 立即一致 |
| 时序窗口 | ⚠️ 存在（队列处理时间） | ✅ 消除 |
| 响应速度 | 2-3ms | 2-3ms（无影响） |
| 最终一致性 | ✅ 依赖模型事件 | ✅ 双重保障 |

## 相关文件

- `app/wallet/controller/game/RsgGameController.php` - 接口层优化
- `app/queue/redis/fast/platform/RsgPlatformHandler.php` - 队列处理器优化
- `app/service/WalletService.php` - 缓存服务
- `app/model/PlayerPlatformCash.php` - 模型事件自动更新

## 版本历史

- **2026-04-03**: 初始版本，解决 RSG 缓存时序问题
