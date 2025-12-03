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
    // 1. POINT D'ENTRÉE : MODE TEST (Garde la compatibilité existante)
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
        
        // On récupère des données "Fake" (toutes à 0) via notre nouvelle structure
        $viewModel = $this->getTestViewModel($type, $campaignYear);

        // On génère le PDF
        return $this->generatePdf($viewModel, $filename, "BSI de test - {$labelType} {$campaignYear}");
    }

    // =========================================================================
    // 2. POINT D'ENTRÉE : MODE RÉEL (Nouveau)
    // =========================================================================

    /**
     * Génère un BSI réel à partir des données extraites du Reader.
     */
    public function generateRealBsi(array $employeeData, int $campaignYear): string
    {
        $this->ensureOutputDirExists();

        // Mapping des données brutes vers le ViewModel standardisé
        $viewModel = $this->mapDataToViewModel($employeeData, $campaignYear);

        // Nettoyage du nom pour le fichier
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '', $viewModel['identity']['nom_complet']);
        $filename = sprintf('BSI_%d_%s.pdf', $campaignYear, $safeName);

        // Génération
        return $this->generatePdf($viewModel, $filename, "BSI {$campaignYear} - {$viewModel['identity']['nom_complet']}");
    }

    // =========================================================================
    // 3. LOGIQUE DE MAPPING (Le Cœur du changement)
    // =========================================================================

    /**
     * Transforme les données brutes du CsvReader en structure propre pour le PDF.
     */
    private function mapDataToViewModel(array $data, int $campaignYear): array
    {
        // Helpers pour nettoyer les montants (ex: "1 200,00" -> 1200.00)
        $parse = fn($val) => $this->parseAmount($val);

        $isForfaitJours = ($data['forfait_jours'] ?? false) === true;
        
        // --- 1. Identité ---
        $nom    = $data['nom'] ?? '';
        $prenom = $data['prenom'] ?? '';
        
        $identity = [
            'nom'              => $nom,
            'prenom'           => $prenom,
            'nom_complet'      => trim("$nom $prenom"),
            'poste'            => $data['poste'] ?? 'Non renseigné',
            'anciennete'       => $data['anciennete'] ?? '',
            'date_arrivee'     => $data['date_arrivee'] ?? '',
            'type_contrat'     => $data['type_contrat'] ?? 'CDI',
            'label_type'       => $isForfaitJours ? 'Forfait jours' : 'Forfait heures',
            'jours_travailles' => $data['nb jours travaillés'] ?? '0',
            'is_test'          => false, // Drapeau pour masquer le bandeau "TEST" si besoin
        ];

        // --- 2. Rémunération (Salarial) ---
        // Les clés ici doivent correspondre exactement aux libellés dans CsvEmployeeReader
        $salaireBase = $parse($data['Salaire de base']['salarial'] ?? 0);
        $heuresSupp  = $parse($data['Heures mensuelles majorées']['salarial'] ?? 0);
        
        // Primes : on additionne "Sous-total Primes" et potentiellement d'autres si besoin
        $primes      = $parse($data['Sous-total Primes']['salarial'] ?? 0);
        $interessement = $parse($data['INTERESSEMENT']['salarial'] ?? 0);

        // Calcul du total annuel brut
        // Note : On peut soit le recalculer, soit prendre "Salaire Brut" du fichier si dispo.
        // Ici on recalcule pour être cohérent avec le breakdown.
        $totalBrutAnnuel = $salaireBase + $heuresSupp + $primes + $interessement;
        
        $remuneration = [
            'base_annuelle'      => $salaireBase,
            'heures_supp'        => $heuresSupp,
            'prime_annuelle'     => $primes,
            'interessement'      => $interessement,
            'total_brut_annuel'  => $totalBrutAnnuel,
            'total_mensuel'      => $totalBrutAnnuel / 12,
            'equiv_mois'         => ($salaireBase > 0) ? ($totalBrutAnnuel / ($salaireBase / 12)) : 0,
        ];

        // --- 3. Protection Sociale (Patronal & Salarial) ---
        // Les clés 'retraite', 'maladie'... sont agrégées par le CsvReader
        $social = [];
        $categories = ['maladie', 'retraite', 'prevoyance', 'chomage', 'mutuelle'];

        foreach ($categories as $cat) {
            $social[$cat] = [
                'salarial' => $parse($data[$cat]['salarial'] ?? 0),
                'patronal' => $parse($data[$cat]['patronal'] ?? 0),
            ];
        }

        // Totaux sociaux (optionnel pour le footer du tableau)
        $social['total'] = [
            'salarial' => array_sum(array_column($social, 'salarial')),
            'patronal' => array_sum(array_column($social, 'patronal')),
        ];

        // --- 4. Pouvoir d'achat (Net) ---
        // "Net imposable" ou "Net a payer" selon ce qui est dispo dans le reader
        $netAnnuel = $parse($data['Net imposable']['salarial'] ?? 0);

        $pouvoirAchat = [
            'net_annuel' => $netAnnuel,
            'net_mensuel' => $netAnnuel / 12,
        ];

        return [
            'identity'     => $identity,
            'remuneration' => $remuneration,
            'social'       => $social,
            'pouvoir_achat'=> $pouvoirAchat,
            'campaign_year'=> $campaignYear,
        ];
    }

    /**
     * Crée un ViewModel vide pour le mode TEST.
     */
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
                'jours_travailles' => '280 jours',
                'is_test'          => true,
            ],
            'remuneration' => [
                'base_annuelle'     => 0.0,
                'heures_supp'       => 0.0,
                'prime_annuelle'    => 0.0,
                'interessement'     => 0.0,
                'total_brut_annuel' => 0.0,
                'total_mensuel'     => 0.0,
                'equiv_mois'        => 0.0,
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
    // 4. RENDU PDF (Agnostique : ne sait pas si c'est du test ou du réel)
    // =========================================================================

    private function generatePdf(array $viewModel, string $filename, string $title): string
    {
        // Construction du HTML via le ViewModel unifié
        $html = $this->renderHtml($viewModel);

        $path = $this->outputDir . '/' . $filename;

        $mpdf = new Mpdf([
            'format'        => 'A4',
            'margin_left'   => 5,
            'margin_right'  => 5,
            'margin_top'    => 5,
            'margin_bottom' => 5,
        ]);

        $mpdf->SetTitle($title);
        $mpdf->WriteHTML($html);
        $mpdf->Output($path, Destination::FILE);

        return $filename;
    }

    /**
     * Remplace l'ancienne "buildTestHtml".
     * Elle prend maintenant le $viewModel standardisé.
     */
    private function renderHtml(array $vm): string
    {
        // Extraction pour simplifier la syntaxe dans le HEREDOC
        $dIdent = $vm['identity'];
        $dRemu  = $vm['remuneration'];
        $dSoc   = $vm['social'];
        $dPower = $vm['pouvoir_achat'];
        $year   = $vm['campaign_year'];

        // Formatage des nombres
        $fmt = fn($v) => number_format((float)$v, 0, ',', ' ') . ' €';
        $fmtDec = fn($v) => number_format((float)$v, 1, ',', ' '); // Pour "12,5 mois"

        // Labels
        $montantAnnuelLabel  = $fmt($dRemu['total_brut_annuel']);
        $montantMensuelLabel = $fmt($dRemu['total_mensuel']);
        $equivMoisLabel      = $fmtDec($dRemu['equiv_mois']);

        // Données Donut
        $remuBreakdown = [
            ['label' => 'Salaire de base annuel', 'color' => '#00B050', 'value' => $dRemu['base_annuelle']],
            ['label' => 'Heures supplémentaires', 'color' => '#FF00FF', 'value' => $dRemu['heures_supp']],
            ['label' => 'Prime annuelle',         'color' => '#FFFF00', 'value' => $dRemu['prime_annuelle']],
            ['label' => "Prime d'intéressement",  'color' => '#0070C0', 'value' => $dRemu['interessement']],
        ];
        $remuBreakdown = $this->computePercentages($remuBreakdown);
        $donutSvg      = $this->buildDonutSvg($remuBreakdown);

        // Variables CSS/Couleurs (identiques à avant)
        $headerGreyBg  = '#D9D9D9'; $headerGrey = '#595959'; $blueCartouche = '#305496';
        $greenMain     = '#00B050'; $greenLight = '#E2F0D9'; $borderGrey = '#A6A6A6';
        $lightGrey     = '#F2F2F2'; $darkText = '#002060';   $greyText = '#555555';
        $rowBlue       = '#D9EAF7';

        // Message de bas de page conditionnel
        $noteBasDePage = $dIdent['is_test'] 
            ? "Les valeurs sont volontairement nulles pour ce BSI de test." 
            : "Document confidentiel généré automatiquement.";

        // --- HTML TEMPLATE (Condensé pour la lisibilité ici, reprendre ton CSS complet) ---
        // J'insère juste les variables $vm aux bons endroits
        
        // CSS (je garde ton CSS existant, il est très bien)
        $css = $this->getCssDefinition($headerGreyBg, $headerGrey, $blueCartouche, $greenMain, $greenLight, $borderGrey, $lightGrey, $darkText, $greyText, $rowBlue);

        $html = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>BSI {$year}</title>
    <style>{$css}</style>
</head>
<body>
<div class="page">
    <table class="header-table">
        <tr>
            <td class="header-left" style="width: 65%;">
                SYNERGIE DÉVELOPPEMENT<br><span class="header-left-sub">Talents & Habitat</span>
            </td>
            <td class="header-right" style="width: 35%;">
                Bilan Social Individuel<br>{$year}
            </td>
        </tr>
    </table>

    <table class="identity-table">
        <tr class="identity-name-row"><td>{$dIdent['nom_complet']}</td></tr>
        <tr class="identity-poste-row"><td>{$dIdent['poste']}</td></tr>
    </table>

    <table class="info-line-table">
        <tr>
            <td style="width: 25%;"><span class="info-label">Ancienneté</span><br><span class="info-value">{$dIdent['anciennete']}</span></td>
            <td style="width: 25%;"><span class="info-label">Date d'arrivée</span><br><span class="info-value">{$dIdent['date_arrivee']}</span></td>
            <td style="width: 25%;"><span class="info-label">Contrat</span><br><span class="info-value">{$dIdent['type_contrat']}</span></td>
            <td style="width: 25%;"><span class="info-label">Jours travaillés</span><br><span class="info-value">{$dIdent['jours_travailles']}</span></td>
        </tr>
    </table>

    <div class="section-title">TA REMUNERATION BRUTE ANNUELLE TOTALE</div>
    <div class="remu-section">
        <table class="remu-overview-table">
            <tr>
                <td style="width: 55%;" class="remu-overview-left">
                    <span class="top-label">Tu as perçu</span><br>
                    <span class="top-amount">{$montantAnnuelLabel}</span><br>
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
                        <tr><td class="remu-detail-label">Salaire de base annuel</td><td class="remu-detail-amount">{$fmt($dRemu['base_annuelle'])}</td></tr>
                        <tr><td class="remu-detail-label">Heures supplémentaires</td><td class="remu-detail-amount">{$fmt($dRemu['heures_supp'])}</td></tr>
                        <tr><td class="remu-detail-label">Prime annuelle</td><td class="remu-detail-amount">{$fmt($dRemu['prime_annuelle'])}</td></tr>
                        <tr><td class="remu-detail-label">Prime d'intéressement</td><td class="remu-detail-amount">{$fmt($dRemu['interessement'])}</td></tr>
                    </table>
                    <table class="remu-highlight-table">
                        <tr><td class="remu-highlight-label">Ton brut annuel :</td><td class="remu-highlight-amount">{$montantAnnuelLabel}</td></tr>
                    </table>
                </td>
                <td style="width: 45%;">
                    <div class="donut-wrapper">{$donutSvg}<div class="donut-caption">Répartition brute</div></div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section-title">TES CHARGES SOCIALES & AVANTAGES SOCIAUX</div>
    <table class="social-table">
        <thead>
            <tr><th style="width: 40%;"></th><th style="width: 30%;">Collaborateur (Salarial)</th><th style="width: 30%;">Employeur (Patronal)</th></tr>
        </thead>
        <tbody>
            <tr><td>Maladie</td><td>{$fmt($dSoc['maladie']['salarial'])}</td><td>{$fmt($dSoc['maladie']['patronal'])}</td></tr>
            <tr><td>Retraite</td><td>{$fmt($dSoc['retraite']['salarial'])}</td><td>{$fmt($dSoc['retraite']['patronal'])}</td></tr>
            <tr><td>Prévoyance</td><td>{$fmt($dSoc['prevoyance']['salarial'])}</td><td>{$fmt($dSoc['prevoyance']['patronal'])}</td></tr>
            <tr><td>Assurance chômage</td><td>{$fmt($dSoc['chomage']['salarial'])}</td><td>{$fmt($dSoc['chomage']['patronal'])}</td></tr>
            <tr><td>Mutuelle</td><td>{$fmt($dSoc['mutuelle']['salarial'])}</td><td>{$fmt($dSoc['mutuelle']['patronal'])}</td></tr>
        </tbody>
        <tfoot>
            <tr>
                <td>Total</td>
                <td>{$fmt($dSoc['total']['salarial'])}</td>
                <td>{$fmt($dSoc['total']['patronal'])}</td>
            </tr>
        </tfoot>
    </table>

    <div class="section-title">TON POUVOIR D'ACHAT</div>
    <table class="power-table">
        <thead><tr><th style="width: 60%;">Élément</th><th style="width: 40%;">Montant</th></tr></thead>
        <tbody>
            <tr><td>Net à payer annuel</td><td>{$fmt($dPower['net_annuel'])}</td></tr>
            <tr><td>Net à payer moyen par mois</td><td>{$fmt($dPower['net_mensuel'])}</td></tr>
        </tbody>
    </table>

    <div class="note-text">{$noteBasDePage}</div>
    <div class="footer">BSI généré automatiquement – {$dIdent['label_type']} · {$year}</div>
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

    /**
     * Nettoie un montant (string ou float) en float propre.
     * Ex: "1 200,50" -> 1200.50
     */
    private function parseAmount(mixed $val): float
    {
        if (is_float($val) || is_int($val)) {
            return (float)$val;
        }
        if (empty($val) || !is_string($val)) {
            return 0.0;
        }
        // Remplacer virgule par point, supprimer les espaces insécables ou normaux
        $clean = str_replace([',', ' ', "\xc2\xa0"], ['.', '', ''], $val);
        return (float)$clean;
    }

    // --- (Garder tes fonctions computePercentages, buildDonutSvg ici telles quelles) ---
    // Je ne les remets pas pour ne pas surcharger la réponse, mais elles sont nécessaires.
    
    private function computePercentages(array $segments): array
    {
        // Copier/coller ton code existant
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
        // Copier/coller ton code existant
        // ... (Le code SVG du fichier original)
        // Juste pour rappel, le code original est parfait.
        
        $width = 190; $height = 190; $cx = 95; $cy = 95; $radius = 70; $strokeWidth = 26;
        $circumference = 2 * M_PI * $radius;
        $circles = ''; $currentOffset = 0.0;

        foreach ($segments as $segment) {
            $percent = (float)($segment['percent'] ?? 0.0);
            if ($percent <= 0.0) continue;
            $length = $circumference * $percent / 100.0;
            $gap = $circumference - $length;
            $color = htmlspecialchars((string)($segment['color'] ?? '#000'), ENT_QUOTES);
            $circles .= sprintf('<circle cx="%F" cy="%F" r="%F" fill="none" stroke="%s" stroke-width="%F" stroke-dasharray="%F %F" stroke-dashoffset="%F" />', $cx, $cy, $radius, $color, $strokeWidth, $length, $gap, -$currentOffset);
            $currentOffset += $length;
        }
        if ($circles === '') {
            $circles = sprintf('<circle cx="%F" cy="%F" r="%F" fill="none" stroke="#E5E7EB" stroke-width="%F" />', $cx, $cy, $radius, $strokeWidth);
        }
        return <<<SVG
<svg width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}" xmlns="http://www.w3.org/2000/svg">{$circles}<circle cx="{$cx}" cy="{$cy}" r="45" fill="#FFFFFF" /></svg>
SVG;
    }

    // Helper pour sortir le gros bloc CSS du renderHtml
    private function getCssDefinition($headerGreyBg, $headerGrey, $blueCartouche, $greenMain, $greenLight, $borderGrey, $lightGrey, $darkText, $greyText, $rowBlue): string
    {
        // Tu peux coller ton CSS original ici pour alléger renderHtml
        return <<<CSS
            body { font-family: Calibri, Arial, sans-serif; font-size: 9pt; color: #000000; }
            .page { width: 100%; box-sizing: border-box; }
            .header-table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
            .header-table td { padding: 4px 6px; font-size: 9pt; }
            .header-left { background-color: {$headerGreyBg}; color: {$headerGrey}; font-weight: 700; }
            .header-left-sub { font-size: 7pt; font-weight: 400; }
            .header-right { background-color: {$blueCartouche}; color: #FFFFFF; text-align: center; font-weight: 700; font-size: 9pt; }
            .identity-table { width: 100%; border-collapse: collapse; margin-bottom: 3px; }
            .identity-name-row td { background-color: {$greenMain}; color: #FFFFFF; font-size: 13pt; font-weight: 700; text-align: center; padding: 4px 6px; }
            .identity-poste-row td { background-color: #FFFFFF; color: {$darkText}; font-size: 10pt; font-weight: 500; text-align: center; padding: 3px 6px; border: 1px solid {$greenMain}; }
            .info-line-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
            .info-line-table td { border: 1px solid {$borderGrey}; padding: 4px 6px; }
            .info-label { font-size: 8pt; color: {$greyText}; }
            .info-value { font-size: 9pt; font-weight: 700; color: #000000; }
            .section-title { width: 100%; background-color: {$greenLight}; color: #006100; font-weight: 700; font-size: 9.5pt; text-transform: uppercase; padding: 3px 6px; margin-top: 4px; border-top: 1px solid #8FBF8F; border-bottom: 1px solid #8FBF8F; }
            .remu-section { width: 100%; margin-top: 2px; margin-bottom: 6px; }
            .remu-overview-table { width: 100%; border-collapse: collapse; }
            .remu-overview-left .top-label { font-size: 8pt; color: {$greyText}; }
            .remu-overview-left .top-amount { font-size: 13pt; font-weight: 700; }
            .remu-overview-left .top-subtitle { font-size: 10pt; font-weight: 700; }
            .remu-overview-right { font-size: 8pt; }
            .remu-overview-right .amount { font-size: 11pt; font-weight: 700; }
            .remu-details-layout { width: 100%; border-collapse: collapse; margin-top: 6px; }
            .remu-detail-table { width: 100%; font-size: 8pt; }
            .remu-detail-amount { text-align: right; }
            .remu-highlight-table { width: 100%; margin-top: 6px; font-size: 8pt; background-color: {$rowBlue}; }
            .remu-highlight-amount { text-align: right; font-weight: 700; }
            .donut-wrapper { text-align: center; display: flex; flex-direction: column; align-items: center; }
            .social-table { width: 100%; border-collapse: collapse; font-size: 8pt; margin-top: 2px; }
            .social-table td, .social-table th { border: 1px solid {$borderGrey}; padding: 3px 4px; }
            .social-table thead th { border-bottom: 2px solid {$greenMain}; text-align: center; }
            .social-table tfoot td { background-color: {$greenLight}; font-weight: 700; }
            .power-table { width: 100%; border-collapse: collapse; font-size: 8pt; margin-top: 2px; }
            .power-table td, .power-table th { border: 1px solid {$borderGrey}; padding: 3px 4px; }
            .power-table thead tr { background-color: {$lightGrey}; font-weight: 700; }
            .note-text { font-size: 7pt; color: {$greyText}; margin-top: 3px; }
            .footer { margin-top: 5px; font-size: 7pt; color: {$greyText}; text-align: right; }
CSS;
    }
}