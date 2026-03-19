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
        Notice::TYPE_LOTTERY => 'Lottery payout',
        Notice::TYPE_RECHARGE_PASS => 'Passed the upper score review',
        Notice::TYPE_RECHARGE_REJECT => 'The upper score review did not pass',
        Notice::TYPE_WITHDRAW_PASS => 'The sub review has been approved',
        Notice::TYPE_WITHDRAW_REJECT => 'The sub review did not pass',
        Notice::TYPE_WITHDRAW_COMPLETE => 'Successfully scored',
        Notice::TYPE_ACTIVITY_PASS => 'Activity Award Review Successful',
        Notice::TYPE_ACTIVITY_REJECT => 'Event reward review unsuccessful',
        Notice::TYPE_ACTIVITY_RECEIVE => '活動奨励金は受領待ち',
        Notice::TYPE_REVERSE_WATER => 'Electronic game anti water reward',
    ],
    'content' => [
        Notice::TYPE_LOTTERY . '_' . Lottery::LOTTERY_TYPE_FIXED => 'Congratulations on receiving the prize money {lottery_name} and game points {amount} on the {machine_type} {machine_code} machine.',
        Notice::TYPE_LOTTERY . '_' . Lottery::LOTTERY_TYPE_RANDOM => 'Congratulations on winning the random prize {lotteryname} {amount} on the {machine_type} {machine_code} machine.',
        Notice::TYPE_RECHARGE_PASS => 'Congratulations, your previous order has been approved. Please check your submission for {point}.',
        Notice::TYPE_RECHARGE_REJECT => 'Sorry, your previous order review did not pass.',
        Notice::TYPE_WITHDRAW_PASS => 'Congratulations, your sub order has been approved. Sub order {point}.',
        Notice::TYPE_WITHDRAW_REJECT => 'Sorry, your sub order review did not pass.',
        Notice::TYPE_WITHDRAW_COMPLETE => 'This application has been successfully processed, with a total of {point} points. Please check.',
        Notice::TYPE_ACTIVITY_PASS => 'Congratulations on your activity reward review being approved, with a bonus of {bonus} for game points',
        Notice::TYPE_ACTIVITY_REJECT => 'Sorry, your activity reward review did not pass.',
        Notice::TYPE_ACTIVITY_RECEIVE => 'Congratulations on meeting the {condition} condition on the {machine_type} {machine_code} machine and receiving a reward for the activity {activity_name}, with a reward of {amount} game points.',
    ],
];
