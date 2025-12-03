<?php
// public/api/generate-bsi.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// Debug
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

use App\Application\Services\BsiGenerationService;
use App\Infrastructure\Csv\CsvEmployeeReader;
use Dotenv\Dotenv;

try {
    $root = dirname(__DIR__, 2); // racine du projet
    require $root . '/vendor/autoload.php';

    // Chargement du .env
    if (file_exists($root . '/.env')) {
        $dotenv = Dotenv::createImmutable($root);
        $dotenv->load();
    }

    // Validation minimale
    if (
        empty($_FILES['bsi_money']['tmp_name']) ||
        empty($_FILES['bsi_jours']['tmp_name']) ||
        empty($_FILES['bsi_description']['tmp_name'])
    ) {
        throw new RuntimeException("Tous les fichiers (Money, Jours, Descriptions) sont requis.");
    }

    $campaignYear = isset($_POST['campaign_year']) && $_POST['campaign_year'] !== ''
        ? (int)$_POST['campaign_year']
        : (int)date('Y');

    // Instanciation du Service simplifié
    // Note : Plus besoin de passer BsiExcelGenerator ici
    $service = new BsiGenerationService(
        new CsvEmployeeReader(),
        $root
    );

    // Lancement
    $result = $service->generate($_FILES, $campaignYear);

    echo json_encode(['success' => true] + $result);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
}