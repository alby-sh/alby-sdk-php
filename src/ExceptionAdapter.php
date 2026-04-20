<?php

declare(strict_types=1);

namespace Alby\Report;

use Throwable;

/**
 * Convert a {@see Throwable} (including its chain via ->getPrevious()) into the
 * Alby wire-protocol `exception` object.
 *
 * Per-request cache of opened source files, to keep re-reads cheap when the same
 * file shows up on many frames.
 */
final class ExceptionAdapter
{
    private const CONTEXT_LINES = 5;

    /** @var array<string, array<int, string>> */
    private array $sourceCache = [];

    /**
     * Normalise a Throwable into the wire-protocol shape.
     *
     * Chain handling: previous exceptions are flattened into the single frames
     * list by prepending them, so the root cause frames appear first (matching
     * PHP's own ordering convention of "caused by…" at the bottom of a stack).
     * Type/value describe the outermost exception.
     *
     * @return array{type: string, value: string, frames: list<array<string, mixed>>}
     */
    public function fromThrowable(Throwable $e): array
    {
        $frames = $this->framesFrom($e);

        // Walk the "previous" chain and prepend each older exception's frames.
        // Cap the walk at 10 levels — pathological loops shouldn't bring us down.
        $prev    = $e->getPrevious();
        $guard   = 0;
        while ($prev instanceof Throwable && $guard++ < 10) {
            $frames = array_merge($this->framesFrom($prev), $frames);
            $prev   = $prev->getPrevious();
        }

        return [
            'type'   => self::shortClass($e),
            'value'  => $e->getMessage(),
            'frames' => $frames,
        ];
    }

    /**
     * Build the frame list for a single Throwable (no chain walk).
     *
     * PHP's convention: innermost frame first. We keep that ordering, then
     * append a synthetic top-level frame pointing at where the Throwable was
     * constructed (getFile/getLine) so that the "throw site" is represented
     * explicitly even when `debug_backtrace` / `getTrace()` elides it.
     *
     * @return list<array<string, mixed>>
     */
    private function framesFrom(Throwable $e): array
    {
        $frames = [];

        // Throw-site frame (from getFile/getLine): appears first (innermost).
        $frames[] = $this->buildFrame([
            'file'     => $e->getFile(),
            'line'     => $e->getLine(),
            'function' => null,
            'class'    => null,
            'type'     => null,
        ]);

        foreach ($e->getTrace() as $raw) {
            $frames[] = $this->buildFrame($raw);
        }

        return $frames;
    }

    /**
     * @param array<string, mixed> $raw one entry from Throwable::getTrace()
     * @return array<string, mixed>
     */
    private function buildFrame(array $raw): array
    {
        $filename = isset($raw['file']) && is_string($raw['file']) ? $raw['file'] : null;
        $lineno   = isset($raw['line']) && is_int($raw['line']) ? $raw['line'] : null;

        $function = null;
        if (isset($raw['function']) && is_string($raw['function']) && $raw['function'] !== '') {
            if (isset($raw['class']) && is_string($raw['class'])) {
                $sep = (isset($raw['type']) && is_string($raw['type'])) ? $raw['type'] : '::';
                $function = $raw['class'] . $sep . $raw['function'];
            } else {
                $function = $raw['function'];
            }
        }

        [$pre, $line, $post] = $this->readContext($filename, $lineno);

        $frame = new Frame(
            filename: $filename,
            function: $function,
            lineno: $lineno,
            preContext: $pre,
            contextLine: $line,
            postContext: $post,
        );

        return $frame->toArray();
    }

    /**
     * Read up to CONTEXT_LINES lines before and after the target line.
     *
     * Returns [null, null, null] when the file can't be read (eval'd code,
     * missing files, permission errors).
     *
     * @return array{0: list<string>|null, 1: string|null, 2: list<string>|null}
     */
    private function readContext(?string $filename, ?int $lineno): array
    {
        if ($filename === null || $lineno === null || $lineno < 1) {
            return [null, null, null];
        }

        $lines = $this->readSource($filename);
        if ($lines === null) {
            return [null, null, null];
        }

        $idx = $lineno - 1;
        if (!array_key_exists($idx, $lines)) {
            return [null, null, null];
        }

        $preStart = max(0, $idx - self::CONTEXT_LINES);
        $postEnd  = min(count($lines) - 1, $idx + self::CONTEXT_LINES);

        $pre  = array_slice($lines, $preStart, $idx - $preStart);
        $line = $lines[$idx];
        $post = array_slice($lines, $idx + 1, $postEnd - $idx);

        return [array_values($pre), $line, array_values($post)];
    }

    /**
     * @return list<string>|null the file split into lines (no trailing newline), or null on failure
     */
    private function readSource(string $filename): ?array
    {
        if (isset($this->sourceCache[$filename])) {
            return $this->sourceCache[$filename];
        }
        if (!is_file($filename) || !is_readable($filename)) {
            return null;
        }
        // Cap per-file size to avoid loading huge files for a single frame.
        $size = @filesize($filename);
        if ($size !== false && $size > 2_000_000) {
            return null;
        }
        $contents = @file_get_contents($filename);
        if ($contents === false) {
            return null;
        }
        $lines = preg_split('/\r\n|\r|\n/', $contents);
        if ($lines === false) {
            return null;
        }
        return $this->sourceCache[$filename] = array_values($lines);
    }

    private static function shortClass(Throwable $e): string
    {
        $fqcn = $e::class;
        $pos  = strrpos($fqcn, '\\');
        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
