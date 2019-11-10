<?php
return [
    'entity_manager' => [
        'mapping_classes_paths' => [
            dirname(__DIR__) . '/src/Entity',
        ],
        'proxy_paths' => [
            dirname(__DIR__) . '/data/doctrine-proxies',
        ],
    ],
    'view_manager' => [
        'template_map' => [
            'config_module' => __DIR__ . '/../view/config_module.phtml',
            'domain_not_configured' => __DIR__ . '/../view/domain_not_configured.phtml',
        ],
    ],
];
