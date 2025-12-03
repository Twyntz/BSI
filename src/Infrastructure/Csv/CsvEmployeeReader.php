<?php
// src/Infrastructure/Csv/CsvEmployeeReader.php

declare(strict_types=1);

namespace App\Infrastructure\Csv;

use PhpOffice\PhpSpreadsheet\IOFactory;

class CsvEmployeeReader
{
    /** @var string[] */
    private array $persons = [];

    /** @var string[] */
    private array $officialNames = [];

    /** @var array<string,mixed> */
    private array $data = [];

    /** @var string[] */
    private array $retraite = [
        "Vieillesse déplafonnée",
        "Vieillesse plafonnée",
        "Retraite TU1",
        "Contribution d'Equilibre Général TU1",
        "Réduct. générale des cotisat. pat. retraite",
    ];

    /** @var string[] */
    private array $maladie = [
        "Maladie - maternité - invalidité - décès",
    ];

    /** @var string[] */
    private array $chomage = [
        "Assurance chômage TrA+TrB",
        "AGS",
    ];

    /** @var string[] */
    private array $prevoyance = [
        "Prévoyance supplémentaire non cadre TrA",
    ];

    /** @var string[] */
    private array $mutuelle = [
        "Frais de santé",
    ];

    /** @var string[] */
    private array $descriptionFields = [
        "nom",
        "prenom",
        "poste",
        "anciennete",
        "date_arrivee",
        "type_contrat",
    ];

    /** @var string[] */
    private array $listLibelleMoney = [];

    /** @var string[] */
    private array $forfaitJoursKeywords = [
        "RTT pris (j)",
        "RTT acquis (j)",
        "RTT et autres repos",
    ];

    public function __construct()
    {
        $this->listLibelleMoney = array_merge(
            [
                "Salaire de base",
                "Salaire Brut",
                "Sous-total Primes",
                "INTERESSEMENT",
                "Net imposable",
                "Acomptes",
                "Frais de transport personnel non soumis",
                "prevoyance",
                "mutuelle",
                "chomage",
                "maladie",
                "retraite",
                "Heures mensuelles majorées",
            ],
            $this->retraite,
            $this->maladie,
            $this->chomage,
            $this->mutuelle,
            $this->prevoyance,
        );
    }

    /**
     * Point d'entrée principal : équivalent de csv_bsi_read()
     *
     * Les fichiers peuvent être en CSV ou en Excel (.xlsx/.xls).
     *
     * @param string   $bsiMoneyPath
     * @param string   $bsiJoursPath
     * @param string[] $descriptionPaths
     *
     * @return array<string,mixed>  Structure $data[person] comme en Python
     */
    public function readAll(
        string $bsiMoneyPath,
        string $bsiJoursPath,
        array $descriptionPaths
    ): array {
        $this->data = [];
        $this->persons = [];
        $this->officialNames = [];

        // 1. Lecture du BSI Money
        $moneyRows = $this->readTableFile($bsiMoneyPath);
        if (count($moneyRows) === 0) {
            return [];
        }

        $this->extractNameBsiMoney($moneyRows);
        $this->createLibelle();
        $this->extractValueBsiMoney($moneyRows);

        // 2. Lecture du BSI Jours
        $joursRows = $this->readTableFile($bsiJoursPath);
        if (count($joursRows) > 0) {
            $this->extractValueJoursBsi($joursRows);
        }

        // 3. Lecture des descriptions collaborateurs (plusieurs fichiers)
        foreach ($descriptionPaths as $path) {
            $descRows = $this->readTableFile($path);
            if (count($descRows) === 0) {
                continue;
            }
            $this->extractValueBsiDescription($descRows);
        }

        // 4. Agrégation des montants (retraite, maladie, etc.)
        $this->sumBsiMoney();

        return $this->data;
    }

    // ---------------------------------------------------------------------
    // Utils : lecture CSV ou XLSX
    // ---------------------------------------------------------------------

    /**
     * Lit un fichier tabulaire :
     * - CSV (séparateur ';')
     * - ou Excel (.xlsx / .xls), 1ère feuille
     *
     * @return array<int,array<int,string>>
     */
    private function readTableFile(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Fichier introuvable : {$path}");
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($ext === 'csv') {
            return $this->readCsvFile($path);
        }

        // Sinon on passe par PhpSpreadsheet (xlsx, xls, ods…)
        return $this->readExcelFile($path);
    }

    /**
     * Lecture d'un CSV avec séparateur ';'
     *
     * @return array<int,array<int,string>>
     */
    private function readCsvFile(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Impossible d'ouvrir le fichier CSV : {$path}");
        }

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            $rows[] = array_map(static fn($v) => $v ?? '', $row);
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Lecture d'un fichier Excel (1ère feuille) en tableau de lignes/colonnes
     *
     * @return array<int,array<int,string>>
     */
    private function readExcelFile(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $rows = [];

        foreach ($sheet->getRowIterator() as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);

            $rowData = [];
            foreach ($cellIterator as $cell) {
                $value = $cell->getValue();
                $rowData[] = $value === null ? '' : (string)$value;
            }
            $rows[] = $rowData;
        }

        return $rows;
    }

    /**
     * Reproduit normaliser_chaine() de Python :
     * garde uniquement les lettres A-Z/a-z et les espaces (accents supprimés).
     */
    private function normaliserChaine(string $chaine): string
    {
        $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $chaine);
        if ($str === false) {
            $str = $chaine;
        }

        $str = preg_replace('/[^a-zA-Z ]+/', '', $str) ?? '';

        return trim($str);
    }

    // ---------------------------------------------------------------------
    // MONEY BSI
    // ---------------------------------------------------------------------

    /**
     * Équivalent de extract_name_bsi_money() : ligne 3 = liste des personnes
     *
     * @param array<int,array<int,string>> $rows
     */
    private function extractNameBsiMoney(array $rows): void
    {
        if (count($rows) < 3) {
            return;
        }

        $headerRow = $rows[2];
        $this->persons = [];
        $this->officialNames = [];

        foreach ($headerRow as $col) {
            if ($col !== '' && $col !== 'TOTAL') {
                $this->persons[] = $this->normaliserChaine($col);
                $this->officialNames[] = $col;
            }
        }
    }

    private function createLibelle(): void
    {
        foreach ($this->persons as $index => $person) {
            $officialName = $this->officialNames[$index] ?? $person;

            $this->data[$person] = [];
            $this->data[$person]['official_name'] = $officialName;

            foreach ($this->listLibelleMoney as $libelle) {
                $this->data[$person][$libelle] = [
                    'salarial' => 0,
                    'patronal' => 0,
                ];
            }

            foreach ($this->descriptionFields as $field) {
                $this->data[$person][$field] = 'no data';
            }

            $this->data[$person]['forfait_jours'] = false;
        }
    }

    /**
     * @param array<int,array<int,string>> $rows
     * @return array{codeIndex:int,libelleIndex:int,startValueIndex:int}
     */
    private function findHeaderBsiMoney(array $rows): array
    {
        $codeIndex = 0;
        $libelleIndex = 0;
        $startValueIndex = 0;

        foreach ($rows as $row) {
            foreach ($row as $idx => $col) {
                if (str_contains($col, 'Code')) {
                    $codeIndex = $idx;
                }
                if (str_contains($col, 'Libellé')) {
                    $libelleIndex = $idx;
                }
                if (str_contains($col, 'Base S.')) {
                    $startValueIndex = $idx;
                    return [
                        'codeIndex'       => $codeIndex,
                        'libelleIndex'    => $libelleIndex,
                        'startValueIndex' => $startValueIndex,
                    ];
                }
            }
        }

        throw new \RuntimeException("Impossible de trouver les colonnes 'Code', 'Libellé' et 'Base S.' dans BSI Money.");
    }

    /**
     * Équivalent de extract_value_bsi_money()
     *
     * @param array<int,array<int,string>> $rows
     */
    private function extractValueBsiMoney(array $rows): void
    {
        if (empty($rows) || empty($this->persons)) {
            return;
        }

        $headerIndexes = $this->findHeaderBsiMoney($rows);
        $libelleIndex = $headerIndexes['libelleIndex'];
        $startValueIndex = $headerIndexes['startValueIndex'];

        foreach ($rows as $row) {
            if (count($row) <= $libelleIndex) {
                continue;
            }

            $libelle = $row[$libelleIndex];
            $tempStartIndex = $startValueIndex;

            foreach ($this->persons as $person) {
                $baseIndex = $tempStartIndex;
                $salarialIndex = $tempStartIndex + 1;
                $patronalIndex = $tempStartIndex + 2;

                if (!array_key_exists($person, $this->data)) {
                    $this->data[$person] = [];
                }

                $baseS = $row[$baseIndex] ?? '';
                $salarial = $row[$salarialIndex] ?? '';
                $patronal = $row[$patronalIndex] ?? '';

                if (in_array($libelle, $this->forfaitJoursKeywords, true)) {
                    $hasValue = (trim($baseS) !== '' || trim($salarial) !== '' || trim($patronal) !== '');
                    if ($hasValue) {
                        $this->data[$person]['forfait_jours'] = true;
                    }
                }

                $this->getDataBsiMoney($person, $libelle, $baseS, $salarial, $patronal);

                $tempStartIndex += 3;
            }
        }
    }

    private function getDataBsiMoney(
        string $person,
        string $libelle,
        string $baseS,
        string $salarial,
        string $patronal
    ): void {
        if (!in_array($libelle, $this->listLibelleMoney, true)) {
            return;
        }

        if (!isset($this->data[$person][$libelle])) {
            $this->data[$person][$libelle] = [
                'salarial' => 0,
                'patronal' => 0,
            ];
        }

        $this->data[$person][$libelle]['salarial'] =
            ($salarial === '') ? '0' : str_replace(' ', '', $salarial);
        $this->data[$person][$libelle]['patronal'] =
            ($patronal === '') ? '0' : str_replace(' ', '', $patronal);
    }

    // ---------------------------------------------------------------------
    // JOURS BSI
    // ---------------------------------------------------------------------

    private function extractValueJoursBsi(array $rows): void
    {
        foreach ($rows as $row) {
            if (!isset($row[2], $row[3])) {
                continue;
            }

            $nom = $this->normaliserChaine($row[2]);
            $prenom = $this->normaliserChaine($row[3]);

            if ($nom === '' || $prenom === '') {
                continue;
            }

            foreach ($this->persons as $person) {
                if (
                    str_contains($person, $nom)
                    && str_contains($person, $prenom)
                    && $row[2] !== ''
                    && $row[3] !== ''
                ) {
                    $value = $row[6] ?? '0';
                    $realWorkingDayCount = str_replace(',', '.', $value);

                    if (!isset($this->data[$person]['nb jours travaillés'])) {
                        $this->data[$person]['nb jours travaillés'] = $realWorkingDayCount;
                    } else {
                        $current = (float)$this->data[$person]['nb jours travaillés'];
                        $final = $current + (float)$realWorkingDayCount;
                        $this->data[$person]['nb jours travaillés'] = (string)$final;
                    }

                    break;
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // DESCRIPTIONS BSI
    // ---------------------------------------------------------------------

    private function extractValueBsiDescription(array $rows): void
    {
        foreach ($rows as $row) {
            if (!isset($row[0], $row[1])) {
                continue;
            }

            $nom = $this->normaliserChaine($row[0]);
            $prenom = $this->normaliserChaine($row[1]);

            if ($nom === '' || $prenom === '') {
                continue;
            }

            foreach ($this->persons as $person) {
                if (
                    str_contains($person, $nom)
                    && str_contains($person, $prenom)
                    && $row[0] !== ''
                    && $row[1] !== ''
                ) {
                    foreach ($this->descriptionFields as $index => $field) {
                        if (
                            !array_key_exists($field, $this->data[$person]) ||
                            $this->data[$person][$field] === 'no data'
                        ) {
                            $this->data[$person][$field] = $row[$index] ?? '';
                        }
                    }
                    break;
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // AGRÉGATION DES COTISATIONS
    // ---------------------------------------------------------------------

    private function sumBsiMoney(): void
    {
        foreach ($this->persons as $person) {
            $retraiteP = 0.0;
            $maladieP = 0.0;
            $chomageP = 0.0;
            $mutuelleP = 0.0;
            $prevoyanceP = 0.0;

            $retraiteS = 0.0;
            $maladieS = 0.0;
            $chomageS = 0.0;
            $mutuelleS = 0.0;
            $prevoyanceS = 0.0;

            foreach ($this->data[$person] as $libelle => $value) {
                if (!is_array($value) || !isset($value['patronal'], $value['salarial'])) {
                    continue;
                }

                $patronal = round((float)$value['patronal'], 2);
                $salarial = round((float)$value['salarial'], 2);

                if (in_array($libelle, $this->retraite, true)) {
                    $retraiteP += $patronal;
                    $retraiteS += $salarial;
                }
                if (in_array($libelle, $this->maladie, true)) {
                    $maladieP += $patronal;
                    $maladieS += $salarial;
                }
                if (in_array($libelle, $this->chomage, true)) {
                    $chomageP += $patronal;
                    $chomageS += $salarial;
                }
                if (in_array($libelle, $this->mutuelle, true)) {
                    $mutuelleP += $patronal;
                    $mutuelleS += $salarial;
                }
                if (in_array($libelle, $this->prevoyance, true)) {
                    $prevoyanceP += $patronal;
                    $prevoyanceS += $salarial;
                }
            }

            $this->data[$person]['prevoyance']['patronal'] = $prevoyanceP;
            $this->data[$person]['prevoyance']['salarial'] = $prevoyanceS;
            $this->data[$person]['mutuelle']['patronal'] = $mutuelleP;
            $this->data[$person]['mutuelle']['salarial'] = $mutuelleS;
            $this->data[$person]['chomage']['patronal'] = $chomageP;
            $this->data[$person]['chomage']['salarial'] = $chomageS;
            $this->data[$person]['maladie']['patronal'] = $maladieP;
            $this->data[$person]['maladie']['salarial'] = $maladieS;
            $this->data[$person]['retraite']['patronal'] = $retraiteP;
            $this->data[$person]['retraite']['salarial'] = $retraiteS;
        }
    }
}
