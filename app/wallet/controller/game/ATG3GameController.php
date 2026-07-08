<?php

namespace app\wallet\controller\game;

use app\service\game\GameServiceFactory;

/**
 * ATG3电子平台控制器（运营商组3）
 *
 * 完全继承ATGGameController，只修改Service类型
 * 所有业务逻辑复用父类
 */
class ATG3GameController extends ATGGameController
{
    public function __construct()
    {
        // 关键差异：使用 ATG3 Service
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_ATG3);
        $this->log = \support\Log::channel('atg_server');
    }
}
