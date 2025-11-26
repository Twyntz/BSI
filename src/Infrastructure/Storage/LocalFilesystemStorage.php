<?php
// src/Infrastructure/Storage/LocalFilesystemStorage.php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

class LocalFilesystemStorage
{
    public function __construct(
        private string $baseOutputDir
    ) {
        if (!is_dir($this->baseOutputDir)) {
            mkdir($this->baseOutputDir, 0775, true);
        }
    }

    public function createWorkDirectory(int $campaignYear): string
    {
        $timestamp = date('Ymd_His');
        $dir = rtrim($this->baseOutputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "bsi_{$campaignYear}_{$timestamp}";

        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Impossible de créer le répertoire de travail : {$dir}");
        }

        return realpath($dir) ?: $dir;
    }

    /**
     * @param string $workDir
     * @param array  $bsiMoneyFile
     * @param array  $bsiJoursFile
     * @param array  $descriptionFiles
     * @return array{bsi_money:string,bsi_jours:string,descriptions:string[]}
     */
    public function storeUploadedFiles(
        string $workDir,
        array $bsiMoneyFile,
        array $bsiJoursFile,
        array $descriptionFiles
    ): array {
        $paths = [
            'bsi_money'    => $this->moveUploadedFile($bsiMoneyFile, $workDir, 'bsi_money.csv'),
            'bsi_jours'    => $this->moveUploadedFile($bsiJoursFile, $workDir, 'bsi_jours.csv'),
            'descriptions' => [],
        ];

        $descDir = $workDir . DIRECTORY_SEPARATOR . 'descriptions';
        if (!is_dir($descDir)) {
            mkdir($descDir, 0775, true);
        }

        foreach ($descriptionFiles as $index => $file) {
            $targetName = sprintf('description_%02d.csv', $index + 1);
            $paths['descriptions'][] = $this->moveUploadedFile($file, $descDir, $targetName);
        }

        return $paths;
    }

    private function moveUploadedFile(array $file, string $targetDir, string $targetName): string
    {
        $targetPath = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $targetName;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            // Si move_uploaded_file ne marche pas (ex: environnement CLI), fallback à copy()
            if (!copy($file['tmp_name'], $targetPath)) {
                throw new \RuntimeException('Impossible de déplacer le fichier uploadé : ' . $file['name']);
            }
        }

        return realpath($targetPath) ?: $targetPath;
    }

    public function createZipFromDirectory(string $directory, int $campaignYear): string
    {
        $zipFilename = rtrim($this->baseOutputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "bsi_{$campaignYear}_bundle.zip";

        $zip = new \ZipArchive();
        if ($zip->open($zipFilename, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Impossible de créer le fichier zip.');
        }

        $dirReal = realpath($directory);
        if ($dirReal === false) {
            throw new \RuntimeException('Répertoire de travail introuvable.');
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dirReal, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $fileInfo) {
            $filePath = $fileInfo->getRealPath();
            if ($filePath === false || $fileInfo->isDir()) {
                continue;
            }
            $relativePath = substr($filePath, strlen($dirReal) + 1);
            $zip->addFile($filePath, $relativePath);
        }

        $zip->close();

        return realpath($zipFilename) ?: $zipFilename;
    }

    /**
     * Transforme un chemin absolu vers une URL publique relative à /public
     */
    public function getPublicUrlForPath(string $absolutePath): string
    {
        // Exemple simple : on considère que baseOutputDir est sous public/RESULT/
        // et qu’on renvoie juste "RESULT/nom_du_zip.zip"
        $publicRoot = realpath(__DIR__ . '/../../../public');

        $real = realpath($absolutePath);
        if ($publicRoot && $real && str_starts_with($real, $publicRoot)) {
            return ltrim(str_replace($publicRoot, '', $real), DIRECTORY_SEPARATOR);
        }

        // Fallback : juste le nom du fichier
        return 'RESULT/' . basename($absolutePath);
    }
}
