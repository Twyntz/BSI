<?php
// public/api/generate-bsi.php

declare(strict_types=1);

use App\Http\Controllers\GenerateBsiController;

require_once __DIR__ . '/../../vendor/autoload.php';

// Si tu utilises dotenv :
$envPath = dirname(__DIR__, 2) . '/.env';
if (file_exists($envPath)) {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2));
    $dotenv->load();
}

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error'   => 'Méthode non autorisée (POST attendu).',
        ]);
        exit;
    }

    $controller = new GenerateBsiController();
    $response = $controller->handle($_POST, $_FILES);

    echo json_encode($response);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Erreur interne du serveur.',
        'details' => $e->getMessage(),
    ]);
}
