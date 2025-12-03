<?php
// src/Application/Services/BsiGenerationService.php

declare(strict_types=1);

namespace App\Application\Services;

use App\Infrastructure\Csv\CsvEmployeeReader;
use App\Infrastructure\Excel\BsiExcelGenerator;
use ZipArchive;

class BsiGenerationService
{
    public function __construct(
        private CsvEmployeeReader $reader,
        private BsiExcelGenerator $generator,
        private string $projectRoot,
    ) {
    }

    /**
     * @param array $files structure $_FILES
     * @param int   $campaignYear
     *
     * @return array{
     *   totalEmployees:int,
     *   forfaitJoursCount:int,
     *   filesGenerated:int,
     *   downloadUrl:string
     * }
     */
    public function generate(array $files, int $campaignYear): array
    {
        // Répertoire public (docroot)
        $publicDir = $this->projectRoot . '/public';

        // Où déposer le ZIP téléchargeable (par défaut "RESULT" sous /public)
        $outputBase = getenv('OUTPUT_PATH') ?: 'RESULT';
        $outputDir  = $publicDir . '/' . trim($outputBase, '/');

        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            throw new \RuntimeException("Impossible de créer le répertoire de sortie : {$outputDir}");
        }

        // Répertoire de travail temporaire
        $tmpBase = $this->projectRoot . '/storage/tmp';
        if (!is_dir($tmpBase) && !mkdir($tmpBase, 0775, true) && !is_dir($tmpBase)) {
            throw new \RuntimeException("Impossible de créer le répertoire de travail : {$tmpBase}");
        }

        $runDir = $tmpBase . '/run_' . date('Ymd_His');
        if (!mkdir($runDir, 0775, true) && !is_dir($runDir)) {
            throw new \RuntimeException("Impossible de créer le répertoire de travail : {$runDir}");
        }

        // Sauvegarde des fichiers uploadés dans le runDir
        $moneyPath = $this->moveUploadedFile($files['bsi_money'], $runDir, 'bsi_money');
        $joursPath = $this->moveUploadedFile($files['bsi_jours'], $runDir, 'bsi_jours');

        $descriptionPaths = $this->moveMultipleUploadedFiles(
            $files['bsi_description'],
            $runDir,
            'bsi_description'
        );

        if (empty($descriptionPaths)) {
            throw new \RuntimeException("Aucun fichier de description collaborateurs n'a été reçu.");
        }

        // Lecture des données consolidées (équivalent CsvReader Python)
        $employeeData = $this->reader->readAll(
            $moneyPath,
            $joursPath,
            $descriptionPaths
        );

        if (empty($employeeData)) {
            throw new \RuntimeException("Aucun collaborateur détecté dans les fichiers fournis.");
        }

        // Répertoire de sortie des Excel pour ce run
        $excelDir = $runDir . '/excel';
        if (!mkdir($excelDir, 0775, true) && !is_dir($excelDir)) {
            throw new \RuntimeException("Impossible de créer le répertoire Excel : {$excelDir}");
        }

        // Génération des fichiers Excel (un par collaborateur)
        $stats = $this->generator->generateAll($employeeData, $excelDir, $campaignYear);

        // Création du ZIP à exposer dans /public/RESULT
        $zipName = 'BSI_' . $campaignYear . '_' . date('Ymd_His') . '.zip';
        $zipPath = $outputDir . '/' . $zipName;

        $this->zipDirectory($excelDir, $zipPath);

        $downloadUrl = '/' . trim($outputBase, '/') . '/' . $zipName;

        return [
            'totalEmployees'    => $stats['totalEmployees'] ?? 0,
            'forfaitJoursCount' => $stats['forfaitJoursCount'] ?? 0,
            'filesGenerated'    => $stats['filesGenerated'] ?? 0,
            'downloadUrl'       => $downloadUrl,
        ];
    }

    /**
     * Déplace un fichier uploadé simple (type="file")
     */
    private function moveUploadedFile(array $file, string $targetDir, string $prefix): string
    {
        if (!isset($file['tmp_name'], $file['name'], $file['error'])) {
            throw new \RuntimeException("Structure de fichier invalide pour {$prefix}.");
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException("Erreur d'upload pour {$prefix} (code {$file['error']}).");
        }

        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $safeName  = $prefix . '.' . ($extension ?: 'dat');
        $destPath  = $targetDir . '/' . $safeName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            // En env Docker/CLI, move_uploaded_file peut parfois échouer, on tente un copy
            if (!copy($file['tmp_name'], $destPath)) {
                throw new \RuntimeException("Impossible de déplacer le fichier {$prefix}.");
            }
        }

        return $destPath;
    }

    /**
     * Déplace un input multiple (bsi_description[])
     *
     * @return string[]
     */
    private function moveMultipleUploadedFiles(array $files, string $targetDir, string $prefix): array
    {
        $paths = [];

        if (
            !isset($files['tmp_name'], $files['name'], $files['error']) ||
            !is_array($files['tmp_name'])
        ) {
            return $paths;
        }

        foreach ($files['tmp_name'] as $idx => $tmpName) {
            $error = $files['error'][$idx] ?? UPLOAD_ERR_OK;
            $name  = $files['name'][$idx] ?? ($prefix . '_' . $idx);

            if ($error !== UPLOAD_ERR_OK || !$tmpName) {
                continue;
            }

            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $safeName  = $prefix . '_' . $idx . '.' . ($extension ?: 'dat');
            $destPath  = $targetDir . '/' . $safeName;

            if (!move_uploaded_file($tmpName, $destPath)) {
                if (!copy($tmpName, $destPath)) {
                    continue;
                }
            }

            $paths[] = $destPath;
        }

        return $paths;
    }

    /**
     * Zippe un répertoire récursivement
     */
    private function zipDirectory(string $sourceDir, string $zipPath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Impossible de créer le fichier ZIP : {$zipPath}");
        }

        $sourceDirReal = realpath($sourceDir);
        if ($sourceDirReal === false) {
            $zip->close();
            throw new \RuntimeException("Répertoire source invalide pour ZIP : {$sourceDir}");
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDirReal, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if ($fileInfo->isDir()) {
                continue;
            }

            $filePath = $fileInfo->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $relativePath = ltrim(str_replace($sourceDirReal, '', $filePath), DIRECTORY_SEPARATOR);
            $zip->addFile($filePath, $relativePath);
        }

        $zip->close();
    }
}
