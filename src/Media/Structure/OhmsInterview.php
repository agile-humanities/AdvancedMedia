<?php
namespace AdvancedMedia\Media\Structure;

use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;

/**
 * An OHMS interview: the item's primary media is an OHMS XML (OHMS Embed module), optionally with the audio or
 * video file the XML plays, hosted in Omeka as a media of the same item.
 *
 * DECLARATIVE, VIA PRIMARY MEDIA (architecture §6.0.1), like the embed structure: the OHMS Embed manual tells
 * editors to make the XML the primary media. The interview's own file is grouped with it so that it counts as
 * "shown in a viewer" (the OHMS viewer plays it) rather than as a loose file to render a second time; the
 * Downloads block can still offer it.
 */
class OhmsInterview extends AbstractStructure
{
    protected $name = 'ohms-interview';
    protected $label = 'Primary media is an OHMS interview'; // @translate
    protected $description = 'An OHMS XML (OHMS Embed module) as the primary media, optionally with the audio or video file it plays.'; // @translate
    protected $priority = 97;

    protected $requirements = [
        "The item's primary media is an OHMS XML file (extension .xml), rendered by the OHMS Embed module.", // @translate
        'If the interview\'s audio or video is hosted in Omeka as a media of the same item, that file is grouped with the XML: the OHMS viewer plays it, so it is not rendered a second time. With one audio/video media it is grouped outright; with several, the one whose file name matches the XML\'s media URL.', // @translate
        'Troubleshooting: if an OHMS item renders as a thumbnail or a download, check that the OHMS Embed module is active, that XML is an allowed media type, and that the XML is designated as the primary media.', // @translate
    ];

    public function detect(ItemRepresentation $item, array $remainingMedia): array
    {
        $xml = $this->primaryOrFirst($item);
        if (!$xml || !$this->isOhmsXml($xml) || !$this->inPool($xml, $remainingMedia)) {
            return [];
        }
        $group = [$xml];

        $av = [];
        foreach ($remainingMedia as $media) {
            if ($media->id() !== $xml->id() && ($this->isAudio($media) || strpos((string) $media->mediaType(), 'video/') === 0)) {
                $av[] = $media;
            }
        }
        if (count($av) === 1) {
            $group[] = $av[0];
        } elseif (count($av) > 1) {
            $target = $this->mediaUrlBasename($xml);
            foreach ($av as $media) {
                if ($target !== null && $this->basename((string) $media->filename()) === $target) {
                    $group[] = $media;
                    break;
                }
            }
        }
        return [$group];
    }

    protected function isOhmsXml(MediaRepresentation $media): bool
    {
        return strtolower((string) $media->extension()) === 'xml'
            && class_exists('OhmsEmbed\\Media\\FileRenderer\\OhmsEmbedRenderer');
    }

    /**
     * The file name the XML's media_url points at, read from the local original when there is one (a remote
     * store is not fetched at display time: with several audio/video files and no local copy, nothing is grouped).
     */
    protected function mediaUrlBasename(MediaRepresentation $xml): ?string
    {
        $path = OMEKA_PATH . '/files/original/' . $xml->storageId() . '.' . $xml->extension();
        if (!is_readable($path)) {
            return null;
        }
        $head = (string) file_get_contents($path, false, null, 0, 65536);
        if (!preg_match('~<media_url>\s*([^<]+?)\s*</media_url>~', $head, $m)) {
            return null;
        }
        return $this->basename(html_entity_decode($m[1]));
    }

    protected function basename(string $urlOrName): string
    {
        $path = parse_url($urlOrName, PHP_URL_PATH) ?: $urlOrName;
        return strtolower(rawurldecode(basename($path)));
    }
}
