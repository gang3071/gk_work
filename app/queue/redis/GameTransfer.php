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

class GameTransfer implements Consumer
{
    // 要消费的队列名
    public $queue = 'game-transfer';

    // 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接`
    public $connection = 'default';

    // 消费
    public function consume($data)
    {
        $log = Log::channel('game_transfer');
        $log->info('开始处理游戏转账', ['data' => $data]);

        $cachePlatformId = Cache::get('depositAmount_' . $data['player_id']);
        if (empty($cachePlatformId)) {
            $log->info('缓存平台ID不存在', ['player_id' => $data['player_id']]);
            return;
        }

        /** @var Player $player */
        $player = Player::query()->find($data['player_id']);
        if (empty($player)) {
            $log->warning('玩家不存在', ['player_id' => $data['player_id']]);
            return;
        }

        $lang = 'zh-CN';
        /** @var PlayerGamePlatform $gamePlatform */
        $gamePlatform = PlayerGamePlatform::query()->where('platform_id', $cachePlatformId)->where('player_id',
            $player->id)->first();

        if (empty($gamePlatform)) {
            $log->warning('游戏平台关联不存在', [
                'player_id' => $data['player_id'],
                'platform_id' => $cachePlatformId
            ]);
            return;
        }

        try {
            $gameService = GameServiceFactory::createService(strtoupper($gamePlatform->gamePlatform->code),
                $player);
            $balance = $gameService->getBalance(['lang' => $lang]);
            $log->info('获取游戏余额', [
                'player_id' => $data['player_id'],
                'platform_id' => $cachePlatformId,
                'balance' => $balance
            ]);
        } catch (Exception $e) {
            $log->error('获取游戏余额失败', [
                'player_id' => $data['player_id'],
                'platform_id' => $cachePlatformId,
                'error' => $e->getMessage()
            ]);
            return;
        }

        if ($balance > 0) {
            DB::beginTransaction();
            try {
                //玩家加點數
                /** @var PlayerPlatformCash $machineWallet */
                $machineWallet = PlayerPlatformCash::query()->where('platform_id',
                    PlayerPlatformCash::PLATFORM_SELF)->where('player_id', $player->id)->lockForUpdate()->first();
                $playerWalletTransfer = new PlayerWalletTransfer();
                $playerWalletTransfer->player_id = $player->id;
                $playerWalletTransfer->parent_player_id = $player->recommend_id ?? 0;
                $playerWalletTransfer->agent_player_id = $player->recommend_promoter->recommend_id ?? 0;
                $playerWalletTransfer->platform_id = $gamePlatform->platform_id;
                $playerWalletTransfer->department_id = $player->department_id;
                $playerWalletTransfer->type = PlayerWalletTransfer::TYPE_IN;
                $playerWalletTransfer->game_amount = $balance;
                $playerWalletTransfer->player_amount = $machineWallet->money;
                $playerWalletTransfer->tradeno = createOrderNo();

                $result = $gameService->withdrawAmount([
                    'amount' => $balance,
                    'order_no' => $playerWalletTransfer->tradeno,
                    'lang' => $lang,
                    'take_all' => 'true',
                ]);

                $playerWalletTransfer->platform_no = $result['order_id'];
                $playerWalletTransfer->amount = $result['amount'];
                $playerWalletTransfer->save();
                $beforeGameAmount = $machineWallet->money;
                // 更新玩家统计
                $machineWallet->money = bcadd($machineWallet->money, $playerWalletTransfer->amount, 2);
                $machineWallet->save();

                $playerDeliveryRecord = new PlayerDeliveryRecord;
                $playerDeliveryRecord->player_id = $player->id;
                $playerDeliveryRecord->department_id = $player->department_id;
                $playerDeliveryRecord->target = $playerWalletTransfer->getTable();
                $playerDeliveryRecord->target_id = $playerWalletTransfer->id;
                $playerDeliveryRecord->platform_id = $gamePlatform->platform_id;
                $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_GAME_PLATFORM_IN;
                $playerDeliveryRecord->source = 'wallet_transfer_in';
                $playerDeliveryRecord->amount = $playerWalletTransfer->amount;
                $playerDeliveryRecord->amount_before = $beforeGameAmount;
                $playerDeliveryRecord->amount_after = $machineWallet->money;
                $playerDeliveryRecord->tradeno = $playerWalletTransfer->tradeno ?? '';
                $playerDeliveryRecord->remark = $playerWalletTransfer->remark ?? '';
                $playerDeliveryRecord->user_id = 0;
                $playerDeliveryRecord->user_name = '';
                $playerDeliveryRecord->save();
                DB::commit();

                $log->info('游戏转账处理完成', [
                    'player_id' => $data['player_id'],
                    'platform_id' => $cachePlatformId,
                    'amount' => $playerWalletTransfer->amount,
                    'before_amount' => $beforeGameAmount,
                    'after_amount' => $machineWallet->money,
                    'tradeno' => $playerWalletTransfer->tradeno
                ]);
            } catch (Exception $e) {
                DB::rollBack();
                $log->error('游戏转账处理失败', [
                    'player_id' => $data['player_id'],
                    'platform_id' => $cachePlatformId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        } else {
            $log->info('游戏余额为0，无需转账', [
                'player_id' => $data['player_id'],
                'platform_id' => $cachePlatformId
            ]);
        }
    }
}
