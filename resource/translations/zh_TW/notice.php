<?php
/**
 * Created by PhpStorm.
 * User: rocky
 * Date: 2022-02-26
 * Time: 00:44
 */

use addons\webman\model\Lottery;
use addons\webman\model\Notice;
use addons\webman\model\PlayerLotteryRecord;

return [
    'title' => [
        Notice::TYPE_LOTTERY => '彩金派彩',
        Notice::TYPE_RECHARGE_PASS => '上分稽核通過',
        Notice::TYPE_RECHARGE_REJECT => '上分稽核不通過',
        Notice::TYPE_WITHDRAW_PASS => '下分稽核通過',
        Notice::TYPE_WITHDRAW_REJECT => '下分稽核不通過',
        Notice::TYPE_WITHDRAW_COMPLETE => '下分成功',
        Notice::TYPE_ACTIVITY_PASS => '活動獎勵稽核通過',
        Notice::TYPE_ACTIVITY_REJECT => '活動獎勵稽核不通過',
        Notice::TYPE_ACTIVITY_RECEIVE => '活動獎勵待領取',
        Notice::TYPE_REVERSE_WATER => '電子遊戲反水獎勵',
    ],
    'content' => [
        Notice::TYPE_LOTTERY . '_' . Lottery::LOTTERY_TYPE_FIXED => '恭喜您在{machine_type}{machine_code}機台，獲得了彩金{lottery_name}獎勵，獎勵遊戲點{amount}',
        Notice::TYPE_LOTTERY . '_' . Lottery::LOTTERY_TYPE_RANDOM => '恭喜您在{machine_type}{machine_code}機台，獲得了隨機彩金{lottery_name}的獎勵{amount}.',
        Notice::TYPE_LOTTERY . '_' . Lottery::LOTTERY_TYPE_RANDOM . '_' . PlayerLotteryRecord::SOURCE_GAME => '恭喜您在電子遊戲, "{game_name}"，獲得了隨機彩金{lottery_name}的獎勵{amount}.',
        Notice::TYPE_RECHARGE_PASS => '恭喜您的上分訂單已稽核通過，上分 {point}，請查收。',
        Notice::TYPE_RECHARGE_REJECT => '抱歉您的上分訂單稽核不通過。',
        Notice::TYPE_WITHDRAW_PASS => '恭喜您的下分訂單已稽核通過，下分 {point}。',
        Notice::TYPE_WITHDRAW_REJECT => '抱歉您的下分訂單稽核不通過。',
        Notice::TYPE_WITHDRAW_COMPLETE => '本次申請已成功處理，下分 {point}，請查收。',
        Notice::TYPE_ACTIVITY_PASS => '恭喜您的活動獎勵稽核通過，奖励游戏点数 {bonus}',
        Notice::TYPE_ACTIVITY_REJECT => '抱歉您的活動獎勵稽核不通過。',
        Notice::TYPE_ACTIVITY_RECEIVE => '恭喜您在{machine_type}{machine_code}機台上達成了{condition}條件，獲得了活動{activity_name}的獎勵，獎勵遊戲點為{amount}。',
    ],
];
