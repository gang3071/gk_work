<?php

namespace app\service\machine;
/**
 * 机台接口
 */
interface BaseMachine
{
    /**
     * 发送机台操作指令
     * @param string $cmd 指令
     * @param int $data 数据
     * @param string $source 来源
     * @param int $source_id 来源id
     * @return mixed
     */
    public function sendCmd(string $cmd, int $data = 0, string $source = 'player', int $source_id = 0);
}