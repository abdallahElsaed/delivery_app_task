<?php

namespace App\Contracts\Imports;

interface ImporterInterface
{
    public function parse(string $filePath): iterable;
}
