<?php
namespace AdvancedMedia\Media\Vtt;

/**
 * A small WebVTT parser: loads a VTT file and returns flat cue segments.
 *
 * Ported from AgileThemeTools\AgileVttParser (itself adapted from
 * webrium/vtt-parser). Cleaned up: flat segment output, cue times exposed in
 * milliseconds via cueMilliseconds() (the old convertToMicroseconds() was
 * misnamed — it always returned ms).
 *
 * @todo Reads over the file URL on every render; add caching (architecture §11.3).
 */
class VttParser
{
    /** @var string */
    private $content = '';

    /** @var bool */
    private $ready = false;

    public function loadUrl(string $url): bool
    {
        $content = @file_get_contents($url);
        if ($content === false) {
            $this->ready = false;
            return false;
        }
        $this->content = $content;
        $this->ready = true;
        return true;
    }

    public function loadString(string $content): bool
    {
        $this->content = $content;
        $this->ready = true;
        return true;
    }

    /**
     * @return array<int, array{start:string,end:string,timestamp:string,start_cue:int,end_cue:int,text:string}>
     */
    public function parse(): array
    {
        if (!$this->ready) {
            return [];
        }
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", trim($this->content)));
        $segments = [];
        $current = null;

        foreach ($lines as $line) {
            $text = trim($line);
            if (strpos($text, '-->') !== false) {
                // New cue timing line. Flush any dangling cue first.
                if ($current !== null) {
                    $segments[] = $this->finalize($current);
                }
                $time = $this->extractTimestamps($text);
                $current = $time ? ($time + ['text' => []]) : null;
            } elseif ($current !== null) {
                if ($text === '') {
                    $segments[] = $this->finalize($current);
                    $current = null;
                } else {
                    $current['text'][] = $text;
                }
            }
            // Non-cue lines (WEBVTT header, NOTE blocks, cue identifiers) are ignored.
        }
        if ($current !== null) {
            $segments[] = $this->finalize($current);
        }
        return $segments;
    }

    private function finalize(array $cue): array
    {
        return [
            'start' => $cue['start'],
            'end' => $cue['end'],
            'timestamp' => $cue['timestamp'],
            'start_cue' => $cue['start_cue'],
            'end_cue' => $cue['end_cue'],
            'text' => implode(' ', $cue['text']),
        ];
    }

    private function extractTimestamps(string $line): ?array
    {
        // Matches HH:MM:SS.mmm, MM:SS.mmm (hours optional per the VTT spec).
        if (!preg_match_all('/(?:\d{1,3}:)?\d{1,2}:\d{2}\.\d{3}/', $line, $matches)
            || !isset($matches[0][0], $matches[0][1])
        ) {
            return null;
        }
        return [
            'start' => $this->formatTime($matches[0][0]),
            'end' => $this->formatTime($matches[0][1]),
            'timestamp' => $this->formatTime($matches[0][0]) . ' --> ' . $this->formatTime($matches[0][1]),
            'start_cue' => $this->cueMilliseconds($matches[0][0]),
            'end_cue' => $this->cueMilliseconds($matches[0][1]),
        ];
    }

    private function formatTime(string $timeString): string
    {
        [$h, $m, $s] = $this->components($timeString);
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }

    /** Cue time in milliseconds (the sync anchor used by the transcript JS). */
    public function cueMilliseconds(string $timeString): int
    {
        [$h, $m, $s] = $this->components($timeString);
        return (int) round((($h * 3600) + ($m * 60) + $s) * 1000);
    }

    /**
     * @return array{0:int,1:int,2:float} [hours, minutes, seconds(+fraction)]
     */
    private function components(string $timeString): array
    {
        $parts = explode(':', $timeString);
        if (count($parts) === 2) {
            array_unshift($parts, '0'); // hours optional
        }
        if (!isset($parts[0]) || $parts[0] === '') {
            $parts[0] = '0'; // handles the malformed ":mm:ss.sss" form
        }
        return [(int) $parts[0], (int) ($parts[1] ?? 0), (float) ($parts[2] ?? 0)];
    }
}
