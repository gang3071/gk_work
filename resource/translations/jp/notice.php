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
        Notice::TYPE_RECHARGE_PASS => '上分割レビューの合格',
        Notice::TYPE_RECHARGE_REJECT => '上分割レビューが失敗しました',
        Notice::TYPE_WITHDRAW_PASS => 'サブディビジョンの承認',
        Notice::TYPE_WITHDRAW_REJECT => '下位レビューが失敗しました',
        Notice::TYPE_WITHDRAW_COMPLETE => '下位成功',
        Notice::TYPE_ACTIVITY_PASS => '活動賞審査成功',
        Notice::TYPE_ACTIVITY_REJECT => '活動奨励審査は成功しなかった',
        Notice::TYPE_ACTIVITY_RECEIVE => '活動奨励金は受領待ち',
        Notice::TYPE_REVERSE_WATER => '電子ゲームの反水奨励金',
    ],
    'content' => [
        Notice::TYPE_LOTTERY => '{machine_type}{machine_code} マシンの導入おめでとうございます。ボーナス額の {lottery_name} の宝くじ報酬を獲得しました',
        Notice::TYPE_LOTTERY . '_' . Lottery::LOTTERY_TYPE_FIXED => '{machine_type}{machine_code}機台で、ランダムカラー金{lottery_name}の奨励金{amount}を受賞しておめでとうございます。',
        Notice::TYPE_LOTTERY . '_' . Lottery::LOTTERY_TYPE_RANDOM => 'おめでとうございます♪machine_type}{machine_code}機台で、彩金{lottery_name}奨励金、奨励ゲームポイント{amount}を獲得しました',
        Notice::TYPE_RECHARGE_PASS => '前回の注文が承認されました。{point}点アップしました。ご査収ください。',
        Notice::TYPE_RECHARGE_REJECT => '申し訳ありませんが、前受注のレビューが失敗しました。',
        Notice::TYPE_WITHDRAW_PASS => 'ご注文おめでとうございます。レビュー済み、サブ{point}。',
        Notice::TYPE_WITHDRAW_REJECT => '申し訳ありませんが、発注レビューが失敗しました。',
        Notice::TYPE_WITHDRAW_COMPLETE => '今回の申請は正常に処理されました。次は{point}です。ご査収ください。',
        Notice::TYPE_ACTIVITY_PASS => 'イベント奨励金レビューの合格おめでとうございます。奨励ゲームポイント{bonus}',
        Notice::TYPE_ACTIVITY_REJECT => '申し訳ありませんが、アクティビティ奨励金のレビューが不合格になりました。',
        Notice::TYPE_ACTIVITY_RECEIVE => '{machine _ type}{machine _ code}コンソールで{condition}条件を達成し、イベント{activity _ name}の奨励金を獲得し、奨励ゲームポイントは{amount}です。',
    ],
];
