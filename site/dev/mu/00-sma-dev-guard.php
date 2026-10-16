<?php
/** Plugin Name: SMA local dev guard */
/** Description: Enables the sma-dev tools only when the site address is 127.0.0.1 or localhost; loads first. */

function sma_dev_is_local()
{
    static $local = null;
    if ($local === null) {
        $host  = strtolower((string) parse_url((string) get_option('siteurl'), PHP_URL_HOST));
        $local = in_array($host, ['127.0.0.1', 'localhost'], true);
    }
    return $local;
}
