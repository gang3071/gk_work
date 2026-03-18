<?php

namespace app\service\game;

/**
 * 单一钱包接口标准
 */
interface SingleWalletServiceInterface
{
    /**
     * 查询余额
     * @return mixed
     */
    public function balance();

    /**
     * 下注
     * @return mixed
     */
    public function bet($data);

    /**
     * 取消单
     * @return mixed
     */
    public function cancelBet($data);

    /**
     * 结算
     * @return mixed
     */
    public function betResulet($data);

    /**
     * 重新结算
     * @return mixed
     */
    public function reBetResulet($data);

    /**
     * 送礼
     * @return mixed
     */
    public function gift($data);

    /**
     * 解密
     * @return mixed
     */
    public function decrypt($data);
}
