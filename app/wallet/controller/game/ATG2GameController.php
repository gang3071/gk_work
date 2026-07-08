<?php

namespace app\wallet\controller\game;

use app\service\game\GameServiceFactory;

/**
 * ATG2电子平台控制器（运营商组2）
 *
 * 完全继承ATGGameController，只修改Service类型
 * 所有业务逻辑复用父类
 */
class ATG2GameController extends ATGGameController
{
    public function __construct()
    {
        // 关键差异：使用 ATG2 Service
        // 注意：控制器初始化时没有 player 和 platform 对象，Service 内部会查询数据库
        $this->service = GameServiceFactory::createService(GameServiceFactory::TYPE_ATG2, null, null);
        $this->log = \support\Log::channel('atg_server');
    }
}
