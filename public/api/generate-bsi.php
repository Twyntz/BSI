<?php
// public/api/generate-bsi.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// Debug (dev seulement) : afficher toutes les erreurs dans le JSON
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

use App\Application\Services\BsiGenerationService;
use App\Infrastructure\Csv\CsvEmployeeReader;
use App\Infrastructure\Excel\BsiExcelGenerator;
use Dotenv\Dotenv;

try {
    $root = dirname(__DIR__, 2); // racine du projet (là où est composer.json)
    require $root . '/vendor/autoload.php';

    // Chargement du .env si présent
    if (file_exists($root . '/.env')) {
        $dotenv = Dotenv::createImmutable($root);
        $dotenv->load();
    }

    // Validation minimale des fichiers côté PHP
    if (
        empty($_FILES['bsi_money']['tmp_name']) ||
        empty($_FILES['bsi_jours']['tmp_name']) ||
        empty($_FILES['bsi_description']['tmp_name'])
    ) {
        throw new RuntimeException("Tous les fichiers (BSI Money, BSI Jours, descriptions) sont requis.");
    }

    $campaignYear = isset($_POST['campaign_year']) && $_POST['campaign_year'] !== ''
        ? (int)$_POST['campaign_year']
        : (int)date('Y');

    // Chemins des templates depuis le .env (ou valeurs par défaut)
    $templateStdRel = getenv('PATH_TEMPLATE_XLSX') ?: 'storage/templates/excel/TemplateBsi.xlsx';
    $templateFJRel  = getenv('PATH_TEMPLATE_FJ_XLSX') ?: 'storage/templates/excel/TemplateBsiFJ.xlsx';

    $templateStd = $root . DIRECTORY_SEPARATOR . $templateStdRel;
    $templateFJ  = $root . DIRECTORY_SEPARATOR . $templateFJRel;

    $service = new BsiGenerationService(
        new CsvEmployeeReader(),
        new BsiExcelGenerator($templateStd, $templateFJ),
        $root
    );

    $result = $service->generate($_FILES, $campaignYear);

    echo json_encode(['success' => true] + $result);
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
        // 'trace'   => $e->getTraceAsString(), // tu peux décommenter pour toi
    ]);
}
