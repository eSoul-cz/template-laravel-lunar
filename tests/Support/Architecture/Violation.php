<?php

declare(strict_types=1);

namespace Tests\Support\Architecture;

use Stringable;

/** One breach of a layer rule: the file and line, what was used there, and the rule it breaks. */
final readonly class Violation implements Stringable
{
    public function __construct(
        public string $path,
        public int $line,
        public string $subject,
        public string $rule,
    ) {}

    public function __toString(): string
    {
        return sprintf('%s:%d uses %s - %s', $this->path, $this->line, $this->subject, $this->rule);
    }
}
