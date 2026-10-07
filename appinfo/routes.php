<?php
declare(strict_types=1);

return ['routes' => [
    ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
    ['name' => 'share#index', 'url' => '/shares', 'verb' => 'GET'],
    ['name' => 'share#preview', 'url' => '/revoke/preview', 'verb' => 'POST'],
    ['name' => 'share#revoke', 'url' => '/revoke', 'verb' => 'POST'],
    ['name' => 'share#destroy', 'url' => '/shares/{id}', 'verb' => 'DELETE'],
]];
