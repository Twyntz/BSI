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
        "Heures mensuelles majorées",
        "Indemnités Jours de repos 10%"
    ];

    private array $categories = [
        'retraite'   => ["Vieillesse déplafonnée", "Vieillesse plafonnée", "Retraite TU1", "Contribution d'Equilibre Général TU1", "Réduct. générale des cotisat. pat. retraite", "Retraite TU2", "Contribution d'Equilibre Général TU2" ],
        'maladie'    => ["Maladie - maternité - invalidité - décès", "Maladie supplémentaire Alsace - Moselle", "Maladie supplémentaire Alsace-Moselle"],
        'chomage'    => ["Assurance chômage TrA+TrB", "AGS", "APEC TrA", "APEC TrB"],
        'prevoyance' => ["Prévoyance non cadre TrA", "Prévoyance cadre TrA", "Prévoyance cadre TrB", "Prévoyance non cadre", "Prévoyance non cadre Tr1", "Prévoyance non cadre Tr2" ],
        'mutuelle'   => ["Mutuelle Forfait Allan", "Frais de santé cadre forfait", "Frais de santé non cadre forfait direct", "Contat de santé forfait"],
    ];

    private array $forfaitJoursKeywords = ["RTT pris (j)", "RTT acquis (j)", "RTT et autres repos", "Indemnités Jours de repos 10%"];
    private array $descriptionFields = ["nom", "prenom", "poste", "anciennete", "date_arrivee", "type_contrat"];

    // --- METHODE PRINCIPALE ---

    public function readAll(string $bsiMoneyPath, string $bsiJoursPath, array $descriptionPaths): array
    {
        $this->data = [];
        $this->persons = [];
        $this->officialNames = [];

        $diagFile = dirname(__DIR__, 3) . '/public/RESULT/diagnostic.txt';
        $this->logDiag($diagFile, "=== DÉBUT DIAGNOSTIC (V6 - Fix Encoding & RTT) ===", true);

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

        // 4. Agrégation & Nettoyage
        $this->sumBsiMoney();
        $this->formatDatesForDisplay();

        return $this->data;
    }

    private function formatDatesForDisplay(): void
    {
        foreach ($this->data as $person => &$info) {
            foreach (['anciennete', 'date_arrivee'] as $field) {
                if (isset($info[$field]) && is_numeric($info[$field]) && (float)$info[$field] > 20000) {
                    $unixDate = ((float)$info[$field] - 25569) * 86400;
                    $info[$field] = gmdate("d/m/Y", (int)$unixDate);
                }
            }
        }
    }

    private function readTableFile(string $path): array
    {
        if (!is_file($path)) throw new \RuntimeException("Fichier introuvable : {$path}");
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return ($ext === 'csv') ? $this->readCsvFile($path) : $this->readExcelFile($path);
    }

    // --- CORRECTION MAJEURE ICI : GESTION DE L'ENCODAGE ---
    private function readCsvFile(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');
        if (!$handle) return [];

        $firstLine = fgets($handle);
        rewind($handle);
        $separator = (str_contains((string)$firstLine, ';')) ? ';' : ',';

        while (($row = fgetcsv($handle, 0, $separator)) !== false) {
            // Utilisation de la méthode convertToUtf8 pour éviter de casser les accents
            $rows[] = array_map(fn($v) => $v === null ? '' : $this->convertToUtf8((string)$v), $row);
        }
        fclose($handle);
        return $rows;
    }

    private function convertToUtf8(string $str): string
    {
        // Si la chaîne est déjà en UTF-8 valide, on la garde telle quelle.
        // Sinon, on tente une conversion depuis Windows-1252 (format Excel standard).
        return mb_check_encoding($str, 'UTF-8') 
            ? $str 
            : mb_convert_encoding($str, 'UTF-8', 'Windows-1252');
    }

    private function readExcelFile(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $rawRows = $spreadsheet->getActiveSheet()->toArray(null, false, false, false);
        $rows = [];
        foreach ($rawRows as $row) {
            $rows[] = array_map(fn($cell) => (string)$cell, $row);
        }
        return $rows;
    }

    private function normaliserChaine(string $chaine): string
    {
        $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $chaine);
        if ($str === false) $str = $chaine;
        $str = preg_replace('/[^a-zA-Z0-9]+/', ' ', $str) ?? '';
        return strtoupper(trim(preg_replace('/\s+/', ' ', $str)));
    }

    private function cleanHeader(string $h): string
    {
        return str_replace(' ', '', $this->normaliserChaine($h));
    }

    private function logDiag(string $path, string $msg, bool $reset = false): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $flags = $reset ? 0 : FILE_APPEND;
        @file_put_contents($path, $msg . "\n", $flags);
    }

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
            $this->data[$person]['rtt_rachetes'] = 0.0; 

            foreach ($this->listLibelleMoney as $l) {
                $this->data[$person][$l] = ['salarial' => 0, 'patronal' => 0];
            }
            foreach ($this->categories as $cat => $labels) {
                foreach ($labels as $l) {
                    $this->data[$person][$l] = ['salarial' => 0, 'patronal' => 0];
                }
            }
            foreach ($this->descriptionFields as $f) {
                $this->data[$person][$f] = 'no data';
            }
        }
    }

    private function extractValueBsiMoney(array $rows): void
    {
        $codeIdx = -1; $libIdx  = -1; $baseIdx = -1;

        foreach ($rows as $r) {
            foreach ($r as $idx => $val) {
                $clean = $this->cleanHeader($val);
                if ($codeIdx < 0 && str_contains($clean, 'CODE')) $codeIdx = $idx;
                if ($libIdx < 0 && str_contains($clean, 'LIBELLE')) $libIdx = $idx;
                if ($baseIdx < 0 && str_contains($clean, 'BASES')) $baseIdx = $idx;
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

                if (in_array($libelle, $this->forfaitJoursKeywords, true)) {
                    if (trim($salarial . $patronal . $baseS) !== '') {
                        $this->data[$person]['forfait_jours'] = true;
                    }
                }

                $this->storeMoney($person, $libelle, $salarial, $patronal);
                $currentBaseIdx += 3;
            }
        }
    }

    private function storeMoney($person, $libelle, $salarial, $patronal): void
    {
        $libelle = trim((string)$libelle);
        if ($libelle === '') return;

        $libNorm = $this->normaliserChaine($libelle);
        $clean = fn($v) => (float) str_replace([',', ' '], ['.', ''], (string)$v);

        // --- CORRECTION MAJEURE ICI : DETECTION ROBUSTE DES RTT ---
        // On utilise str_contains pour éviter les problèmes d'accents sur "Indemnités"
        // Le libellé complet est "Indemnités Jours de repos 10%"
        if (str_contains($libNorm, 'JOURS DE REPOS 10')) {
            $this->data[$person]['rtt_rachetes'] += $clean($salarial);
        }

        $isKnown = false;
        foreach ($this->listLibelleMoney as $known) {
            if ($libNorm === $this->normaliserChaine($known)) { $isKnown = true; break; }
        }

        if (!$isKnown) {
            foreach ($this->categories as $items) {
                foreach ($items as $known) {
                    if ($libNorm === $this->normaliserChaine($known)) { $isKnown = true; break 2; }
                }
            }
        }

        if (!$isKnown) return;

        if (!isset($this->data[$person][$libelle])) {
            $this->data[$person][$libelle] = ['salarial' => 0, 'patronal' => 0];
        }

        $this->data[$person][$libelle]['salarial'] += $clean($salarial);
        $this->data[$person][$libelle]['patronal'] += $clean($patronal);
    }

    private function sumBsiMoney(): void
    {
        foreach ($this->persons as $p) {
            foreach ($this->categories as $cat => $expectedLabels) {
                $totS = 0.0;
                $totP = 0.0;
                $expectedNorms = array_map([$this, 'normaliserChaine'], $expectedLabels);

                foreach ($this->data[$p] as $labelReel => $valeurs) {
                    if (!is_array($valeurs) || !isset($valeurs['salarial'])) continue;
                    $labelNorm = $this->normaliserChaine((string)$labelReel);
                    if (in_array($labelNorm, $expectedNorms, true)) {
                        $totS += (float)($valeurs['salarial'] ?? 0);
                        $totP += (float)($valeurs['patronal'] ?? 0);
                    }
                }
                $this->data[$p][$cat] = ['salarial' => $totS, 'patronal' => $totP];
            }
        }
    }

    private function extractValueJoursBsi(array $rows, string $diagFile): void
    {
        $nomIdx = -1; $prenomIdx = -1; $valIdx = -1;
        for ($i = 0; $i < min(5, count($rows)); $i++) {
            foreach ($rows[$i] as $idx => $val) {
                $clean = $this->cleanHeader($val);
                if ($clean === 'NOM') $nomIdx = $idx;
                if (in_array($clean, ['PRENOM', 'PRENOMS'], true)) $prenomIdx = $idx;
                if (str_contains($clean, $this->joursColumnKeyword)) $valIdx = $idx;
            }
            if ($nomIdx >= 0 && $prenomIdx >= 0 && $valIdx >= 0) break;
        }

        if ($valIdx === -1) $valIdx = 6;

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

    private function extractValueBsiDescription(array $rows, string $diagFile): void
    {
        $header = $rows[0];
        $mapping = [];
        $nomIdx = -1; $prenomIdx = -1;

        foreach ($header as $idx => $col) {
            $clean = $this->cleanHeader($col);
            if ($clean === 'NOM') $nomIdx = $idx;
            if (in_array($clean, ['PRENOM', 'PRENOMS'], true)) $prenomIdx = $idx;
            foreach ($this->descriptionMapping as $field => $keywords) {
                foreach ($keywords as $kw) {
                    if (str_contains($clean, $kw)) { $mapping[$field] = $idx; break; }
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
}