<?php

namespace app\service\game;

use app\model\Game;

interface GameServiceInterface
{
    /**
     * 创建玩家
     * @return mixed
     */
    public function createPlayer();

    /**
     * 获取玩家信息
     * @return mixed
     */
    public function getPlayer();

    /**
     * 登录大厅
     * @return mixed
     */
    public function lobbyLogin();

    /**
     * 玩家登出
     * @return mixed
     */
    public function userLogout();

    /**
     * 获取游戏列表
     * @return mixed
     */
    public function getGameList();

    /**
     * 游戏回放记录
     * @return mixed
     */
    public function replay();

    /**
     * 进入游戏
     * @param Game $game
     * @param string $lang
     * @return mixed
     */
    public function gameLogin(Game $game, string $lang);
}
