<?php

use App\Services\AppHrMotorProvisioner;

return [
    'platforms' => [
        'app_hr_motor' => [
            'enabled' => true,
            'label' => 'App HR Motor',
            'processor' => AppHrMotorProvisioner::class,
        ],
        'microsoft_365' => [
            'enabled' => false,
            'label' => 'Microsoft 365',
        ],
        'salesforce' => [
            'enabled' => false,
            'label' => 'Salesforce',
        ],
    ],
];
