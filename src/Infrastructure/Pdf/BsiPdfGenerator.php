<?php
// src/Infrastructure/Pdf/BsiPdfGenerator.php

declare(strict_types=1);

namespace App\Infrastructure\Pdf;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class BsiPdfGenerator
{
    private string $outputDir;

    public function __construct(string $outputDir)
    {
        $this->outputDir = rtrim($outputDir, '/');
    }

    // =========================================================================
    // 1. POINT D'ENTRÉE : MODE TEST
    // =========================================================================

    public function generateTestBsi(string $type, int $campaignYear): string
    {
        if (!in_array($type, ['forfait_heure', 'forfait_jour'], true)) {
            throw new \InvalidArgumentException("Type invalide : {$type}");
        }

        $this->ensureOutputDirExists();

        $labelType = $type === 'forfait_heure' ? 'Forfait heures' : 'Forfait jours';
        $fileLabel = $type === 'forfait_heure' ? 'FORFAIT_HEURES' : 'FORFAIT_JOURS';
        $filename  = sprintf('TEST_BSI_%s_%d.pdf', $fileLabel, $campaignYear);

        $viewModel = $this->getTestViewModel($type, $campaignYear);

        return $this->generatePdf($viewModel, $filename, "BSI de test - {$labelType} {$campaignYear}");
    }

    // =========================================================================
    // 2. POINT D'ENTRÉE : MODE RÉEL
    // =========================================================================

    public function generateRealBsi(array $employeeData, int $campaignYear): string
    {
        $this->ensureOutputDirExists();

        $viewModel = $this->mapDataToViewModel($employeeData, $campaignYear);

        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '', $viewModel['identity']['nom_complet']);
        $filename = sprintf('BSI_%d_%s.pdf', $campaignYear, $safeName);

        return $this->generatePdf($viewModel, $filename, "BSI {$campaignYear} - {$viewModel['identity']['nom_complet']}");
    }

    // =========================================================================
    // 3. LOGIQUE DE MAPPING
    // =========================================================================

    private function mapDataToViewModel(array $data, int $campaignYear): array
    {
        $parse = fn($val) => $this->parseAmount($val);
        $isForfaitJours = ($data['forfait_jours'] ?? false) === true;

        $nom    = $data['nom'] ?? '';
        $prenom = $data['prenom'] ?? '';

        // Logique jours travaillés : Fixe à 218 si Forfait Jours, sinon réel
        $joursTravailles = $isForfaitJours ? '218' : ($data['nb jours travaillés'] ?? '0');

        $identity = [
            'nom'              => $nom,
            'prenom'           => $prenom,
            'nom_complet'      => trim("$nom $prenom"),
            'poste'            => $data['poste'] ?? 'Non renseigné',
            'anciennete'       => $data['anciennete'] ?? '',
            'date_arrivee'     => $data['date_arrivee'] ?? '',
            'type_contrat'     => $data['type_contrat'] ?? 'CDI',
            'label_type'       => $isForfaitJours ? 'Forfait jours' : 'Forfait heures',
            'jours_travailles' => $joursTravailles,
            'is_forfait_jours' => $isForfaitJours,
            'is_test'          => false,
        ];

        $salaireBase   = $parse($data['Salaire de base']['salarial'] ?? 0);
        $heuresSupp    = $parse($data['Heures mensuelles majorées']['salarial'] ?? 0);
        $primes        = $parse($data['Sous-total Primes']['salarial'] ?? 0);
        $interessement = $parse($data['INTERESSEMENT']['salarial'] ?? 0);
        $acomptes      = $parse($data['Acomptes']['salarial'] ?? 0);
        
        $rttRachetes   = $parse($data['rtt_rachetes'] ?? 0);

        // --- MODIFICATION ICI : Calcul du Total Brut Annuel ---
        if ($isForfaitJours) {
            // Si Forfait Jours : On EXCLUT les RTT rachetés du total
            $totalBrutAnnuel = $salaireBase + $primes + $interessement;
        } else {
            // Si Forfait Heures (Standard) : On inclut tout (dont les HS)
            $totalBrutAnnuel = $salaireBase + $heuresSupp + $primes + $interessement;
        }

        $remuneration = [
            'base_annuelle'     => $salaireBase,
            'heures_supp'       => $heuresSupp,
            'rtt_rachetes'      => $rttRachetes, // On garde la valeur pour l'affichage ligne
            'prime_annuelle'    => $primes,
            'interessement'     => $interessement,
            'acomptes'          => $acomptes,
            'total_brut_annuel' => $totalBrutAnnuel,
            'total_mensuel'     => $totalBrutAnnuel / 12,
            'equiv_mois'        => ($salaireBase > 0) ? ($totalBrutAnnuel / ($salaireBase / 12)) : 0,
        ];

        // --- AVANTAGES SOCIAUX ---
        $transport = $parse($data['Frais de transport personnel non soumis']['salarial'] ?? 0)
            + $parse($data['Frais de transport personnel non soumis']['patronal'] ?? 0);

        $socialExtra = [
            'transport' => $transport,
            'cheques'   => 190.0,
        ];

        // --- COTISATIONS ---
        $social = [];
        $categories = ['maladie', 'retraite', 'prevoyance', 'chomage', 'mutuelle'];

        foreach ($categories as $cat) {
            $social[$cat] = [
                'salarial' => $parse($data[$cat]['salarial'] ?? 0),
                'patronal' => $parse($data[$cat]['patronal'] ?? 0),
            ];
        }

        // Total (sans doublonner total lui-même)
        $sumS = 0.0; $sumP = 0.0;
        foreach ($categories as $cat) {
            $sumS += (float)$social[$cat]['salarial'];
            $sumP += (float)$social[$cat]['patronal'];
        }
        $social['total'] = ['salarial' => $sumS, 'patronal' => $sumP];

        $netAnnuel = $parse($data['Net imposable']['salarial'] ?? 0);

        $pouvoirAchat = [
            'net_annuel'  => $netAnnuel,
            'net_mensuel' => $netAnnuel / 12,
        ];

        return [
            'identity'      => $identity,
            'remuneration'  => $remuneration,
            'social_extra'  => $socialExtra,
            'social'        => $social,
            'pouvoir_achat' => $pouvoirAchat,
            'campaign_year' => $campaignYear,
        ];
    }

    private function getTestViewModel(string $type, int $campaignYear): array
    {
        $isForfaitJours = ($type === 'forfait_jour');

        return [
            'campaign_year' => $campaignYear,
            'identity' => [
                'nom'              => 'NOM',
                'prenom'           => 'Prénom',
                'nom_complet'      => 'NOM Prénom',
                'poste'            => 'Poste occupé',
                'anciennete'       => '4,7 an(s)',
                'date_arrivee'     => '19/03/' . max(2000, $campaignYear - 3),
                'type_contrat'     => $isForfaitJours ? 'CDI Forfait jours' : 'CDI',
                'label_type'       => $isForfaitJours ? 'Forfait jours' : 'Forfait heures',
                'jours_travailles' => $isForfaitJours ? '218' : '280',
                'is_forfait_jours' => $isForfaitJours,
                'is_test'          => true,
            ],
            'remuneration' => [
                'base_annuelle'     => 0.0,
                'heures_supp'       => 0.0,
                'rtt_rachetes'      => 0.0, 
                'prime_annuelle'    => 0.0,
                'interessement'     => 0.0,
                'acomptes'          => 0.0,
                'total_brut_annuel' => 0.0,
                'total_mensuel'     => 0.0,
                'equiv_mois'        => 0.0,
            ],
            'social_extra' => [
                'transport' => 0.0,
                'cheques'   => 190.0,
            ],
            'social' => [
                'maladie'    => ['salarial' => 0, 'patronal' => 0],
                'retraite'   => ['salarial' => 0, 'patronal' => 0],
                'prevoyance' => ['salarial' => 0, 'patronal' => 0],
                'chomage'    => ['salarial' => 0, 'patronal' => 0],
                'mutuelle'   => ['salarial' => 0, 'patronal' => 0],
                'total'      => ['salarial' => 0, 'patronal' => 0],
            ],
            'pouvoir_achat' => [
                'net_annuel'  => 0.0,
                'net_mensuel' => 0.0,
            ]
        ];
    }

    // =========================================================================
    // 4. RENDU PDF
    // =========================================================================

    private function generatePdf(array $viewModel, string $filename, string $title): string
    {
        @ini_set('memory_limit', '512M');

        $html = $this->renderHtml($viewModel);
        $path = $this->outputDir . '/' . $filename;

        $tmpDir = '/tmp';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }

        $mpdf = new Mpdf([
            'format'        => 'A4',
            'margin_left'   => 5,
            'margin_right'  => 5,
            'margin_top'    => 30,
            'margin_bottom' => 5,
            'tempDir'       => $tmpDir,
            'default_font'  => 'dejavusans',
        ]);

        $mpdf->SetTitle($title);
        $mpdf->WriteHTML($html);
        $mpdf->Output($path, Destination::FILE);

        return $filename;
    }

    private function renderHtml(array $vm): string
    {
        $dIdent    = $vm['identity'];
        $dRemu     = $vm['remuneration'];
        $dSocExtra = $vm['social_extra'];
        $dSoc      = $vm['social'];
        $dPower    = $vm['pouvoir_achat'];
        $year      = $vm['campaign_year'];

        $fmt = fn($v) => number_format((float)$v, 0, ',', ' ') . ' €';
        $fmtDec = fn($v) => number_format((float)$v, 1, ',', ' ');

        $montantAnnuelLabel  = $fmt($dRemu['total_brut_annuel']);
        $montantMensuelLabel = $fmt($dRemu['total_mensuel']);
        $equivMoisLabel      = $fmtDec($dRemu['equiv_mois']);

        // LOGIQUE DYNAMIQUE : Libellé HS ou RTT
        $isForfaitJours = $dIdent['is_forfait_jours'] ?? false;
        
        // 1. Choix du label
        $labelHs = $isForfaitJours ? 'Jours RTT rachetés' : 'Heures supplémentaires';
        
        // 2. Choix du montant pour l'affichage TABLEAU (On garde la valeur réelle)
        $montantHsOuRtt = $isForfaitJours ? ($dRemu['rtt_rachetes'] ?? 0.0) : ($dRemu['heures_supp'] ?? 0.0);

        // 3. Choix de la valeur pour le GRAPHIQUE (Camembert)
        // SI Forfait Jours -> 0 (Exclu du schéma)
        // SI Forfait Heures -> Montant HS (Inclus)
        $valeurPourGraphique = $isForfaitJours ? 0.0 : $montantHsOuRtt;

        $colors = [
            'base'   => '#BCEED7',
            'hs'     => '#f077f0ff',
            'prime'  => '#ffff83ff',
            'inter'  => '#7bc2f5ff'
        ];

        $remuBreakdown = [
            ['label' => 'Salaire de base annuel', 'color' => $colors['base'],  'value' => $dRemu['base_annuelle']],
            // Utilisation de la variable spécifique graphique (0 si forfait jours)
            ['label' => $labelHs,                 'color' => $colors['hs'],    'value' => $valeurPourGraphique],
            ['label' => 'Prime annuelle',         'color' => $colors['prime'], 'value' => $dRemu['prime_annuelle']],
            ['label' => "Prime d'intéressement",  'color' => $colors['inter'], 'value' => $dRemu['interessement']],
        ];
        $remuBreakdown = $this->computePercentages($remuBreakdown);
        $donutSvg      = $this->buildDonutSvg($remuBreakdown);

        // --- RESSOURCES ---
        $assetsDir = dirname(__DIR__, 3) . '/public/assets/img/';

        $logoPath = $assetsDir . 'synergie.png';
        $logoHtml = file_exists($logoPath)
            ? '<img src="' . $logoPath . '" style="height: 40px; vertical-align: middle;" alt="Synergie" />'
            : '<span style="color: #FFFFFF; font-weight: bold; font-size: 14pt;">SYNERGIE</span>';

        $getIcon = function($file) use ($assetsDir) {
            $path = $assetsDir . $file;
            if (file_exists($path)) {
                return '<img src="' . $path . '" style="height: 25px; width: auto; vertical-align: middle;" />';
            }
            return '';
        };

        $iconAnciennete = $getIcon('anciennete.png');
        $iconArrivee    = $getIcon('arrive.png');
        $iconContrat    = $getIcon('contrat.jpg');
        $iconTravail    = $getIcon('travaille.jpg');

        // Couleurs
        $headerDarkBg = '#333333';
        $greenMain    = '#00B050';
        $greenLight   = '#E2F0D9';
        $borderGrey   = '#A6A6A6';
        $lightGrey    = '#F2F2F2';
        $darkText     = '#002060';
        $greyText     = '#555555';
        $rowBlue      = '#D9EAF7';

        $css = $this->getCssDefinition($headerDarkBg, $greenMain, $greenLight, $borderGrey, $lightGrey, $darkText, $greyText, $rowBlue);

        $bullet = fn($col) => "<span style='color:{$col}; font-size:14pt; line-height:10pt;'>■</span>";

        $html = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>BSI {$year}</title>
    <style>{$css}</style>
</head>
<body>

    <htmlpageheader name="mainHeader">
        <div style="background-color: {$headerDarkBg}; padding: 10px 15px;">
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="text-align: left; vertical-align: middle; width: 50%;">
                        {$logoHtml}
                    </td>
                    <td style="text-align: right; vertical-align: middle; width: 50%; color: #FFFFFF; font-weight: bold; font-size: 12pt;">
                        Bilan Social Individuel<br>
                        <span style="color: #cccccc; font-size: 10pt; font-weight: normal;">Campagne {$year}</span>
                    </td>
                </tr>
            </table>
        </div>
    </htmlpageheader>

<div class="page">

    <table class="identity-table">
        <tr class="identity-name-row">
            <td>{$dIdent['nom_complet']}</td>
        </tr>
        <tr class="identity-poste-row">
            <td>{$dIdent['poste']}</td>
        </tr>
    </table>

    <table class="info-line-table">
        <tr>
            <td style="width: 25%;">
                <table style="width: 100%; border: none;">
                    <tr>
                        <td style="width: 35px; border: none; text-align: center; vertical-align: middle;">{$iconAnciennete}</td>
                        <td style="border: none; vertical-align: middle;">
                            <span class="info-label">Ancienneté</span><br>
                            <span class="info-value">{$dIdent['anciennete']}</span>
                        </td>
                    </tr>
                </table>
            </td>
            <td style="width: 25%;">
                <table style="width: 100%; border: none;">
                    <tr>
                        <td style="width: 35px; border: none; text-align: center; vertical-align: middle;">{$iconArrivee}</td>
                        <td style="border: none; vertical-align: middle;">
                            <span class="info-label">Date d'arrivée</span><br>
                            <span class="info-value">{$dIdent['date_arrivee']}</span>
                        </td>
                    </tr>
                </table>
            </td>
            <td style="width: 25%;">
                <table style="width: 100%; border: none;">
                    <tr>
                        <td style="width: 35px; border: none; text-align: center; vertical-align: middle;">{$iconContrat}</td>
                        <td style="border: none; vertical-align: middle;">
                            <span class="info-label">Contrat</span><br>
                            <span class="info-value">{$dIdent['type_contrat']}</span>
                        </td>
                    </tr>
                </table>
            </td>
            <td style="width: 25%;">
                <table style="width: 100%; border: none;">
                    <tr>
                        <td style="width: 35px; border: none; text-align: center; vertical-align: middle;">{$iconTravail}</td>
                        <td style="border: none; vertical-align: middle;">
                            <span class="info-label">Jours travaillés</span><br>
                            <span class="info-value">{$dIdent['jours_travailles']}</span>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="section-title-spaced">TA REMUNERATION BRUTE ANNUELLE TOTALE</div>

    <div class="remu-section">
        <table class="remu-overview-table">
            <tr>
                <td style="width: 55%;" class="remu-overview-left">
                    <span class="top-label">Tu as perçu</span>
                    <span class="top-amount">{$montantAnnuelLabel}</span>
                    <span class="top-subtitle">Brut annuel</span>
                </td>
                <td style="width: 45%;" class="remu-overview-right">
                    <span class="label">soit</span> <span class="amount">{$montantMensuelLabel}</span>
                    <span class="main-label">mensuellement</span><br>
                    <span class="label">(ou l'équivalent de {$equivMoisLabel} mois de salaire)</span>
                </td>
            </tr>
        </table>

        <table class="remu-details-layout">
            <tr>
                <td style="width: 55%;">
                    <table class="remu-detail-table">
                        <tr><td class="remu-detail-label">{$bullet($colors['base'])} Salaire de base annuel</td><td class="remu-detail-amount">{$fmt($dRemu['base_annuelle'])}</td></tr>
                        
                        <tr><td class="remu-detail-label">{$bullet($colors['hs'])} {$labelHs}</td><td class="remu-detail-amount">{$fmt($montantHsOuRtt)}</td></tr>
                        
                        <tr><td class="remu-detail-label">{$bullet($colors['prime'])} Prime annuelle</td><td class="remu-detail-amount">{$fmt($dRemu['prime_annuelle'])}</td></tr>
                        <tr><td class="remu-detail-label">{$bullet($colors['inter'])} Prime d'intéressement</td><td class="remu-detail-amount">{$fmt($dRemu['interessement'])}</td></tr>
                    </table>

                    <table class="remu-highlight-table">
                        <tr>
                            <td class="remu-highlight-label">Ton brut annuel :</td>
                            <td class="remu-highlight-amount">{$montantAnnuelLabel}</td>
                        </tr>
                        <tr><td colspan="2" style="height: 10px;"></td></tr>
                        <tr>
                            <td class="remu-highlight-label">Acompte sur le salaire :</td>
                            <td class="remu-highlight-amount">{$fmt($dRemu['acomptes'])}</td>
                        </tr>
                    </table>
                </td>
                <td style="width: 45%;">
                    <div class="donut-wrapper">{$donutSvg}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section-title-spaced">TES CHARGES SOCIALES & AVANTAGES SOCIAUX</div>

    <table class="social-extra">
        <tr>
            <td style="width: 33%;">
                <div class="social-extra-amount">{$fmt($dSocExtra['transport'])}</div>
                <div class="social-extra-label">de frais de transport</div>
            </td>
            <td style="width: 33%;">
                <div class="social-extra-amount">{$fmt($dSocExtra['cheques'])}</div>
                <div class="social-extra-label">
                    de chèques cadeaux<br>
                    <span class="social-extra-small">(si présence en novembre {$year})</span>
                </div>
            </td>
            <td style="width: 34%;">
                <div class="social-extra-label" style="font-weight:bold;">Hors remise personnelle sur achat</div>
                <div class="social-extra-small">(dans la limite de 20 000 € tous les 3 ans)</div>
            </td>
        </tr>
    </table>

    <table class="social-table">
        <thead>
            <tr><th style="width: 40%;"></th><th style="width: 30%;">Collaborateur</th><th style="width: 30%;">Employeur</th></tr>
        </thead>
        <tbody>
            <tr><td style="text-align: left;">Maladie</td><td>{$fmt($dSoc['maladie']['salarial'])}</td><td>{$fmt($dSoc['maladie']['patronal'])}</td></tr>
            <tr><td style="text-align: left;">Retraite</td><td>{$fmt($dSoc['retraite']['salarial'])}</td><td>{$fmt($dSoc['retraite']['patronal'])}</td></tr>
            <tr><td style="text-align: left;">Prévoyance</td><td>{$fmt($dSoc['prevoyance']['salarial'])}</td><td>{$fmt($dSoc['prevoyance']['patronal'])}</td></tr>
            <tr><td style="text-align: left;">Assurance chômage</td><td>{$fmt($dSoc['chomage']['salarial'])}</td><td>{$fmt($dSoc['chomage']['patronal'])}</td></tr>
            <tr><td style="text-align: left;">Mutuelle</td><td>{$fmt($dSoc['mutuelle']['salarial'])}</td><td>{$fmt($dSoc['mutuelle']['patronal'])}</td></tr>
        </tbody>
        <tfoot>
            <tr>
                <td style="text-align: left;">Total</td>
                <td>{$fmt($dSoc['total']['salarial'])}</td>
                <td>{$fmt($dSoc['total']['patronal'])}</td>
            </tr>
        </tfoot>
    </table>

    <div class="section-title-spaced">TON POUVOIR D'ACHAT</div>

    <div class="power-box">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="width: 50%; text-align: center; border-right: 1px solid #CCCCCC;">
                    <div style="font-size: 10pt; font-weight: bold; color: #555;">Net à payer annuel (avant impôt)</div>
                    <div style="font-size: 16pt; font-weight: 800; color: #000; margin-top: 3px;">{$fmt($dPower['net_annuel'])}</div>
                </td>
                <td style="width: 50%; text-align: center;">
                    <div style="font-size: 10pt; font-weight: bold; color: #555;">Net à payer moyen par mois</div>
                    <div style="font-size: 16pt; font-weight: 800; color: #000; margin-top: 3px;">{$fmt($dPower['net_mensuel'])}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="footer">
        <div class="note-text">BSI généré automatiquement – {$dIdent['label_type']} · {$year}</div>
    </div>
</div>
</body>
</html>
HTML;

        return $html;
    }

    // =========================================================================
    // 5. HELPERS & OUTILS
    // =========================================================================

    private function ensureOutputDirExists(): void
    {
        if (!is_dir($this->outputDir) && !mkdir($concurrent = $this->outputDir, 0775, true) && !is_dir($concurrent)) {
            throw new \RuntimeException("Impossible de créer le répertoire : {$this->outputDir}");
        }
    }

    private function parseAmount(mixed $val): float
    {
        if (is_float($val) || is_int($val)) {
            return (float)$val;
        }
        if (empty($val) || !is_string($val)) {
            return 0.0;
        }
        $clean = str_replace([',', ' ', "\xc2\xa0"], ['.', '', ''], $val);
        return (float)$clean;
    }

    private function computePercentages(array $segments): array
    {
        $total = 0.0;
        foreach ($segments as $s) $total += max(0.0, (float) ($s['value'] ?? 0.0));
        if ($total <= 0.0) {
            foreach ($segments as &$s) $s['percent'] = 0.0;
            return $segments;
        }
        foreach ($segments as &$s) $s['percent'] = max(0.0, (float) ($s['value'] ?? 0.0)) / $total * 100.0;
        return $segments;
    }

    private function buildDonutSvg(array $segments): string
    {
        $width = 190; $height = 190; $cx = 95; $cy = 95; $radius = 70; $strokeWidth = 26;
        $circumference = 2 * M_PI * $radius;
        $circles = ''; $currentOffset = 0.0;
        $accumulatedAngle = -M_PI / 2;
        $texts = '';

        foreach ($segments as $segment) {
            $percent = (float)($segment['percent'] ?? 0.0);
            if ($percent <= 0.0) continue;
            $length = $circumference * $percent / 100.0;
            $gap = $circumference - $length;
            $color = htmlspecialchars((string)($segment['color'] ?? '#000'), ENT_QUOTES);

            $circles .= sprintf('<circle cx="%F" cy="%F" r="%F" fill="none" stroke="%s" stroke-width="%F" stroke-dasharray="%F %F" stroke-dashoffset="%F" />', $cx, $cy, $radius, $color, $strokeWidth, $length, $gap, -$currentOffset);
            $currentOffset += $length;

            $segmentAngle = ($percent / 100.0) * (2 * M_PI);
            $middleAngle = $accumulatedAngle + ($segmentAngle / 2);
            $textRadius = $radius;
            $tx = $cx + ($textRadius * cos($middleAngle));
            $ty = $cy + ($textRadius * sin($middleAngle));

            if ($percent > 4) {
                $displayPercent = round($percent) . '%';
                $texts .= sprintf('<text x="%F" y="%F" text-anchor="middle" dominant-baseline="middle" font-size="10" font-weight="bold" fill="#333333" stroke="#ffffff" stroke-width="3" paint-order="stroke">%s</text>', $tx, $ty, $displayPercent);
                $texts .= sprintf('<text x="%F" y="%F" text-anchor="middle" dominant-baseline="middle" font-size="10" font-weight="bold" fill="#333333">%s</text>', $tx, $ty, $displayPercent);
            }
            $accumulatedAngle += $segmentAngle;
        }
        if ($circles === '') {
            $circles = sprintf('<circle cx="%F" cy="%F" r="%F" fill="none" stroke="#E5E7EB" stroke-width="%F" />', $cx, $cy, $radius, $strokeWidth);
        }
        return <<<SVG
<svg width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}" xmlns="http://www.w3.org/2000/svg"><g transform="rotate(-90 95 95)">{$circles}</g>{$texts}<circle cx="{$cx}" cy="{$cy}" r="45" fill="#FFFFFF" /></svg>
SVG;
    }

    private function getCssDefinition($headerDarkBg, $greenMain, $greenLight, $borderGrey, $lightGrey, $darkText, $greyText, $rowBlue): string
    {
        $sectionTitleBg = '#BCEED7';

        return <<<CSS
            @page {
                header: html_mainHeader;
                margin-header: 5px;
                margin-bottom: 10px;
            }
            /* ✅ Calibri -> DejaVu Sans (mPDF friendly) */
            body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10pt; color: #000000; }
            .page { width: 100%; box-sizing: border-box; }

            .mt-50 { margin-top: 25px !important; }

            /* HEADER */
            .header-table { width: 100%; border-collapse: collapse; margin-bottom: 2px; }
            .header-table td { padding: 6px 6px; font-size: 10pt; vertical-align: middle; }
            .header-left { background-color: {$headerDarkBg}; color: #FFFFFF; font-weight: 700; }
            .header-right { background-color: {$headerDarkBg}; color: #FFFFFF; text-align: center; font-weight: 700; font-size: 10pt; }

            /* IDENTITÉ */
            .identity-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; margin-top: 5px; }
            .identity-name-row td {
                background-color: #FFFFFF;
                color: {$greenMain};
                font-size: 18pt;
                font-weight: 700;
                text-align: center;
                padding: 6px;
                text-transform: uppercase;
                border: none;
            }
            .identity-poste-row td {
                background-color: #FFFFFF;
                color: {$greenMain};
                font-size: 14pt;
                font-weight: 700;
                text-align: center;
                padding: 4px;
                border: none;
            }

            .info-line-table { width: 100%; border-collapse: collapse; margin-bottom: 5px; }
            .info-line-table td { border: 1px solid {$borderGrey}; padding: 3px 6px; vertical-align: middle; }
            .info-label { font-size: 9pt; color: {$greyText}; }
            .info-value { font-size: 10pt; font-weight: 700; color: #000000; }

            /* TITRES SECTIONS CENTRÉS */
            .section-title {
                width: 100%;
                background-color: {$sectionTitleBg};
                color: {$headerDarkBg};
                font-weight: bold;
                font-size: 14pt;
                text-transform: uppercase;
                padding: 4px 8px;
                margin-top: 10px;
                margin-bottom: 4px;
                border: none;
                text-align: center;
            }

            .section-title-spaced {
                width: 100%;
                background-color: {$sectionTitleBg};
                color: {$headerDarkBg};
                font-weight: bold;
                font-size: 14pt;
                text-transform: uppercase;
                padding: 4px 8px;
                margin-top: 25px;
                margin-bottom: 4px;
                border: none;
                text-align: center;
            }

            .remu-section { width: 100%; margin-top: 2px; margin-bottom: 5px; }
            .remu-overview-table { width: 100%; border-collapse: collapse; }
            .remu-overview-left .top-label { font-size: 11pt; color: {$greyText}; margin-right: 5px; }
            .remu-overview-left .top-amount { font-size: 16pt; font-weight: 800; margin-right: 5px; color: #000; }
            .remu-overview-left .top-subtitle { font-size: 11pt; font-weight: 700; color: #555; }

            .remu-overview-right { font-size: 9pt; }
            .remu-overview-right .amount { font-size: 12pt; font-weight: 700; }

            .remu-details-layout { width: 100%; border-collapse: collapse; margin-top: 5px; }
            .remu-detail-table { width: 100%; font-size: 9pt; }
            .remu-detail-amount { text-align: right; }
            .remu-highlight-table { width: 100%; font-weight: bold; margin-top: 5px; font-size: 9pt; background-color: {$rowBlue}; }
            .remu-highlight-amount { text-align: right; font-weight: 700; }
            .remu-highlight-label { font-weight: 700; }

            .donut-wrapper { text-align: center; display: flex; flex-direction: column; align-items: center; }

            .social-extra { width: 100%; border-collapse: collapse; margin-bottom: 5px; margin-top: 5px; text-align: center; }
            .social-extra td { padding: 3px; vertical-align: top; }
            .social-extra-amount { font-size: 16pt; font-weight: 700; color: #000000; }
            .social-extra-label { font-size: 9pt; color: {$greyText}; }
            .social-extra-small { font-size: 8pt; color: {$greyText}; font-style: italic; }

            .social-table { width: 100%; border-collapse: collapse; font-size: 8.5pt; margin-top: 2px; }
            .social-table td, .social-table th { border: 1px solid {$borderGrey}; padding: 3px 4px; text-align: right; }
            .social-table td:first-child { text-align: left; }
            .social-table thead th { border-bottom: 2px solid {$greenMain}; text-align: center; }
            .social-table tfoot td { background-color: {$greenLight}; font-weight: 700; font-size: 12pt; }

            .power-box {
                width: 100%;
                background-color: #F2F2F2;
                border: 1px solid #A6A6A6;
                padding: 10px 0;
                margin-top: 3px;
            }

            .note-text { font-size: 8pt; color: {$greyText}; margin-top: 2px; }
            .footer { display: flex; align-items: center; justify-content: space-between; }
CSS;
    }
}