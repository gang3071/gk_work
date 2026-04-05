<?php
/**
 * Here is your custom functions.
 */

use app\model\GameType;
use app\model\LevelList;
use app\model\Machine;
use app\model\MachineKeepingLog;
use app\model\MachineKickLog;
use app\model\MachineMedia;
use app\model\MachineMediaPush;
use app\model\NationalProfitRecord;
use app\model\Notice;
use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerGameLog;
use app\model\PlayerGameRecord;
use app\model\PlayerLotteryRecord;
use app\model\PlayerPlatformCash;
use app\model\PlayerPromoter;
use app\model\PlayerRechargeRecord;
use app\model\PlayerWithdrawRecord;
use app\model\PlayGameRecord;
use app\model\PromoterProfitRecord;
use app\model\PromoterProfitSettlementRecord;
use app\model\StoreSetting;
use app\model\SystemSetting;
use app\service\ActivityServices;
use app\service\LotteryServices;
use app\service\machine\MachineServices;
use app\service\machine\Slot;
use app\service\MediaServer;
use support\Cache;
use support\Db;
use support\Log;
use Webman\Push\Api;
use Webman\Push\PushException;
use Webman\RedisQueue\Client as queueClient;

/**
 * 检查玩家游戏状态 5分钟没有使用机台玩家将被踢出(分数返还)
 * @return void
 * @throws Exception
 */
function machineKeepOutPlayer(): void
{
    $log = Log::channel('machine_keeping');
    //機台例行維護中
    if (machineMaintaining()) {
        $log->info('PlayOutMachine: 全站维护中');
        return;
    }
    /** @var SystemSetting $setting */
    $setting = SystemSetting::query()->where('feature', 'pending_minutes')->where('status', 1)->first();
    if (!$setting || $setting->num <= 0) {
        $settingMinutes = 2; // 默认2分钟进入保留状态
    } else {
        $settingMinutes = $setting->num;
    }

    // 不扣保留时间设置
    $isFreeTime = false;
    /** @var SystemSetting $keepingSetting */
    $keepingSetting = SystemSetting::query()->where('feature', 'keeping_off')->where('status', 1)->first();
    if (!empty($keepingSetting)) {
        $offStart = $keepingSetting['date_start'] ?? '';
        $offEnd = $keepingSetting['date_end'] ?? '';
        if (!empty($offStart) && !empty($offEnd)) {
            $dateStart = date('Y-m-d') . ' ' . $offStart;
            $dateEnd = date('Y-m-d') . ' ' . $offEnd;

            if ($dateStart > $dateEnd) {
                $dateStart = date('Y-m-d H:i:s', strtotime($dateStart . '-1 day'));
            }

            $now = time();
            if ($now >= strtotime($dateStart) && $now <= strtotime($dateEnd)) {
                $isFreeTime = true;
            }
        }
    }
    //遊戲中玩家
    $gamingMachines = Machine::query()
        ->where('gaming', 1)
        ->where('gaming_user_id', '!=', 0)
        ->orderBy('type')
        ->get();
    /** @var Machine $machine */
    foreach ($gamingMachines as $machine) {
        try {
            if (Cache::has('machine_open_point' . $machine->id . '_' . $machine->gaming_user_id)) {
                continue;
            }
            /** @var Player $player */
            $player = $machine->gamingPlayer;
            $services = MachineServices::createServices($machine);
            if ($services->has_lock == 1) {
                $log->info('PlayOutMachine: 机台锁定跳过' . $machine->code);
                continue;
            }
            if ($machine->maintaining == 1) {
                $services->last_play_time = time();
            }
            $minutes = $settingMinutes * 60;
            if ($machine->type == GameType::TYPE_SLOT && $services->reward_status == 1) {
                $minutes = $settingMinutes + (15 * 60);
            }
            if ($services->keeping == 0 && time() - $services->last_play_time > $minutes) {
                if ($machine->type == GameType::TYPE_SLOT && $machine->is_special == 0 && $machine->control_type == Machine::CONTROL_TYPE_MEI) {
                    $services->sendCmd($services::OUT_OFF, 0, 'player', $player->id, 1);
                }
                $services->keeping = 1;
                $services->keeping_user_id = $machine->gaming_user_id;
                $services->last_keep_at = time();
                // 记录保留日志
                $machineKeepingLog = new MachineKeepingLog();
                $machineKeepingLog->player_id = $player->id;
                $machineKeepingLog->machine_id = $machine->id;
                $machineKeepingLog->machine_name = $machine->name;
                $machineKeepingLog->is_system = 1;
                $machineKeepingLog->department_id = $player->department_id;
                $machineKeepingLog->save();
                // 发送进入保留状态消息
                sendSocketMessage('player-' . $machine->gaming_user_id . '-' . $machine->id, [
                    'msg_type' => 'player_machine_keeping',
                    'player_id' => $machine->gaming_user_id,
                    'machine_id' => $machine->id,
                    'keep_seconds' => $services->keep_seconds,
                    'keeping' => $services->keeping
                ]);
                sendSocketMessage('player-' . $machine->gaming_user_id, [
                    'msg_type' => 'player_machine_keeping',
                    'player_id' => $machine->gaming_user_id,
                    'machine_id' => $machine->id,
                    'keep_seconds' => $services->keep_seconds,
                    'keeping' => $services->keeping
                ]);
            }
            if ($services->keeping == 0) {
                $log->info('PlayOutMachine: 非保留状态跳过' . $machine->code);
                continue;
            }
            if ($isFreeTime && $services->keep_seconds > 1800) {
                $log->info('PlayOutMachine: 自由时间且时间大于1800秒跳过' . $machine->code);
                continue;
            }
            $keepSeconds = $services->keep_seconds;
            if ($keepSeconds > 0) {
                if ($services->reward_status == 1) {
                    if ($machine->type == GameType::TYPE_STEEL_BALL) {
                        $log->info('PlayOutMachine: ' . $machine->code . '开奖中15分钟内不扣除保留时间');
                        continue;
                    }
                }
                $log->info('PlayOutMachine: 扣除保留时间', ['keeping_setting' => $keepingSetting, 'keep_seconds' => $keepSeconds]);
                $services->keep_seconds = max(bcsub($keepSeconds, 10), 0);
                sendSocketMessage('player-' . $machine->gaming_user_id . '-' . $machine->id, [
                    'msg_type' => 'player_machine_keeping',
                    'player_id' => $machine->gaming_user_id,
                    'machine_id' => $machine->id,
                    'keep_seconds' => $keepSeconds,
                    'keeping' => $services->keeping
                ]);
                sendSocketMessage('player-' . $machine->gaming_user_id, [
                    'msg_type' => 'player_machine_keeping',
                    'player_id' => $machine->gaming_user_id,
                    'machine_id' => $machine->id,
                    'keep_seconds' => $keepSeconds,
                    'keeping' => $services->keeping
                ]);
            } else {
                // 保留时间为0时踢出玩家
                $beforeGameAmount = $player->machine_wallet->money;
                if (machineWash($player, $machine, 'leave', 1)) {
                    /** @var PlayerPlatformCash $playerPlatformWallet */
                    $playerPlatformWallet = PlayerPlatformCash::query()->where('player_id', $player->id)->first();
                    //寫入踢人log
                    $afterGameAmount = $playerPlatformWallet->money;
                    $wash_point = abs($afterGameAmount - $beforeGameAmount);
                    $machineKickLog = new MachineKickLog;
                    $machineKickLog->player_id = $player->id;
                    $machineKickLog->machine_id = $machine->id;
                    $machineKickLog->platform_id = PlayerPlatformCash::PLATFORM_SELF;
                    $machineKickLog->wash_point = $wash_point;
                    $machineKickLog->before_game_amount = $beforeGameAmount;
                    $machineKickLog->after_game_amount = $afterGameAmount;

                    $machineKickLog->save();
                    // 更新保留日志
                    updateKeepingLog($machine->id, $player->id);
                    // 发送踢人消息
                    sendSocketMessage('player-' . $player->id . '-' . $machine->id, [
                        'msg_type' => 'kick_out',
                        'machine_id' => $machine->id,
                        'machine_name' => $machine->name,
                        'machine_code' => $machine->code,
                        'wash_point' => $wash_point,
                        'before_game_amount' => $beforeGameAmount,
                        'after_game_amount' => $afterGameAmount
                    ]);
                    sendSocketMessage('player-' . $player->id, [
                        'msg_type' => 'player_machine_keeping',
                        'player_id' => $player->id,
                        'machine_id' => $machine->id,
                        'keep_seconds' => '0',
                        'keeping' => '0'
                    ]);
                    // 清理赠点缓存
                    Cache::delete('gift_cache_' . $machine->id . '_' . $player->id);
                }
            }
        } catch (Exception $e) {
            $log->error('PlayOutMachine: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        }
    }
}

/**
 * 生成唯一单号
 * @return string
 */
function createOrderNo(): string
{

    $yCode = [
        'A',
        'B',
        'C',
        'D',
        'E',
        'F',
        'G',
        'H',
        'I',
        'J',
        'K',
        'L',
        'M',
        'N',
        'O',
        'P',
        'Q',
        'R',
        'S',
        'T',
        'U',
        'V',
        'W',
        'X',
        'Y',
        'Z'
    ];
    return $yCode[intval(date('Y')) - 2011] . strtoupper(dechex(date('m'))) . date('d') . substr(time(),
            -5) . substr(microtime(), 2, 5) . sprintf('%02d', rand(0, 99));
}

/**
 * 发送socket消息
 * @param $channels
 * @param $content
 * @param string $form
 * @return bool|string
 */
function sendSocketMessage($channels, $content, string $form = 'system'): bool|string
{
    try {
        // 直接读取 .env 配置，连接到 gk_api 的推送服务
        $api = new Api(
            env('PUSH_API_URL', 'http://10.140.0.6:3232'),
            env('PUSH_APP_KEY', '20f94408fc4c52845f162e92a253c7a3'),
            env('PUSH_APP_SECRET', '3151f8648a6ccd9d4515386f34127e28')
        );
        return $api->trigger($channels, 'message', [
            'from_uid' => $form,
            'content' => json_encode($content)
        ]);
    } catch (Exception $e) {
        Log::error('sendSocketMessage: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        return false;
    }
}

/**
 * 获取增点缓存
 * @param $playerId
 * @param $machineId
 * @return mixed
 */
function getGivePoints($playerId, $machineId): mixed
{
    return Cache::get('gift_cache_' . $machineId . '_' . $playerId);
}

/**
 * 反转位（用于 CRC8 计算）
 * @param $num
 * @param $width
 * @return void
 */
function reflect_bits(&$num, $width): void
{
    $ref = 0;

    for ($i = 0; $i < $width; $i++) {
        $bit = ($num >> $i) & 0b1;
        $bit = ($bit << (($width - 1) - $i));
        $ref = $ref | $bit;
    }

    $num = $ref;
}

/**
 * crc8Maxim检查
 * @param $str
 * @param $polynomial
 * @param $ini
 * @param $xor
 * @param bool $ref_in
 * @param bool $ref_out
 * @param bool $has_fill
 * @return string
 * @throws Exception
 */
function crc8(
    $str,
    $polynomial,
    $ini,
    $xor,
    bool $ref_in = true,
    bool $ref_out = true,
    bool $has_fill = true
): string
{
    if (!is_scalar($str)) {
        throw new exception(
            "Variable for CRC calculation must be a scalar."
        );
    }
    $crc = $ini;
    for ($i = 0; $i < strlen($str); $i++) {
        $byte = ord($str[$i]);

        if ($ref_in) {
            reflect_bits($byte, 8);
        }
        $crc ^= $byte;
        for ($j = 0; $j < 8; $j++) {
            if ($crc & 0x80) {
                $crc = (($crc << 1) & 0xff) ^ $polynomial;
            } else {
                $crc = ($crc << 1) & 0xff;
            }
        }
    }

    $result = ($crc ^ $xor) & 0xff;

    if ($ref_out) {
        reflect_bits($result, 8);
    }
    $result = sprintf("%02X", $result);

    if ($has_fill) {
        $hex = '';
        for ($i = strlen($result) - 1; $i >= 0; $i--) {
            $hex .= sprintf("%02X", hexdec($result[$i]));
        }
        return $hex;
    }

    return $result;
}

/**
 * 数据位只能有3byte
 * @param $data
 * @return string
 * @throws Exception
 */
function encodeData($data): string
{
    $dataStr = sprintf("%06X", $data);
    if (strlen($dataStr) > 6) {
        throw new Exception('数据异常');
    }
    $dataStr = strrev($dataStr);
    $paddedStr = "";
    foreach (str_split($dataStr) as $char) {
        $paddedStr .= str_pad($char, 2, '0', STR_PAD_LEFT);
    }
    return str_pad($paddedStr, 12, '0');
}

/**
 * 数据位只能有3byte
 * @param $data
 * @return string
 * @throws Exception
 */
function jackpotEncodeData($data): string
{
    $dataStr = sprintf("%06X", $data);
    if (strlen($dataStr) > 6) {
        throw new Exception('数据异常');
    }
    return substr($dataStr, 4, 2) . substr($dataStr, 2, 2) . substr($dataStr, 0, 2);
}

/**
 * 数据位只能有3byte
 * @param $data
 * @return string
 * @throws Exception
 */
function encodeDataXor55($data): string
{
    $cmd = sprintf("%06X", $data);
    if (strlen($cmd) > 6) {
        throw new Exception('数据异常');
    }
    $result = intval(hexdec(substr($cmd, 4, 2))) ^ intval(hexdec(substr($cmd, 2, 2))) ^ intval(hexdec(substr($cmd,
            0,
            2))) ^ 0x55;
    $result = sprintf("%02X", $result);
    $hex = "";
    for ($i = strlen($result) - 1; $i >= 0; $i--) {
        $hex .= sprintf("%02X", hexdec($result[$i]));
    }
    return $hex;
}

/**
 * 数据位只能有3byte
 * @param $data
 * @return string
 * @throws Exception
 */
function jackpotEncodeDataXor55($data): string
{
    $cmd = sprintf("%06X", $data);
    if (strlen($cmd) > 6) {
        throw new Exception('数据异常');
    }
    $result = intval(hexdec(substr($cmd, 0, 2))) ^ intval(hexdec(substr($cmd, 2, 2))) ^ intval(hexdec(substr($cmd,
            4,
            2))) ^ 0x55;
    return sprintf("%02X", $result);
}

/**
 * 检查crc8
 * @param string $data
 * @return bool
 * @throws Exception
 */
function checkCRC8(string $data): bool
{
    $str = substr($data, 0, 28);
    $crc8 = substr($data, 28, 4);
    if ($crc8 !== crc8(hex2bin($str), 0x31, 0x00, 0x00)) {
        throw new Exception('crc8检查不通过' . $crc8 . crc8(hex2bin($str), 0x31, 0x00, 0x00));
    }

    return true;
}

/**
 * slot检查Xor55
 * @param string $msg
 * @param string $data
 * @return bool
 * @throws Exception
 */
function checkSlotXor55(string $msg, string $data): bool
{
    $fun = substr($msg, 2, 2);
    if ($fun == Slot::MACHINE_BUSY) {
        return true;
    }
    $xor55 = substr($msg, 20, 4);
    if ($xor55 !== encodeDataXor55($data)) {
        throw new Exception('xor55检查不通过');
    }

    return true;
}

/**
 * slot检查Xor55
 * @param string $msg
 * @param string $data
 * @return bool
 * @throws Exception
 */
function checkJackpotXor55(string $msg, string $data): bool
{
    $fun = substr($msg, 2, 2);
    if ($fun == '2B') {
        return true;
    }
    $xor55 = substr($msg, 14, 2);
    if ($xor55 !== jackpotEncodeDataXor55($data)) {
        throw new Exception('xor55检查不通过');
    }

    return true;
}

/**
 * 检查crc8
 * @param string $data
 * @return bool
 * @throws Exception
 */
function jackPotCheckCRC8(string $data): bool
{
    $str = substr($data, 0, 28);
    $crc8 = substr($data, 28, 2);
    if ($crc8 !== crc8(hex2bin($str), 0x31, 0x00, 0x00, true, true, false)) {
        throw new Exception('crc8检查不通过');
    }

    return true;
}

/**
 * 解码数据位
 * @param $msg
 * @return string
 */
function decodeData($msg): string
{
    $str = substr($msg, 8, 12);
    $data2HI = substr(substr($str, 10, 2), 1, 1);
    $data2LO = substr(substr($str, 8, 2), 1, 1);

    $data1HI = substr(substr($str, 6, 2), 1, 1);
    $data1LO = substr(substr($str, 4, 2), 1, 1);

    $data0HI = substr(substr($str, 2, 2), 1, 1);
    $data0LO = substr(substr($str, 0, 2), 1, 1);

    $input = ltrim($data2HI . $data2LO . $data1HI . $data1LO . $data0HI . $data0LO, '0');
    return intval(hexdec($input));
}

/**
 * 解码数据位
 * @param $msg
 * @return string
 */
function jackpotDecodeData($msg): string
{
    $str = substr($msg, 8, 6);

    $data0 = substr($str, 4, 2);
    $data1 = substr($str, 2, 2);
    $data2 = substr($str, 0, 2);

    $input = ltrim($data0 . $data1 . $data2, '0');
    return intval(hexdec($input));
}

/**
 * slot自动卡检查crc8
 * @param string $data
 * @return bool
 * @throws Exception
 */
function slotCheckCRC8(string $data): bool
{
    $str = substr($data, 0, 12);
    $crc8 = substr($data, 12, 2);
    if ($crc8 !== crc8(hex2bin($str), 0x31, 0x00, 0x00, true, true, false)) {
        throw new Exception('crc8检查不通过');
    }
    return true;
}

/**
 * 解码机台状态
 * @param $data
 * @return string
 */
function decodeStatus($data): string
{
    $decoded_stat = hexdec($data);
    return sprintf("%08b", $decoded_stat);
}

/**
 * 解码机台状态
 * @param Machine $machine
 * @param $type
 * @param int $playerId
 */
function sendMachineException(Machine $machine, $type, int $playerId = 0): void
{
    $notice = new Notice();
    $notice->department_id = 1;
    $notice->player_id = 0;
    $notice->source_id = $machine->id;
    $notice->receiver = Notice::RECEIVER_ADMIN;
    $notice->is_private = 0;
    switch ($type) {
        case Notice::TYPE_MACHINE_BET:
            $content = '斯洛';
            $content .= '機台編號為: ' . $machine->code . ', 發生bet（壓分）數據异常，請聯系設備管理員處理！';
            $notice->content = $content;
            $notice->title = '機台bet（壓分）异常通知';
            $notice->type = Notice::TYPE_MACHINE_BET;
            $notice->save();
            $msgType = 'machine_bet_error';
            break;
        case Notice::TYPE_MACHINE_WIN:
            $content = '斯洛';
            $content .= '機台編號為: ' . $machine->code . ', 發生win（得分）數據异常，請聯系設備管理員處理！';
            $notice->content = $content;
            $notice->title = '機台win（得分）异常通知';
            $notice->type = Notice::TYPE_MACHINE_WIN;
            $notice->save();
            $msgType = 'machine_win_error';
            break;
        case Notice::TYPE_MACHINE_WIN_NUMBER:
            $content = '钢珠';
            $content .= '機台編號為: ' . $machine->code . ', 發生中洞兑奖次数（压转）數據异常，請聯系設備管理員處理！';
            $notice->content = $content;
            $notice->title = '機台中洞兑奖次数（压转）异常通知';
            $notice->type = Notice::TYPE_MACHINE_WIN_NUMBER;
            $notice->save();
            $msgType = 'machine_win_error';
            break;
        case Notice::TYPE_MACHINE:
            $content = $machine->type == GameType::TYPE_SLOT ? '斯洛' : '鋼珠';
            $content .= '機台編號為: ' . $machine->code . ', 發生异常離線，請聯系設備管理員處理！';
            $notice->content = $content;
            $notice->title = '機台離線通知';
            $notice->type = Notice::TYPE_MACHINE;
            $notice->save();
            $msgType = 'machine_online';
            break;
        case Notice::TYPE_MACHINE_LOCK:
            $content = $machine->type == GameType::TYPE_SLOT ? '斯洛' : '鋼珠';
            $content .= '機台編號為: ' . $machine->code . ', 發生异常鎖定，請聯系設備管理員處理！';
            $notice->content = $content;
            $notice->title = '機台鎖定通知';
            $notice->type = Notice::TYPE_MACHINE_LOCK;
            $notice->save();
            $msgType = 'machine_lock';
            if (!empty($playerId)) {
                /** @var Player $player */
                $player = Player::query()->find($playerId);
                sendSocketMessage('private-admin_group-channel-' . $player->department_id, [
                    'msg_type' => 'machine_lock',
                    'id' => $machine->id,
                    'player_id' => $player->id,
                ]);
                $content = $machine->type == GameType::TYPE_SLOT ? '斯洛' : '鋼珠';
                $content .= '機台編號為: ' . $machine->code . ', 發生异常鎖定';
                $content .= '當前使用玩家為: ' . $player->uuid . ', 發生异常鎖定，請聯系設備管理員處理！';
                $notice = new Notice();
                $notice->department_id = $player->department_id;
                $notice->player_id = $player->id;
                $notice->source_id = $machine->id;
                $notice->type = Notice::TYPE_MACHINE_LOCK;
                $notice->receiver = Notice::RECEIVER_DEPARTMENT;
                $notice->is_private = 0;
                $notice->title = '機台鎖定通知';
                $notice->content = $content;
                $notice->save();
            }
            break;
        default:
            return;
    }
    sendSocketMessage('private-admin_group-admin-1', [
        'msg_type' => $msgType,
        'id' => $machine->id,
    ]);
}

/**
 * 获取毫秒级时间戳
 * @return float
 */
function getMillisecond(): float
{
    [$t1, $t2] = explode(' ', microtime());
    return (float)sprintf('%.0f', (floatval($t1) + floatval($t2)) * 1000000);
}

/**
 * 毫秒转时间戳
 * @param $millisecond
 * @return string
 */
function millisecondsToTimeFormat($millisecond): string
{
    $seconds = floor($millisecond / 1000000); // 将毫秒转换为秒

    $date = new DateTime();
    $date->setTimestamp($seconds);
    return $date->format('Y-m-d H:i:s');
}

//终止机台录像
/**
 * 生成随机1位字符串
 * @param int $length
 * @return string
 */
function generateRandomString(int $length = 1): string
{
    // 定义字符集
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';

    // 生成随机字符串
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[mt_rand(0, $charactersLength - 1)];
    }

    return $randomString;
}

/**
 *  获取推流地址
 *  如果不传key和过期时间，将返回不含防盗链的url
 * @param $machineCode
 * @param string $pushDomain
 * @param string $pushKey
 * @return array
 */
function getPushUrl($machineCode, string $pushDomain = '', string $pushKey = ''): array
{
    $pushUrl = '';
    $endpointServiceId = uniqid();
    if (!empty($machineCode) && !empty($pushDomain)) {
        $name = $machineCode . '_' . $endpointServiceId;
        if (!empty($pushKey)) {
            $time = date('Y-m-d H:i:s'); // 获取当前时间
            $timePlus24Hours = date('Y-m-d H:i:s', strtotime($time) + 24 * 60 * 60 * 30 * 24);
            $txTime = strtoupper(base_convert(strtotime($timePlus24Hours), 10, 16));
            $txSecret = md5($pushKey . $name . $txTime);
            $ext_str = "?" . http_build_query(array(
                    "txSecret" => $txSecret,
                    "txTime" => $txTime
                ));
        }
        $pushUrl = [
            'rtmp_url' => "rtmp://" . $pushDomain . "/live/" . $name . ($ext_str ?? ""),
            'expiration_date' => $timePlus24Hours ?? '',
            'endpoint_service_id' => $endpointServiceId,
            'machine_code' => $machineCode,
        ];
    }

    return $pushUrl;
}

/**
 * 清理媒体流
 * @return void
 */
function mediaClear(): void
{
    MachineMedia::query()
        ->whereHas('machine', function ($query) {
            $query->where('status', 1)->whereNull('deleted_at');
        })->chunk(100, function ($machineMediaList) {
            /** @var MachineMedia $machineMedia */
            foreach ($machineMediaList as $machineMedia) {
                $mediaServer = new MediaServer($machineMedia->push_ip, $machineMedia->media_app);
                try {
                    $endpointServiceId = [];
                    $streamInfo = $mediaServer->getBroadcasts($machineMedia->stream_name);
                    if (!empty($streamInfo['endPointList'])) {
                        foreach ($streamInfo['endPointList'] as $endPoint) {
                            if (!MachineMediaPush::query()->where('endpoint_service_id',
                                $endPoint['endpointServiceId'])->exists()) {
                                $mediaServer->deleteRtmpEndpoint($endPoint['endpointServiceId'],
                                    $machineMedia->stream_name);
                            }
                            $endpointServiceId[] = $endPoint['endpointServiceId'];
                        }
                    }
                    $mediaServer->log->error('MediaClear', [
                        'stream_info' => $streamInfo,
                        'endpoint_service_id' => $endpointServiceId,
                        'machine_code' => $machineMedia->machine->code
                    ]);
                } catch (Exception $e) {
                    $mediaServer->log->error('MediaClear: ' . $e->getMessage(), [
                        'machine_code' => $machineMedia->machine->code,
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }
        });
}

/**
 * 机台维护检查
 * @return bool
 */
function machineMaintaining(): bool
{
    //每周機台維護時段
    /** @var SystemSetting $setting */
    $setting = SystemSetting::query()->where('feature', 'machine_maintain')->first();
    if ($setting && $setting->status != 0) {
        $week = $setting->num;
        $time_start = $setting->date_start;
        $time_end = $setting->date_end;
        $today_week = date('w');
        if ($today_week == '0') {
            $today_week = '7';
        }
        //判斷星期是否一樣
        if ($week != $today_week) {
            return false;
        }
        if (!empty($time_start) && !empty($time_end)) {
            $date_start = date('Y-m-d') . ' ' . $time_start;
            $date_end = date('Y-m-d') . ' ' . $time_end;
            $now = time();
            if ($now >= strtotime($date_start) && $now <= strtotime($date_end)) {
                return true;
            }
        }
    }
    return false;
}

/**
 * 更新保留日志
 * @param $machineId
 * @param $playerId
 * @return void
 */
function updateKeepingLog($machineId, $playerId): void
{
    /** @var MachineKeepingLog $machineKeepingLog */
    $machineKeepingLog = MachineKeepingLog::query()->where([
        'machine_id' => $machineId,
        'player_id' => $playerId
    ])->where('status', MachineKeepingLog::STATUS_STAR)->first();
    if ($machineKeepingLog) {
        // 更新保留日志
        $machineKeepingLog->keep_seconds = time() - strtotime($machineKeepingLog->created_at);
        $machineKeepingLog->status = MachineKeepingLog::STATUS_END;
        $machineKeepingLog->save();
    }
}

/**
 * 执行推广员分润结算
 * @param $id
 * @param int $userId
 * @param string $userName
 * @return void
 * @throws Exception
 */
function doSettlement($id, int $userId = 0, string $userName = ''): void
{
    /** @var PlayerPromoter $playerPromoter */
    $playerPromoter = PlayerPromoter::query()->where('player_id', $id)->first();
    if (empty($playerPromoter)) {
        throw new Exception(trans('profit_amount_not_found', [], 'message'));
    }
    if ($playerPromoter->status == 0) {
        throw new Exception(trans('player_promoter_has_disable', [], 'message'));
    }
    if (!isset($playerPromoter->profit_amount)) {
        throw new Exception(trans('profit_amount_not_found', [], 'message'));
    }
    $profitAmount = PromoterProfitRecord::query()->where('status', PromoterProfitRecord::STATUS_UNCOMPLETED)
        ->where('promoter_player_id', $id)
        ->first([
            DB::raw('SUM(`withdraw_amount`) as total_withdraw_amount'),
            DB::raw('SUM(`recharge_amount`) as total_recharge_amount'),
            DB::raw('SUM(`commission`) as total_commission_amount'),
            DB::raw('SUM(`bonus_amount`) as total_bonus_amount'),
            DB::raw('SUM(`admin_deduct_amount`) as total_admin_deduct_amount'),
            DB::raw('SUM(`admin_add_amount`) as total_admin_add_amount'),
            DB::raw('SUM(`present_amount`) as total_present_amount'),
            DB::raw('SUM(`machine_up_amount`) as total_machine_up_amount'),
            DB::raw('SUM(`machine_down_amount`) as total_machine_down_amount'),
            DB::raw('SUM(`lottery_amount`) as total_lottery_amount'),
            DB::raw('SUM(`profit_amount`) as total_profit_amount'),
            DB::raw('SUM(`player_profit_amount`) as total_player_profit_amount'),
            DB::raw('SUM(`game_amount`) as total_game_amount'),
        ])
        ->toArray();

    DB::beginTransaction();
    try {
        $promoterProfitSettlementRecord = new PromoterProfitSettlementRecord();
        $promoterProfitSettlementRecord->department_id = $playerPromoter->player->department_id;
        $promoterProfitSettlementRecord->promoter_player_id = $playerPromoter->player_id;
        $promoterProfitSettlementRecord->total_withdraw_amount = $profitAmount['total_withdraw_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_recharge_amount = $profitAmount['total_recharge_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_commission_amount = $profitAmount['total_commission_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_bonus_amount = $profitAmount['total_bonus_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_admin_deduct_amount = $profitAmount['total_admin_deduct_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_admin_add_amount = $profitAmount['total_admin_add_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_present_amount = $profitAmount['total_present_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_machine_up_amount = $profitAmount['total_machine_up_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_machine_down_amount = $profitAmount['total_machine_down_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_lottery_amount = $profitAmount['total_lottery_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_profit_amount = $profitAmount['total_profit_amount'];
        $promoterProfitSettlementRecord->total_player_profit_amount = $profitAmount['total_player_profit_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_game_amount = $profitAmount['total_game_amount'] ?? 0;
        $promoterProfitSettlementRecord->last_profit_amount = $playerPromoter->last_profit_amount;
        $promoterProfitSettlementRecord->adjust_amount = $playerPromoter->adjust_amount;
        $promoterProfitSettlementRecord->type = PromoterProfitSettlementRecord::TYPE_SETTLEMENT;
        $promoterProfitSettlementRecord->tradeno = createOrderNo();
        $promoterProfitSettlementRecord->user_id = $userId;
        $promoterProfitSettlementRecord->user_name = $userName;
        $settlement = $amount = bcsub(bcadd($promoterProfitSettlementRecord->total_profit_amount,
            $promoterProfitSettlementRecord->adjust_amount, 2),
            $promoterProfitSettlementRecord->total_commission_amount, 2);
        if ($amount > 0) {
            if ($playerPromoter->settlement_amount < 0) {
                $diffAmount = bcadd($amount, $playerPromoter->settlement_amount, 2);
                $settlement = max($diffAmount, 0);
            }
        }
        $promoterProfitSettlementRecord->actual_amount = $settlement;
        $promoterProfitSettlementRecord->save();
        // 更新结算报表
        PromoterProfitRecord::query()->where('status', PromoterProfitRecord::STATUS_UNCOMPLETED)
            ->where('promoter_player_id', $id)
            ->update([
                'status' => PromoterProfitRecord::STATUS_COMPLETED,
                'settlement_time' => date('Y-m-d H:i:s'),
                'settlement_tradeno' => $promoterProfitSettlementRecord->tradeno,
                'settlement_id' => $promoterProfitSettlementRecord->id,
            ]);
        // 结算后这些数据清零
        $playerPromoter->profit_amount = 0;
        $playerPromoter->player_profit_amount = 0;
        $playerPromoter->team_recharge_total_amount = 0;
        $playerPromoter->total_commission = 0;
        $playerPromoter->team_withdraw_total_amount = 0;
        $playerPromoter->adjust_amount = 0;
        // 更新数据
        $playerPromoter->team_profit_amount = bcsub($playerPromoter->team_profit_amount,
            $promoterProfitSettlementRecord->total_profit_amount, 2);
        $playerPromoter->last_profit_amount = $settlement;
        $playerPromoter->settlement_amount = bcadd($playerPromoter->settlement_amount, $amount, 2);
        $playerPromoter->team_settlement_amount = bcadd($playerPromoter->team_settlement_amount,
            $promoterProfitSettlementRecord->total_profit_amount, 2);
        $playerPromoter->last_settlement_time = date('Y-m-d', strtotime('-1 day'));

        if (!empty($playerPromoter->path)) {
            PlayerPromoter::query()->where('player_id', '!=', $playerPromoter->player_id)
                ->whereIn('player_id', explode(',', $playerPromoter->path))
                ->update([
                    'team_profit_amount' => DB::raw("team_profit_amount - {$promoterProfitSettlementRecord->total_profit_amount}"),
                    'team_settlement_amount' => DB::raw("team_settlement_amount + $promoterProfitSettlementRecord->total_profit_amount"),
                ]);
        }
        if ($settlement > 0) {
            // 增加钱包余额
            $amountBefore = $playerPromoter->player->machine_wallet->money;
            $amountAfter = bcadd($amountBefore, $settlement, 2);
            $playerDeliveryRecord = new PlayerDeliveryRecord;
            $playerDeliveryRecord->player_id = $playerPromoter->player_id;
            $playerDeliveryRecord->department_id = $playerPromoter->department_id;
            $playerDeliveryRecord->target = $promoterProfitSettlementRecord->getTable();
            $playerDeliveryRecord->target_id = $promoterProfitSettlementRecord->id;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_PROFIT;
            $playerDeliveryRecord->source = 'profit';
            $playerDeliveryRecord->amount = $settlement;
            $playerDeliveryRecord->amount_before = $amountBefore;
            $playerDeliveryRecord->amount_after = $amountAfter;
            $playerDeliveryRecord->tradeno = $promoterProfitSettlementRecord->tradeno ?? '';
            $playerDeliveryRecord->remark = '';
            $playerDeliveryRecord->save();

            $playerPromoter->player->machine_wallet->money = $amountAfter;
            $playerPromoter->player->machine_wallet->save();
        }
        $playerPromoter->push();
        DB::commit();
    } catch (Exception $e) {
        DB::rollback();
        throw new Exception($e->getMessage());
    }
}

/**
 * 机台洗分
 * @param Player $player
 * @param Machine $machine
 * @param string $path
 * @param int $is_system
 * @param bool $hasLottery
 * @return PlayerLotteryRecord|array|bool
 * @throws Exception
 * @throws PushException
 */
function machineWash(
    Player  $player,
    Machine $machine,
    string  $path = 'leave',
    int     $is_system = 0,
    bool    $hasLottery = false
): PlayerLotteryRecord|bool|array
{
    try {
        $lang = locale() ?? 'zh_CN';
        $services = MachineServices::createServices($machine, $lang);
        if ($services->last_point_at + 5 >= time()) {
            throw new Exception(trans('exception_msg.point_must_5seconds', [], 'message', $lang));
        }
        // 洗分限制（强制退出洗分）
        $giftPoint = getGivePoints($player->id, $machine->id);
        $gamingTurnPoint = 0; // 转数
        $gamingPressure = 0; // 压分
        $gamingScore = 0; // 得分
        $money = 0; // 机台下分
        //斯洛 需要判斷下分限制
        switch ($machine->type) {
            case GameType::TYPE_STEEL_BALL:
                // 弃台需要下转,下珠
                if ($path == 'leave') {
                    if ($machine->control_type == Machine::CONTROL_TYPE_MEI) {
                        $services->sendCmd($services::PUSH . $services::PUSH_STOP, 0, 'player', $player->id,
                            $is_system);
                        if ($services->auto == 1) {
                            $services->sendCmd($services::AUTO_UP_TURN, 0, 'player', $player->id, $is_system);
                        }
                        if ($services->score > 0) {
                            $services->sendCmd($services::SCORE_TO_POINT, 0, 'player', $player->id, $is_system);
                        }
                        if ($services->turn > 0) {
                            $services->sendCmd($services::TURN_DOWN_ALL, 0, 'player', $player->id, $is_system);
                        }
                    }
                    if ($machine->control_type == Machine::CONTROL_TYPE_SONG) {
                        if ($services->auto == 1) {
                            $services->sendCmd($services::AUTO_UP_TURN, 0, 'player', $player->id, $is_system);
                        }
                        $services->sendCmd($services::MACHINE_TURN, 0, 'player', $player->id, $is_system);
                        $services->sendCmd($services::MACHINE_SCORE, 0, 'player', $player->id, $is_system);
                        if ($services->score > 0) {
                            $services->sendCmd($services::SCORE_TO_POINT, 0, 'player', $player->id, $is_system);
                        }
                        if ($services->turn > 0) {
                            $services->sendCmd($services::TURN_DOWN_ALL, 0, 'player', $player->id, $is_system);
                        }
                    }
                }
                $services->sendCmd($services::MACHINE_POINT, 0, 'player', $player->id, $is_system);
                $services->sendCmd($services::WIN_NUMBER, 0, 'player', $player->id, $is_system);
                $gamingTurnPoint = $services->player_win_number;
                $money = $services->point;
                if (!empty($giftPoint) && $path == 'leave') {
                    $money = max($money - $giftPoint['gift_point'], 0);
                }
                break;
            case GameType::TYPE_SLOT:
                if ($services->move_point == 1 && $machine->control_type == Machine::CONTROL_TYPE_MEI) {
                    $services->sendCmd($services::MOVE_POINT_OFF, 0, 'player', $player->id, $is_system);
                }
                if ($services->auto == 1) {
                    $services->sendCmd($services::OUT_OFF, 0, 'player', $player->id, $is_system);
                }
                $services->sendCmd($services::STOP_ONE, 0, 'player', $player->id, $is_system);
                $services->sendCmd($services::STOP_TWO, 0, 'player', $player->id, $is_system);
                $services->sendCmd($services::STOP_THREE, 0, 'player', $player->id, $is_system);
                $services->sendCmd($services::READ_SCORE, 0, 'player', $player->id, $is_system);
                Log::channel('song_slot_machine')->info('slot -> wash', [
                    'point' => $money,
                    'code' => $machine->code,
                    'bet' => $services->bet,
                    'player_pressure' => $services->player_pressure,
                ]);
                $services->sendCmd($services::READ_BET, 0, 'player', $player->id, $is_system);
                $gamingPressure = bcsub($services->bet, $services->player_pressure);
                $gamingScore = bcsub($services->win, $services->player_score);
                $money = $services->point;
                Log::channel('slot_machine')->info('slot -> wash', [
                    'point' => $money,
                    'code' => $machine->code,
                ]);
                if (!empty($giftPoint)) {
                    if ($money < $giftPoint['open_point'] * $giftPoint['condition']) {
                        $money = max($money - $giftPoint['gift_point'], 0);
                    }
                }
                break;
        }
    } catch (Exception $e) {
        throw new Exception($e->getMessage());
    }

    /** 彩金预留检查 */
    if ($hasLottery && $machine->type == GameType::TYPE_SLOT && $path == 'down' && $money > 0) {
        try {
            $playerLotteryRecord = (new LotteryServices())->setMachine($machine)->setPlayer($player)->fixedPotCheckLottery($money,
                true);
            if ($playerLotteryRecord) {
                return $playerLotteryRecord;
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }
    DB::beginTransaction();
    try {
        if ($money >= 0) {
            $machine = machineWashZero($player, $machine, $money, $is_system, max($gamingPressure, 0),
                max($gamingScore, 0), max($gamingTurnPoint, 0), $path);
        }
        if ($path == 'leave') {
            if ($services->keeping == 1) {
                // 更新保留日志
                updateKeepingLog($machine->id, $player->id);
            }
            $machine->gaming = 0;
            $machine->gaming_user_id = 0;
            $machine->save();

            if ($machine->type == GameType::TYPE_STEEL_BALL) {
                $activityServices = new ActivityServices($machine, $player);
                $activityServices->playerFinishActivity(true);
            }
            /** TODO 计算打码量 */
        }
        // 斯洛离开机台或弃台下分重置活动 检查彩金中奖情况
        if ($machine->type == GameType::TYPE_SLOT) {
            // 离开机台参与活动结束
            $activityServices = new ActivityServices($machine, $player);
            $activityServices->playerFinishActivity(true);
            // 下分检查彩金获奖情况
            if ($money > 0) {
                $playerLotteryRecord = (new LotteryServices())->setMachine($machine)->setPlayer($player)->fixedPotCheckLottery($money,
                    false, $path == 'leave');
            }
        }
        DB::commit();
        // 执行下分操作
        switch ($machine->type) {
            case GameType::TYPE_STEEL_BALL:
                $services->sendCmd($services::WASH_ZERO, 0, 'player', $player->id, $is_system);
                $services->sendCmd($services::CLEAR_LOG, 0, 'player', $player->id, $is_system);
                $services->player_win_number = 0;
                break;
            case GameType::TYPE_SLOT:
                $services->sendCmd($services::WASH_ZERO, 0, 'player', $player->id, $is_system);
                $services->sendCmd($services::ALL_DOWN, 0, 'player', $player->id, $is_system);
                $services->player_pressure = 0;
                $services->player_score = 0;
                $services->bet = 0;
                break;
        }
    } catch (Exception $e) {
        DB::rollback();
        throw new Exception($e->getMessage());
    }
    // 游戏结束同步Redis彩金到数据库（新版：独立彩池模式）
    // 强制同步所有彩金的Redis数据到数据库
    try {
        LotteryServices::forceSyncRedisToDatabase();
    } catch (Exception $e) {
        Log::error('游戏结束同步彩金失败: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
    }
    queueClient::send('media-recording', [
        'machine_id' => $machine->id,
        'action' => 'stop',
    ], 10);
    //下分成功 下分&下轉限制歸零 開獎中結束 關閉 push auto
    $services->last_play_time = time();
    if ($path == 'leave') {
        $services->gaming_user_id = 0;
        $services->gaming = 0;
        $services->keeping_user_id = 0;
        $services->keeping = 0;
        $services->last_keep_at = 0;
        $services->keep_seconds = 0;
        if ($machine->type == GameType::TYPE_SLOT) {
            $services->player_pressure = 0;
            $services->player_score = 0;
        }
        if ($machine->type == GameType::TYPE_STEEL_BALL) {
            $services->player_win_number = 0;
        }
        $services->player_open_point = 0;
        $services->player_wash_point = 0;
    }
    switch ($machine->type) {
        case GameType::TYPE_STEEL_BALL:
            if ($path == 'leave') {
                $services->gift_bet = 0;
                Cache::delete('gift_cache_' . $machine->id . '_' . $player->id);
            }
            break;
        case GameType::TYPE_SLOT:
            Cache::delete('gift_cache_' . $machine->id . '_' . $player->id);
            break;
    }

    // 清理消息缓存
    LotteryServices::clearNoticeCache($player->id, $machine->id);

    return $playerLotteryRecord ?? true;
}

/**
 * 洗分清零算法
 * @param Player $player
 * @param Machine $machine
 * @param $money
 * @param int $is_system
 * @param int $gamingPressure
 * @param int $gamingScore
 * @param int $gamingTurnPoint
 * @param string $action
 * @return Machine
 * @throws Exception
 */
function machineWashZero(
    Player  $player,
    Machine $machine,
            $money,
    int     $is_system = 0,
    int     $gamingPressure = 0,
    int     $gamingScore = 0,
    int     $gamingTurnPoint = 0,
    string  $action = 'leave'
): Machine
{
    try {
        $services = MachineServices::createServices($machine);
        $control_open_point = !empty($machine->control_open_point) ? $machine->control_open_point : 100;
        //记录游戏局记录
        /** @var PlayerGameRecord $gameRecord */
        $gameRecord = PlayerGameRecord::query()->where('machine_id', $machine->id)
            ->where('player_id', $player->id)
            ->where('status', PlayerGameRecord::STATUS_START)
            ->orderBy('created_at', 'desc')
            ->first();
        /** @var PlayerPlatformCash $machineWallet */
        $machineWallet = PlayerPlatformCash::query()->where('player_id', $player->id)->lockForUpdate()->first();
        $beforeGameAmount = $machineWallet->money;
        if ($money > 0) {
            //api洗分
            $wash_point = $money;
            //依照比值轉成錢包幣值 無條件捨去
            $game_amount = floor($money * ($machine->odds_x ?? 1) / ($machine->odds_y ?? 1));
            $machineWallet->money = bcadd($machineWallet->money, $game_amount, 2);
            $machineWallet->save();
            if (!empty($gameRecord)) {
                $gameRecord->wash_point = bcadd($gameRecord->wash_point, $wash_point, 2);
                $gameRecord->wash_amount = bcadd($gameRecord->wash_amount, $game_amount, 2);
                $gameRecord->after_game_amount = $machineWallet->money;
                if ($action == 'leave') {
                    $gameRecord->status = PlayerGameRecord::STATUS_END;
                    /** TODO 计算客损 */
                    $diff = bcsub($gameRecord->wash_amount, $gameRecord->open_amount, 2);
                    nationalPromoterSettlement([
                        ['player_id' => $player->id, 'bet' => 0, 'diff' => $diff]
                    ]);
                    if (!empty($player->recommend_id)) {
                        $recommendPromoter = Player::query()->find($player->recommend_id);
                        $gameRecord->national_damage_ratio = $recommendPromoter->national_promoter->level_list->damage_rebate_ratio ?? 0;
                    }
                }
                $gameRecord->save();
            }

            //添加机台点数转换记录
            $playerGameLog = addPlayerGameLog($player, $machine, $gameRecord, $control_open_point);
            $playerGameLog->wash_point = $wash_point;
            $playerGameLog->game_amount = $game_amount;
            $playerGameLog->before_game_amount = $beforeGameAmount;
            $playerGameLog->after_game_amount = $machineWallet->money;
            $playerGameLog->action = ($action == 'leave' ? PlayerGameLog::ACTION_LEAVE : PlayerGameLog::ACTION_DOWN);
            $playerGameLog->chip_amount = 0;
            if ($machine->type == GameType::TYPE_SLOT) {
                $ratio = ($machine->odds_x ?? 1) / ($machine->odds_y ?? 1);
                $playerGameLog->chip_amount = bcmul($gamingPressure, $ratio, 2);
            } elseif ($machine->type == GameType::TYPE_STEEL_BALL) {
                $playerGameLog->chip_amount = bcmul($machine->machineCategory->turn_used_point, $gamingTurnPoint);
            }
            extracted($is_system, $playerGameLog, $gamingPressure, $gamingScore, $gamingTurnPoint);

            //寫入金流明細
            $playerDeliveryRecord = new PlayerDeliveryRecord;
            $playerDeliveryRecord->player_id = $player->id;
            $playerDeliveryRecord->department_id = $player->department_id;
            $playerDeliveryRecord->target = $playerGameLog->getTable();
            $playerDeliveryRecord->target_id = $playerGameLog->id;
            $playerDeliveryRecord->machine_id = $machine->id;
            $playerDeliveryRecord->machine_name = $machine->name;
            $playerDeliveryRecord->machine_type = $machine->type;
            $playerDeliveryRecord->code = $machine->code;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_MACHINE_DOWN;
            $playerDeliveryRecord->source = 'game_machine';
            $playerDeliveryRecord->amount = $game_amount;
            $playerDeliveryRecord->amount_before = $beforeGameAmount;
            $playerDeliveryRecord->amount_after = $machineWallet->money;
            $playerDeliveryRecord->tradeno = $playerGameLog->tradeno ?? '';
            $playerDeliveryRecord->remark = $playerGameLog->remark ?? '';
            $playerDeliveryRecord->user_id = 0;
            $playerDeliveryRecord->user_name = '';
            $playerDeliveryRecord->save();

            //保存下分時間
            $services->last_point_at = time();
            //累計該玩家洗分
            $services->player_wash_point = bcadd($services->player_wash_point, $wash_point);
        } else {
            //添加机台点数转换记录
            $playerGameLog = addPlayerGameLog($player, $machine, $gameRecord, $control_open_point);
            $playerGameLog->wash_point = 0;
            $playerGameLog->game_amount = 0;
            $playerGameLog->before_game_amount = $machineWallet->money;
            $playerGameLog->after_game_amount = $machineWallet->money;
            $playerGameLog->action = ($action == 'leave' ? PlayerGameLog::ACTION_LEAVE : PlayerGameLog::ACTION_DOWN);
            extracted($is_system, $playerGameLog, $gamingPressure, $gamingScore, $gamingTurnPoint);

            if (!empty($gameRecord)) {
                $gameRecord->after_game_amount = $machineWallet->money;
                if ($action == 'leave') {
                    $gameRecord->status = PlayerGameRecord::STATUS_END;
                    /** TODO 计算客损 */
                    $diff = bcsub($gameRecord->wash_amount, $gameRecord->open_amount, 2);
                    nationalPromoterSettlement([
                        ['player_id' => $player->id, 'bet' => 0, 'diff' => $diff]
                    ]);
                    if (!empty($player->recommend_id)) {
                        $recommendPromoter = Player::query()->find($player->recommend_id);
                        $gameRecord->national_damage_ratio = $recommendPromoter->national_promoter->level_list->damage_rebate_ratio ?? 0;
                    }
                }
                $gameRecord->save();
            }
            //保存下分時間
            $services->last_point_at = time();
        }
    } catch (Exception $e) {
        throw new Exception($e->getMessage());
    }

    return $machine;
}

/**
 * 添加游戏日志记录
 * @param Player $player
 * @param Machine $machine
 * @param PlayerGameRecord|null $gameRecord
 * @param int $control_open_point
 * @return PlayerGameLog
 */
function addPlayerGameLog(
    Player            $player,
    Machine           $machine,
    ?PlayerGameRecord $gameRecord,
    int               $control_open_point
): PlayerGameLog
{
    $odds = $machine->odds_x . ':' . $machine->odds_y;
    if ($machine->type == GameType::TYPE_STEEL_BALL) {
        $odds = $machine->machineCategory->name;
    }
    $playerGameLog = new PlayerGameLog;
    $playerGameLog->player_id = $player->id;
    $playerGameLog->parent_player_id = $player->recommend_id ?? 0;
    $playerGameLog->agent_player_id = $player->recommend_promoter->recommend_id ?? 0;
    $playerGameLog->department_id = $player->department_id;
    $playerGameLog->machine_id = $machine->id;
    $playerGameLog->game_record_id = isset($gameRecord) && !empty($gameRecord->id) ? $gameRecord->id : 0;
    $playerGameLog->game_id = $machine->machineCategory->game_id;
    $playerGameLog->type = $machine->type;
    $playerGameLog->odds = $odds;
    $playerGameLog->control_open_point = $control_open_point;
    $playerGameLog->open_point = 0;
    $playerGameLog->turn_used_point = $machine->machineCategory->turn_used_point;
    $playerGameLog->is_test = $player->is_test; //标记测试数据

    return $playerGameLog;
}

/**
 * 提取游戏日志数据
 * @param int $is_system
 * @param PlayerGameLog $playerGameLog
 * @param int $gamingPressure 押分
 * @param int $gamingScore 得分
 * @param int $gamingTurnPoint 转数
 * @return void
 */
function extracted(
    int           $is_system,
    PlayerGameLog $playerGameLog,
    int           $gamingPressure,
    int           $gamingScore,
    int           $gamingTurnPoint
): void
{
    $playerGameLog->is_system = $is_system;
    $playerGameLog->pressure = $gamingPressure;
    $playerGameLog->score = $gamingScore;
    $playerGameLog->turn_point = $gamingTurnPoint;
    $playerGameLog->user_id = 0;
    $playerGameLog->user_name = '';
    $playerGameLog->save();
}

/**
 * 全民代理结算
 * @param $data
 * @return bool
 */
function nationalPromoterSettlement($data): bool
{
    foreach ($data as $item) {
        /** @var Player $player */
        $player = Player::query()->find($item['player_id']);
        //玩家上级详情
        $recommendPromoter = Player::query()->find($player->recommend_id);
        //计算所有玩家打码量
        if ($item['bet'] > 0) {
            //当前玩家打码量
            $player->national_promoter->chip_amount = bcadd($player->national_promoter->chip_amount, $item['bet'],
                2);
            //根据打码量查询玩家当前全民代理等级
            $levelId = LevelList::query()->where('department_id', $player->department_id)
                ->where('must_chip_amount', '<=',
                    $player->national_promoter->chip_amount)->orderBy('must_chip_amount', 'desc')->first();
            if (!empty($levelId) && isset($levelId->id)) {
                //根据打码量提升玩家全民代理等级
                $player->national_promoter->level = $levelId->id;
            }
            $player->push();
        }
        //当前玩家渠道未开通全民代理功能
        if ($player->channel->national_promoter_status == 0) {
            continue;
        }
        //上级是全民代理,并且当前玩家已充值激活全民代理身份
        if (!empty($recommendPromoter) && !empty($recommendPromoter->national_promoter) && $item['diff'] != 0 && !empty($player->national_promoter) && $player->national_promoter->status == 1 && $recommendPromoter->is_promoter < 1) {
            $damageRebateRatio = isset($recommendPromoter->national_promoter->level_list->damage_rebate_ratio) ? $recommendPromoter->national_promoter->level_list->damage_rebate_ratio : 0;
            $money = bcdiv(bcmul(-$item['diff'], $damageRebateRatio, 2), 100, 2);
            $recommendPromoter->national_promoter->pending_amount = bcadd($recommendPromoter->national_promoter->pending_amount,
                $money, 2);
            $recommendPromoter->push();
            /** @var NationalProfitRecord $nationalProfitRecord */
            $nationalProfitRecord = NationalProfitRecord::query()->where('uid', $player->id)
                ->where('type', 1)
                ->whereDate('created_at', date('Y-m-d'))->first();
            if (!empty($nationalProfitRecord)) {
                $nationalProfitRecord->money = bcadd($nationalProfitRecord->money, $money, 2);
            } else {
                $nationalProfitRecord = new NationalProfitRecord();
                $nationalProfitRecord->uid = $player->id;
                $nationalProfitRecord->recommend_id = $player->recommend_id;
                $nationalProfitRecord->money = $money;
                $nationalProfitRecord->type = 1;
            }
            $nationalProfitRecord->save();
        }
    }
    return true;
}

/**
 * 发送提现待审核消息
 * @return void
 * @throws Exception
 */
function reviewedWithdrawMessage(): void
{
    $subQuery = PlayerWithdrawRecord::query()
        ->select(DB::raw('MAX(id) as id'))
        ->where('status', PlayerWithdrawRecord::STATUS_WAIT)
        ->groupBy('department_id');
    /** @var PlayerWithdrawRecord $playerWithdrawRecord */
    $playerWithdrawRecordList = PlayerWithdrawRecord::query()
        ->whereIn('id', $subQuery)
        ->get();
    if (!empty($playerWithdrawRecordList)) {
        /** @var PlayerWithdrawRecord $item */
        foreach ($playerWithdrawRecordList as $item) {
            sendSocketMessage('private-admin_group-channel-' . $item->department_id, [
                'msg_type' => 'player_create_withdraw_order',
                'id' => $item->id,
                'player_id' => $item->player_id,
                'player_name' => $item->player_name,
                'player_phone' => $item->player_phone,
                'money' => $item->money,
                'point' => $item->point,
                'status' => $item->status,
                'tradeno' => $item->tradeno,
            ]);
        }
    }
}

/**
 * 发送充值待审核消息
 * @return void
 * @throws Exception
 */
function reviewedRechargeMessage(): void
{
    $subQuery = PlayerRechargeRecord::query()
        ->select(DB::raw('MAX(id) as id'))
        ->where('status', PlayerRechargeRecord::STATUS_RECHARGING)
        ->whereIn('type', [PlayerRechargeRecord::TYPE_SELF, PlayerRechargeRecord::TYPE_BUSINESS])
        ->groupBy('department_id');
    /** @var PlayerRechargeRecord $playerRechargeRecord */
    $playerRechargeRecordList = PlayerRechargeRecord::query()
        ->whereIn('id', $subQuery)
        ->get();
    if (!empty($playerRechargeRecordList)) {
        /** @var PlayerRechargeRecord $item */
        foreach ($playerRechargeRecordList as $item) {
            sendSocketMessage('private-admin_group-channel-' . $item->department_id, [
                'msg_type' => 'player_examine_recharge_order',
                'id' => $item->id,
                'player_id' => $item->player_id,
                'player_name' => $item->player_name,
                'player_phone' => $item->player_phone,
                'money' => $item->money,
                'status' => $item->status,
                'tradeno' => $item->tradeno,
            ]);
        }
    }
}

/**
 * 全民代理分润结算
 * @return void
 */
function nationalPromoterRebate(): void
{
    $log = Log::channel('national_promoter');
    ini_set('memory_limit', '512M');
    $log->info('全民代理统计开始: NationalPromoterRebate' . date('Y-m-d H:i:s'));
    $time = date('Y-m-d H:i:s');
    $playGameRecord = PlayGameRecord::query()
        ->where('national_promoter_action', 0)
        ->where('created_at', '<=', $time)
        ->where('settlement_status', PlayGameRecord::SETTLEMENT_STATUS_SETTLED)
        ->selectRaw("player_id, sum(bet) as all_bet, sum(diff) as all_diff")
        ->groupBy('player_id')
        ->get();
    if (empty($playGameRecord->toArray())) {
        $log->info('全民代理统计结束: NationalPromoterRebate' . date('Y-m-d H:i:s') . ' 未产生数据');
        return;
    }
    foreach ($playGameRecord as $item) {
        Db::beginTransaction();
        try {
            $log->info('全民代理统计: NationalPromoterRebate' . date('Y-m-d H:i:s'), $item->toArray());
            //计算所有玩家打码量
            if ($item->all_bet > 0 && !empty($item->player->national_promoter)) {
                //当前玩家打码量
                $item->player->national_promoter->chip_amount = bcadd($item->player->national_promoter->chip_amount,
                    $item->all_bet, 2);
                //根据打码量查询玩家当前全民代理等级
                /** @var LevelList $levelId */
                $levelId = LevelList::query()
                    ->where('department_id', $item->player->department_id)
                    ->where('must_chip_amount', '<=', $item->player->national_promoter->chip_amount)
                    ->orderBy('must_chip_amount', 'desc')
                    ->first();
                if (!empty($levelId) && isset($levelId->id)) {
                    //根据打码量提升玩家全民代理等级
                    $item->player->national_promoter->level = $levelId->id;
                }
                $item->player->push();
                if (!empty($item->player->recommend_id) && $item->all_diff != 0 && $item->player->national_promoter->status == 1 && !empty($levelId)) {
                    /** @var Player $recommendPromoter */
                    $recommendPromoter = Player::query()->with([
                        'national_promoter',
                        'national_promoter.level_list'
                    ])->find($item->player->recommend_id);
                    if (!empty($recommendPromoter->national_promoter) && $recommendPromoter->is_promoter < 1 && $recommendPromoter->status_national == 1) {
                        $damageRebateRatio = isset($recommendPromoter->national_promoter->level_list->damage_rebate_ratio) ? $recommendPromoter->national_promoter->level_list->damage_rebate_ratio : 0;
                        $money = bcdiv(bcmul(-$item->all_diff, $damageRebateRatio, 2), 100, 2);
                        $recommendPromoter->national_promoter->pending_amount = bcadd($recommendPromoter->national_promoter->pending_amount,
                            $money, 2);
                        $recommendPromoter->push();
                        /** @var NationalProfitRecord $nationalProfitRecord */
                        $nationalProfitRecord = NationalProfitRecord::query()->where('uid', $item->player->id)
                            ->where('type', 1)
                            ->whereDate('created_at', date('Y-m-d'))
                            ->first();
                        if (!empty($nationalProfitRecord)) {
                            $nationalProfitRecord->money = bcadd($nationalProfitRecord->money, $money, 2);
                        } else {
                            $nationalProfitRecord = new NationalProfitRecord();
                            $nationalProfitRecord->uid = $item->player->id;
                            $nationalProfitRecord->recommend_id = $item->player->recommend_id;
                            $nationalProfitRecord->money = $money;
                            $nationalProfitRecord->type = 1;
                        }
                        $nationalProfitRecord->save();
                    }
                }
            }
            PlayGameRecord::query()
                ->where('national_promoter_action', 0)
                ->where('settlement_status', PlayGameRecord::SETTLEMENT_STATUS_SETTLED)
                ->where('player_id', $item->player_id)
                ->where('created_at', '<=', $time)
                ->update([
                    'national_promoter_action' => 1,
                    'national_damage_ratio' => $damageRebateRatio ?? 0
                ]);
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            $log->error('全民代理统计错误: NationalPromoterRebate' . date('Y-m-d H:i:s') . ' - ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    $log->info('全民代理统计结束: NationalPromoterRebate' . date('Y-m-d H:i:s'));
}

/**
 * 检查设备是否爆机
 *
 * 只检查钱包的 is_crashed 字段，不判断当前余额
 * 这样可以让最后一笔触发爆机的交易正常完成，从而更新爆机状态和发送通知
 *
 * @param Player $player 玩家对象
 * @return array 返回爆机状态信息 ['crashed' => bool, 'crash_amount' => float|null, 'current_amount' => float]
 */
function checkMachineCrash(Player $player): array
{
    // 直接通过玩家ID查询钱包的爆机状态
    $wallet = PlayerPlatformCash::where('player_id', $player->id)->first();

    $currentAmount = $wallet->money ?? 0;
    $isCrashed = $wallet && $wallet->is_crashed == 1;

    // 获取爆机金额配置（用于返回信息）
    $crashAmount = null;
    $adminUserId = $player->store_admin_id ?? null;

    if ($adminUserId) {
        $crashSetting = StoreSetting::getSetting(
            'machine_crash_amount',
            $player->department_id,
            null,
            $adminUserId
        );
        $crashAmount = ($crashSetting && $crashSetting->status == 1) ? ($crashSetting->num ?? 0) : null;
    }

    return [
        'crashed' => $isCrashed,
        'crash_amount' => $crashAmount,
        'current_amount' => $currentAmount,
    ];
}

/**
 * 通知设备爆机
 * @param Player $player 玩家对象
 * @param array $crashInfo 爆机信息
 * @return void
 */
function notifyMachineCrash(Player $player, array $crashInfo): void
{
    try {
        // 玩家端消息
        $playerMessage = [
            'msg_type' => 'machine_crash',
            'player_id' => $player->id,
            'crash_amount' => $crashInfo['crash_amount'],
            'current_amount' => $crashInfo['current_amount'],
            'message' => '⚠️ 您的設備餘額已達到爆機金額，請聯繫管理員處理！',
            'timestamp' => time(),
        ];

        // 后台消息（包含更多信息）
        $adminMessage = [
            'msg_type' => 'machine_crash',
            'event' => 'player_crashed',
            'player_id' => $player->id,
            'player_name' => $player->name ?? '',
            'player_uuid' => $player->uuid ?? '',
            'store_admin_id' => $player->store_admin_id ?? null,
            'department_id' => $player->department_id,
            'crash_amount' => $crashInfo['crash_amount'],
            'current_amount' => $crashInfo['current_amount'],
            'message' => "设备已爆机：{$player->name} (ID:{$player->id}) 余额达到 {$crashInfo['current_amount']}，超过爆机金额 {$crashInfo['crash_amount']}",
            'timestamp' => time(),
        ];

        // 1. 发送给玩家
        $playerChannel = 'player-' . $player->id;
        sendSocketMessage([$playerChannel], $playerMessage, 'system');

        // 2. 发送给渠道后台
        $channelAdminChannel = 'private-admin_group-channel-' . $player->department_id;
        sendSocketMessage($channelAdminChannel, $adminMessage, 'system');

        // 3. 创建通知记录（渠道后台）
        $channelNotice = new Notice();
        $channelNotice->department_id = $player->department_id;
        $channelNotice->player_id = $player->id;
        $channelNotice->source_id = $player->id;
        $channelNotice->type = Notice::TYPE_MACHINE_CRASH;
        $channelNotice->receiver = Notice::RECEIVER_DEPARTMENT;
        $channelNotice->is_private = 0;
        $channelNotice->title = '設備爆機通知';
        $channelNotice->content = "設備已爆機：玩家 {$player->name} (UID:{$player->uuid}) 餘額達到 " . number_format($crashInfo['current_amount'], 2) . "，超過爆機金額 " . number_format($crashInfo['crash_amount'], 2) . "，請聯繫管理員處理！";
        $channelNotice->save();

        Log::info('Machine crash notification sent', [
            'player_id' => $player->id,
            'player_name' => $player->name,
            'store_admin_id' => $player->store_admin_id,
            'department_id' => $player->department_id,
            'crash_amount' => $crashInfo['crash_amount'],
            'current_amount' => $crashInfo['current_amount'],
        ]);
    } catch (Exception $e) {
        Log::error('Failed to send machine crash notification', [
            'player_id' => $player->id,
            'error' => $e->getMessage(),
        ]);
    }
}

/**
 * 计算爆机状态下允许的最大洗分金额
 * 用于渠道后台洗分：如果余额超过爆机金额，只能洗到爆机金额
 * @param Player $player 玩家对象
 * @param float $requestedAmount 请求洗分的金额
 * @return array 返回 ['allowed_amount' => float, 'is_limited' => bool, 'crash_info' => array]
 */
function calculateAllowedWithdrawAmount(Player $player, float $requestedAmount): array
{
    $crashCheck = checkMachineCrash($player);
    $currentAmount = $player->machine_wallet->money ?? 0;
    $allowedAmount = $requestedAmount;
    $isLimited = false;

    // 如果当前爆机，并且有爆机金额设置
    if ($crashCheck['crashed'] && $crashCheck['crash_amount'] > 0) {
        // 最多只能洗到刚好等于爆机金额
        // 即：当前余额 - 爆机金额 = 最大可洗金额
        $maxAllowedAmount = $currentAmount - $crashCheck['crash_amount'];

        if ($maxAllowedAmount < 0) {
            $maxAllowedAmount = 0;
        }

        if ($requestedAmount > $maxAllowedAmount) {
            $allowedAmount = $maxAllowedAmount;
            $isLimited = true;
        }
    }

    return [
        'allowed_amount' => $allowedAmount,
        'is_limited' => $isLimited,
        'crash_info' => $crashCheck,
        'original_amount' => $requestedAmount,
    ];
}

/**
 * 检查并通知爆机解锁
 * 用于洗分后检查是否已解锁爆机状态
 * @param Player $player 玩家对象
 * @param float $previousAmount 洗分前的余额
 * @return void
 */
function checkAndNotifyCrashUnlock(Player $player, float $previousAmount): void
{
    try {
        $crashCheckBefore = checkMachineCrash($player);

        // 如果当前没有爆机，检查之前是否爆机
        if (!$crashCheckBefore['crashed'] && $crashCheckBefore['crash_amount'] > 0) {
            // 检查之前的余额是否达到爆机金额
            $wasCrashed = $previousAmount >= $crashCheckBefore['crash_amount'];

            // 如果之前爆机，现在已解锁，发送通知
            if ($wasCrashed) {
                // 玩家端消息
                $playerMessage = [
                    'msg_type' => 'machine_crash_unlock',
                    'player_id' => $player->id,
                    'crash_amount' => $crashCheckBefore['crash_amount'],
                    'current_amount' => $crashCheckBefore['current_amount'],
                    'message' => '✓ 您的设备爆机状态已解除，可继续正常使用。',
                    'timestamp' => time(),
                ];

                // 1. 发送给玩家
                $playerChannel = 'player-' . $player->id;
                sendSocketMessage([$playerChannel], $playerMessage, 'system');

                Log::info('Machine crash unlock notification sent', [
                    'player_id' => $player->id,
                    'player_name' => $player->name,
                    'store_admin_id' => $player->store_admin_id,
                    'department_id' => $player->department_id,
                    'previous_amount' => $previousAmount,
                    'current_amount' => $crashCheckBefore['current_amount'],
                    'crash_amount' => $crashCheckBefore['crash_amount'],
                ]);
            }
        }
    } catch (Exception $e) {
        Log::error('Failed to check and notify crash unlock', [
            'player_id' => $player->id,
            'error' => $e->getMessage(),
        ]);
    }
}

/**
 * 记录 Lua 脚本调用审计日志
 *
 * 统一记录所有 Lua 脚本调用的关键参数，便于审计和问题排查
 *
 * @param string $operation 操作类型（bet/settle/cancel）
 * @param string $platform 平台代码
 * @param int $playerId 玩家ID
 * @param array $params Lua 脚本参数
 * @param string|null $channel 日志通道（默认为 platform_server）
 */
function logLuaScriptCall(string $operation, string $platform, int $playerId, array $params, ?string $channel = null): void
{
    $channel = $channel ?? strtolower($platform) . '_server';

    // 提取关键参数
    $logData = [
        'operation' => $operation,
        'platform' => $platform,
        'player_id' => $playerId,
        'order_no' => $params['order_no'] ?? 'N/A',
        'amount' => $params['amount'] ?? 'N/A',
        'refund_amount' => $params['refund_amount'] ?? 'N/A',
        'diff' => $params['diff'] ?? 'N/A',
        'platform_id' => $params['platform_id'] ?? 'N/A',
        'game_code' => $params['game_code'] ?? 'N/A',
        'transaction_type' => $params['transaction_type'] ?? 'N/A',
    ];

    // 移除 N/A 值，保持日志简洁
    $logData = array_filter($logData, function ($value) {
        return $value !== 'N/A';
    });

    \support\Log::channel($channel)->info(
        sprintf('[Lua审计] %s操作', ucfirst($operation)),
        $logData
    );
}