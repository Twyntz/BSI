<?php
// src/Infrastructure/Csv/CsvEmployeeReader.php

declare(strict_types=1);

namespace App\Infrastructure\Csv;

use PhpOffice\PhpSpreadsheet\IOFactory;

class CsvEmployeeReader
{
    private array $persons = [];
    private array $officialNames = [];
    private array $data = [];

    // --- CONFIGURATION ---
    private array $descriptionMapping = [
        'poste'        => ['INTITULEDEPOSTE'], 
        'date_arrivee' => ['DEBUTDECONTRAT'],
        'anciennete'   => ['DATEDANCIENNETE', 'DATEANCIENNETE'],
        'type_contrat' => ['MODELEDECONTRAT']
    ];

    private string $joursColumnKeyword = 'NOMBREDEJOURSTRAVAILLESTHEORIQUE';

    private array $listLibelleMoney = [
        "Salaire de base", "Salaire Brut", "Sous-total Primes", "INTERESSEMENT",
        "Net imposable", "Acomptes", "Frais de transport personnel non soumis",
        "Heures mensuelles majorées"
    ];

    private array $categories = [
        'retraite'   => ["Vieillesse déplafonnée", "Vieillesse plafonnée", "Retraite TU1", "Contribution d'Equilibre Général TU1", "Réduct. générale des cotisat. pat. retraite", "retraite"],
        'maladie'    => ["Maladie - maternité - invalidité - décès", "maladie"],
        'chomage'    => ["Assurance chômage TrA+TrB", "AGS", "chomage"],
        'prevoyance' => ["Prévoyance supplémentaire non cadre TrA", "prevoyance"],
        'mutuelle'   => ["Frais de santé", "mutuelle"],
    ];

    private array $forfaitJoursKeywords = ["RTT pris (j)", "RTT acquis (j)", "RTT et autres repos"];
    private array $descriptionFields = ["nom", "prenom", "poste", "anciennete", "date_arrivee", "type_contrat"];

    // --- METHODE PRINCIPALE ---

    public function readAll(string $bsiMoneyPath, string $bsiJoursPath, array $descriptionPaths): array
    {
        $this->data = [];
        $this->persons = [];
        $this->officialNames = [];
        
        $diagFile = dirname(__DIR__, 3) . '/public/RESULT/diagnostic.txt';
        $this->logDiag($diagFile, "=== DÉBUT DIAGNOSTIC (V3 - Math Date Fix) ===", true);

        // 1. Money
        $moneyRows = $this->readTableFile($bsiMoneyPath);
        if (empty($moneyRows)) return [];
        $this->extractNameBsiMoney($moneyRows);
        $this->createLibelle();
        $this->extractValueBsiMoney($moneyRows);

        // 2. Jours
        $joursRows = $this->readTableFile($bsiJoursPath);
        if (count($joursRows) > 0) $this->extractValueJoursBsi($joursRows, $diagFile);

        // 3. Descriptions
        foreach ($descriptionPaths as $path) {
            $descRows = $this->readTableFile($path);
            if (!empty($descRows)) $this->extractValueBsiDescription($descRows, $diagFile);
        }

        // 4. Agrégation & Conversion Dates
        $this->sumBsiMoney();
        $this->formatDatesForDisplay(); 

        return $this->data;
    }

    // --- CONVERSION DATES EXCEL (MÉTHODE MATHÉMATIQUE) ---
    private function formatDatesForDisplay(): void
    {
        foreach ($this->data as $person => &$info) {
            foreach (['anciennete', 'date_arrivee'] as $field) {
                // Si on a un nombre > 20000 (environ année 1954)
                if (isset($info[$field]) && is_numeric($info[$field]) && $info[$field] > 20000) {
                    // Formule : (ExcelSerial - 25569) * 86400 = Timestamp Unix
                    // 25569 est le nombre de jours entre le 01/01/1900 (Excel) et le 01/01/1970 (Unix)
                    $unixDate = ($info[$field] - 25569) * 86400;
                    
                    // On utilise gmdate pour éviter les décalages de fuseau horaire (GMT)
                    $info[$field] = gmdate("d/m/Y", (int)$unixDate);
                }
            }
        }
    }

    // --- LECTURE ---

    private function readTableFile(string $path): array
    {
        if (!is_file($path)) throw new \RuntimeException("Fichier introuvable : {$path}");
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return ($ext === 'csv') ? $this->readCsvFile($path) : $this->readExcelFile($path);
    }

    private function readCsvFile(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');
        $firstLine = fgets($handle);
        rewind($handle);
        $separator = (str_contains($firstLine, ';')) ? ';' : ',';

        while (($row = fgetcsv($handle, 0, $separator)) !== false) {
            $rows[] = array_map(fn($v) => $v === null ? '' : mb_convert_encoding((string)$v, 'UTF-8', 'Windows-1252'), $row);
        }
        fclose($handle);
        return $rows;
    }

    private function readExcelFile(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        // On force string pour éviter les problèmes de null
        $rawRows = $spreadsheet->getActiveSheet()->toArray(null, false, false, false);
        $rows = [];
        foreach ($rawRows as $row) {
            $rows[] = array_map(fn($cell) => (string)$cell, $row);
        }
        return $rows;
    }

    // --- UTILS CLEANING ---

    private function normaliserChaine(string $chaine): string
    {
        $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $chaine);
        if ($str === false) $str = $chaine;
        $str = preg_replace('/[^a-zA-Z ]+/', '', $str) ?? '';
        return strtoupper(trim($str));
    }

    private function cleanHeader(string $h): string
    {
        return str_replace(' ', '', $this->normaliserChaine($h));
    }

    private function logDiag(string $path, string $msg, bool $reset = false): void
    {
        file_put_contents($path, $msg . "\n", $reset ? 0 : FILE_APPEND);
    }

    // --- MONEY ---

    private function extractNameBsiMoney(array $rows): void
    {
        if (count($rows) < 3) return;
        $headerRow = $rows[2];
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
            $this->data[$person] = [];
            $this->data[$person]['official_name'] = $this->officialNames[$index] ?? $person;
            $this->data[$person]['forfait_jours'] = false;
            foreach ($this->listLibelleMoney as $l) $this->data[$person][$l] = ['salarial' => 0, 'patronal' => 0];
            foreach ($this->categories as $cat) foreach ($cat as $l) $this->data[$person][$l] = ['salarial' => 0, 'patronal' => 0];
            foreach ($this->descriptionFields as $f) $this->data[$person][$f] = 'no data';
        }
    }

    private function extractValueBsiMoney(array $rows): void
    {
        $codeIdx = -1; $libIdx = -1; $baseIdx = -1;
        foreach ($rows as $r) {
            foreach ($r as $idx => $val) {
                $clean = $this->cleanHeader($val);
                if (str_contains($clean, 'CODE')) $codeIdx = $idx;
                if (str_contains($clean, 'LIBELLE')) $libIdx = $idx;
                if (str_contains($clean, 'BASES')) $baseIdx = $idx;
            }
            if ($codeIdx >= 0 && $libIdx >= 0 && $baseIdx >= 0) break;
        }

        if ($baseIdx === -1) return;

        foreach ($rows as $row) {
            if (!isset($row[$libIdx])) continue;
            $libelle = trim($row[$libIdx]);
            $currentBaseIdx = $baseIdx;
            
            foreach ($this->persons as $person) {
                if (!isset($this->data[$person])) continue;
                $salarial = $row[$currentBaseIdx + 1] ?? '';
                $patronal = $row[$currentBaseIdx + 2] ?? '';
                $baseS    = $row[$currentBaseIdx] ?? '';

                if (in_array($libelle, $this->forfaitJoursKeywords)) {
                    if (trim($salarial . $patronal . $baseS) !== '') $this->data[$person]['forfait_jours'] = true;
                }
                $this->storeMoney($person, $libelle, $salarial, $patronal);
                $currentBaseIdx += 3;
            }
        }
    }

    private function storeMoney($person, $libelle, $salarial, $patronal): void
    {
        $target = $libelle;
        $isKnown = in_array($libelle, $this->listLibelleMoney);
        if (!$isKnown) {
            foreach ($this->categories as $catName => $items) {
                if (in_array($libelle, $items)) {
                    $target = $libelle;
                    $isKnown = true;
                    break;
                }
            }
        }
        if ($isKnown) {
            if (!isset($this->data[$person][$target])) $this->data[$person][$target] = ['salarial' => 0, 'patronal' => 0];
            $clean = fn($v) => (float)str_replace([',', ' '], ['.', ''], (string)$v);
            $this->data[$person][$target]['salarial'] = $clean($salarial);
            $this->data[$person][$target]['patronal'] = $clean($patronal);
        }
    }

    // --- JOURS ---

    private function extractValueJoursBsi(array $rows, string $diagFile): void
    {
        $nomIdx = -1; $prenomIdx = -1; $valIdx = -1;

        for ($i = 0; $i < min(5, count($rows)); $i++) {
            foreach ($rows[$i] as $idx => $val) {
                $clean = $this->cleanHeader($val);
                if ($clean === 'NOM') $nomIdx = $idx;
                if (in_array($clean, ['PRENOM', 'PRENOMS'])) $prenomIdx = $idx;
                if (str_contains($clean, $this->joursColumnKeyword)) $valIdx = $idx;
            }
            if ($nomIdx >= 0 && $prenomIdx >= 0 && $valIdx >= 0) break;
        }

        if ($valIdx === -1) {
            $valIdx = 6; 
        }

        foreach ($rows as $row) {
            $nom = $this->normaliserChaine($row[$nomIdx] ?? '');
            $prenom = $this->normaliserChaine($row[$prenomIdx] ?? '');
            if ($nom === '' || $prenom === '' || $nom === 'NOM') continue;

            $this->matchPerson($nom, $prenom, function($p) use ($row, $valIdx) {
                $val = str_replace(',', '.', $row[$valIdx] ?? '0');
                $this->data[$p]['nb jours travaillés'] = $val;
            });
        }
    }

    // --- DESCRIPTION ---

    private function extractValueBsiDescription(array $rows, string $diagFile): void
    {
        $header = $rows[0];
        $mapping = [];
        $nomIdx = -1; $prenomIdx = -1;

        foreach ($header as $idx => $col) {
            $clean = $this->cleanHeader($col);
            if ($clean === 'NOM') $nomIdx = $idx;
            if (in_array($clean, ['PRENOM', 'PRENOMS'])) $prenomIdx = $idx;

            foreach ($this->descriptionMapping as $field => $keywords) {
                foreach ($keywords as $kw) {
                    if (str_contains($clean, $kw)) {
                        $mapping[$field] = $idx;
                        break;
                    }
                }
            }
        }
        
        if ($nomIdx === -1) return;

        foreach ($rows as $i => $row) {
            if ($i === 0) continue;
            $nom = $this->normaliserChaine($row[$nomIdx] ?? '');
            $prenom = $this->normaliserChaine($row[$prenomIdx] ?? '');
            if ($nom === '' || $prenom === '') continue;

            $this->matchPerson($nom, $prenom, function($p) use ($row, $mapping, $nomIdx, $prenomIdx) {
                if ($this->data[$p]['nom'] === 'no data') $this->data[$p]['nom'] = trim($row[$nomIdx]);
                if ($this->data[$p]['prenom'] === 'no data') $this->data[$p]['prenom'] = trim($row[$prenomIdx]);
                foreach ($mapping as $field => $idx) {
                     $val = trim($row[$idx] ?? '');
                     if ($val !== '') $this->data[$p][$field] = $val;
                }
            });
        }
    }

    private function matchPerson(string $nom, string $prenom, callable $onMatch): void
    {
        foreach ($this->persons as $person) {
            $nomMatch = str_contains($person, $nom);
            $prenomMatch = str_contains($person, $prenom);
            $initiale = mb_substr($prenom, 0, 1);
            $initialeMatch = str_contains($person, $nom . ' ' . $initiale);
            if ($nomMatch && ($prenomMatch || $initialeMatch)) {
                $onMatch($person);
                break; 
            }
        }
    }

    private function sumBsiMoney(): void
    {
        foreach ($this->persons as $p) {
            foreach ($this->categories as $cat => $keys) {
                $totS = 0; $totP = 0;
                foreach ($keys as $k) {
                    if (isset($this->data[$p][$k])) {
                        $totS += $this->data[$p][$k]['salarial'];
                        $totP += $this->data[$p][$k]['patronal'];
                    }
                }
                $this->data[$p][$cat] = ['salarial' => $totS, 'patronal' => $totP];
            }
        }
    }
}