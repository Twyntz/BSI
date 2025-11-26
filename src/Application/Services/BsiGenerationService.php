<?php
// src/Application/Services/BsiGenerationService.php

declare(strict_types=1);

namespace App\Application\Services;

use App\Infrastructure\Storage\LocalFilesystemStorage;
use App\Infrastructure\Csv\CsvEmployeeReader;
use App\Infrastructure\Excel\BsiExcelGenerator;

class BsiGenerationService
{
    public function __construct(
        private LocalFilesystemStorage $storage
    ) {
    }

    /**
     * @param int   $campaignYear
     * @param array $bsiMoneyFile       ['tmp_name' => string, 'name' => string]
     * @param array $bsiJoursFile       ['tmp_name' => string, 'name' => string]
     * @param array $descriptionFiles   array<array{tmp_name:string,name:string}>
     *
     * @return array
     */
    public function generateBsiBundle(
        int $campaignYear,
        array $bsiMoneyFile,
        array $bsiJoursFile,
        array $descriptionFiles
    ): array {
        // 1. Créer un dossier de travail unique pour cette génération
        $workDir = $this->storage->createWorkDirectory($campaignYear);

        // 2. Copier les fichiers uploadés dans le dossier de travail
        $paths = $this->storage->storeUploadedFiles(
            workDir: $workDir,
            bsiMoneyFile: $bsiMoneyFile,
            bsiJoursFile: $bsiJoursFile,
            descriptionFiles: $descriptionFiles
        );

        // 3. Lire les CSV et construire la structure de données (équivalent CsvReader Python)
        $csvReader = new CsvEmployeeReader();
        $employeeData = $csvReader->readAll(
            bsiMoneyPath: $paths['bsi_money'],
            bsiJoursPath: $paths['bsi_jours'],
            descriptionPaths: $paths['descriptions']
        );

        // 4. Générer les BSI Excel (et éventuellement PDF) dans le dossier de travail
        $excelGenerator = new BsiExcelGenerator(
            templateStandardPath: $_ENV['PATH_TEMPLATE_XLSX'] ?? (dirname(__DIR__, 3) . '/storage/templates/excel/TemplateBsi.xlsx'),
            templateForfaitJoursPath: $_ENV['PATH_TEMPLATE_FJ_XLSX'] ?? (dirname(__DIR__, 3) . '/storage/templates/excel/TemplateBsiFJ.xlsx')
        );

        $generationResult = $excelGenerator->generateAll(
            employeeData: $employeeData,
            outputDir: $workDir,
            campaignYear: $campaignYear
        );

        // 5. Créer un zip du dossier de travail
        $zipPath = $this->storage->createZipFromDirectory(
            directory: $workDir,
            campaignYear: $campaignYear
        );

        // 6. Construire une URL de téléchargement (relative pour le front)
        $downloadUrl = $this->storage->getPublicUrlForPath($zipPath);

        return [
            'success'          => true,
            'totalEmployees'   => $generationResult['totalEmployees'] ?? null,
            'forfaitJoursCount'=> $generationResult['forfaitJoursCount'] ?? null,
            'filesGenerated'   => $generationResult['filesGenerated'] ?? null,
            'downloadUrl'      => $downloadUrl,
        ];
    }
}
