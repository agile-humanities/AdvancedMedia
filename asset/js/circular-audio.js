/*
 * Circular audio player + transcript sync.
 * Migrated from AgileThemeTools/asset/js/circularAudio.js.
 *
 * Changes on migration:
 *  - Guarded on jQuery; if absent, the native <video controls> remains usable.
 *  - Adds `.ca-enhanced` to each container so CSS can swap native <video> for
 *    the SVG player only once this script runs (progressive enhancement).
 *  - Removed stray console.log() calls.
 *  - Transcript sync (setupTranscript) is now driven from the server-parsed cue
 *    times rather than the native track's cuechange, which removes both a CORS
 *    dependency and a float-matching bug. The native <track> is still attached
 *    when the media host permits CORS — see setupTranscript().
 *
 * @todo Depends on jQuery; a vanilla-JS rewrite would drop the dependency.
 */
(function ($) {
    if (!$) { return; }

    $(document).ready(function () {

        let hasActive = false;
        let activeAudioObj = null;
        let activePlayObj = null;
        let activeControls = null;
        let activeProgress = null;

        $('.ca-player-container').each(function (i) {

            const containerObj = $(this);
            const playObj = $(this).find('svg')[0],
                audioObj = $(this).find('.ca-player')[0],
                controlsObj = playObj.querySelector('.controls'),
                progress = playObj.querySelector('.progress-bar'),
                precache = playObj.querySelector('.precache-bar'),
                timerDisplay = $(this).find('.ca-timer .current-time')[0],
                timerDuration = $(this).find('.ca-timer .duration')[0],
                progressTrack = playObj.querySelector('.progress-track'),
                captionContainer = $(this).find('.ca-caption')[0],
                pt = playObj.createSVGPoint(),
                pc = 298.1371428256714; // 2 pi r

            let playerInitialized = false;

            // Progressive enhancement: reveal the SVG player, hide native <video>.
            containerObj.addClass('ca-enhanced');

            /* First player on the page becomes the keyboard-control target. */
            if (i === 0) {
                setActive();
            }

            /* Initialized on first click interaction with the player controls. */
            function initializePlayer() {
                audioObj.addEventListener('timeupdate', reportPosition);
                audioObj.addEventListener('timeupdate', updateTimer);
                precache.addEventListener('mousedown', positionAudio);
                progress.addEventListener('mousedown', positionAudio);
                progressTrack.addEventListener('mousedown', positionAudio);
                controlsObj.addEventListener('mousedown', setActive);
                progress.addEventListener('mousedown', setActive);
                playerInitialized = true;
            }

            controlsObj.addEventListener('click', function (e) {
                switch (playObj.getAttribute('class')) {
                    case 'not-started':
                        initializePlayer();
                        audioObj.play();
                        playObj.setAttribute('class', 'playing');
                        break;
                    case 'playing':
                        playObj.setAttribute('class', 'paused');
                        audioObj.pause();
                        break;
                    case 'paused':
                        playObj.setAttribute('class', 'playing');
                        audioObj.play();
                        break;
                    case 'ended':
                        playObj.setAttribute('class', 'not-started');
                        audioObj.removeEventListener('timeupdate', reportPosition);
                        audioObj.addEventListener('timeupdate', updateTimer);
                        break;
                }
            });

            audioObj.addEventListener('progress', function () {
                var length = audioObj.buffered.length - 1;
                if (length > -1) {
                    setGraphValue(precache, audioObj.buffered.end(audioObj.buffered.length - 1));
                }
            });

            audioObj.addEventListener('loadedmetadata', setDuration);

            setupTranscript(audioObj);

            /* Keyboard controls target the active player. */
            document.addEventListener('keydown', function (event) {
                if (!hasActive) return;
                const skipSeconds = 10;
                if (event.code === 'Space') {
                    event.preventDefault();
                    if (activeAudioObj.paused) {
                        activeAudioObj.play();
                        activePlayObj.setAttribute('class', 'playing');
                    } else {
                        activeAudioObj.pause();
                        activePlayObj.setAttribute('class', 'paused');
                    }
                } else if (event.code === 'ArrowRight') {
                    event.preventDefault();
                    if ((activeAudioObj.currentTime + skipSeconds) < activeAudioObj.duration) {
                        activeAudioObj.currentTime += skipSeconds;
                        setGraphValue(activeProgress, activeAudioObj.currentTime);
                        updateTimer();
                    }
                } else if (event.code === 'ArrowLeft') {
                    event.preventDefault();
                    if ((activeAudioObj.currentTime - skipSeconds) > 0) {
                        activeAudioObj.currentTime -= skipSeconds;
                        reportPosition();
                        updateTimer();
                    }
                }
            });

            // In some browsers loadedmetadata never fires; poll as a fallback.
            const updateDuration = setInterval(function () {
                timerDuration.textContent = timerDuration.textContent + ' .';
                if (audioObj && typeof audioObj.duration !== 'undefined') {
                    setDuration();
                }
            }, 1000);

            /* --- Supporting functions --- */

            function setActive() {
                activePlayObj = playObj;
                activeAudioObj = audioObj;
                activeControls = controlsObj;
                activeProgress = progress;
                hasActive = true;
            }

            function getTextTrack(video, captionContainer) {
                if (!video || !video.textTracks) return;
                var track = video.textTracks[0];
                if (!track) return;
                track.mode = 'hidden';
                track.oncuechange = function () {
                    var cue = this.activeCues[0];
                    if (cue && captionContainer) {
                        captionContainer.innerHTML = cue.getCueAsHTML().textContent;
                    }
                };
            }

            function cursorPoint(evt) {
                pt.x = evt.clientX; pt.y = evt.clientY;
                return pt.matrixTransform(playObj.getScreenCTM().inverse());
            }

            function angle(ex, ey) {
                var dy = ey - 50;
                var dx = ex - 50;
                var theta = Math.atan2(dy, dx);
                theta *= 180 / Math.PI;
                theta = theta + 90;
                if (theta < 0) theta = 360 + theta;
                return theta;
            }

            function getSvgCenter(svgElement) {
                const bbox = svgElement.getBBox();
                return { x: bbox.x + bbox.width / 2, y: bbox.y + bbox.height / 2 };
            }

            function calculateAngle(center, click) {
                const radians = Math.atan2(click.y - center.y, click.x - center.x);
                return radians * (180 / Math.PI);
            }

            function degreesToPercentage(degrees) {
                if (typeof degrees !== 'number' || isNaN(degrees)) {
                    throw new Error('Input must be a number.');
                }
                degrees = degrees % 360;
                if (degrees < 0) degrees += 360;
                return degrees / 360;
            }

            function setGraphValue(obj, val) {
                var offset = pc - parseFloat(((val / audioObj.duration) * pc), 10);
                obj.style.strokeDashoffset = offset;
                if (offset === 0) {
                    lunar.addClass(obj, 'done');
                    if (obj === progress) lunar.className(playObj, 'ended');
                }
            }

            function setDuration() {
                const minutes = Math.floor(audioObj.duration / 60);
                const seconds = Math.floor(audioObj.duration % 60);
                timerDuration.textContent = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
                containerObj.css('opacity', 1);
                clearInterval(updateDuration);
            }

            function reportPosition() {
                setGraphValue(progress, audioObj.currentTime);
            }

            function updateTimer() {
                const minutes = Math.floor(audioObj.currentTime / 60);
                const seconds = Math.floor(audioObj.currentTime % 60);
                timerDisplay.textContent = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
            }

            function positionAudio(event) {
                var clickPoint = cursorPoint(event);
                var centrePoint = getSvgCenter(playObj);
                // Top of the circle is -90 degrees; rotate to compensate.
                var degrees = calculateAngle(centrePoint, clickPoint) + 90;
                if (degrees < 0) degrees += 360;
                audioObj.currentTime = Math.round(audioObj.duration * degreesToPercentage(degrees));
                setGraphValue(progress, audioObj.currentTime);
            }

            /**
             * Wires the player to its transcript.
             *
             * Highlighting (and the caption fallback) are driven from the cue times we
             * parsed SERVER-side — integer milliseconds in data-start-cue/data-end-cue.
             * That needs no CORS, and it removes a latent float bug: the old code keyed a
             * lookup on `cues[0].startTime * 1000`, but in JS `6.68 * 1000` is
             * 6680.000000000001, which silently failed to match the integer 6680.
             *
             * The native <track> is attached only if the media host allows CORS. We probe
             * first, because setting `crossorigin` puts the MEDIA into CORS mode too — on a
             * host without CORS headers that kills audio playback, not just captions.
             */
            function setupTranscript(video) {
                if (!video) return;

                const track = video.querySelector('track[data-src]');
                if (!track) return;

                const vttUrl = track.getAttribute('data-src');
                const transcriptID = track.getAttribute('data-transcript-id');
                const transcript = transcriptID ? document.getElementById(transcriptID) : null;

                let nativeCaptions = false;
                let activeIdx = -1;
                let segments = [];

                if (transcript) {
                    segments = Array.prototype.slice
                        .call(transcript.querySelectorAll('.vtt-segment[data-start-cue]'))
                        .map(function (el, i) {
                            el.id = 'segment-' + transcriptID + '-' + i;
                            el.setAttribute('title', 'Cue audio player to here');
                            return {
                                el: el,
                                start: parseInt(el.getAttribute('data-start-cue'), 10),
                                end: parseInt(el.getAttribute('data-end-cue'), 10),
                                text: el.textContent
                            };
                        });

                    // Click a transcript line to seek there.
                    segments.forEach(function (seg) {
                        seg.el.addEventListener('click', function () {
                            video.currentTime = seg.start / 1000;
                            if (video.paused) {
                                if (playerInitialized === false) {
                                    initializePlayer();
                                }
                                playObj.setAttribute('class', 'playing');
                                video.play();
                            }
                        });
                    });

                    // Highlight the active line; caption it too when there's no native track.
                    video.addEventListener('timeupdate', function () {
                        const t = video.currentTime * 1000;
                        const current = segments[activeIdx];
                        if (current && t >= current.start && t < current.end) {
                            return; // still inside the same cue
                        }

                        let idx = -1;
                        for (let i = 0; i < segments.length; i++) {
                            if (t >= segments[i].start && t < segments[i].end) {
                                idx = i;
                                break;
                            }
                        }
                        if (idx === activeIdx) return;

                        if (current) current.el.classList.remove('cue-active');
                        activeIdx = idx;

                        if (idx > -1) {
                            segments[idx].el.classList.add('cue-active');
                            if (!nativeCaptions && captionContainer) {
                                captionContainer.textContent = segments[idx].text;
                            }
                        } else if (!nativeCaptions && captionContainer) {
                            captionContainer.textContent = '';
                        }
                    });
                }

                if (!vttUrl) return;

                const mediaUrl = video.currentSrc || video.getAttribute('src');

                // Probe the host for CORS before switching into CORS mode.
                //
                // `cache: 'reload'` is essential, not incidental. A response cached BEFORE the
                // host had a CORS config carries no Access-Control-Allow-Origin, and the browser
                // will happily reuse it — so a correctly-configured host still fails the CORS
                // check. 'reload' bypasses the cache AND replaces the stale entry with a
                // CORS-valid one. We do it for the MEDIA as well as the VTT, because attaching
                // the track re-requests the media in CORS mode too, and a stale media entry
                // would kill playback. The media probe asks for 1KB, not the whole file.
                Promise.all([
                    fetch(vttUrl, { mode: 'cors', cache: 'reload' }),
                    mediaUrl
                        ? fetch(mediaUrl, { mode: 'cors', cache: 'reload', headers: { Range: 'bytes=0-1023' } })
                        : Promise.resolve({ ok: true })
                ])
                    .then(function (responses) {
                        if (!responses.every(function (r) { return r.ok; })) {
                            throw new Error('CORS probe failed');
                        }
                        enableNativeTrack();
                    })
                    .catch(function () {
                        // Media host has no CORS: stay out of CORS mode so audio still
                        // plays. Captions come from the server-parsed cues above.
                    });

                /**
                 * Switch the element into CORS mode and attach the native caption track.
                 *
                 * Attaching a cross-origin track forces the MEDIA into CORS mode too, so the
                 * media is guarded: if it fails we drop straight back to the no-CORS element.
                 * Audio must never be the casualty of a captions upgrade. The guard stays armed
                 * until the MEDIA itself is playable — not merely until the (far smaller, far
                 * faster) track has loaded, which was the flaw in the first cut.
                 */
                function enableNativeTrack() {
                    const cleanup = function () {
                        video.removeEventListener('error', revert);
                        video.removeEventListener('canplay', cleanup);
                    };
                    const revert = function () {
                        cleanup();
                        nativeCaptions = false;          // our server-parsed caption resumes
                        video.removeAttribute('crossorigin');
                        track.removeAttribute('src');
                        video.load();                    // back to the mode we know plays
                    };

                    video.addEventListener('error', revert);
                    video.addEventListener('canplay', cleanup);

                    track.addEventListener('load', function () {
                        const textTrack = video.textTracks[0];
                        if (!textTrack || !textTrack.cues || textTrack.cues.length === 0) {
                            return;                      // no usable cues — keep our caption
                        }
                        nativeCaptions = true;
                        getTextTrack(video, captionContainer);
                    });

                    track.addEventListener('error', function () {
                        nativeCaptions = false;          // keep the server-parsed caption
                    });

                    video.crossOrigin = 'anonymous';     // public objects: no credentials needed
                    track.src = vttUrl;
                    video.load();                        // re-request the media in CORS mode
                }
            }
        });
    });
})(window.jQuery);

// Minimal class helpers. https://github.com/toddmotto/lunar/
(function (root, factory) {
    if (typeof define === 'function' && define.amd) {
        define(factory);
    } else if (typeof exports === 'object') {
        module.exports = factory;
    } else {
        root.lunar = factory();
    }
})(this, function () {

    'use strict';

    var lunar = {};

    lunar.hasClass = function (elem, name) {
        return new RegExp('(\\s|^)' + name + '(\\s|$)').test(elem.getAttribute('class'));
    };

    lunar.addClass = function (elem, name) {
        !lunar.hasClass(elem, name) && elem.setAttribute('class', (!!elem.getAttribute('class') ? elem.getAttribute('class') + ' ' : '') + name);
    };

    lunar.removeClass = function (elem, name) {
        var remove = elem.getAttribute('class').replace(new RegExp('(\\s|^)' + name + '(\\s|$)', 'g'), '$2');
        lunar.hasClass(elem, name) && elem.setAttribute('class', remove);
    };

    lunar.toggleClass = function (elem, name) {
        lunar[lunar.hasClass(elem, name) ? 'removeClass' : 'addClass'](elem, name);
    };

    lunar.className = function (elem, name) {
        elem.setAttribute('class', name);
    };

    return lunar;
});
