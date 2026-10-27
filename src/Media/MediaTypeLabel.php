<?php
namespace AdvancedMedia\Media;

use Omeka\Api\Representation\MediaRepresentation;

/**
 * Human-readable names and sizes for download buttons.
 *
 * "Download PDF (1.2 MB)" rather than "Download application/pdf (1258291)".
 * The size is part of the label because it is the single most useful thing a
 * visitor wants to know before clicking a link that may be 40 MB.
 */
class MediaTypeLabel
{
    /**
     * Curated names for types that actually turn up in collections.
     *
     * Curated rather than derived: the MIME subtype is unusable as a label for
     * anything from Office (`vnd.openxmlformats-officedocument.wordprocessingml.document`)
     * and merely cryptic for the rest. Unknown types fall back to the file
     * extension, which is what a visitor recognises anyway.
     */
    const NAMES = [
        'application/pdf' => 'PDF',
        'application/epub+zip' => 'EPUB',
        'application/zip' => 'ZIP',
        'application/msword' => 'Word document',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'Word document',
        'application/vnd.ms-excel' => 'Excel spreadsheet',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'Excel spreadsheet',
        'application/vnd.ms-powerpoint' => 'PowerPoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'PowerPoint',
        'text/plain' => 'Text file',
        'text/csv' => 'CSV',
        'text/vtt' => 'Captions',
        'image/jpeg' => 'JPEG image',
        'image/png' => 'PNG image',
        'image/tiff' => 'TIFF image',
        'image/gif' => 'GIF image',
        'audio/mpeg' => 'MP3 audio',
        'audio/wav' => 'WAV audio',
        'audio/x-wav' => 'WAV audio',
        'audio/mp4' => 'Audio',
        'video/mp4' => 'MP4 video',
        'video/quicktime' => 'QuickTime video',
    ];

    /** e.g. "PDF", or "JP2" derived from the extension, or a last-resort "File". */
    public static function name(MediaRepresentation $media): string
    {
        return self::nameFor((string) $media->mediaType(), (string) $media->extension());
    }

    /**
     * The same name from a bare type and extension — for the config form, which
     * describes media TYPES and has no media to ask.
     */
    public static function nameFor(string $type, string $extension = ''): string
    {
        if (isset(self::NAMES[$type])) {
            return self::NAMES[$type];
        }

        $extension = trim($extension);
        if ($extension !== '') {
            return strtoupper($extension);
        }

        // Never show a raw MIME string to a visitor.
        return 'File'; // @translate
    }

    /**
     * "1.2 MB". Returns '' when the size is unknown, so the caller can omit the
     * parenthetical rather than print "(0 B)".
     *
     * Decimal units (1 kB = 1000 B), matching what an operating system reports
     * for a downloaded file — the number a visitor will compare this against.
     */
    public static function size(MediaRepresentation $media): string
    {
        $bytes = (int) $media->size();
        if ($bytes <= 0) {
            return '';
        }

        $units = ['B', 'kB', 'MB', 'GB', 'TB'];
        $power = (int) floor(log($bytes, 1000));
        $power = max(0, min($power, count($units) - 1));
        $value = $bytes / (1000 ** $power);

        // Whole numbers for bytes and kB; one decimal above that, where the
        // fraction carries real information.
        $decimals = ($power >= 2 && $value < 100) ? 1 : 0;

        return sprintf('%s %s', number_format($value, $decimals), $units[$power]);
    }

    /** The token a label may carry where the file size should appear. */
    const SIZE_TOKEN = '{size}';

    /**
     * Turn a label pattern into the text of one download link.
     *
     * A pattern is plain text with an optional {size} token: the built-in label is
     * the pattern "Download PDF ({size})", and an admin's custom label is whatever
     * they typed. One code path for both, so a custom label is not a special case
     * that can drift from the default.
     *
     *   - {size} becomes the file size. When the size is unknown the token goes,
     *     and so do the brackets or dash that introduced it — "Download PDF", never
     *     "Download PDF ()".
     *   - $number, when given, distinguishes several files sharing one label
     *     ("… 2"). It goes before a trailing size, where it reads as part of the
     *     name, and otherwise at the end.
     */
    public static function compose(string $pattern, string $size, ?int $number = null): string
    {
        $token = preg_quote(self::SIZE_TOKEN, '~');

        if ($number !== null) {
            $numbered = preg_replace('~(\s*[(\[–—-]?\s*' . $token . '\s*[)\]]?\s*)$~u', ' ' . $number . '$1', $pattern, 1, $count);
            $pattern = $count ? $numbered : $pattern . ' ' . $number;
        }

        if ($size === '') {
            // Take the token's own punctuation with it.
            $pattern = preg_replace('~\s*[(\[]\s*' . $token . '\s*[)\]]|\s*[–—-]?\s*' . $token . '~u', '', $pattern);
        } else {
            $pattern = str_replace(self::SIZE_TOKEN, $size, $pattern);
        }

        return trim(preg_replace('~\s{2,}~u', ' ', $pattern));
    }
}
