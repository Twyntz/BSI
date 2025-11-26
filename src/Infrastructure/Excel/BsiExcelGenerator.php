<?php
// src/Infrastructure/Excel/BsiExcelGenerator.php

declare(strict_types=1);

namespace App\Infrastructure\Excel;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment as XlAlignment;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

class BsiExcelGenerator
{
    public function __construct(
        private string $templateStandardPath,
        private string $templateForfaitJoursPath
    ) {
    }

    /**
     * Génère tous les BSI Excel (un fichier par collaborateur).
     *
     * @param array  $employeeData structure $employeeData[$person] venant de CsvEmployeeReader
     * @param string $outputDir    répertoire de sortie
     * @param int    $campaignYear année de campagne (non utilisé directement ici mais dispo si besoin)
     *
     * @return array{totalEmployees:int,forfaitJoursCount:int,filesGenerated:int}
     */
    public function generateAll(array $employeeData, string $outputDir, int $campaignYear): array
    {
        if (!is_dir($outputDir)) {
            if (!mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
                throw new \RuntimeException("Impossible de créer le répertoire de sortie : {$outputDir}");
            }
        }

        $totalEmployees = 0;
        $forfaitJoursCount = 0;
        $filesGenerated = 0;

        foreach ($employeeData as $personKey => $personData) {
            $totalEmployees++;

            $isForfaitJours = !empty($personData['forfait_jours']);
            if ($isForfaitJours) {
                $forfaitJoursCount++;
            }

            $templatePath = $this->templateStandardPath;
            if ($isForfaitJours && is_file($this->templateForfaitJoursPath)) {
                $templatePath = $this->templateForfaitJoursPath;
            }

            if (!is_file($templatePath)) {
                throw new \RuntimeException("Template Excel introuvable : {$templatePath}");
            }

            // 1. Charger le template
            $spreadsheet = IOFactory::load($templatePath);
            $sheet = $spreadsheet->getActiveSheet();

            // 2. Définir la "current_person" implicite et remplir les données
            $this->addRemunerationBrute($sheet, $personData);
            $this->addChargesAvantagesSociales($sheet, $personData);
            $this->addDescription($sheet, $personData);

            // 3. Mise en page (paysage + centré horizontalement)
            $pageSetup = $sheet->getPageSetup();
            $pageSetup->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
            $pageSetup->setHorizontalCentered(true);

            // 4. Sauvegarde du fichier
            $officialName = $personData['official_name'] ?? $personKey;
            $safeName = $this->sanitizeFileName("BSI_" . $officialName) . ".xlsx";
            $outputPath = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safeName;

            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save($outputPath);

            $filesGenerated++;
        }

        return [
            'totalEmployees'    => $totalEmployees,
            'forfaitJoursCount' => $forfaitJoursCount,
            'filesGenerated'    => $filesGenerated,
        ];
    }

    // ---------------------------------------------------------------------
    // Remplissage des valeurs de rémunération brute (add_remuneration_brute)
    // ---------------------------------------------------------------------

    /**
     * @param Worksheet $sheet
     * @param array     $personData
     */
    private function addRemunerationBrute(Worksheet $sheet, array $personData): void
    {
        // Positions (reprend les constantes de XlsCreator)
        $salairePos        = 'D16';
        $primePos          = 'D18';
        $interessementPos  = 'D19';
        $heuresMajPos      = 'D17';
        $netImposablePos   = 'D42';
        $acompteSalairePos = 'D23';
        $fraisTransportPos = 'B28';
        $nbJoursTravPos    = 'H7';

        // Petite fonction pour écrire un nombre aligné
        $writeNumber = function (string $cell, float $value, string $horizontal, string $vertical) use ($sheet): void {
            $sheet->setCellValue($cell, $value);
            $sheet->getStyle($cell)->getAlignment()
                ->setHorizontal($horizontal)
                ->setVertical($vertical);
        };

        // salaire de base
        $salaire = (float)($personData['Salaire de base']['salarial'] ?? 0);
        $writeNumber($salairePos, round($salaire, 2), XlAlignment::HORIZONTAL_LEFT, XlAlignment::VERTICAL_CENTER);

        // sous-total primes
        $prime = (float)($personData['Sous-total Primes']['salarial'] ?? 0);
        $writeNumber($primePos, round($prime, 2), XlAlignment::HORIZONTAL_LEFT, XlAlignment::VERTICAL_CENTER);

        // intéressement
        $interessement = (float)($personData['INTERESSEMENT']['salarial'] ?? 0);
        $writeNumber($interessementPos, round($interessement, 2), XlAlignment::HORIZONTAL_LEFT, XlAlignment::VERTICAL_CENTER);

        // heures mensuelles majorées
        $heuresMaj = (float)($personData['Heures mensuelles majorées']['salarial'] ?? 0);
        $writeNumber($heuresMajPos, round($heuresMaj, 2), XlAlignment::HORIZONTAL_LEFT, XlAlignment::VERTICAL_CENTER);

        // net imposable
        $netImposable = (float)($personData['Net imposable']['salarial'] ?? 0);
        $writeNumber($netImposablePos, round($netImposable, 2), XlAlignment::HORIZONTAL_CENTER, XlAlignment::VERTICAL_CENTER);

        // acompte sur salaire
        $acompte = (float)($personData['Acomptes']['salarial'] ?? 0);
        $writeNumber($acompteSalairePos, round($acompte, 2), XlAlignment::HORIZONTAL_CENTER, XlAlignment::VERTICAL_CENTER);

        // frais de transport personnel non soumis
        $fraisTransport = (float)($personData['Frais de transport personnel non soumis']['salarial'] ?? 0);
        $writeNumber($fraisTransportPos, round($fraisTransport, 2), XlAlignment::HORIZONTAL_CENTER, XlAlignment::VERTICAL_CENTER);

        // nb jours travaillés
        if (isset($personData['nb jours travaillés'])) {
            $raw = (string)$personData['nb jours travaillés'];
            $value = (float)str_replace(' ', '', $raw);
            $label = sprintf('%.2f jours', round($value, 2));
            $sheet->setCellValue($nbJoursTravPos, $label);
        } else {
            $sheet->setCellValue($nbJoursTravPos, 'no data');
        }
        $sheet->getStyle($nbJoursTravPos)->getAlignment()
            ->setHorizontal(XlAlignment::HORIZONTAL_CENTER)
            ->setVertical(XlAlignment::VERTICAL_CENTER);

        // total D21 = somme D16:D19
        $sheet->setCellValue('D21', '=SUM(D16:D19)');
        $sheet->getStyle('D21')->getAlignment()
            ->setHorizontal(XlAlignment::HORIZONTAL_CENTER)
            ->setVertical(XlAlignment::VERTICAL_CENTER);

        // C13 = somme D16:D19
        $sheet->setCellValue('C13', '=SUM(D16:D19)');
        $sheet->getStyle('C13')->getAlignment()
            ->setHorizontal(XlAlignment::HORIZONTAL_CENTER)
            ->setVertical(XlAlignment::VERTICAL_CENTER);
    }

    // ---------------------------------------------------------------------
    // Charges et avantages sociaux (add_charges_avantages_sociales)
    // ---------------------------------------------------------------------

    /**
     * @param Worksheet $sheet
     * @param array     $personData
     */
    private function addChargesAvantagesSociales(Worksheet $sheet, array $personData): void
    {
        $maladiePPos    = 'F32';
        $maladieSPos    = 'D32';
        $retraitePPos   = 'F33';
        $retraiteSPos   = 'D33';
        $prevoyancePPos = 'F34';
        $prevoyanceSPos = 'D34';
        $chomagePPos    = 'F35';
        $chomageSPos    = 'D35';
        $mutuellePPos   = 'F36';
        $mutuelleSPos   = 'D36';

        $writeNumberCenter = function (string $cell, float $value) use ($sheet): void {
            $sheet->setCellValue($cell, $value);
            $sheet->getStyle($cell)->getAlignment()
                ->setHorizontal(XlAlignment::HORIZONTAL_CENTER)
                ->setVertical(XlAlignment::VERTICAL_CENTER);
        };

        // maladie
        $maladieP = (float)($personData['maladie']['patronal'] ?? 0);
        $maladieS = (float)($personData['maladie']['salarial'] ?? 0);
        $writeNumberCenter($maladiePPos, $maladieP);
        $writeNumberCenter($maladieSPos, $maladieS);

        // retraite
        $retraiteP = (float)($personData['retraite']['patronal'] ?? 0);
        $retraiteS = (float)($personData['retraite']['salarial'] ?? 0);
        $writeNumberCenter($retraitePPos, $retraiteP);
        $writeNumberCenter($retraiteSPos, $retraiteS);

        // chômage
        $chomageP = (float)($personData['chomage']['patronal'] ?? 0);
        $chomageS = (float)($personData['chomage']['salarial'] ?? 0);
        $writeNumberCenter($chomagePPos, $chomageP);
        $writeNumberCenter($chomageSPos, $chomageS);

        // mutuelle
        $mutuelleP = (float)($personData['mutuelle']['patronal'] ?? 0);
        $mutuelleS = (float)($personData['mutuelle']['salarial'] ?? 0);
        $writeNumberCenter($mutuellePPos, $mutuelleP);
        $writeNumberCenter($mutuelleSPos, $mutuelleS);

        // prévoyance
        $prevoyanceP = (float)($personData['prevoyance']['patronal'] ?? 0);
        $prevoyanceS = (float)($personData['prevoyance']['salarial'] ?? 0);
        $writeNumberCenter($prevoyancePPos, $prevoyanceP);
        $writeNumberCenter($prevoyanceSPos, $prevoyanceS);

        // totaux D37 et F37
        $sheet->setCellValue('D37', '=SUM(D32:D36)');
        $sheet->getStyle('D37')->getAlignment()
            ->setHorizontal(XlAlignment::HORIZONTAL_CENTER)
            ->setVertical(XlAlignment::VERTICAL_CENTER);

        $sheet->setCellValue('F37', '=SUM(F32:F36)');
        $sheet->getStyle('F37')->getAlignment()
            ->setHorizontal(XlAlignment::HORIZONTAL_CENTER)
            ->setVertical(XlAlignment::VERTICAL_CENTER);
    }

    // ---------------------------------------------------------------------
    // Description (nom, poste, date d’arrivée, type contrat, ancienneté)
    // ---------------------------------------------------------------------

    /**
     * @param Worksheet $sheet
     * @param array     $personData
     */
    private function addDescription(Worksheet $sheet, array $personData): void
    {
        $namePos        = 'A3';
        $postePos       = 'A4';
        $dateArriveePos = 'D7';
        $typeContratPos = 'F7';
        $anciennetePos  = 'B7';

        $horizontal = XlAlignment::HORIZONTAL_LEFT;
        $vertical   = XlAlignment::VERTICAL_CENTER;

        try {
            $nom    = $personData['nom'] ?? '';
            $prenom = $personData['prenom'] ?? '';

            // NAME
            if ($nom === '' || $prenom === '') {
                $sheet->setCellValue($namePos, 'no data');
                $sheet->getStyle($namePos)->getAlignment()
                    ->setHorizontal(XlAlignment::HORIZONTAL_CENTER)
                    ->setVertical($vertical);
                return;
            }

            $fullName = $nom . ' ' . $prenom;
            $sheet->setCellValue($namePos, $fullName);
            $sheet->getStyle($namePos)->getAlignment()
                ->setHorizontal(XlAlignment::HORIZONTAL_CENTER)
                ->setVertical($vertical);

            // POSTE
            $poste = $personData['poste'] ?? '';
            $sheet->setCellValue($postePos, $poste);
            $sheet->getStyle($postePos)->getAlignment()
                ->setHorizontal(XlAlignment::HORIZONTAL_CENTER)
                ->setVertical($vertical);

            // date d'arrivée
            $dateArrivee = $personData['date_arrivee'] ?? '';
            $sheet->setCellValue($dateArriveePos, $dateArrivee);
            $sheet->getStyle($dateArriveePos)->getAlignment()
                ->setHorizontal($horizontal)
                ->setVertical($vertical);

            // type de contrat
            $typeContrat = $personData['type_contrat'] ?? '';
            $sheet->setCellValue($typeContratPos, $typeContrat);
            $sheet->getStyle($typeContratPos)->getAlignment()
                ->setHorizontal($horizontal)
                ->setVertical($vertical);

            // ancienneté (calcul à partir de la date)
            $ancienneteRaw = $personData['anciennete'] ?? '';
            if ($ancienneteRaw === '' || $ancienneteRaw === null) {
                $sheet->setCellValue($anciennetePos, 'no data');
                $sheet->getStyle($anciennetePos)->getAlignment()
                    ->setHorizontal($horizontal)
                    ->setVertical($vertical);
            } else {
                $dateAnciennete = \DateTime::createFromFormat('d/m/Y', $ancienneteRaw);
                if (!$dateAnciennete) {
                    $sheet->setCellValue($anciennetePos, 'no data');
                    $sheet->getStyle($anciennetePos)->getAlignment()
                        ->setHorizontal($horizontal)
                        ->setVertical($vertical);
                } else {
                    $now = new \DateTimeImmutable();
                    $years = $now->format('Y') - $dateAnciennete->format('Y');

                    $label = $years > 0
                        ? sprintf('%d an(s)', $years)
                        : "moins d'un an";

                    $sheet->setCellValue($anciennetePos, $label);
                    $sheet->getStyle($anciennetePos)->getAlignment()
                        ->setHorizontal($horizontal)
                        ->setVertical($vertical);
                }
            }
        } catch (\Throwable $e) {
            // En cas de souci, on logguérait éventuellement, mais on évite de casser toute la génération
            // error_log('Erreur addDescription BSI : ' . $e->getMessage());
        }
    }

    // ---------------------------------------------------------------------
    // Utils
    // ---------------------------------------------------------------------

    /**
     * Nettoie un nom de fichier (enlève caractères dangereux)
     */
    private function sanitizeFileName(string $filename): string
    {
        // remplace les caractères interdits par un underscore
        $sanitized = preg_replace('/[^\w\-\.]+/u', '_', $filename) ?? $filename;
        return trim($sanitized, '_');
    }
}
