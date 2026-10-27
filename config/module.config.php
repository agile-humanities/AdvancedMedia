<?php
namespace AdvancedMedia;

return [
    'service_manager' => [
        'factories' => [
            Media\Player\Manager::class => Service\Media\Player\ManagerFactory::class,
            Media\PlayerResolver::class => Service\Media\PlayerResolverFactory::class,
            Media\Structure\Manager::class => Service\Media\Structure\ManagerFactory::class,
            Media\Compound\Manager::class => Service\Media\Compound\ManagerFactory::class,
            Media\CompoundResolver::class => Service\Media\CompoundResolverFactory::class,
            Media\Composer::class => Service\Media\ComposerFactory::class,
            Media\DownloadResolver::class => Service\Media\DownloadResolverFactory::class,
            Media\MediaAudit::class => Service\Media\MediaAuditFactory::class,
            File\FetchDiagnostics::class => Service\File\FetchDiagnosticsFactory::class,
            File\ReachabilityProbe::class => \Laminas\ServiceManager\Factory\InvokableFactory::class,
            File\ServeModeDetector::class => \Laminas\ServiceManager\Factory\InvokableFactory::class,
            Config\ConfigOptions::class => Service\Config\ConfigOptionsFactory::class,
            Config\ConfigWriter::class => Service\Config\ConfigWriterFactory::class,
            Config\ScopedFlag::class => Service\Config\ScopedFlagFactory::class,
        ],
        // Replace AmazonS3's store with our proxying subclass. A delegator rather
        // than a config factory or a runtime setFactory because it is the only
        // order-INDEPENDENT option — see the delegator class for the full why.
        //
        // Unconditional on purpose. When AmazonS3 is absent the service keyed here
        // is never created, so the delegator never fires — a delegator on an
        // unused service is inert. `::class` on an absent class is just its string
        // name and triggers no autoload, so building this array is always safe. A
        // class_exists() guard would be worse: it would make registration depend on
        // whether AmazonS3's autoloader was ready at config-merge time, reintroducing
        // exactly the order-dependency this change removes.
        'delegators' => [
            \AmazonS3\File\Store\AwsS3::class => [
                Service\File\Store\ProxyingAwsS3Delegator::class,
            ],
        ],
    ],

    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],

    'controllers' => [
        'factories' => [
            Controller\SiteAdmin\IndexController::class => Service\Controller\SiteAdminIndexControllerFactory::class,
            Controller\FileController::class => Service\Controller\FileControllerFactory::class,
        ],
    ],

    // The per-site media page: a child of the site-admin slug route, so it sits
    // alongside Pages / Navigation / Resources for the current site.
    'router' => [
        'routes' => [
            // ----------------------------------------------------------------
            // The /files/* space (docs/media-routing.md).
            //
            // These only ever MATCH for paths with no file behind them: Omeka's
            // stock .htaccess serves anything that exists on disk before the
            // request reaches index.php. So local files are untouched and only
            // S3-backed paths land in FileController.
            //
            // Explicit priorities because '/files/download/x.pdf' also matches
            // the generic route with path='download/x.pdf'. Higher wins.
            // ----------------------------------------------------------------
            // Highest priority: it must win over both /files/:path patterns, and
            // it must answer even when the opt-in is off (that is its purpose).
            'advancedmedia-file-probe' => [
                'type' => \Laminas\Router\Http\Literal::class,
                'priority' => 200,
                'options' => [
                    'route' => '/files/' . \AdvancedMedia\File\ReachabilityProbe::PROBE_PATH,
                    'defaults' => [
                        '__NAMESPACE__' => 'AdvancedMedia\Controller',
                        'controller' => Controller\FileController::class,
                        'action' => 'probe',
                    ],
                ],
            ],
            // Detects whether X-Accel-Redirect is wired up. Also opt-in-independent:
            // it answers so the module can pick a serve mode before committing.
            'advancedmedia-file-xaccel-probe' => [
                'type' => \Laminas\Router\Http\Literal::class,
                'priority' => 200,
                'options' => [
                    'route' => '/files/__advancedmedia-xaccel-probe',
                    'defaults' => [
                        '__NAMESPACE__' => 'AdvancedMedia\Controller',
                        'controller' => Controller\FileController::class,
                        'action' => 'xaccelProbe',
                    ],
                ],
            ],
            'advancedmedia-file-download' => [
                'type' => \Laminas\Router\Http\Segment::class,
                'priority' => 100,
                'options' => [
                    'route' => '/files/download/:path',
                    'constraints' => ['path' => '.+'],
                    'defaults' => [
                        '__NAMESPACE__' => 'AdvancedMedia\Controller',
                        'controller' => Controller\FileController::class,
                        'action' => 'download',
                    ],
                ],
            ],
            'advancedmedia-file' => [
                'type' => \Laminas\Router\Http\Segment::class,
                'priority' => 90,
                'options' => [
                    'route' => '/files/:path',
                    'constraints' => ['path' => '.+'],
                    'defaults' => [
                        '__NAMESPACE__' => 'AdvancedMedia\Controller',
                        'controller' => Controller\FileController::class,
                        'action' => 'file',
                    ],
                ],
            ],

            'admin' => [
                'child_routes' => [
                    'site' => [
                        'child_routes' => [
                            'slug' => [
                                'child_routes' => [
                                    'advanced-media' => [
                                        'type' => \Laminas\Router\Http\Literal::class,
                                        'options' => [
                                            'route' => '/advanced-media',
                                            'defaults' => [
                                                '__NAMESPACE__' => 'AdvancedMedia\Controller\SiteAdmin',
                                                'controller' => Controller\SiteAdmin\IndexController::class,
                                                'action' => 'index',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'navigation' => [
        // Sidebar → Modules. Points at our own module-config page (the global scope).
        'AdminModule' => [
            [
                'label' => 'Advanced media', // @translate
                'route' => 'admin/default',
                'params' => ['controller' => 'module', 'action' => 'configure'],
                'query' => ['id' => 'AdvancedMedia'],
                'resource' => 'Omeka\Controller\Admin\Module',
                'privilege' => 'browse',
            ],
        ],
        // Site admin sidebar → alongside Site admin / Pages / Navigation / Resources.
        'site' => [
            [
                'label' => 'Media', // @translate
                'class' => 'media',
                'route' => 'admin/site/slug/advanced-media',
                'action' => 'index',
                'useRouteMatch' => true,
                'privilege' => 'update',
                'resource' => Controller\SiteAdmin\IndexController::class,
            ],
        ],
    ],

    'view_helpers' => [
        'factories' => [
            'advancedMediaPlayer' => Service\ViewHelper\AdvancedMediaPlayerFactory::class,
            'advancedMediaCompose' => Service\ViewHelper\AdvancedMediaComposeFactory::class,
            'advancedMediaDownloadUrl' => Service\ViewHelper\AdvancedMediaDownloadUrlFactory::class,
        ],
        // `?page=N` deep links + URL write-back for every Mirador viewer
        // (docs/mirador-page-links.md). Inert when the Mirador module is absent: the
        // `mirador` helper is never created, so its delegator never fires.
        'delegators' => [
            'mirador' => [
                Service\ViewHelper\MiradorPageLinksDelegator::class,
            ],
        ],
    ],

    // Our resource-page blocks.
    'resource_page_block_layouts' => [
        'invokables' => [
            // Composes an item's media (structures + compounds + player resolver).
            'advancedMedia' => Site\ResourcePageBlockLayout\AdvancedMedia::class,
        ],
        'factories' => [
            // Independent of the block above: an item can show a viewer AND offer
            // the same file as a download. Placement is separate too.
            'advancedMediaDownloads' => Service\Site\ResourcePageBlockLayout\DownloadsFactory::class,
        ],
    ],

    // The player registry. Adding a player is just another entry here (or in
    // another module's config) — see docs/architecture.md §4.
    'advancedmedia_media_players' => [
        'invokables' => [
            'core-native'    => Media\Player\CoreNative::class,
            'none'           => Media\Player\None::class,
            'download'       => Media\Player\Download::class,
            'html5-audio'    => Media\Player\Html5Audio::class,
            'html5-video'    => Media\Player\Html5Video::class,
            'pdf-embed'      => Media\Player\PdfEmbed::class,
            'epub-reader'    => Media\Player\EpubReader::class,
            'photoswipe'     => Media\Player\PhotoSwipe::class,
            'thumbnail-link' => Media\Player\ThumbnailLink::class,
            'circular-audio' => Media\Player\CircularAudio::class,
            // Adapter over the OHMS Embed module's viewer; available only while that module is active.
            'ohms'           => Media\Player\Ohms::class,
        ],
        'factories' => [
            // The one player that fetches server-side (it reads the VTT), so it
            // needs the store and the diagnostics recorder injected.
            'transcript'     => Service\Media\Player\TranscriptFactory::class,
        ],
    ],

    // The STRUCTURE registry (docs/architecture.md §6.0). Structures DETECT and
    // PARTITION; they never render, and they never depend on an installed viewer
    // module — which is what makes a config row keyed on them stable.
    //
    // Priority = specificity of the pattern, so more specific patterns claim first.
    'advancedmedia_structures' => [
        'invokables' => [
            'audio-with-transcript' => Media\Structure\AudioWithTranscript::class, // 100
            'av-with-images'        => Media\Structure\AvWithImages::class,        //  95
            'embed-with-poster'     => Media\Structure\EmbedWithPoster::class,     //  90
            'multiple-images'       => Media\Structure\MultipleImages::class,      //  50
            'single-image'          => Media\Structure\SingleImage::class,         //  50
            'epub-edition'          => Media\Structure\EpubEdition::class,         //  60
            'pdf-with-image-set'    => Media\Structure\PdfWithImageSet::class,     //  45
            'paged-document'        => Media\Structure\PagedDocument::class,       //  40
            'ohms-interview'        => Media\Structure\OhmsInterview::class,       //  97
        ],
    ],

    // The COMPOUND registry (docs/architecture.md §6.0). Compounds RENDER a group
    // handed to them by a structure, and declare which structures they support.
    // Several compounds may support one structure — that is what lets an admin
    // choose between them.
    'advancedmedia_compound_types' => [
        'invokables' => [
            // Renders nothing; needs no collaborators.
            'none'                => Media\Compound\None::class,
        ],
        'factories' => [
            'identity'            => Service\Media\Compound\IdentityFactory::class,
            'audioTranscript'     => Service\Media\Compound\AudioTranscriptFactory::class,
            'avWithImages'        => Service\Media\Compound\AvWithImagesFactory::class,
            'interactiveResource' => Service\Media\Compound\InteractiveResourceFactory::class,
            'mirador'             => Service\Media\Compound\MiradorFactory::class,
            'photoswipe'          => Service\Media\Compound\PhotoSwipeFactory::class,
            'pagedDocument'       => Service\Media\Compound\PagedDocumentFactory::class,
            'ohmsInterview'       => Service\Media\Compound\OhmsInterviewFactory::class,
        ],
    ],

    'advancedmedia' => [
        // Shipped default player mapping, keyed by context (only 'page' in v1). The
        // resolver reads admin overrides from Settings/SiteSettings first, then this.
        'default_players' => [
            'page' => [
                'audio/*' => 'html5-audio',
                'video/*' => 'html5-video',
                // Omeka core registers file renderers for audio and video MIME types
                // ONLY. Everything else — PDFs included — falls through
                // Media\Renderer\File to the 'thumbnail' renderer: a poster image
                // linking to the original. So 'core-native' on a PDF is not a reader,
                // and there is no reader anywhere in core to fall back to. Mapping it
                // explicitly is what makes a PDF readable in the page.
                'application/pdf' => 'pdf-embed',

                // Same reasoning as the PDF above: core has no ePub renderer at
                // all, so without this row an uploaded edition falls through to a
                // thumbnail linking to the file.
                'application/epub+zip' => 'epub-reader',

                // An OHMS XML (OHMS Embed module) plays in the OHMS viewer. The player is
                // available only while that module is active; otherwise the resolver falls
                // through to core-native (Omeka's thumbnail fallback for XML).
                'text/xml' => 'ohms',
                'application/xml' => 'ohms',
                'default' => 'core-native',
            ],
        ],

        // Shipped download policy: OPT-IN, nothing is downloadable until an admin
        // says so.
        //
        // The catch-all is '0' deliberately. Placing the Downloads block should not
        // instantly expose every sidecar, derivative and VTT in the install — and in
        // this collection `text/plain` IS the WebVTT transcripts, which are exactly
        // the kind of file that should never appear as a public download by accident.
        'default_downloads' => [
            'page' => [
                'default' => '0',
            ],
        ],

        // Shipped structure → compound mapping. Both image structures point at the
        // same compound, so a single image and an image set behave identically
        // until an admin deliberately separates them (architecture §6.0.2).
        'default_structure_compounds' => [
            'page' => [
                'audio-with-transcript' => 'audioTranscript',
                'av-with-images'        => 'avWithImages',
                'ohms-interview'        => 'ohmsInterview',
                'embed-with-poster'     => 'interactiveResource',
                'multiple-images'       => 'mirador',

                // 'identity' is the RIGHT answer here, not a placeholder: it renders
                // each claimed ePub through its resolved player (the reader), and
                // because the group is structure-claimed it is exempt from the
                // "only display primary and grouped media" gate. A dedicated
                // compound would only duplicate that. One is worth adding the day an
                // ePub needs rendering TOGETHER with something else — a cover, a
                // transcript — which is precisely when a compound earns its keep.
                'epub-edition'          => 'identity',
                'single-image'          => 'mirador',

                // ⚠️ DELIBERATELY 'identity', NOT 'pagedDocument'.
                //
                // The Paged Document compound and its UV adapter work — UV boots and
                // fetches /iiif/3/{id}/manifest — but the manifest exposes a PDF canvas
                // as `type: Text, format: application/pdf` with NO IIIF image service
                // and no dimensions, so UV has no page images to draw and renders a
                // BLACK canvas. (Re-verified 2026-08-12 on item 58, and with
                // `iiifserver_media_api_support_non_image` flipped on and the manifest
                // cache busted — the canvas is unchanged, and on mixed items the PDF is
                // absent from the manifest entirely.)
                //
                // The cause is the IIIF stack, not this module: PDF page rasterization
                // is not happening, though Ghostscript is present. See
                // _media_iiif_stack_README.md.
                //
                // 'identity' is NOT a stopgap here any more. It renders each PDF through
                // its resolved player, and `application/pdf` now maps to 'pdf-embed' —
                // the browser's own reader, in the page. That is a working PDF reader,
                // not a fallback. `pagedDocument` remains registered and selectable;
                // flip this row the moment the IIIF stack serves PDF page images, and
                // the whole-item-viewer guard in the Composer re-arms by itself.
                // 'identity' ships here, NOT 'none': suppressing the PDF is this
                // client's presentation choice, not a default worth imposing on a
                // fresh install, and today's behaviour is that the PDF renders. A
                // site that wants it hidden sets this one row to "Do not display".
                'pdf-with-image-set'    => 'identity',
                'paged-document'        => 'identity',
            ],
        ],
    ],
];
