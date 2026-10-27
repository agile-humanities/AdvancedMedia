<?php
namespace AdvancedMedia\Media;

use Doctrine\DBAL\Connection;

/**
 * Finds media records whose FILE is the problem, for the module config page.
 *
 * Configuration cannot fix these — no player choice makes an empty file play — so
 * they are listed for an editor to delete or replace. Each check is a record-level
 * signal that is cheap to read from the `media` table and that, on Pitts
 * production, flagged only genuine junk (2026-10-27, against the 2026-09-30 dump).
 *
 * WHY THESE FOUR
 * --------------
 * - hidden: a dot-prefixed filename (`.DS_Store`, `._page.jpg`, `.00000001.jpg`)
 *   is a system file a bulk import swept up from a source folder. FileController
 *   refuses to serve any dot-prefixed path segment (deliberately), so with
 *   proxying on it is a 404 everywhere, including the server's own dimension
 *   probes, which then retry on every manifest build. Found 2026-10-23 as a
 *   "malformed URL" in the access log; the URL was correct, the record was junk.
 * - empty: zero bytes. Usually an ingest that fetched an empty or failed object;
 *   it renders as a blank page in every viewer.
 * - imageNoThumbnails: an image whose thumbnails were never made. Thumbnailing
 *   ran and failed: the bytes are not a readable image, or the image was too
 *   large for the thumbnailer (on Pitts, 10–20 MB TIFFs).
 * - unknownType: `application/octet-stream`, i.e. the type could not be detected,
 *   so no player mapping applies and the media falls through to the fallback.
 *
 * Deliberately NOT checked: things that need the file itself (decoding, S3 HEAD
 * requests). This runs on every load of the config page, so it stays inside the
 * database — one aggregate query, plus one sample query per check that fires.
 * Measured at ~300 ms against Pitts' 89,658 media (full table scans; there is no
 * index that helps a LIKE on a path suffix). Fine for an admin page; do not call
 * this from anything public.
 */
class MediaAudit
{
    /** How many example rows to list per check. The count is always exact. */
    const SAMPLE_SIZE = 25;

    /** @var Connection */
    private $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return array<string, array{key: string, count: int, rows: array}>
     *   Only the checks that found something, in display order. Empty when clean.
     */
    public function run(): array
    {
        $checks = $this->checks();

        $select = [];
        foreach ($checks as $key => $condition) {
            $select[] = sprintf('SUM(CASE WHEN %s THEN 1 ELSE 0 END) AS `%s`', $condition, $key);
        }
        try {
            $counts = $this->connection->fetchAssociative(
                'SELECT ' . implode(', ', $select) . ' FROM media'
            );
        } catch (\Exception $e) {
            // A diagnostics panel must never take the config page down with it.
            return [];
        }

        $results = [];
        foreach ($checks as $key => $condition) {
            $count = (int) ($counts[$key] ?? 0);
            if (!$count) {
                continue;
            }
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, item_id, storage_id, extension, source, media_type, size'
                . ' FROM media WHERE ' . $condition
                . ' ORDER BY id LIMIT ' . self::SAMPLE_SIZE
            );
            $results[$key] = [
                'key' => $key,
                'count' => $count,
                'rows' => array_map([$this, 'row'], $rows),
            ];
        }
        return $results;
    }

    /**
     * Each check as a SQL condition on `media`. Only media with a stored original
     * are considered: a URL- or oEmbed-style media has no file to be wrong.
     *
     * Each media is reported under ONE check only, the first that matches: the
     * order runs from root cause to symptom. A hidden `._page.jpg` also has no
     * thumbnails, but "it is a system file" is the thing to act on, so listing it
     * twice would only inflate the counts.
     */
    private function checks(): array
    {
        $raw = [
            'hidden' => "storage_id LIKE '.%' OR storage_id LIKE '%/.%'",
            'empty' => "size = 0 OR media_type IN ('application/x-empty', 'inode/x-empty')",
            'imageNoThumbnails' => "has_thumbnails = 0 AND media_type LIKE 'image/%'",
            'unknownType' => "media_type = 'application/octet-stream'",
        ];

        $checks = [];
        $earlier = [];
        foreach ($raw as $key => $condition) {
            // COALESCE: a NULL size or type must not turn "NOT (earlier)" into NULL
            // and silently drop the row from every later check.
            $exclude = $earlier
                ? ' AND NOT COALESCE((' . implode(') OR (', $earlier) . '), 0)'
                : '';
            $checks[$key] = "has_original = 1 AND COALESCE(($condition), 0)$exclude";
            $earlier[] = $condition;
        }
        return $checks;
    }

    private function row(array $row): array
    {
        $storageId = (string) $row['storage_id'];
        $extension = (string) $row['extension'];
        return [
            'id' => (int) $row['id'],
            'itemId' => (int) $row['item_id'],
            'file' => $storageId . ($extension !== '' ? '.' . $extension : ''),
            // The source is shown so the editor can find the stray file where it
            // came from (an S3 folder, a sideload directory) and remove it there too.
            'source' => (string) $row['source'],
            'mediaType' => (string) $row['media_type'],
            'size' => $row['size'] === null ? null : (int) $row['size'],
        ];
    }
}
