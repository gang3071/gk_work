<?php

namespace app\queue\redis;

use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerGamePlatform;
use app\model\PlayerPlatformCash;
use app\model\PlayerWalletTransfer;
use app\service\game\GameServiceFactory;
use Exception;
use support\Cache;
use support\Db;
use support\Log;
use Webman\RedisQueue\Consumer;

class GameDepositAmount implements Consumer
{
    // 要消费的队列名
    public $queue = 'game-depositAmount';

    // 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接`
    public $connection = 'default';

    // 消费
    public function consume($data)
    {
        $cachePlatformId = Cache::get('depositAmount_' . $data['player_id']);
        if (!empty($cachePlatformId)) {
            /** @var Player $player */
            $player = Player::query()->find($data['player_id']);
            $lang = 'zh-CN';
            /** @var PlayerGamePlatform $gamePlatform */
            $gamePlatform = PlayerGamePlatform::query()->where('platform_id', $cachePlatformId)->where('player_id',
                $player->id)->first();
            try {
                $gameService = GameServiceFactory::createService(strtoupper($gamePlatform->gamePlatform->code),
                    $player);
            } catch (Exception $e) {
                Log::channel('game_deposit_amount')->error('game-depositAmount : ' . $e->getMessage());
                return;
            }
            $amount = $data['amount'];
            if ($amount > 0) {
                DB::beginTransaction();
                //驗證通過
                try {
                    //玩家加點數
                    /** @var PlayerPlatformCash $machineWallet */
                    $machineWallet = PlayerPlatformCash::query()->where('platform_id',
                        PlayerPlatformCash::PLATFORM_SELF)->where('player_id', $player->id)->lockForUpdate()->first();
                    $balance = $gameService->getBalance(['lang' => $lang]);
                    //驗證通過
                    $playerWalletTransfer = new PlayerWalletTransfer();
                    $playerWalletTransfer->player_id = $player->id;
                    $playerWalletTransfer->parent_player_id = $player->recommend_id ?? 0;
                    $playerWalletTransfer->agent_player_id = $player->recommend_promoter->recommend_id ?? 0;
                    $playerWalletTransfer->platform_id = $gamePlatform->platform_id;
                    $playerWalletTransfer->department_id = $player->department_id;
                    $playerWalletTransfer->type = PlayerWalletTransfer::TYPE_OUT;
                    $playerWalletTransfer->amount = abs($amount);
                    $playerWalletTransfer->game_amount = $balance;
                    $playerWalletTransfer->player_amount = $machineWallet->money;
                    $playerWalletTransfer->tradeno = createOrderNo();
                    $playerWalletTransfer->platform_no = $gameService->depositAmount([
                        'amount' => $amount,
                        'order_no' => $playerWalletTransfer->tradeno,
                        'lang' => $lang,
                    ]);
                    $playerWalletTransfer->save();
                    $beforeGameAmount = $machineWallet->money;
                    // 更新玩家统计
                    $machineWallet->money = bcsub($machineWallet->money, $playerWalletTransfer->amount, 2);
                    $machineWallet->save();

                    $playerDeliveryRecord = new PlayerDeliveryRecord;
                    $playerDeliveryRecord->player_id = $player->id;
                    $playerDeliveryRecord->department_id = $player->department_id;
                    $playerDeliveryRecord->target = $playerWalletTransfer->getTable();
                    $playerDeliveryRecord->target_id = $playerWalletTransfer->id;
                    $playerDeliveryRecord->platform_id = $gamePlatform->platform_id;
                    $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_GAME_PLATFORM_OUT;
                    $playerDeliveryRecord->source = 'wallet_transfer_out';
                    $playerDeliveryRecord->amount = $playerWalletTransfer->amount;
                    $playerDeliveryRecord->amount_before = $beforeGameAmount;
                    $playerDeliveryRecord->amount_after = $machineWallet->money;
                    $playerDeliveryRecord->tradeno = $playerWalletTransfer->tradeno ?? '';
                    $playerDeliveryRecord->remark = $playerWalletTransfer->remark ?? '';
                    $playerDeliveryRecord->user_id = 0;
                    $playerDeliveryRecord->user_name = '';
                    $playerDeliveryRecord->save();

                    DB::commit();
                } catch (Exception $e) {
                    DB::rollBack();
                    Log::channel('game_deposit_amount')->error('game-depositAmount : ' . $e->getMessage());
                }
            }
        }
    }
}
