<?php
/**
 * The platform routes the app here; do not start a pretend audio stream.
 * Future media engine must originate an Asterisk playback call and send
 * separately transcoded Cisco image frames to the authenticated handset.
 */
return static function(array $ctx,array $q) {
    return $ctx['text']('Media Player',
        'Media transport is not installed yet. Audio will be carried by an Asterisk call; screen frames are a separate push stream.');
};
