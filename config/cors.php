<?php

return [

    /*
     | Estate SSO needs credentialed cross-origin reads of the identity broker
     | from every *.scarletbeast.com property. Credentialed CORS forbids a "*"
     | origin, so we reflect any scarletbeast.com origin explicitly.
     */

    'paths' => ['api/sso/*', 'api/wallet/global'],

    'allowed_methods' => ['GET', 'OPTIONS'],

    'allowed_origins' => [],

    'allowed_origins_patterns' => ['#^https?://([a-z0-9-]+\.)*scarletbeast\.com$#i'],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 600,

    'supports_credentials' => true,

];
