<?php

declare(strict_types=1);

namespace EachCore\SharedCore;

enum SmsProviderKind: string
{
    case FAKE = 'fake';
    case MITAKE = 'mitake';
    case EVERY8D = 'every8d';
    case SMSGO = 'smsgo';
}
