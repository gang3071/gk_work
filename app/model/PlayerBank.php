<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class PlayerBank
 * @property int id 主键
 * @property int player_id 玩家id
 * @property string bank_name 开户行
 * @property string account 银行卡号
 * @property string account_name 户名
 * @property int status 状态
 * @property int type 类型
 * @property int wallet_address 钱包地址
 * @property int qr_code 钱包二维码
 * @property string gb_nickname 购宝昵称
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @property string deleted_at 删除时间
 * @property Player player 渠道
 * @package app\model
 */
class PlayerBank extends Model
{
    use SoftDeletes, HasDateTimeFormatter;

    protected $table = 'player_bank';

    /**
     * 玩家信息
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id')->withTrashed();
    }

    /**
     * 模型事件 - 删除前
     */
    protected static function boot()
    {
        parent::boot();

        static::deleting(function (PlayerBank $playerBank) {
            if (!empty($playerBank->qr_code)) {
                $imagePath = self::extractImagePathFromUrl($playerBank->qr_code);

                if ($imagePath) {
                    deleteToGCS($imagePath);
                }
            }
        });
    }

    /**
     * 从 URL 中提取图片路径
     */
    private static function extractImagePathFromUrl($url): string
    {
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            $parsedUrl = parse_url($url);
            if (isset($parsedUrl['path'])) {
                $path = $parsedUrl['path'];

                // 移除可能的存储桶名称
                $bucketName = env('GOOGLE_CLOUD_STORAGE_BUCKET', 'yjbfile');
                $bucketPrefix = '/' . $bucketName . '/';

                if (str_starts_with($path, $bucketPrefix)) {
                    return substr($path, strlen($bucketPrefix));
                }

                return ltrim($path, '/');
            }
        }

        return $url;
    }
}
