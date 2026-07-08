# ATG Multi-Platform Configuration Guide

## Overview

Created 3 independent ATG platforms to support multiple operator accounts with separate callback URLs and limit groups.

```
ATG    → Operator 1 → /wallet/game/atg-channel/*
ATG_1  → Operator 2 → /wallet/game/atg1-channel/*
ATG_2  → Operator 3 → /wallet/game/atg2-channel/*
```

## Architecture

### Database Structure

```sql
game_platforms:
├─ id=34, code='ATG'
├─ id=XX, code='ATG_1'   (new)
└─ id=YY, code='ATG_2'   (new)

platform_limit_group_config:
├─ platform_id=34 → {operator, key, providerId}  (ATG limit groups)
├─ platform_id=XX → {operator, key, providerId}  (ATG_1 limit groups)
└─ platform_id=YY → {operator, key, providerId}  (ATG_2 limit groups)
```

### Code Structure

```
Service Layer (Inheritance):
ATGServiceInterface (base)
 ├─ ATG1ServiceInterface  (extends ATG, only changes platform='ATG_1')
 └─ ATG2ServiceInterface  (extends ATG, only changes platform='ATG_2')

Controller Layer (Inheritance):
ATGGameController (base)
 ├─ ATG1GameController (extends ATG, only changes service type)
 └─ ATG2GameController (extends ATG, only changes service type)

Route Layer:
/wallet/game/atg-channel/*   → ATGGameController  → ATGServiceInterface
/wallet/game/atg1-channel/*  → ATG1GameController → ATG1ServiceInterface
/wallet/game/atg2-channel/*  → ATG2GameController → ATG2ServiceInterface
```

## Setup Steps

### 1. Database Setup

Execute SQL to create platforms:

```bash
mysql -h10.59.177.7 -ujin -p'^%q0[%Y&2_-yedt>' -Djin < create_atg_platforms.sql
```

This creates:
- ATG_1 platform (copy of ATG settings)
- ATG_2 platform (copy of ATG settings)

### 2. Configure Operator Credentials

Edit `.env` file and replace placeholder values:

```env
# ATG_1 Platform (Operator 2)
ATG_1_OPERATOR=your_real_operator2_name
ATG_1_PROVIDERID=your_real_provider2_id
ATG_1_KEY=your_real_operator2_key

# ATG_2 Platform (Operator 3)
ATG_2_OPERATOR=your_real_operator3_name
ATG_2_PROVIDERID=your_real_provider3_id
ATG_2_KEY=your_real_operator3_key
```

### 3. Configure Callback URLs in Operator Backend

For each operator, configure their callback URLs:

**Operator 1 (Original ATG):**
```
Balance:    https://api.jinzun.org/wallet/game/atg-channel/balance
Betting:    https://api.jinzun.org/wallet/game/atg-channel/betting
Settlement: https://api.jinzun.org/wallet/game/atg-channel/settlement
Refund:     https://api.jinzun.org/wallet/game/atg-channel/refund
```

**Operator 2 (ATG_1):**
```
Balance:    https://api.jinzun.org/wallet/game/atg1-channel/balance
Betting:    https://api.jinzun.org/wallet/game/atg1-channel/betting
Settlement: https://api.jinzun.org/wallet/game/atg1-channel/settlement
Refund:     https://api.jinzun.org/wallet/game/atg1-channel/refund
```

**Operator 3 (ATG_2):**
```
Balance:    https://api.jinzun.org/wallet/game/atg2-channel/balance
Betting:    https://api.jinzun.org/wallet/game/atg2-channel/betting
Settlement: https://api.jinzun.org/wallet/game/atg2-channel/settlement
Refund:     https://api.jinzun.org/wallet/game/atg2-channel/refund
```

### 4. Configure Limit Groups (Backend Admin)

Each platform can have its own limit groups:

1. Go to Backend → Limit Group Management
2. Create limit groups for each platform:
   - **ATG Platform**: Create groups for different stores/bet limits
   - **ATG_1 Platform**: Create groups for different stores/bet limits
   - **ATG_2 Platform**: Create groups for different stores/bet limits

3. Assign limit groups to stores:
   - Store A → ATG Platform + Limit Group X
   - Store B → ATG_1 Platform + Limit Group Y
   - Store C → ATG_2 Platform + Limit Group Z

### 5. Restart Service

```bash
php windows.php restart
```

## Features

### ✅ Completely Separate

- **Platforms**: 3 independent platforms in database
- **Callback URLs**: Each operator has its own URL path
- **Limit Groups**: Each platform manages its own limit groups
- **Game Records**: Separated by platform_id
- **Statistics**: Can be queried separately by platform

### ✅ Code Reuse

- Service classes inherit from ATGServiceInterface
- Controller classes inherit from ATGGameController
- Only override platform code and config source
- All business logic (decrypt, API calls, limit groups) is reused

### ✅ Flexible Configuration

- Each platform supports multiple limit groups
- Stores can be assigned to different platforms
- Easy to add 4th, 5th operator (just add ATG_3, ATG_4...)

## Verification

### 1. Check Platform Creation

```sql
SELECT id, code, name, status, created_at
FROM game_platforms
WHERE code IN ('ATG', 'ATG_1', 'ATG_2')
ORDER BY code;
```

### 2. Check Player Registration

```sql
SELECT
    p.uuid,
    pgp.operator,
    gp.code as platform_code,
    pgp.created_at
FROM player_game_platform pgp
JOIN players p ON pgp.player_id = p.id
JOIN game_platforms gp ON pgp.platform_id = gp.id
WHERE gp.code IN ('ATG', 'ATG_1', 'ATG_2')
ORDER BY pgp.created_at DESC
LIMIT 20;
```

### 3. Check Game Records by Platform

```sql
SELECT
    gp.code as platform,
    COUNT(*) as record_count,
    SUM(bet) as total_bet,
    SUM(win) as total_win,
    SUM(diff) as total_diff
FROM play_game_record gr
JOIN game_platforms gp ON gr.platform_id = gp.id
WHERE gp.code IN ('ATG', 'ATG_1', 'ATG_2')
GROUP BY gp.code;
```

### 4. Test Callback

Monitor logs to verify correct routing:

```bash
tail -f D:/gk_work/runtime/logs/atg_server-*.log | grep "operator"
```

## Troubleshooting

### Issue: Callback returns 404

**Cause**: Route not loaded or incorrect URL

**Solution**:
1. Check route file loaded correctly
2. Verify URL matches exactly (case-sensitive)
3. Restart service: `php windows.php restart`

### Issue: Decrypt error

**Cause**: Operator credentials mismatch

**Solution**:
1. Verify `.env` configuration matches operator backend
2. Check `config/game_platform.php` loaded correctly
3. Clear cache: `php artisan cache:clear`

### Issue: Player not found

**Cause**: Player not registered on this platform

**Solution**:
- Players are registered per platform+operator
- If player switches from ATG to ATG_1, system will auto-register

## Technical Details

### Decrypt Flow

Each platform's decrypt method only searches its own limit groups:

```php
// ATG1ServiceInterface
$this->platform = GamePlatform::where('code', 'ATG_1')->first();

// decrypt() queries limit groups
$limitGroupConfigs = PlatformLimitGroupConfig::query()
    ->where('platform_id', $this->platform->id)  // Only ATG_1's groups
    ->get();
```

This ensures:
- ✅ Callback to `/atg1-channel/*` only decrypts with ATG_1 configurations
- ✅ No cross-platform interference
- ✅ Each operator's callbacks are isolated

### Player Isolation

Players can register on multiple platforms:

```sql
-- player_game_platform unique index
UNIQUE KEY (player_id, platform_id, operator)
```

Example:
- Player #123 on ATG platform, operator1
- Player #123 on ATG_1 platform, operator2
- Player #123 on ATG_2 platform, operator3

All 3 registrations are independent!

## Files Modified

### Created Files

- `app/service/game/ATG1ServiceInterface.php`
- `app/service/game/ATG2ServiceInterface.php`
- `app/wallet/controller/game/ATG1GameController.php`
- `app/wallet/controller/game/ATG2GameController.php`
- `create_atg_platforms.sql`
- `ATG_Multi_Platform_Guide.md` (this file)

### Modified Files

- `app/service/game/GameServiceFactory.php` - Added TYPE_ATG_1, TYPE_ATG_2
- `config/route.php` - Added /atg1-channel and /atg2-channel routes
- `config/game_platform.php` - Added ATG_1 and ATG_2 configurations
- `.env` - Added ATG_1 and ATG_2 environment variables

## Next Steps

1. ✅ Code implementation complete
2. ⏳ Get real operator credentials for ATG_1 and ATG_2
3. ⏳ Update `.env` with real credentials
4. ⏳ Execute SQL to create platforms
5. ⏳ Configure callback URLs in operator backends
6. ⏳ Configure limit groups in admin panel
7. ⏳ Test with real players

## Summary

**What we achieved:**
- ✅ 3 independent ATG platforms
- ✅ Each with its own callback URL
- ✅ Each supports multiple limit groups
- ✅ Code reuse through inheritance
- ✅ Easy to extend (add ATG_3, ATG_4...)

**Benefits:**
- ✅ Separate statistics per platform
- ✅ Flexible store assignment
- ✅ Independent operator configurations
- ✅ Clean architecture with minimal code duplication
