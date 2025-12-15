<?php

namespace App\Services;

class AliExpressSDKTest
{
    public static function test()
    {
        // Define SDK path before requiring autoloader
        if (!defined('IOP_AUTOLOADER_PATH')) {
            define('IOP_AUTOLOADER_PATH', app_path('Libraries/AliExpressSDK'));
        }

        require_once IOP_AUTOLOADER_PATH . '/Autoloader.php';

        $results = [];

        // Test IopClientImpl
        $results['IopClientImpl'] = class_exists('\IopClientImpl') ? '✅ Available' : '❌ Missing';

        // Test IopRequest
        $results['IopRequest'] = class_exists('\IopRequest') ? '✅ Available' : '❌ Missing';

        // Test Protocol
        $results['Protocol'] = class_exists('\Protocol') ? '✅ Available' : '❌ Missing';

        return $results;
    }
}
