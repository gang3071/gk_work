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
     * @throws Exception
     */
    public function __construct(Player $player = null)
    {
        // ========== 关键差异：使用 ATG3 平台 ==========
        $this->platform = GamePlatform::query()->where('code', 'ATG3')->first();

        if (!$this->platform) {
            throw new GameException('ATG3 平台未配置');
        }

        $this->player = $player;
        $this->log = \support\Log::channel('atg_server');

        // ========== 关键差异：读取 ATG3 配置 ==========
        $config = config('game_platform.ATG3');

        if (!$config) {
            throw new GameException('ATG3 平台配置文件缺失');
        }

        // ========== 以下逻辑与父类完全相同 ==========
        // 如果有玩家，必须从数据库获取配置
        if ($player) {
            $limitConfig = $this->getLimitRedConfig();

            if (!$limitConfig) {
                throw new GameException('游戏平台未配置');
            }

            // 验证配置完整性（必须包含所有字段）
            $requiredFields = ['operator', 'key', 'providerId'];
            $missingFields = [];
            foreach ($requiredFields as $field) {
                if (empty($limitConfig[$field])) {
                    $missingFields[] = $field;
                }
            }

            if (!empty($missingFields)) {
                throw new GameException('游戏平台配置不完整: 缺少 ' . implode(', ', $missingFields));
            }

            $this->config = [
                'api_domain' => $config['api_domain'],
                'operator' => $limitConfig['operator'],
                'providerId' => $limitConfig['providerId'],
                'key' => $limitConfig['key'],
            ];

        } else {
            // player=null时（控制器初始化或公共API调用），使用配置文件作为fallback
            // decrypt方法会在解密成功后从数据库重新获取配置
            $this->config = $config;
        }

        $this->apiDomain = $this->config['api_domain'] ?? '';
        $this->providerId = $this->config['providerId'] ?? '';
    }
}
