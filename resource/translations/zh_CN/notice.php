<?php
/**
 * Created by PhpStorm.
 * User: rocky
 * Date: 2022-02-26
 * Time: 00:44
 */

use app\model\Lottery;
use app\model\Notice;

return [
    'title' => [
        Notice::TYPE_LOTTERY => '彩金派彩',
        Notice::TYPE_RECHARGE_PASS => '上分审核通过',
        Notice::TYPE_RECHARGE_REJECT => '上分审核不通过',
        Notice::TYPE_WITHDRAW_PASS => '下分审核通过',
        Notice::TYPE_WITHDRAW_REJECT => '下分审核不通过',
        Notice::TYPE_WITHDRAW_COMPLETE => '下分成功',
        Notice::TYPE_ACTIVITY_PASS => '活动奖审核成功',
        Notice::TYPE_ACTIVITY_REJECT => '活动奖励审核不成功',
        Notice::TYPE_ACTIVITY_RECEIVE => '活动奖励待领取',
        Notice::TYPE_REVERSE_WATER => '电子游戏反水奖励',
    ],
    'content' => [
        Notice::TYPE_LOTTERY . '_' . Lottery::LOTTERY_TYPE_FIXED => '恭喜您在{machine_type}{machine_code}机台，获得了彩金{lottery_name}奖励, 奖励游戏点{amount}',
        Notice::TYPE_LOTTERY . '_' . Lottery::LOTTERY_TYPE_RANDOM => '恭喜您在{machine_type}{machine_code}机台，获得了随机彩金{lottery_name}的奖励{amount}.',
        Notice::TYPE_RECHARGE_PASS => '恭喜您的上分订单已审核通过，上分 {point}，请查收。',
        Notice::TYPE_RECHARGE_REJECT => '抱歉您的上分订单审核不通过。',
        Notice::TYPE_WITHDRAW_PASS => '恭喜您的下分订单已审核通过，下分 {point}。',
        Notice::TYPE_WITHDRAW_REJECT => '抱歉您的下分订单审核不通过。',
        Notice::TYPE_WITHDRAW_COMPLETE => '本次申请已成功处理，下分 {point}，请查收。',
        Notice::TYPE_ACTIVITY_PASS => '恭喜您的活动奖励审核通过，奖励游戏点数 {bonus}',
        Notice::TYPE_ACTIVITY_REJECT => '抱歉您的活动奖励审核不通过。',
        Notice::TYPE_ACTIVITY_RECEIVE => '恭喜您在{machine_type}{machine_code}机台上达成了{condition}条件，获得了活动{activity_name}的奖励，奖励游戏点为{amount}。',
    ],
];
