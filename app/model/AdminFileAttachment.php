<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 文件附件模型
 *
 * @property int $id 主键ID
 * @property int $cate_id 分类ID
 * @property int $uploader_id 上传者ID
 * @property int $type 类型
 * @property int $file_type 文件类型
 * @property string $name 文件名称
 * @property string $real_name 真实文件名
 * @property string $path 文件路径
 * @property string $url 访问URL
 * @property string $ext 文件扩展名
 * @property string $disk 存储磁盘
 * @property int $size 文件大小（字节）
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 * @property string $deleted_at 删除时间
 *
 * @package app\model
 * @table admin_file_attachments
 */
class AdminFileAttachment extends Model
{
    use SoftDeletes, HasDateTimeFormatter;

    protected $fillable = ['cate_id', 'uploader_id', 'type', 'file_type', 'name', 'real_name', 'path', 'url', 'ext', 'disk', 'size'];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(plugin()->webman->config('database.attachment_table'));
    }
}
