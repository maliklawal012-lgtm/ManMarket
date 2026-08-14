<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

const GENIUSPAY_API_BASE_DEFAULT = 'https://geniuspay.ci/api/v1/merchant';

define('GENIUSPAY_API_BASE', env('GENIUSPAY_API_BASE', GENIUSPAY_API_BASE_DEFAULT));
define('GENIUSPAY_MODE', env_required('GENIUSPAY_MODE'));
define('GENIUSPAY_PUBLIC_KEY', env_required('GENIUSPAY_PUBLIC_KEY'));
define('GENIUSPAY_SECRET_KEY', env_required('GENIUSPAY_SECRET_KEY'));
define('GENIUSPAY_MERCHANT_CODE', env('GENIUSPAY_MERCHANT_CODE', ''));
define('GENIUSPAY_WEBHOOK_SECRET', env('GENIUSPAY_WEBHOOK_SECRET', ''));

/**
 * Acces type oriente-objet a la config Genius Pay (utilise par GeniusPayService).
 * Ex: geniuspay_config('PUBLIC_KEY') -> valeur de GENIUSPAY_PUBLIC_KEY.
 */
function geniuspay_config(string $key): string
{
    return env_required('GENIUSPAY_' . $key);
}
