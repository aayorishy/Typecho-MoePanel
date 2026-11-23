<?php

declare(strict_types=1);

namespace TypechoPlugin\MoePanel\Kook;

class WebSocketException extends \RuntimeException
{
}

class WebSocketTimeoutException extends WebSocketException
{
}
