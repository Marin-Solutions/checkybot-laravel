<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Support;

/**
 * Package constants
 */
final class Constants
{
    // Configuration keys
    public const CONFIG_KEY = 'checkybot-laravel';

    public const CONFIG_PUBLISH_TAG = 'checkybot-laravel-config';

    // Environment variable names
    public const ENV_API_KEY = 'CHECKYBOT_API_KEY';

    public const ENV_PROJECT_ID = 'CHECKYBOT_PROJECT_ID';

    public const ENV_BASE_URL = 'CHECKYBOT_URL';

    // Default values
    public const DEFAULT_TIMEOUT = 30;

    public const DEFAULT_RETRY_TIMES = 3;

    public const DEFAULT_RETRY_DELAY = 1000; // milliseconds

    public const DEFAULT_BASE_URL = 'https://checkybot.com';

    // Check types
    public const CHECK_TYPE_UPTIME = 'uptime';

    public const CHECK_TYPE_SSL = 'ssl';

    public const CHECK_TYPE_API = 'api';

    // API endpoint
    public const API_ENDPOINT_SYNC = '/api/v1/projects/%s/checks/sync';

    // HTTP headers
    public const HEADER_ACCEPT = 'Accept';

    public const HEADER_ACCEPT_JSON = 'application/json';

    public const HEADER_X_API_KEY = 'X-API-Key';

    public const HEADER_AUTHORIZATION = 'Authorization';

    public const HEADER_AUTHORIZATION_PREFIX = 'Bearer ';

    // Error messages
    public const ERROR_API_KEY_MISSING = 'CHECKYBOT_API_KEY is not configured';

    public const ERROR_PROJECT_ID_MISSING = 'CHECKYBOT_PROJECT_ID is not configured';

    public const ERROR_DUPLICATE_CHECK_NAMES = 'Duplicate %s check names found: %s';

    public const ERROR_SYNC_FAILED = 'Sync failed after retries';

    // Log messages
    public const LOG_SYNC_STARTED = 'Checkybot sync started';

    public const LOG_SYNC_SUCCESS = 'Checkybot sync successful';

    public const LOG_SYNC_FAILED = 'Checkybot sync failed';

    private function __construct()
    {
        // Prevent instantiation
    }
}
