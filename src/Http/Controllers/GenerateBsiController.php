<?php
// src/Http/Controllers/GenerateBsiController.php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Services\BsiGenerationService;
use App\Infrastructure\Storage\LocalFilesystemStorage;

class GenerateBsiController
{
    public function __construct(
        private ?BsiGenerationService $service = null
    ) {
        if ($this->service === null) {
            $storage = new LocalFilesystemStorage(
                baseOutputDir: $_ENV['OUTPUT_PATH'] ?? (__DIR__ . '/../../../storage/output')
            );

            $this->service = new BsiGenerationService($storage);
        }
    }

    /**
     * @param array $post  $_POST
     * @param array $files $_FILES
     * @return array       Réponse JSON serialisable
     */
    public function handle(array $post, array $files): array
    {
        // Validation fichiers
        if (
            empty($files['bsi_money']['tmp_name']) ||
            empty($files['bsi_jours']['tmp_name']) ||
            empty($files['bsi_description']['tmp_name'])
        ) {
            return [
                'success' => false,
                'error'   => 'Les fichiers BSI Money, BSI Jours et descriptions sont requis.',
            ];
        }

        $campaignYear = isset($post['campaign_year']) && $post['campaign_year'] !== ''
            ? (int)$post['campaign_year']
            : (int)date('Y');

        // Normalisation des fichiers description (multi)
        $descriptionFiles = [];
        if (is_array($files['bsi_description']['tmp_name'])) {
            $count = count($files['bsi_description']['tmp_name']);
            for ($i = 0; $i < $count; $i++) {
                if ($files['bsi_description']['error'][$i] === UPLOAD_ERR_OK) {
                    $descriptionFiles[] = [
                        'tmp_name' => $files['bsi_description']['tmp_name'][$i],
                        'name'     => $files['bsi_description']['name'][$i],
                    ];
                }
            }
        } elseif ($files['bsi_description']['error'] === UPLOAD_ERR_OK) {
            $descriptionFiles[] = [
                'tmp_name' => $files['bsi_description']['tmp_name'],
                'name'     => $files['bsi_description']['name'],
            ];
        }

        if (empty($descriptionFiles)) {
            return [
                'success' => false,
                'error'   => 'Aucun fichier de descriptions valide n’a été fourni.',
            ];
        }

        // Appel du service métier
        $result = $this->service->generateBsiBundle(
            campaignYear: $campaignYear,
            bsiMoneyFile: [
                'tmp_name' => $files['bsi_money']['tmp_name'],
                'name'     => $files['bsi_money']['name'],
            ],
            bsiJoursFile: [
                'tmp_name' => $files['bsi_jours']['tmp_name'],
                'name'     => $files['bsi_jours']['name'],
            ],
            descriptionFiles: $descriptionFiles
        );

        return $result;
    }
}
