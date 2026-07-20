<?php

use Webman\Route;

Route::options('[{path:.+}]', function () {
    return response('');
});

// API v1 路由（接收来自 gk_api 的代理请求 - 玩家端，需要 JWT Token）
Route::group('/api', function () {
    Route::group('/v1', function () {
        // 进入游戏
        Route::post('/enter-game', [\app\api\v1\GamePlatformProxyController::class, 'enterGame']);
        // 进入游戏大厅
        Route::post('/lobby-login', [\app\api\v1\GamePlatformProxyController::class, 'lobbyLogin']);
        // 平台转出到电子游戏
        Route::post('/wallet-transfer-out', [\app\api\v1\GamePlatformProxyController::class, 'walletTransferOut']);
        // 电子游戏转入到平台
        Route::post('/wallet-transfer-in', [\app\api\v1\GamePlatformProxyController::class, 'walletTransferIn']);
        // 查询电子游戏平台余额
        Route::post('/get-balance', [\app\api\v1\GamePlatformProxyController::class, 'getBalance']);
        // 查询所有电子游戏平台余额
        Route::post('/get-wallet', [\app\api\v1\GamePlatformProxyController::class, 'getWallet']);
        // 全部转出
        Route::post('/withdrawAmountAll', [\app\api\v1\GamePlatformProxyController::class, 'withdrawAmountAll']);
        // 快速转出电子游戏钱包余额
        Route::post('/fast-transfer', [\app\api\v1\GamePlatformProxyController::class, 'fastTransfer']);
        // 获取游戏列表（保留兼容性，建议使用 admin 接口）
        Route::post('/get-game-list', [\app\api\v1\GamePlatformProxyController::class, 'getGameList']);

        // 推送测试接口
        Route::post('/push-test', [\app\api\v1\PushTestController::class, 'test']);
        Route::post('/push-broadcast', [\app\api\v1\PushTestController::class, 'broadcast']);
        Route::get('/push-config', [\app\api\v1\PushTestController::class, 'checkConfig']);
        Route::post('/push-test-player', [\app\api\v1\PushTestController::class, 'testPlayerPush']);

        // 玩家机台操作 API（来自 gk_api，使用 JWT Token 或 X-Player-Id）
        Route::group('/machine', function () {
            // ✅ 统一操作接口
            Route::post('/execute', [\app\api\v1\MachineOperationController::class, 'execute']);
            Route::get('/operations', [\app\api\v1\MachineOperationController::class, 'getOperations']);

            // ⚠️ 保留的特殊接口
            Route::post('/send-cmd', [\app\api\v1\PlayerMachineController::class, 'sendCmd']);
            Route::post('/status', [\app\api\v1\PlayerMachineController::class, 'getMachineStatus']);
            Route::post('/check-online', [\app\api\v1\PlayerMachineController::class, 'checkOnline']);
            Route::post('/batch-check-online', [\app\api\v1\PlayerMachineController::class, 'batchCheckOnline']);
            Route::post('/get-description', [\app\api\v1\PlayerMachineController::class, 'getDescription']);
        });
    });
});

// Admin API 路由（接收来自 gk_admin 的请求 - 管理后台，使用 X-Player-Id）
Route::group('/api', function () {
    Route::group('/admin', function () {
        // 管理后台 - 进入游戏大厅
        Route::post('/lobby-login', [\app\api\v1\AdminGamePlatformController::class, 'lobbyLogin']);
        // 管理后台 - 获取游戏列表
        Route::post('/get-game-list', [\app\api\v1\AdminGamePlatformController::class, 'getGameList']);
        // 管理后台 - 进入游戏
        Route::post('/enter-game', [\app\api\v1\AdminGamePlatformController::class, 'enterGame']);
        // 管理后台 - 游戏回放
        Route::post('/replay', [\app\api\v1\AdminGamePlatformController::class, 'replay']);
        // 管理后台 - 机台操作 API
        Route::group('/machine', function () {
            // ✅ 新增：统一操作入口（推荐使用）
            Route::post('/execute', [\app\api\v1\MachineOperationController::class, 'execute']);
            Route::post('/batch-execute', [\app\api\v1\MachineOperationController::class, 'batchExecute']);
            Route::get('/operations', [\app\api\v1\MachineOperationController::class, 'getOperations']);

            // ⚠️ 以下为兼容旧接口（保留，但推荐使用 /execute）
            // 发送机台指令
            Route::post('/send-cmd', [\app\api\v1\AdminMachineController::class, 'sendCmd']);
            // 批量发送机台指令
            Route::post('/batch-send-cmd', [\app\api\v1\AdminMachineController::class, 'batchSendCmd']);
            // 获取机台状态
            Route::post('/status', [\app\api\v1\AdminMachineController::class, 'getMachineStatus']);
            // 检查机台在线状态
            Route::post('/check-online', [\app\api\v1\AdminMachineController::class, 'checkOnline']);
            // 批量检查机台在线状态
            Route::post('/batch-check-online', [\app\api\v1\AdminMachineController::class, 'batchCheckOnline']);
            // 获取机台操作描述
            Route::post('/get-description', [\app\api\v1\AdminMachineController::class, 'getDescription']);
            // 获取Gateway信息（调试用）
            Route::get('/gateway-info', [\app\api\v1\AdminMachineController::class, 'getGatewayInfo']);
            // 获取所有机台在线状态
            Route::post('/all-online-status', [\app\api\v1\AdminMachineController::class, 'getAllOnlineStatus']);
            // 获取机台在线统计
            Route::get('/online-statistics', [\app\api\v1\AdminMachineController::class, 'getOnlineStatistics']);
            // 批量获取机台状态
            Route::post('/batch-status', [\app\api\v1\AdminMachineController::class, 'batchGetMachineStatus']);
            // 更新机台状态
            Route::post('/update-state', [\app\api\v1\AdminMachineController::class, 'updateMachineState']);
            // ✅ 高级操作（踢出玩家、开分等）
            // 踢出玩家（洗分）
            Route::post('/kick-player', [\app\api\v1\AdminMachineController::class, 'kickPlayer']);
            // 强制踢出玩家（不返还分数）
            Route::post('/force-kick-player', [\app\api\v1\AdminMachineController::class, 'forceKickPlayer']);
            // 自定义开分
            Route::post('/custom-open-score', [\app\api\v1\AdminMachineController::class, 'customOpenScore']);
        });

        // 管理后台 - 媒体服务器 API（业务接口）
        Route::group('/media-server', function () {
            // 删除机台流
            Route::post('/delete-machine-stream', [\app\api\v1\MediaServerController::class, 'deleteMachineStream']);
            // 创建机台流
            Route::post('/create-machine-stream', [\app\api\v1\MediaServerController::class, 'createMachineStream']);
            // 添加 RTMP 节点
            Route::post('/rtmp-endpoint', [\app\api\v1\MediaServerController::class, 'rtmpEndpoint']);
            // 删除 RTMP 节点
            Route::post('/delete-rtmp-endpoint', [\app\api\v1\MediaServerController::class, 'deleteRtmpEndpoint']);
            // 获取观看人数
            Route::post('/get-viewers', [\app\api\v1\MediaServerController::class, 'getViewers']);
            // 获取流信息
            Route::post('/get-broadcasts', [\app\api\v1\MediaServerController::class, 'getBroadcasts']);
        });
    });
});

// 单一钱包api
Route::group('/single-wallet', function () {
    Route::group('/mt-channel', function () {
        Route::post('/Balance', [\app\wallet\controller\game\MtGameController::class, 'balance']);
        Route::post('/Bet', [\app\wallet\controller\game\MtGameController::class, 'bet']);
        Route::post('/CancelBet', [\app\wallet\controller\game\MtGameController::class, 'cancelBet']);
        Route::post('/BetResult', [\app\wallet\controller\game\MtGameController::class, 'betResult']);
        Route::post('/ReBetResult', [\app\wallet\controller\game\MtGameController::class, 'reBetResult']);
        Route::post('/Gift', [\app\wallet\controller\game\MtGameController::class, 'gift']);
    });
    Route::group('/rsg-channel', function () {
        Route::post('/GetBalance', [\app\wallet\controller\game\RsgGameController::class, 'balance']);
        Route::post('/Bet', [\app\wallet\controller\game\RsgGameController::class, 'bet']);
        Route::post('/CancelBet', [\app\wallet\controller\game\RsgGameController::class, 'cancelBet']);
        Route::post('/BetResult', [\app\wallet\controller\game\RsgGameController::class, 'betResult']);
        Route::post('/ReBetResult', [\app\wallet\controller\game\RsgGameController::class, 'reBetResult']);
        Route::post('/JackpotResult', [\app\wallet\controller\game\RsgGameController::class, 'jackpotResult']);
        Route::post('/Prepay', [\app\wallet\controller\game\RsgGameController::class, 'prepay']);
        Route::post('/Refund', [\app\wallet\controller\game\RsgGameController::class, 'refund']);
        Route::post('/CheckTransaction', [\app\wallet\controller\game\RsgGameController::class, 'checkTransaction']);
    });
    Route::group('/gclub-channel', function () {
        Route::post('/api/Wallet/Balance', [\app\wallet\controller\game\RsgLiveGameController::class, 'balance']);
        Route::post('/api/Wallet/Debit', [\app\wallet\controller\game\RsgLiveGameController::class, 'bet']);
        Route::post('/api/Wallet/Credit', [\app\wallet\controller\game\RsgLiveGameController::class, 'betResult']);
        Route::post('/api/Wallet/Cancel', [\app\wallet\controller\game\RsgLiveGameController::class, 'cancel']);
        Route::post('/api/Auth/CheckUser', [\app\wallet\controller\game\RsgLiveGameController::class, 'checkUser']);
        Route::post('/api/Auth/RequestExtendToken', [\app\wallet\controller\game\RsgLiveGameController::class, 'RequestExtendToken']);
    });
    Route::group('/sp-channel', function () {
        Route::post('/GetUserBalance', [\app\wallet\controller\game\SPGameController::class, 'balance']);
        Route::post('/PlaceBet', [\app\wallet\controller\game\SPGameController::class, 'bet']);
        Route::post('/PlayerWin', [\app\wallet\controller\game\SPGameController::class, 'betResult']);
        Route::post('/PlayerLost', [\app\wallet\controller\game\SPGameController::class, 'betResult']);
        Route::post('/PlaceBetCancel', [\app\wallet\controller\game\SPGameController::class, 'cancelBet']);
    });
    Route::group('/sa-channel', function () {
        Route::post('/GetUserBalance', [\app\wallet\controller\game\SAGameController::class, 'balance']);
        Route::post('/PlaceBet', [\app\wallet\controller\game\SAGameController::class, 'bet']);
        Route::post('/PlayerWin', [\app\wallet\controller\game\SAGameController::class, 'betResult']);
        Route::post('/PlayerLost', [\app\wallet\controller\game\SAGameController::class, 'betResult']);
        Route::post('/PlaceBetCancel', [\app\wallet\controller\game\SAGameController::class, 'cancelBet']);
        Route::post('/BalanceAdjustment', [\app\wallet\controller\game\SAGameController::class, 'adjustment']);
    });
    Route::group('/atg-channel', function () {
        Route::post('/balance', [\app\wallet\controller\game\ATGGameController::class, 'balance']);
        Route::post('/betting', [\app\wallet\controller\game\ATGGameController::class, 'bet']);
        Route::post('/settlement', [\app\wallet\controller\game\ATGGameController::class, 'betResult']);
        Route::post('/refund', [\app\wallet\controller\game\ATGGameController::class, 'refund']);
    });
    Route::group('/atg2-channel', function () {
        Route::post('/balance', [\app\wallet\controller\game\ATG2GameController::class, 'balance']);
        Route::post('/betting', [\app\wallet\controller\game\ATG2GameController::class, 'bet']);
        Route::post('/settlement', [\app\wallet\controller\game\ATG2GameController::class, 'betResult']);
        Route::post('/refund', [\app\wallet\controller\game\ATG2GameController::class, 'refund']);
    });
    Route::group('/atg3-channel', function () {
        Route::post('/balance', [\app\wallet\controller\game\ATG3GameController::class, 'balance']);
        Route::post('/betting', [\app\wallet\controller\game\ATG3GameController::class, 'bet']);
        Route::post('/settlement', [\app\wallet\controller\game\ATG3GameController::class, 'betResult']);
        Route::post('/refund', [\app\wallet\controller\game\ATG3GameController::class, 'refund']);
    });
    Route::group('/ug-channel', function () {
        Route::post('/wallet/token', [\app\wallet\controller\game\O8GameController::class, 'token']);
        Route::post('/wallet/balance', [\app\wallet\controller\game\O8GameController::class, 'balance']);
        Route::post('/wallet/debit', [\app\wallet\controller\game\O8GameController::class, 'bet']);
        Route::post('/wallet/credit', [\app\wallet\controller\game\O8GameController::class, 'betResult']);
        Route::post('/wallet/cancel', [\app\wallet\controller\game\O8GameController::class, 'cancel']);
    });
    Route::group('/tnine-channel', function () {
        Route::post('/balance', [\app\wallet\controller\game\TNineGameController::class, 'balance']);
        Route::post('/bet', [\app\wallet\controller\game\TNineGameController::class, 'bet']);
        Route::post('/notice', [\app\wallet\controller\game\TNineGameController::class, 'betResult']);
    });
    Route::group('/tnine-solt-channel',function(){
        // 获取玩家钱包
        Route::post('/SeamlessGameHub/GetBalance', [\app\wallet\controller\game\TNineSlotGameController::class, 'balance']); //商戶會員餘額查詢
        Route::post('/SeamlessGameHub/BetAndSettle', [\app\wallet\controller\game\TNineSlotGameController::class, 'bet']); //商戶會員餘額查詢
        Route::post('SeamlessGameHub/CancelBet', [\app\wallet\controller\game\TNineSlotGameController::class, 'cancelBet']); //商戶會員餘額查詢
    });
    Route::group('/kt-channel', function () {
        Route::post('/auth', [\app\wallet\controller\game\KTGameController::class, 'auth']);
        Route::post('/balance', [\app\wallet\controller\game\KTGameController::class, 'balance']);
        Route::post('/bet', [\app\wallet\controller\game\KTGameController::class, 'bet']);
        Route::post('/cancelBet', [\app\wallet\controller\game\KTGameController::class, 'cancelBet']);
    });
    Route::group('/dg-channel', function () {
        Route::post('/v2/specification/user/getBalance/{agentName}', [\app\wallet\controller\game\DGGameController::class, 'balance']);
        Route::post('/v2/specification/account/transfer/{agentName}', [\app\wallet\controller\game\DGGameController::class, 'bet']);
        Route::post('/v2/specification/account/inform/{agentName}', [\app\wallet\controller\game\DGGameController::class, 'inform']);
    });
    Route::group('/btg-channel', function () {
        Route::post('/get_user_balance', [\app\wallet\controller\game\BTGGameController::class, 'balance']);
        Route::post('/transfer', [\app\wallet\controller\game\BTGGameController::class, 'transfer']);
    });
    Route::group('/qt-channel', function () {
        // 中心钱包接口
        Route::any('/accounts/{playerId}/session', [\app\wallet\controller\game\QTGameController::class, 'verifySession']);
        Route::any('/accounts/{playerId}/balance', [\app\wallet\controller\game\QTGameController::class, 'getBalance']);
        Route::post('/transactions', [\app\wallet\controller\game\QTGameController::class, 'transaction']);
        Route::post('/transactions/rollback', [\app\wallet\controller\game\QTGameController::class, 'rollback']);
        Route::post('/bonus/status', [\app\wallet\controller\game\QTGameController::class, 'promotionStatus']);
        Route::post('/bonus/rewards', [\app\wallet\controller\game\QTGameController::class, 'rewards']);
    });
});
Route::disableDefaultRoute();
