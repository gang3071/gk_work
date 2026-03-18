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
     * 查询余额
     * @return mixed
     */
    public function balance(): mixed
    {
        return $this->player->machine_wallet()->lockForUpdate()->value('money');
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
        $playerDeliveryRecord->remark = $target->remark ?? '';
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
        $playerDeliveryRecord->remark = $target->remark ?? '';
        $playerDeliveryRecord->user_id = 0;
        $playerDeliveryRecord->user_name = '';
        $playerDeliveryRecord->save();

        return $machineWallet->money;
    }

    /**
     * 查詢玩家餘額
     * @return float
     * @throws GameException
     */
    public function getBalance(): float
    {
        return $this->player->machine_wallet->money;
    }
}
