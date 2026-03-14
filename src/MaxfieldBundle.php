<?php

declare(strict_types=1);

namespace Elkuku\MaxfieldBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class MaxfieldBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
