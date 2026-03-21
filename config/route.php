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
        Route::post('/push/test', [\app\api\v1\PushTestController::class, 'test']);
        Route::post('/push/broadcast', [\app\api\v1\PushTestController::class, 'broadcast']);
        Route::get('/push/config', [\app\api\v1\PushTestController::class, 'checkConfig']);
        Route::post('/push/test-player', [\app\api\v1\PushTestController::class, 'testPlayerPush']);
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
    });
    Route::group('/atg-channel', function () {
        Route::post('/balance', [\app\wallet\controller\game\ATGGameController::class, 'balance']);
        Route::post('/betting', [\app\wallet\controller\game\ATGGameController::class, 'bet']);
        Route::post('/settlement', [\app\wallet\controller\game\ATGGameController::class, 'betResult']);
        Route::post('/refund', [\app\wallet\controller\game\ATGGameController::class, 'refund']);
    });
    Route::group('/ug-channel', function () {
        Route::post('/wallet/token', [\app\wallet\controller\game\O8GameController::class, 'token']);
        Route::post('/wallet/balance', [\app\wallet\controller\game\O8GameController::class, 'balance']);
        Route::post('/wallet/debit', [\app\wallet\controller\game\O8GameController::class, 'bet']);
        Route::post('/wallet/credit', [\app\wallet\controller\game\O8GameController::class, 'betResult']);
    });
    Route::group('/tnine-channel', function () {
        Route::post('/balance', [\app\wallet\controller\game\TNineGameController::class, 'balance']);
        Route::post('/bet', [\app\wallet\controller\game\TNineGameController::class, 'bet']);
        Route::post('/notice', [\app\wallet\controller\game\TNineGameController::class, 'betResult']);
    });
    Route::group('/kt-channel', function () {
        Route::post('/auth', [\app\wallet\controller\game\KTGameController::class, 'auth']);
        Route::post('/balance', [\app\wallet\controller\game\KTGameController::class, 'balance']);
        Route::post('/bet', [\app\wallet\controller\game\KTGameController::class, 'bet']);
    });
    Route::group('/dg-channel', function () {
        Route::post('/v2/specification/user/getBalance/{agentName}', [\app\wallet\controller\game\DGGameController::class, 'balance']);
        Route::post('/v2/specification/account/transfer/{agentName}', [\app\wallet\controller\game\DGGameController::class, 'bet']);
    });
});
Route::disableDefaultRoute();
