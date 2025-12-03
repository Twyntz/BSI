<?php
// src/Application/Services/BsiGenerationService.php

declare(strict_types=1);

namespace App\Application\Services;

use App\Infrastructure\Csv\CsvEmployeeReader;
use App\Infrastructure\Pdf\BsiPdfGenerator; // <-- On utilise le générateur PDF
use ZipArchive;

class BsiGenerationService
{
    public function __construct(
        private CsvEmployeeReader $reader,
        // On retire BsiExcelGenerator du constructeur
        private string $projectRoot,
    ) {
    }

    /**
     * @param array $files structure $_FILES
     * @param int   $campaignYear
     *
     * @return array{
     * totalEmployees:int,
     * forfaitJoursCount:int,
     * filesGenerated:int,
     * downloadUrl:string
     * }
     */
    public function generate(array $files, int $campaignYear): array
    {
        // 1. Configuration des chemins (inchangé)
        $publicDir = $this->projectRoot . '/public';
        $outputBase = getenv('OUTPUT_PATH') ?: 'RESULT';
        $outputDir  = $publicDir . '/' . trim($outputBase, '/');

        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            throw new \RuntimeException("Impossible de créer le répertoire de sortie : {$outputDir}");
        }

        // Répertoire de travail temporaire pour ce run spécifique
        $tmpBase = $this->projectRoot . '/storage/tmp';
        if (!is_dir($tmpBase) && !mkdir($tmpBase, 0775, true) && !is_dir($tmpBase)) {
            throw new \RuntimeException("Impossible de créer le répertoire de travail : {$tmpBase}");
        }

        $runDir = $tmpBase . '/run_' . date('Ymd_His');
        if (!mkdir($runDir, 0775, true) && !is_dir($runDir)) {
            throw new \RuntimeException("Impossible de créer le répertoire de run : {$runDir}");
        }

        // 2. Traitement des uploads (inchangé)
        $moneyPath = $this->moveUploadedFile($files['bsi_money'], $runDir, 'bsi_money');
        $joursPath = $this->moveUploadedFile($files['bsi_jours'], $runDir, 'bsi_jours');
        $descriptionPaths = $this->moveMultipleUploadedFiles($files['bsi_description'], $runDir, 'bsi_description');

        // 3. Lecture des données (inchangé)
        $employeeData = $this->reader->readAll($moneyPath, $joursPath, $descriptionPaths);

        // AJOUT TEMPORAIRE POUR DEBUG
        // Cela va créer un fichier log.txt dans storage/output avec tout le contenu des données lues
        file_put_contents($outputDir . '/debug_data.txt', print_r($employeeData, true));

        if (empty($employeeData)) {
            throw new \RuntimeException("Aucun collaborateur détecté dans les fichiers fournis.");
        }

        // 4. Préparation de la génération PDF (MODIFIÉ)
        
        // On crée un dossier 'pdf' au lieu de 'excel' dans le dossier temporaire
        $pdfDir = $runDir . '/pdf';
        if (!mkdir($pdfDir, 0775, true) && !is_dir($pdfDir)) {
            throw new \RuntimeException("Impossible de créer le répertoire PDF : {$pdfDir}");
        }

        // On instancie le générateur PDF en lui donnant ce dossier temporaire
        $pdfGenerator = new BsiPdfGenerator($pdfDir);

        $filesGenerated = 0;
        $forfaitJoursCount = 0;

        // Boucle sur chaque employé détecté
        foreach ($employeeData as $personName => $data) {
            // Stats : comptage Forfait Jours
            if (($data['forfait_jours'] ?? false) === true) {
                $forfaitJoursCount++;
            }

            try {
                // Génération du PDF individuel
                $pdfGenerator->generateRealBsi($data, $campaignYear);
                $filesGenerated++;
            } catch (\Exception $e) {
                // Optionnel : on pourrait logger l'erreur mais continuer pour les autres
                // error_log("Erreur pour $personName : " . $e->getMessage());
            }
        }

        // 5. Création du ZIP final (MODIFIÉ : cible le dossier pdf)
        $zipName = 'BSI_Bundle_' . $campaignYear . '_' . date('Ymd_His') . '.zip';
        $zipPath = $outputDir . '/' . $zipName;

        $this->zipDirectory($pdfDir, $zipPath);

        // Nettoyage (Optionnel : supprimer $runDir ici pour gagner de la place)

        $downloadUrl = '/' . trim($outputBase, '/') . '/' . $zipName;

        return [
            'totalEmployees'    => count($employeeData),
            'forfaitJoursCount' => $forfaitJoursCount,
            'filesGenerated'    => $filesGenerated,
            'downloadUrl'       => $downloadUrl,
        ];
    }

    // --- Les méthodes privées (moveUploadedFile, zipDirectory, etc.) restent inchangées ---
    // (Assurez-vous de garder les méthodes privées existantes du fichier original en bas de classe)
    
    private function moveUploadedFile(array $file, string $targetDir, string $prefix): string
    {
        if (!isset($file['tmp_name'], $file['name'], $file['error'])) throw new \RuntimeException("Fichier invalide {$prefix}");
        if ($file['error'] !== UPLOAD_ERR_OK) throw new \RuntimeException("Erreur upload {$prefix}");
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $dest = $targetDir . '/' . $prefix . '.' . ($ext ?: 'dat');
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            if (!copy($file['tmp_name'], $dest)) throw new \RuntimeException("Echec déplacement {$prefix}");
        }
        return $dest;
    }

    private function moveMultipleUploadedFiles(array $files, string $targetDir, string $prefix): array
    {
        $paths = [];
        if (!isset($files['tmp_name']) || !is_array($files['tmp_name'])) return $paths;
        foreach ($files['tmp_name'] as $idx => $tmp) {
            if (($files['error'][$idx] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !$tmp) continue;
            $ext = pathinfo($files['name'][$idx] ?? '', PATHINFO_EXTENSION);
            $dest = $targetDir . '/' . $prefix . '_' . $idx . '.' . ($ext ?: 'dat');
            if (move_uploaded_file($tmp, $dest) || copy($tmp, $dest)) $paths[] = $dest;
        }
        return $paths;
    }

    private function zipDirectory(string $sourceDir, string $zipPath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Impossible de créer le ZIP : {$zipPath}");
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(realpath($sourceDir), \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) continue;
            $zip->addFile($file->getRealPath(), $file->getFilename()); // Juste le nom de fichier pour un zip plat
        }
        $zip->close();
    }
}