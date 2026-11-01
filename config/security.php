<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ClamAV upload scanning
    |--------------------------------------------------------------------------
    |
    | Off by default: nothing in this deployment installs ClamAV yet. See
    | App\Support\VirusScan -- turning this on requires a real clamdscan/clamscan
    | binary on the server (e.g. a clamav-daemon package added to the Docker
    | image), which is an ops decision, not something this code alone provides.
    | Until then, SafeUpload's existing content-sniffing and image re-encoding
    | remain the only line of defense, same as before this file existed.
    |
    */
    'clamav_enabled' => env('CLAMAV_ENABLED', false),
    'clamav_binary' => env('CLAMAV_BINARY', '/usr/bin/clamdscan'),

];
