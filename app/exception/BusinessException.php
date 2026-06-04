<?php

namespace app\exception;

use Exception;

/**
 * 业务异常 - 可以安全地返回给客户端
 *
 * 用于业务逻辑错误，消息内容是面向用户的，不包含敏感信息
 */
class BusinessException extends Exception
{
    /**
     * 错误码
     */
    protected int $errorCode;

    /**
     * 额外数据
     */
    protected array $data;

    /**
     * @param string $message 错误消息（可以安全显示给用户）
     * @param int $errorCode 错误码
     * @param array $data 额外数据
     */
    public function __construct(string $message, int $errorCode = 400, array $data = [])
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->data = $data;
    }

    public function getErrorCode(): int
    {
        return $this->errorCode;
    }

    public function getData(): array
    {
        return $this->data;
    }
}
