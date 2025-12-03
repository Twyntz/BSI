<?php
// public/api/test-bsi.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

use App\Infrastructure\Pdf\BsiPdfGenerator;
use Dotenv\Dotenv;

try {
    $root = dirname(__DIR__, 2); // racine du projet
    require $root . '/vendor/autoload.php';

    // Chargement du .env
    if (file_exists($root . '/.env')) {
        $dotenv = Dotenv::createImmutable($root);
        $dotenv->load();
    }

    $type = $_GET['type'] ?? $_POST['type'] ?? null;
    if ($type === null) {
        throw new RuntimeException("Paramètre 'type' requis (forfait_heure ou forfait_jour).");
    }

    if (!in_array($type, ['forfait_heure', 'forfait_jour'], true)) {
        throw new RuntimeException("Type de test invalide : {$type}. Attendu : forfait_heure ou forfait_jour.");
    }

    $campaignYear = (int)($_POST['campaign_year'] ?? date('Y'));

    // Répertoire public
    $publicDir = $root . '/public';

    // OUTPUT_PATH = sous-dossier de public pour les fichiers générés
    $outputBase = getenv('OUTPUT_PATH') ?: 'RESULT';
    $outputDir  = $publicDir . '/' . trim($outputBase, '/');

    if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
        throw new RuntimeException("Impossible de créer le répertoire de sortie : {$outputDir}");
    }

    $generator = new BsiPdfGenerator($outputDir);
    $filename  = $generator->generateTestBsi($type, $campaignYear);

    $downloadUrl = '/' . trim($outputBase, '/') . '/' . $filename;

    echo json_encode([
        'success'     => true,
        'downloadUrl' => $downloadUrl,
    ]);
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
        // 'trace' => $e->getTraceAsString(),
    ]);
}
