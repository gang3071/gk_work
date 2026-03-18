<?php
/**
 * Here is your custom functions.
 */

use app\model\ApiErrorLog;
use app\model\GameType;
use app\model\Machine;
use app\model\MachineKeepingLog;
use app\model\MachineKickLog;
use app\model\MachineMedia;
use app\model\MachineMediaPush;
use app\model\Notice;
use app\model\PhoneSmsLog;
use app\model\Player;
use app\model\PlayerPlatformCash;
use app\model\SystemSetting;
use app\service\machine\MachineServices;
use app\service\machine\Slot;
use app\service\MediaServer;
use support\Cache;
use support\Log;
use Webman\Push\Api;
use Webman\Push\PushException;
use WebmanTech\LaravelHttpClient\Facades\Http;

/**
 * 生成uuid
 * @return string
 */
function gen_uuid(): string
{
    $uuid['time_low'] = mt_rand(0, 0xffff) + (mt_rand(0, 0xffff) << 16);
    $uuid['time_mid'] = mt_rand(0, 0xffff);
    $uuid['time_hi'] = (4 << 12) | (mt_rand(0, 0x1000));
    $uuid['clock_seq_hi'] = (1 << 7) | (mt_rand(0, 128));
    $uuid['clock_seq_low'] = mt_rand(0, 255);

    for ($i = 0; $i < 6; $i++) {
        $uuid['node'][$i] = mt_rand(0, 255);
    }

    return sprintf('%08x-%04x-%04x-%02x%02x-%02x%02x%02x%02x%02x%02x',
        $uuid['time_low'],
        $uuid['time_mid'],
        $uuid['time_hi'],
        $uuid['clock_seq_hi'],
        $uuid['clock_seq_low'],
        $uuid['node'][0],
        $uuid['node'][1],
        $uuid['node'][2],
        $uuid['node'][3],
        $uuid['node'][4],
        $uuid['node'][5]
    );
}

/**
 * 检查玩家游戏状态 5分钟没有使用机台玩家将被踢出(分数返还)
 * @return void
 * @throws Exception
 * @throws PushException
 */
function machineKeepOutPlayer(): void
{
    $log = Log::channel('machine_keeping');
    //機台例行維護中
    if (machineMaintaining()) {
        $log->info('PlayOutMachine', ['全站维护中']);
        return;
    }
    /** @var SystemSetting $setting */
    $setting = SystemSetting::where('feature', 'pending_minutes')->where('status', 1)->first();
    if (!$setting || $setting->num <= 0) {
        $settingMinutes = 2; // 默认2分钟进入保留状态
    } else {
        $settingMinutes = $setting->num;
    }

    // 不扣保留时间设置
    $isFreeTime = false;
    /** @var SystemSetting $keepingSetting */
    $keepingSetting = SystemSetting::where('feature', 'keeping_off')->where('status', 1)->first();
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
                        $log->info('PlayOutMachine', [$machine->code . '开奖中15分钟内不扣除保留时间']);
                        continue;
                    }
                }
                $log->info('PlayOutMachine: 扣除保留时间', [$keepingSetting, $keepSeconds]);
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
                    $playerPlatformWallet = PlayerPlatformCash::where([
                        'player_id' => $player->id,
                        'platform_id' => PlayerPlatformCash::PLATFORM_SELF,
                    ])->first();
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
        } catch (\Exception $e) {
            $log->error('PlayOutMachine', [$e->getMessage()]);
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
 * 设置短信key
 * @param string $phone 手机号
 * @param int $type 模式 1 为修改密码短信
 * @return string
 */
function setSmsKey(string $phone, int $type): string
{
    switch ($type) {
        case PhoneSmsLog::TYPE_LOGIN:
            return 'sms-login' . $phone;
        case PhoneSmsLog::TYPE_REGISTER:
            return 'sms-register' . $phone;
        case PhoneSmsLog::TYPE_CHANGE_PASSWORD:
            return 'sms-change-password' . $phone;
        case PhoneSmsLog::TYPE_CHANGE_PAY_PASSWORD:
            return 'sms-change-pay-password' . $phone;
        case PhoneSmsLog::TYPE_CHANGE_PHONE:
            return 'sms-change-phone' . $phone;
        case PhoneSmsLog::TYPE_BIND_NEW_PHONE:
            return 'sms-type-bind-new-phone' . $phone;
        case PhoneSmsLog::TYPE_TALK_BIND:
            return 'sms-type-talk-bind' . $phone;
        default:
            return 'sms-' . $phone;
    }
}

/**
 * 获取短信消息
 * @param int $type 模式 1 为修改密码短信
 * @param string $source 来源
 * @return string
 */
function getContent(int $type, string $source): string
{
    switch ($type) {
        case PhoneSmsLog::TYPE_LOGIN:
            return config($source . '-sms.login_content');
        case PhoneSmsLog::TYPE_REGISTER:
            return config($source . '-sms.register_content');
        case PhoneSmsLog::TYPE_CHANGE_PASSWORD:
            return config($source . '-sms.change_password_content');
        case PhoneSmsLog::TYPE_CHANGE_PAY_PASSWORD:
            return config($source . '-sms.change_pay_password');
        case PhoneSmsLog::TYPE_CHANGE_PHONE:
            return config($source . '-sms.change_phone');
        case PhoneSmsLog::TYPE_BIND_NEW_PHONE:
            return config($source . '-sms.bind_new_phone');
        case PhoneSmsLog::TYPE_TALK_BIND:
            return config($source . '-sms.talk_bind');
        case PhoneSmsLog::TYPE_LINE_BIND:
            return config($source . '-sms.line_bind');
        default:
            return config($source . '-sms.sm_content');
    }
}

/**
 * 发送socket消息
 * @param $channels
 * @param $content
 * @param string $form
 * @return bool|string
 * @throws PushException
 */
function sendSocketMessage($channels, $content, string $form = 'system')
{
    try {
        // 发送进入保留状态消息
        $api = new Api(
            'http://127.0.0.1:3232',
            config('plugin.webman.push.app.app_key'),
            config('plugin.webman.push.app.app_secret')
        );
        return $api->trigger($channels, 'message', [
            'from_uid' => $form,
            'content' => json_encode($content)
        ]);
    } catch (Exception $e) {
        Log::error('sendSocketMessage', [$e->getMessage()]);
        return false;
    }
}

/**
 * 组装请求
 * @param string $url
 * @param array $params
 * @param int $gaming_user_id
 * @param int $machine_id
 * @return array|mixed|null
 * @throws \Exception
 */
function doCurl(string $url, int $gaming_user_id, int $machine_id, array $params = [])
{
    $result = Http::timeout(7)->contentType('application/json')->accept('application/json')->asJson()->post($url,
        $params);
    if (!isset($result['result'])) {
        $apiErrorLog = new ApiErrorLog;
        $apiErrorLog->player_id = $gaming_user_id;
        $apiErrorLog->target = 'machine';
        $apiErrorLog->target_id = $machine_id;
        $apiErrorLog->url = $url;
        $apiErrorLog->params = json_encode($params);
        $apiErrorLog->content = '後台 api timeout';
        $apiErrorLog->save();
    }
    return $result->json();
}

/**
 * 获取增点缓存
 * @param $playerId
 * @param $machineId
 * @return mixed
 */
function getGivePoints($playerId, $machineId)
{
    return Cache::get('gift_cache_' . $machineId . '_' . $playerId);
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
 * @throws PushException
 */
function sendMachineException(Machine $machine, $type, int $playerId = 0)
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
                    $mediaServer->log->error('MediaClear',
                        [$streamInfo, $endpointServiceId, $machineMedia->machine->code]);
                } catch (Exception $e) {
                    $mediaServer->log->error('MediaClear', [$e->getMessage(), $machineMedia->machine->code]);
                }
            }
        });
}

