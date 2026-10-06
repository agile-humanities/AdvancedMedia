<?php
namespace AdvancedMedia\Media\Compound;

use Laminas\View\Renderer\PhpRenderer;

/**
 * "Do not display": renders nothing at all for the group it is handed.
 *
 * WHY THE STRUCTURE LAYER NEEDS ITS OWN WAY TO HIDE. The `none` PLAYER already
 * hides a media type, but it does so wherever that type occurs — mapping
 * application/pdf to it would hide the 2,000-odd standalone PDFs along with the
 * few dozen that merely duplicate an item's page images. Suppression is often
 * wanted for a PATTERN rather than a TYPE, and until now no structure row could
 * express it: every compound renders something.
 *
 * Universal structure support ('*'), so any row can be turned off this way. Note
 * what it does NOT do: the media are still CLAIMED, so they do not fall through
 * to the identity floor to be rendered individually — claimed and silent is
 * exactly the intent. Nothing here touches the Downloads block, which walks the
 * item's media directly and never goes through the composer, so a suppressed file
 * stays downloadable when the download policy allows it.
 */
class None extends AbstractCompound
{
    protected $name = 'none';
    protected $label = 'Do not display'; // @translate
    protected $supportedStructures = ['*'];

    public function render(PhpRenderer $view, array $mediaGroup, array $context): string
    {
        return '';
    }
}
