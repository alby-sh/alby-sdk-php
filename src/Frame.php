<?php

declare(strict_types=1);

namespace Alby\Report;

/**
 * Single stack frame in the Alby wire format.
 *
 * All fields are optional — the protocol accepts a sparse frame, and not every
 * PHP trace entry carries a filename/line (e.g. calls from eval'd code).
 */
final class Frame
{
    /**
     * @param list<string>|null $preContext  up to 5 source lines BEFORE $contextLine
     * @param list<string>|null $postContext up to 5 source lines AFTER $contextLine
     * @param array<string, mixed>|null $vars rarely filled; locals if available
     */
    public function __construct(
        public readonly ?string $filename = null,
        public readonly ?string $function = null,
        public readonly ?int $lineno = null,
        public readonly ?int $colno = null,
        public readonly ?array $preContext = null,
        public readonly ?string $contextLine = null,
        public readonly ?array $postContext = null,
        public readonly ?array $vars = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];
        if ($this->filename !== null)     $out['filename']     = $this->filename;
        if ($this->function !== null)     $out['function']     = $this->function;
        if ($this->lineno !== null)       $out['lineno']       = $this->lineno;
        if ($this->colno !== null)        $out['colno']        = $this->colno;
        if ($this->preContext !== null)   $out['pre_context']  = $this->preContext;
        if ($this->contextLine !== null)  $out['context_line'] = $this->contextLine;
        if ($this->postContext !== null)  $out['post_context'] = $this->postContext;
        if ($this->vars !== null)         $out['vars']         = $this->vars;
        return $out;
    }
}
