<?php
declare(strict_types=1);

use App\Controllers\InstallController;
use App\Core\Router;

return static function (Router $r): void {
    $r->get('/install', [InstallController::class, 'show'], ['auth' => false]);
    $r->post('/install', [InstallController::class, 'install'], ['auth' => false]);
};
