<?php

namespace app\service;

use app\model\Player;
use app\model\StoreSetting;
use support\Log;
use Webman\RedisQueue\Client;

/**
 * 玩家积分队列投递服务
 *
 * 所有 'player-points' 消息统一经由本类投递（跨项目投递到 gk_api 消费）。
 * 发送前检查玩家所属店家/代理的积分开关（store_setting.feature = 'enable_points'），
 * 关闭时跳过投递。未配置默认开启。
 *
 * ⚠️ 不可用 StoreSetting::getSetting()：本项目该方法带 where('status', 1) 过滤，
 *    会把 status=0 的「关闭」行过滤成 null，与「未配置」混淆。
 */
class PlayerPointsQueueService
{
    /**
     * 队列名（与 gk_api 消费者 app\queue\redis\fast\PlayerPoints::$queue 一致）
     */
    public const QUEUE = 'player-points';

    /**
     * 投递积分队列消息（店家关闭积分时跳过）
     *
     * @param array $data 消息数据（需包含 player_id）
     * @return bool true=已投递，false=店家关闭积分已跳过
     */
    public static function send(array $data): bool
    {
        $playerId = intval($data['player_id'] ?? 0);

        if ($playerId > 0 && !self::isPointsEnabled($playerId)) {
            Log::channel('bet_statistics')->info('[积分队列] 店家已关闭积分，跳过投递', [
                'player_id' => $playerId,
                'batch_id' => $data['batch_id'] ?? '',
            ]);
            return false;
        }

        Client::send(self::QUEUE, $data);
        return true;
    }

    /**
     * 玩家所属店家/代理的积分开关是否开启
     *
     * @return bool true=开启（无绑定/未配置/查询异常时放行），false=店家已关闭
     */
    public static function isPointsEnabled(int $playerId): bool
    {
        try {
            $player = Player::query()
                ->select('department_id', 'store_admin_id', 'agent_admin_id')
                ->find($playerId);
            if (!$player) {
                return true;
            }

            // 所属店家（无店家绑定时用代理）
            $adminUserId = $player->store_admin_id ?: $player->agent_admin_id;
            if (!$adminUserId) {
                return true;
            }

            $setting = StoreSetting::query()
                ->where('feature', 'enable_points')
                ->where('department_id', $player->department_id)
                ->where('admin_user_id', $adminUserId)
                ->first();

            return !$setting || $setting->status == 1;
        } catch (\Throwable $e) {
            // 查询异常放行，避免误杀积分（但必须留痕，便于发现 DB 故障）
            Log::channel('bet_statistics')->error('[积分队列] 积分开关查询异常，放行投递', [
                'player_id' => $playerId,
                'error' => $e->getMessage(),
            ]);
            return true;
        }
    }
}
