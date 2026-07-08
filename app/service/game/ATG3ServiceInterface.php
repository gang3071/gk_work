<?php

namespace app\service\game;

use app\exception\GameException;
use app\model\GamePlatform;
use app\model\Player;
use Exception;

/**
 * ATG3 平台服务（运营商组3）
 *
 * 继承ATGServiceInterface，只修改平台代码和配置源
 * 其他逻辑（限红组查询、解密、API调用）完全复用
 */
class ATG3ServiceInterface extends ATGServiceInterface
{
    /**
     * @param Player|null $player
     * @param GamePlatform|null $platform 可选的平台对象，传入则不再查询数据库
     * @throws Exception
     */
    public function __construct(Player $player = null, GamePlatform $platform = null)
    {
        // ========== 关键差异：使用 ATG3 平台 ==========
        // 如果传入了平台对象，直接使用（避免重复查询）
        if ($platform && $platform->code === 'ATG3') {
            $this->platform = $platform;
        } else {
            // 否则从数据库查询
            try {
                $this->platform = GamePlatform::query()->where('code', 'ATG3')->first();
            } catch (\Exception $e) {
                throw new GameException('数据库查询失败: ' . $e->getMessage());
            }

            if (!$this->platform) {
                throw new GameException(trans('atg3_platform_not_configured', [], 'admin_game_platform'));
            }
        }

        $this->player = $player;
        $this->log = \support\Log::channel('atg_server');

        // ========== 关键差异：读取 ATG3 配置 ==========
        $config = config('game_platform.ATG3');

        if (!$config) {
            throw new GameException(trans('atg3_platform_config_missing', [], 'admin_game_platform'));
        }

        // 验证配置文件完整性
        $requiredConfigFields = ['api_domain', 'operator', 'key', 'providerId'];
        $missingConfigFields = [];
        foreach ($requiredConfigFields as $field) {
            if (empty($config[$field])) {
                $missingConfigFields[] = $field;
            }
        }

        if (!empty($missingConfigFields)) {
            $this->log->error('ATG3 配置文件不完整，请检查 .env 文件', [
                'missing_fields' => $missingConfigFields,
                'config' => $config,
            ]);
            throw new GameException(trans('atg3_platform_config_missing', [], 'admin_game_platform') . ': ' . implode(', ', $missingConfigFields));
        }

        // ========== 以下逻辑与父类完全相同 ==========
        // 如果有玩家，优先从数据库获取限红组配置，没有则 fallback 到配置文件
        if ($player) {
            $limitConfig = $this->getLimitRedConfig();

            if (!$limitConfig) {
                // 如果数据库没有配置限红组，fallback 到配置文件
                $this->log->info('ATG3 平台未配置限红组，使用配置文件 fallback', [
                    'player_id' => $player->id,
                    'store_admin_id' => $player->store_admin_id ?? null,
                    'config' => $config,  // 记录配置内容
                    'operator' => $config['operator'] ?? 'NULL',
                ]);
                $this->config = $config;
            } else {
                // 验证配置完整性（必须包含所有字段）
                $requiredFields = ['operator', 'key', 'providerId'];
                $missingFields = [];
                foreach ($requiredFields as $field) {
                    if (empty($limitConfig[$field])) {
                        $missingFields[] = $field;
                    }
                }

                if (!empty($missingFields)) {
                    throw new GameException(trans('platform_config_incomplete', ['fields' => implode(', ', $missingFields)], 'admin_game_platform'));
                }

                // 使用数据库的限红组配置
                $this->config = [
                    'api_domain' => $config['api_domain'],
                    'operator' => $limitConfig['operator'],
                    'providerId' => $limitConfig['providerId'],
                    'key' => $limitConfig['key'],
                ];

                $this->log->info('ATG3 平台使用限红组配置', [
                    'player_id' => $player->id,
                    'operator' => $limitConfig['operator'],
                ]);
            }

        } else {
            // player=null时（控制器初始化或公共API调用），使用配置文件
            // decrypt方法会在解密成功后从数据库重新获取配置
            $this->config = $config;
        }

        $this->apiDomain = $this->config['api_domain'] ?? '';
        $this->providerId = $this->config['providerId'] ?? '';
    }
}
