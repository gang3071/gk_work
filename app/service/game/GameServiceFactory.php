<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\ChannelGameWeb;
use app\model\GamePlatform;
use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerPlatformCash;
use app\model\PlayGameRecord;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Str;
use WebmanTech\LaravelHttpClient\Facades\Http;

/**
 * 游戏服务工厂
 */
class GameServiceFactory
{
    const TYPE_BTG = 'BTG'; // BTGaming
    const TYPE_QT = 'QT'; // QT
    const TYPE_WM = 'WM'; // WM
    const TYPE_RSG = 'RSG'; // RSG
    const TYPE_RSG_LIVE = 'RSGLIVE'; // RSG真人
    const TYPE_ATG = 'ATG'; // ATG
    const TYPE_DG = 'DG'; // DG
    const TYPE_JDB = 'JDB'; // DG
    const TYPE_KY = 'KY'; // KY
    const TYPE_KYS = 'KYS'; // KYS
    const TYPE_YZG = 'YZG'; // YZG
    const TYPE_MT = 'MT'; // MT
    const TYPE_OB = 'OB'; // OB
    const TYPE_SP = 'SP'; // SP
    const TYPE_SPS_DY = 'SPS_DY'; // SPS单一
    const TYPE_SA = 'SA'; // SA
    const TYPE_O8 = 'O8'; // O8
    const TYPE_O8_STM = 'STM'; // O8_STM
    const TYPE_O8_HS = 'HS'; // O8_HS
    const TYPE_TNINE = 'TNINE'; // T9
    const TYPE_TNINE_SLOT = 'TNINE_SLOT'; // T9电子
    const TYPE_KT = 'KT'; // T9

    /** @var Player $player */
    public $player;
    /** @var GamePlatform $platform */
    public $platform;

    /**
     * 错误信息
     * @var string
     */
    public string $error = '';

    /**
     * 创建服务
     * @throws Exception
     */
    public static function createService(string $type, $player = null): GameServiceInterface|SingleWalletServiceInterface
    {
        switch ($type) {
            case self::TYPE_BTG:
                return new BTGServiceInterface($player);
            case self::TYPE_QT:
                return new QTServiceInterface($player);
            case self::TYPE_WM:
                return new WMServiceInterface($player);
            case self::TYPE_RSG:
                return new RSGServiceInterface($player);
            case self::TYPE_RSG_LIVE:
                return new RSGLiveServiceInterface($player);
            case self::TYPE_ATG:
                return new ATGServiceInterface($player);
            case self::TYPE_DG:
                return new DGServiceInterface($player);
            case self::TYPE_JDB:
                return new JDBServiceInterface($player);
            case self::TYPE_KY:
                return new KYServiceInterface($player);
            case self::TYPE_KYS:
                return new KYSServiceInterface($player);
            case self::TYPE_YZG:
                return new YZGServiceInterface($player);
            case self::TYPE_OB:
                return new OBServiceInterface($player);
            case self::TYPE_MT:
                return new MTServiceInterface($player);
            case self::TYPE_SP:
                return new SPServiceInterface($player);
            case self::TYPE_SPS_DY:
                return new SPSDYServiceInterface($player);
            case self::TYPE_SA:
                return new SAServiceInterface($player);
            case self::TYPE_O8:
            case self::TYPE_O8_STM:
            case self::TYPE_O8_HS:
                return new O8ServiceInterface($player, $type);
            case self::TYPE_TNINE:
                return new TNineServiceInterface($player);
            case self::TYPE_TNINE_SLOT:
                return new TNineSlotServiceInterface($player);
            case self::TYPE_KT:
                return new KTServiceInterface($player);
            default:
                throw new Exception("未知的游戏服务类型: $type");
        }
    }

    /**
     * 组装请求
     * @param string $url
     * @param array $params
     * @return array|mixed
     * @throws Exception
     */
    public function doCurl(string $url, array $params = [])
    {
        $response = Http::timeout(7)
            ->contentType('application/json')
            ->accept('application/json')
            ->asJson()
            ->post($url, $params);
        if (!$response->ok()) {
            throw new Exception(trans('system_busy', [], 'message'));
        }
        $data = $response->json();
        if (empty($data)) {
            throw new Exception(trans('system_busy', [], 'message'));
        }

        return $data;
    }

    /**
     * 生成渠道webid
     * @param $channelId
     * @return string
     */
    public static function generateWebId(): string
    {
        return substr(md5(Str::uuid()), 3, 10);
    }

    public function getWebId()
    {
        return ChannelGameWeb::query()->where('channel_id', $this->player->channel->id)->where('platform_id', $this->platform->id)->value('web_id') ?? '';
    }

    /**
     * 查询余额（使用 Redis 缓存）
     * @return mixed
     */
    public function balance(): mixed
    {
        return \app\service\WalletService::getBalance($this->player->id);
    }

    /**
     * 记录用户下注记录
     * @param PlayerPlatformCash $machineWallet
     * @param Player $player
     * @param $record
     * @param $bet
     * @return float|string
     */
    public function createBetRecord(PlayerPlatformCash $machineWallet, Player $player, $record, $bet): float|string
    {
        $beforeGameAmount = $machineWallet->money;
        //处理用户金额记录
        // 更新玩家统计
        $machineWallet->money = bcsub($machineWallet->money, $bet, 2);
        $machineWallet->save();

        //todo 语言文件后续处理
        //用户交易记录  现在单一钱包没有转账的说法 暂不记录转账记录
        $playerDeliveryRecord = new PlayerDeliveryRecord;
        $playerDeliveryRecord->player_id = $player->id;
        $playerDeliveryRecord->department_id = $player->department_id;
        $playerDeliveryRecord->target = $record->getTable();
        $playerDeliveryRecord->target_id = $record->id;
        $playerDeliveryRecord->platform_id = $this->platform->id;
        $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_BET;
        $playerDeliveryRecord->source = 'player_bet';
        $playerDeliveryRecord->amount = $bet;
        $playerDeliveryRecord->amount_before = $beforeGameAmount;
        $playerDeliveryRecord->amount_after = $machineWallet->money;
        $playerDeliveryRecord->tradeno = $record->order_no ?? '';
        $playerDeliveryRecord->remark = '遊戲下注';
        $playerDeliveryRecord->user_id = 0;
        $playerDeliveryRecord->user_name = '';
        $playerDeliveryRecord->save();

        return $machineWallet->money;
    }

    /**
     * 记录取消下注
     * @param PlayGameRecord $record
     * @param array $data
     * @param $bet
     * @return float|string
     */
    public function createCancelBetRecord(PlayGameRecord $record, array $data, $bet): float|string
    {
        $record->platform_action_at = Carbon::now()->toDateTimeString();
        $record->settlement_status = PlayGameRecord::SETTLEMENT_STATUS_CANCELLED;
        $record->action_data = json_encode($data, JSON_UNESCAPED_UNICODE);
        $record->save();


        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = $this->player->machine_wallet()->lockForUpdate()->first();

        $beforeGameAmount = $machineWallet->money;
        //处理用户金额记录
        // 更新玩家统计
        $machineWallet->money = bcadd($machineWallet->money, $bet, 2);
        $machineWallet->save();

        $player = $this->player;
        //todo 语言文件后续处理
        //用户交易记录  现在单一钱包没有转账的说法 暂不记录转账记录
        $playerDeliveryRecord = new PlayerDeliveryRecord;
        $playerDeliveryRecord->player_id = $player->id;
        $playerDeliveryRecord->department_id = $player->department_id;
        $playerDeliveryRecord->target = $record->getTable();
        $playerDeliveryRecord->target_id = $record->id;
        $playerDeliveryRecord->platform_id = $this->platform->id;
        $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_CANCEL_BET;
        $playerDeliveryRecord->source = 'player_cancel_bet';
        $playerDeliveryRecord->amount = $bet;
        $playerDeliveryRecord->amount_before = $beforeGameAmount;
        $playerDeliveryRecord->amount_after = $machineWallet->money;
        $playerDeliveryRecord->tradeno = $record->order_no ?? '';
        $playerDeliveryRecord->remark = '取消下注';
        $playerDeliveryRecord->user_id = 0;
        $playerDeliveryRecord->user_name = '';
        $playerDeliveryRecord->save();

        return $machineWallet->money;
    }

    /**
     * 查詢玩家餘額（使用 Redis 缓存）
     * @return float
     * @throws GameException
     */
    public function getBalance(): float
    {
        return \app\service\WalletService::getBalance($this->player->id);
    }

    /**
     * 检查设备是否爆机（使用 Redis 缓存）
     * 如果设备已爆机，返回true，否则返回false
     *
     * @return bool
     */
    protected function checkMachineCrash(): bool
    {
        try {
            if (!$this->player || !$this->player->id) {
                return false;
            }

            // 🚀 优化 #1: 使用 Redis 缓存爆机状态（1小时过期）
            $cacheKey = "machine_crash_status:{$this->player->id}";
            $cached = \support\Redis::get($cacheKey);

            if ($cached !== null && $cached !== false) {
                return (bool)$cached;
            }

            // 缓存未命中，从数据库查询
            /** @var PlayerPlatformCash $machineWallet */
            $machineWallet = PlayerPlatformCash::query()
                ->where('player_id', $this->player->id)
                ->where('platform_id', PlayerPlatformCash::PLATFORM_SELF)
                ->first(['is_crashed', 'platform_id']);

            if (!$machineWallet) {
                // 缓存"未爆机"状态（10分钟）
                \support\Redis::setex($cacheKey, 600, 0);
                return false;
            }

            $isCrashed = (bool)$machineWallet->is_crashed;

            // 🚀 优化 #2: 根据爆机状态设置不同的缓存过期时间
            if ($isCrashed) {
                // 爆机状态缓存1小时（爆机后较少变化）
                \support\Redis::setex($cacheKey, 3600, 1);
            } else {
                // 未爆机状态缓存10分钟（可能会变化）
                \support\Redis::setex($cacheKey, 600, 0);
            }

            return $isCrashed;

        } catch (\Exception $e) {
            \support\Log::error('GameServiceFactory: Failed to check machine crash', [
                'player_id' => $this->player->id ?? null,
                'error' => $e->getMessage(),
            ]);
            // 发生异常时返回false，不影响正常游戏流程
            return false;
        }
    }

    /**
     * 获取爆机时的余额不足错误码/错误信息
     * 子类可以重写此方法以返回平台特定的错误码
     *
     * @return mixed 返回平台特定的余额不足错误码或错误响应
     */
    protected function getInsufficientBalanceError(): mixed
    {
        // 默认返回通用错误信息
        // 子类应该重写此方法返回各自平台的错误码
        return 'INSUFFICIENT_BALANCE';
    }

    /**
     * 在下注前检查设备爆机状态（优化版）
     * 如果设备已爆机，设置错误码并返回true
     * 如果未爆机，返回false，继续正常流程
     *
     * @return bool 如果已爆机返回true，否则返回false
     */
    protected function checkAndHandleMachineCrash(): bool
    {
        // 🚀 优化 #1: Redis 预检查（避免不必要的方法调用）
        if (!$this->player || !$this->player->id) {
            return false;
        }

        $crashCheck = checkMachineCrash($this->player);

        if ($crashCheck['crashed'] && $crashCheck['crash_amount'] > 0) {
            // 设备已爆机，设置余额不足错误
            $this->error = $this->getInsufficientBalanceError();

            // 🚀 优化 #2: 只在需要时记录日志（减少 I/O）
            // 生产环境可以降低日志级别或异步写入
            if (config('app.debug', false)) {
                \support\Log::warning('GameServiceFactory: Machine crashed, bet denied', [
                    'player_id' => $this->player->id,
                    'platform' => $this->platform->code ?? null,
                    // 🚀 优化 #3: 移除重复的余额查询（减少 Redis 查询）
                    // 爆机状态已经说明余额问题，不需要再查询余额
                ]);
            }

            return true;
        }

        return false;
    }

    /**
     * 清除玩家的爆机状态缓存
     * 在玩家充值或管理员手动修改爆机状态后调用
     *
     * @param int $playerId 玩家ID
     * @return bool
     */
    public static function clearMachineCrashCache(int $playerId): bool
    {
        try {
            $cacheKey = "machine_crash_status:{$playerId}";
            \support\Redis::del($cacheKey);
            return true;
        } catch (\Exception $e) {
            \support\Log::error('GameServiceFactory: Failed to clear crash cache', [
                'player_id' => $playerId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
