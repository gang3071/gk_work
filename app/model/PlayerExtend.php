<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class PlayerExtends
 * @property int id 主键
 * @property int player_id 推荐id
 * @property int sex 性别
 * @property string email email
 * @property string ip ip
 * @property string qq qq账号
 * @property string telegram
 * @property string birthday 生日
 * @property string id_number 身份证
 * @property string address 地址
 * @property string wechat 微信
 * @property string whatsapp 海外微信
 * @property string facebook
 * @property string line
 * @property string remark 备注
 * @property float recharge_amount 总充值点数
 * @property float withdraw_amount 总提现点数
 * @property float present_out_amount 总转出点数
 * @property float present_in_amount 总转入点数
 * @property float third_recharge_amount 第三方总充值点数
 * @property float third_withdraw_amount 第三方总提现点数
 * @property float coin_recharge_amount 币商充值总点数
 * @property float machine_put_amount 机器投钞总金额
 * @property float machine_put_point 机器投钞总点数
 * @property float team_machine_put_point 机器投钞总点数(团队)
 * @property float team_machine_put_amount 机器投钞总金额(团队)
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property Player $player 玩家
 * @package app\model
 */
class PlayerExtend extends Model
{
    use SoftDeletes, HasDateTimeFormatter;

    protected $fillable = ['remark', 'player_id', 'sex', 'email', 'ip', 'qq', 'telegram', 'birthday', 'id_number', 'address', 'wechat', 'whatsapp', 'facebook', 'line', 'remark'];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(plugin()->webman->config('database.player_extend_table'));
    }

    /**
     * 玩家信息
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.player_model'), 'player_id')->withTrashed();
    }

    /**
     * 总充值点数
     *
     * @param $value
     * @return float
     */
    public function getRechargeAmountAttribute($value): float
    {
        return floatval($value);
    }

    /**
     * 总提现金额
     *
     * @param $value
     * @return float
     */
    public function getWithdrawAmountAttribute($value): float
    {
        return floatval($value);
    }

    /**
     * 总提转入金额
     *
     * @param $value
     * @return float
     */
    public function getPresentInAmountAttribute($value): float
    {
        return floatval($value);
    }

    /**
     * 总提转出金额
     *
     * @param $value
     * @return float
     */
    public function getPresentOutAmountAttribute($value): float
    {
        return floatval($value);
    }

    /**
     * 第三方总充值点数
     *
     * @param $value
     * @return float
     */
    public function getThirdRechargeAmountAttribute($value): float
    {
        return floatval($value);
    }

    /**
     * 第三方总提现金额
     *
     * @param $value
     * @return float
     */
    public function getThirdWithdrawAmountAttribute($value): float
    {
        return floatval($value);
    }

    /**
     * 币商充值总金额
     *
     * @param $value
     * @return float
     */
    public function getCoinRechargeAmountAttribute($value): float
    {
        return floatval($value);
    }

    protected static function booted()
    {
        static::updated(function (PlayerExtend $playerExtend) {
            $columns = [
                'sex',
                'email',
                'qq',
                'telegram',
                'birthday',
                'id_number',
                'address',
                'wechat',
                'whatsapp',
                'facebook',
                'line',
                'remark',
            ];
            if ($playerExtend->wasChanged($columns) && false) {
                $orData = $playerExtend->getOriginal();
                $changeData = $playerExtend->getChanges();
                $orDataArr = [];
                $newDataArr = [];
                foreach ($changeData as $key => $item) {
                    if (empty($item) == empty($orData[$key])) {
                        continue;
                    }
                    if ($key == 'updated_at') {
                        $orData[$key] = date('Y-m-d H:i:s', strtotime($orData[$key]));
                    }
                    $orDataArr[$key] = $orData[$key];
                    $newDataArr[$key] = $item;
                }
                if (!empty($newDataArr)) {
                    $playerEditLog = new PlayerEditLog();
                    $playerEditLog->player_id = $playerExtend->player_id;
                    $playerEditLog->department_id = $playerExtend->player->department_id;
                    $playerEditLog->origin_data = json_encode($orDataArr);
                    $playerEditLog->new_data = json_encode($newDataArr);
                    $playerEditLog->user_id = 0;
                    $playerEditLog->user_name = 'system';
                    $playerEditLog->save();
                }
            }
        });
    }
}
