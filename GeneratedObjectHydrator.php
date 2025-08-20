<?php

declare(strict_types=1);

namespace Ovrflo\JitHydrator;

interface GeneratedObjectHydrator
{
    public function hydrate (array $data, &$result);

    public function cleanup();
}
