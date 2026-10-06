<?php
namespace AdvancedMedia\Media\Player;

use AdvancedMedia\Media\Vtt\VttParser;
use Omeka\Api\Representation\MediaRepresentation;
use Laminas\View\Renderer\PhpRenderer;

/**
 * Renders a VTT file as a list of clickable, time-cued transcript segments.
 * Used inside the Audio-with-Transcript compound (wired to a player by a shared
 * transcript id), and available standalone for text/vtt media.
 *
 * Recognised options: 'transcriptId' (id for the container element),
 * 'heading' (transcript heading text).
 */
class Transcript extends AbstractPlayer
{
    protected $name = 'transcript';
    protected $label = 'Transcript'; // @translate
    protected $mediaTypeCapabilities = ['text/vtt'];

    public function supports(MediaRepresentation $media, array $context = []): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        // VTT files are sometimes sniffed as text/plain; accept by extension too.
        return $this->matchesMediaType($media->mediaType())
            || strtolower((string) $media->extension()) === 'vtt';
    }

    /** @var mixed The configured file store, or null when built as an invokable. */
    private $store;

    /** @var \AdvancedMedia\File\FetchDiagnostics|null */
    private $diagnostics;

    public function __construct($store = null, $diagnostics = null)
    {
        $this->store = $store;
        $this->diagnostics = $diagnostics;
    }

    /** Public URL → one this server can actually fetch. Safe on any input. */
    private function fetchableUrl(?string $url): ?string
    {
        if (!$url || !($this->store instanceof \AdvancedMedia\File\Store\ProxyingAwsS3)) {
            return $url;
        }
        return $this->store->toFetchableUri($url);
    }

    public function render(PhpRenderer $view, MediaRepresentation $media, array $options = []): string
    {
        $escapeHtml = $view->plugin('escapeHtml');
        $escapeAttr = $view->plugin('escapeHtmlAttr');
        $transcriptId = $options['transcriptId'] ?? ('advmedia-transcript-' . $media->id());

        $segmentsHtml = '';
        // originalUrl() is BROWSER-facing. This fetch is server-side, so it has to
        // go through toFetchableUri(): with file proxying on, the public URL may
        // not be reachable from the server at all (see ProxyingAwsS3). Getting
        // this wrong is silent — the transcript just renders empty.
        $url = $this->fetchableUrl($media->originalUrl());
        if ($url) {
            $parser = new VttParser();
            $loaded = $parser->loadUrl($url);
            if (!$loaded && $this->diagnostics) {
                // Degrade gracefully — a missing transcript must not take the page
                // down — but leave a trace. This failure used to be completely
                // silent, which is how it went unnoticed.
                $this->diagnostics->recordFailure($url, 'Transcript player (VTT)');
            }
            if ($loaded) {
                foreach ($parser->parse() as $seg) {
                    $segmentsHtml .= sprintf(
                        '<span class="vtt-segment" data-timestamp="%s" data-start-time="%s" data-end-time="%s" data-start-cue="%s" data-end-cue="%s">%s</span> ',
                        $escapeAttr($seg['timestamp']),
                        $escapeAttr($seg['start']),
                        $escapeAttr($seg['end']),
                        $escapeAttr((string) $seg['start_cue']),
                        $escapeAttr((string) $seg['end_cue']),
                        $escapeHtml($seg['text'])
                    );
                }
            }
        }

        return $view->partial('common/player/transcript', [
            'transcriptId' => $transcriptId,
            'segmentsHtml' => $segmentsHtml,
            'heading' => $options['heading'] ?? 'Transcript', // @translate
        ]);
    }
}
