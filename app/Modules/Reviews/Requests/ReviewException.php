<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Requests;

final class ReviewException extends \RuntimeException
{
    public $errorCode;
    public $httpStatus;
    public function __construct(string $code, string $message, int $status = 422)
    {
        parent::__construct($message);
        $this->errorCode = $code;
        $this->httpStatus = $status;
    }
}
