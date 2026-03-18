<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * 附件分类模型
 *
 * @property int $id 主键ID
 * @property string $name 分类名称
 * @property int $sort 排序
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 *
 * @package app\model
 * @table admin_file_attachment_cates
 */
class AdminFileAttachmentCate extends Model
{
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(plugin()->webman->config('database.attachment_cate_table'));
    }
}
