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
        if (count($moneyRows) === 0) return [];

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
            if (count($descRows) === 0) continue;
            $this->extractValueBsiDescription($descRows);
        }

        // 4. Agrégation des montants
        $this->sumBsiMoney();

        return $this->data;
    }

    // ---------------------------------------------------------------------
    // Utils : lecture CSV ou XLSX
    // ---------------------------------------------------------------------

    private function readTableFile(string $path): array
    {
        if (!is_file($path)) throw new \RuntimeException("Fichier introuvable : {$path}");
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($ext === 'csv') return $this->readCsvFile($path);
        return $this->readExcelFile($path);
    }

    private function readCsvFile(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');
        if ($handle === false) throw new \RuntimeException("Impossible d'ouvrir le fichier CSV : {$path}");

        // Détection séparateur
        $firstLine = fgets($handle);
        rewind($handle);
        $separator = (str_contains($firstLine, ';')) ? ';' : ',';

        while (($row = fgetcsv($handle, 0, $separator)) !== false) {
            // Nettoyage de l'encodage pour chaque cellule
            $rows[] = array_map(function($v) {
                return $v === null ? '' : mb_convert_encoding((string)$v, 'UTF-8', 'auto');
            }, $row);
        }
        fclose($handle);
        return $rows;
    }

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
     * Normalisation stricte : MAJUSCULES, sans accents, lettres uniquement.
     */
    private function normaliserChaine(string $chaine): string
    {
        $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $chaine);
        if ($str === false) $str = $chaine;
        $str = preg_replace('/[^a-zA-Z ]+/', '', $str) ?? '';
        return strtoupper(trim($str));
    }

    /**
     * Détecte dynamiquement les index des colonnes "Nom" et "Prénom" dans l'entête
     */
    private function findNameIndexes(array $headerRow, int $defaultNom = 0, int $defaultPrenom = 1): array
    {
        $nomIdx = $defaultNom;
        $prenomIdx = $defaultPrenom;

        foreach ($headerRow as $index => $col) {
            $colClean = $this->normaliserChaine($col);
            if ($colClean === 'NOM') $nomIdx = $index;
            if (in_array($colClean, ['PRENOM', 'PRENOMS'])) $prenomIdx = $index;
        }

        return ['nom' => $nomIdx, 'prenom' => $prenomIdx];
    }

    // ---------------------------------------------------------------------
    // MONEY BSI
    // ---------------------------------------------------------------------

    private function extractNameBsiMoney(array $rows): void
    {
        if (count($rows) < 3) return;
        $headerRow = $rows[2]; // Ligne 3
        $this->persons = [];
        $this->officialNames = [];

        foreach ($headerRow as $col) {
            if ($col !== '' && strtoupper($col) !== 'TOTAL') {
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
                $this->data[$person][$libelle] = ['salarial' => 0, 'patronal' => 0];
            }
            foreach ($this->descriptionFields as $field) {
                $this->data[$person][$field] = 'no data';
            }
            $this->data[$person]['forfait_jours'] = false;
        }
    }

    private function findHeaderBsiMoney(array $rows): array
    {
        $codeIndex = 0; 
        $libelleIndex = 0; 
        $startValueIndex = 0;

        foreach ($rows as $row) {
            foreach ($row as $idx => $col) {
                $colNorm = $this->normaliserChaine($col); // Ex: "BASE S" ou "LIBELLE"

                if (str_contains($colNorm, 'CODE')) $codeIndex = $idx;
                if (str_contains($colNorm, 'LIBELLE')) $libelleIndex = $idx;

                // CORRECTION ICI : On supprime les espaces pour être sûr de trouver "BASES"
                // Cela permet de matcher "Base S.", "Base S", "Base Sécu", etc.
                $colNormNoSpace = str_replace(' ', '', $colNorm); 

                if (str_contains($colNormNoSpace, 'BASES')) {
                    $startValueIndex = $idx;
                    return [
                        'codeIndex'       => $codeIndex,
                        'libelleIndex'    => $libelleIndex,
                        'startValueIndex' => $startValueIndex
                    ];
                }
            }
        }

        // Si on arrive ici, c'est qu'on a échoué. On affiche les headers pour aider au debug.
        // On prend la ligne 4 (index 3) car c'est souvent là que sont les titres
        $headersTrouves = isset($rows[3]) ? implode(' | ', $rows[3]) : 'Ligne introuvable';
        
        throw new \RuntimeException("Impossible de trouver les colonnes 'Code', 'Libellé' et 'Base S.' dans BSI Money. Headers lus sur la ligne probable : " . $headersTrouves);
    }

    private function extractValueBsiMoney(array $rows): void
    {
        if (empty($rows) || empty($this->persons)) return;

        $headerIndexes = $this->findHeaderBsiMoney($rows);
        $libelleIndex = $headerIndexes['libelleIndex'];
        $startValueIndex = $headerIndexes['startValueIndex'];

        foreach ($rows as $row) {
            if (count($row) <= $libelleIndex) continue;

            $libelle = trim($row[$libelleIndex]); 
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
                    if ($hasValue) $this->data[$person]['forfait_jours'] = true;
                }

                $this->getDataBsiMoney($person, $libelle, $baseS, $salarial, $patronal);
                $tempStartIndex += 3;
            }
        }
    }

    private function getDataBsiMoney(string $person, string $libelle, string $baseS, string $salarial, string $patronal): void
    {
        if (!in_array($libelle, $this->listLibelleMoney, true)) return;
        if (!isset($this->data[$person][$libelle])) {
            $this->data[$person][$libelle] = ['salarial' => 0, 'patronal' => 0];
        }
        $clean = fn($v) => ($v === '') ? '0' : str_replace([' ', "\xc2\xa0"], '', $v);
        $this->data[$person][$libelle]['salarial'] = $clean($salarial);
        $this->data[$person][$libelle]['patronal'] = $clean($patronal);
    }

    // ---------------------------------------------------------------------
    // JOURS BSI (Auto-détection colonnes + Matching souple)
    // ---------------------------------------------------------------------

    private function extractValueJoursBsi(array $rows): void
    {
        if (empty($rows)) return;

        // Détection des colonnes sur la 1ère ligne
        // Par défaut 2 et 3 si pas trouvé (cas classique export Stats)
        $indexes = $this->findNameIndexes($rows[0], 2, 3);
        $nomIdx = $indexes['nom'];
        $prenomIdx = $indexes['prenom'];

        // On cherche une colonne valeur (souvent index 6 ou contient "JOURS"/"TOTAL")
        // Par défaut 6 (Colonne G)
        $valueIdx = 6; 

        foreach ($rows as $i => $row) {
            // On saute la ligne d'entête si elle contient "Nom"
            if ($i === 0 && $this->normaliserChaine($row[$nomIdx] ?? '') === 'NOM') continue;

            if (!isset($row[$nomIdx], $row[$prenomIdx])) continue;

            $nom = $this->normaliserChaine($row[$nomIdx]);
            $prenom = $this->normaliserChaine($row[$prenomIdx]);

            if ($nom === '' || $prenom === '') continue;

            $this->matchAndAssignJours($nom, $prenom, $row[$valueIdx] ?? '0');
        }
    }

    private function matchAndAssignJours(string $nom, string $prenom, string $valeur): void
    {
        foreach ($this->persons as $person) {
            $nomMatch = str_contains($person, $nom);
            // Matching: Prénom complet OU initiale
            $prenomMatch = str_contains($person, $prenom);
            $initiale = mb_substr($prenom, 0, 1);
            $initialeMatch = str_contains($person, $nom . ' ' . $initiale);

            if ($nomMatch && ($prenomMatch || $initialeMatch)) {
                $realWorkingDayCount = str_replace(',', '.', $valeur);

                if (!isset($this->data[$person]['nb jours travaillés']) || $this->data[$person]['nb jours travaillés'] === 'no data') {
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

    // ---------------------------------------------------------------------
    // DESCRIPTIONS BSI (Auto-détection colonnes + Matching souple)
    // ---------------------------------------------------------------------

    private function extractValueBsiDescription(array $rows): void
    {
        if (empty($rows)) return;

        // Détection des colonnes Nom/Prénom sur la 1ère ligne
        // Si échoue, fallback sur 0 et 1, MAIS vos fichiers semblent être 2 et 3 (Matricule/Civilité avant)
        // Donc on tente de trouver "Nom", sinon on suppose 2 et 3 si c'est un format export RH standard
        $indexes = $this->findNameIndexes($rows[0], 0, 1);
        $nomIdx = $indexes['nom'];
        $prenomIdx = $indexes['prenom'];

        // Identification des autres colonnes par nom d'entête
        $header = array_map([$this, 'normaliserChaine'], $rows[0]);
        $mapFields = [];
        // Mapping nom technique -> libellé entête approximatif
        $search = [
            'poste' => 'POSTE', 
            'anciennete' => 'ANCIENNETE', 
            'date_arrivee' => ['DATE', 'ARRIVEE'], // Contient DATE ou ARRIVEE
            'type_contrat' => ['CONTRAT', 'TYPE']
        ];

        foreach ($this->descriptionFields as $field) {
            if ($field === 'nom' || $field === 'prenom') continue;
            foreach ($header as $idx => $h) {
                $terms = $search[$field] ?? strtoupper($field);
                if (is_array($terms)) {
                    foreach($terms as $t) if (str_contains($h, $t)) { $mapFields[$field] = $idx; break; }
                } else {
                    if (str_contains($h, $terms)) $mapFields[$field] = $idx;
                }
            }
        }

        foreach ($rows as $i => $row) {
            // Skip header
            if ($i === 0) continue;

            $nom = $this->normaliserChaine($row[$nomIdx] ?? '');
            $prenom = $this->normaliserChaine($row[$prenomIdx] ?? '');

            if ($nom === '' || $prenom === '') continue;

            foreach ($this->persons as $person) {
                $nomMatch = str_contains($person, $nom);
                $prenomMatch = str_contains($person, $prenom);
                $initiale = mb_substr($prenom, 0, 1);
                $initialeMatch = str_contains($person, $nom . ' ' . $initiale);

                if ($nomMatch && ($prenomMatch || $initialeMatch)) {
                    foreach ($this->descriptionFields as $field) {
                        if ($field === 'nom' || $field === 'prenom') {
                             // On met les beaux noms (Pas en majuscule, ceux du fichier)
                             if (($this->data[$person][$field] ?? 'no data') === 'no data') {
                                 $val = ($field === 'nom') ? ($row[$nomIdx]??'') : ($row[$prenomIdx]??'');
                                 $this->data[$person][$field] = trim($val);
                             }
                             continue;
                        }

                        // Champs dynamiques ou fallback index fixe si pas trouvé
                        $idx = $mapFields[$field] ?? -1;
                        // Si on a pas trouvé la colonne dynamiquement, on fallback sur les index "classiques" 
                        // Poste=2, Anciennete=3... (A adapter si besoin, mais le dynamique devrait marcher)
                        
                        if ($idx >= 0 && isset($row[$idx])) {
                             if (($this->data[$person][$field] ?? 'no data') === 'no data') {
                                 $this->data[$person][$field] = trim($row[$idx]);
                             }
                        }
                    }
                    break;
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // AGRÉGATION
    // ---------------------------------------------------------------------

    private function sumBsiMoney(): void
    {
        foreach ($this->persons as $person) {
            $retraiteP = 0.0; $maladieP = 0.0; $chomageP = 0.0; $mutuelleP = 0.0; $prevoyanceP = 0.0;
            $retraiteS = 0.0; $maladieS = 0.0; $chomageS = 0.0; $mutuelleS = 0.0; $prevoyanceS = 0.0;

            foreach ($this->data[$person] as $libelle => $value) {
                if (!is_array($value) || !isset($value['patronal'], $value['salarial'])) continue;

                $cleanVal = fn($v) => (float)str_replace([',', ' '], ['.', ''], (string)$v);
                $patronal = $cleanVal($value['patronal']);
                $salarial = $cleanVal($value['salarial']);

                if (in_array($libelle, $this->retraite, true)) { $retraiteP += $patronal; $retraiteS += $salarial; }
                if (in_array($libelle, $this->maladie, true)) { $maladieP += $patronal; $maladieS += $salarial; }
                if (in_array($libelle, $this->chomage, true)) { $chomageP += $patronal; $chomageS += $salarial; }
                if (in_array($libelle, $this->mutuelle, true)) { $mutuelleP += $patronal; $mutuelleS += $salarial; }
                if (in_array($libelle, $this->prevoyance, true)) { $prevoyanceP += $patronal; $prevoyanceS += $salarial; }
            }
            $this->data[$person]['prevoyance'] = ['patronal' => $prevoyanceP, 'salarial' => $prevoyanceS];
            $this->data[$person]['mutuelle'] = ['patronal' => $mutuelleP, 'salarial' => $mutuelleS];
            $this->data[$person]['chomage'] = ['patronal' => $chomageP, 'salarial' => $chomageS];
            $this->data[$person]['maladie'] = ['patronal' => $maladieP, 'salarial' => $maladieS];
            $this->data[$person]['retraite'] = ['patronal' => $retraiteP, 'salarial' => $retraiteS];
        }
    }
}