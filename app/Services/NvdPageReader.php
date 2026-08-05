<?php

namespace App\Services;

use Generator;
use JsonException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Walks an NVD `/cves/2.0` payload one CVE at a time.
 *
 * Decoding a whole page in one `json_decode()` costs roughly seven times its
 * wire size in PHP arrays — a 7 MB page of 2000 CVEs peaks past 50 MB — which
 * is what forced `sources.page_size` down to 200. This reads the body as a
 * stream and decodes a single entry at a time, so peak memory tracks the
 * largest CVE in the page rather than the page itself, and the page size stops
 * being a memory decision.
 *
 * Everything outside `vulnerabilities` (`totalResults` and friends) is small
 * and gets collected into metadata as it is passed, in whatever order the
 * payload happens to use.
 */
class NvdPageReader
{
    private const CHUNK_BYTES = 65536;

    /** Characters that end an unquoted scalar. */
    private const SCALAR_DELIMITERS = ",}] \t\n\r";

    private string $buffer = '';

    private int $position = 0;

    /** @var array<string, mixed> */
    private array $metadata = [];

    public function __construct(private readonly StreamInterface $stream)
    {
        if ($this->stream->isSeekable()) {
            $this->stream->rewind();
        }
    }

    /**
     * Yields each entry of the `vulnerabilities` array, decoded on its own.
     *
     * @return Generator<int, mixed>
     *
     * @throws JsonException|RuntimeException on a malformed payload
     */
    public function vulnerabilities(): Generator
    {
        $this->expect('{');

        while (true) {
            $this->compact();
            $character = $this->peek();

            if ($character === '}') {
                $this->position++;

                return;
            }

            if ($character === ',') {
                $this->position++;

                continue;
            }

            $key = $this->decode($this->readString());
            $this->expect(':');

            if ($key === 'vulnerabilities') {
                yield from $this->readVulnerabilities();

                continue;
            }

            $this->metadata[$key] = $this->decode($this->readValue());
        }
    }

    /**
     * Only meaningful once {@see vulnerabilities()} has passed the key, which
     * for NVD's payload order means after the generator has been drained.
     */
    public function totalResults(): int
    {
        return (int) ($this->metadata['totalResults'] ?? 0);
    }

    /**
     * @return Generator<int, mixed>
     */
    private function readVulnerabilities(): Generator
    {
        $this->expect('[');

        while (true) {
            $this->compact();
            $character = $this->peek();

            if ($character === ']') {
                $this->position++;

                return;
            }

            if ($character === ',') {
                $this->position++;

                continue;
            }

            yield $this->decode($this->readValue());
        }
    }

    /**
     * Returns the raw JSON text of the next value, without decoding it.
     */
    private function readValue(): string
    {
        $character = $this->peek();

        return match (true) {
            $character === '{' || $character === '[' => $this->readContainer(),
            $character === '"' => $this->readString(),
            default => $this->readScalar(),
        };
    }

    /**
     * Scans a balanced object or array. Quoted strings are skipped wholesale so
     * that braces inside them do not move the depth counter.
     */
    private function readContainer(): string
    {
        $start = $this->position;
        $depth = 0;
        $inString = false;
        $escaped = false;

        while (true) {
            $character = $this->readByte();

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($character === '"') {
                $inString = true;

                continue;
            }

            if ($character === '{' || $character === '[') {
                $depth++;

                continue;
            }

            if (($character === '}' || $character === ']') && --$depth === 0) {
                break;
            }
        }

        return substr($this->buffer, $start, $this->position - $start);
    }

    private function readString(): string
    {
        if ($this->peek() !== '"') {
            throw new RuntimeException('Expected a string in the NVD response.');
        }

        $start = $this->position++;
        $escaped = false;

        while (true) {
            $character = $this->readByte();

            if ($escaped) {
                $escaped = false;

                continue;
            }

            if ($character === '\\') {
                $escaped = true;

                continue;
            }

            if ($character === '"') {
                break;
            }
        }

        return substr($this->buffer, $start, $this->position - $start);
    }

    private function readScalar(): string
    {
        $start = $this->position;

        while ($this->hasMore()) {
            if (str_contains(self::SCALAR_DELIMITERS, $this->buffer[$this->position])) {
                break;
            }

            $this->position++;
        }

        if ($this->position === $start) {
            throw new RuntimeException('Expected a value in the NVD response.');
        }

        return substr($this->buffer, $start, $this->position - $start);
    }

    private function expect(string $character): void
    {
        if ($this->peek() !== $character) {
            throw new RuntimeException("Expected \"{$character}\" in the NVD response.");
        }

        $this->position++;
    }

    /**
     * Returns the next meaningful character, leaving the cursor on it.
     */
    private function peek(): string
    {
        while ($this->hasMore()) {
            $character = $this->buffer[$this->position];

            if (! str_contains(" \t\n\r", $character)) {
                return $character;
            }

            $this->position++;
        }

        throw new RuntimeException('The NVD response ended mid-value.');
    }

    /**
     * Returns the byte under the cursor and advances past it, whitespace
     * included. Container and string scans consume bytes literally; only
     * structural positions are allowed to skip whitespace.
     */
    private function readByte(): string
    {
        if (! $this->hasMore()) {
            throw new RuntimeException('The NVD response ended mid-value.');
        }

        return $this->buffer[$this->position++];
    }

    /**
     * True once the cursor has a byte to look at, pulling from the stream if
     * the buffer is spent.
     */
    private function hasMore(): bool
    {
        while ($this->position >= strlen($this->buffer)) {
            if ($this->stream->eof()) {
                return false;
            }

            $chunk = $this->stream->read(self::CHUNK_BYTES);

            if ($chunk === '') {
                return false;
            }

            $this->buffer .= $chunk;
        }

        return true;
    }

    /**
     * Drops the consumed prefix. Safe only between values: the read helpers
     * hold offsets into the buffer while they scan.
     */
    private function compact(): void
    {
        if ($this->position > 0) {
            $this->buffer = substr($this->buffer, $this->position);
            $this->position = 0;
        }
    }

    /**
     * @throws JsonException
     */
    private function decode(string $raw): mixed
    {
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }
}
