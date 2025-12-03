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

    /**
     * Génère un BSI de test avec toutes les valeurs à 0.
     *
     * @param string $type "forfait_heure" ou "forfait_jour"
     * @param int    $campaignYear
     */
    public function generateTestBsi(string $type, int $campaignYear): string
    {
        if (!in_array($type, ['forfait_heure', 'forfait_jour'], true)) {
            throw new \InvalidArgumentException("Type de BSI de test invalide : {$type}");
        }

        if (!is_dir($this->outputDir) && !mkdir($concurrent = $this->outputDir, 0775, true) && !is_dir($concurrent)) {
            throw new \RuntimeException("Impossible de créer le répertoire de sortie PDF : {$this->outputDir}");
        }

        $labelType = $type === 'forfait_heure' ? 'Forfait heures' : 'Forfait jours';
        $fileLabel = $type === 'forfait_heure' ? 'FORFAIT_HEURES' : 'FORFAIT_JOURS';

        $filename = sprintf('TEST_BSI_%s_%d.pdf', $fileLabel, $campaignYear);
        $path     = $this->outputDir . '/' . $filename;

        $fakeEmployee = [
            'nom'              => 'NOM',
            'prenom'           => 'Prénom',
            'poste'            => 'Poste occupé',
            'anciennete'       => '4,7 an(s)',
            'date_arrivee'     => '19/03/' . max(2000, $campaignYear - 3),
            'type_contrat'     => $labelType === 'Forfait jours' ? 'CDI Forfait jours' : 'CDI',
            'forfait_jours'    => $type === 'forfait_jour',
            'jours_travailles' => '280 jours',
        ];

        $html = $this->buildTestHtml($fakeEmployee, $campaignYear, $labelType);

        $mpdf = new Mpdf([
            'format'        => 'A4',
            'margin_left'   => 5,
            'margin_right'  => 5,
            'margin_top'    => 5,
            'margin_bottom' => 5,
        ]);

        $mpdf->SetTitle("BSI de test - {$labelType} {$campaignYear}");
        $mpdf->WriteHTML($html);
        $mpdf->Output($path, Destination::FILE);

        return $filename;
    }

    /**
     * Construit le HTML du BSI de test.
     *
     * @param array<string,mixed> $employee
     */
    private function buildTestHtml(array $employee, int $campaignYear, string $labelType): string
    {
        $safe = static fn (string $key) => isset($employee[$key])
            ? htmlspecialchars((string) $employee[$key], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            : '';

        $nom        = $safe('nom');
        $prenom     = $safe('prenom');
        $nomComplet = $nom . ' ' . $prenom;

        // Palette proche Excel
        $headerGrey    = '#595959';
        $headerGreyBg  = '#D9D9D9';
        $blueCartouche = '#305496';
        $greenMain     = '#00B050';
        $greenLight    = '#E2F0D9';
        $borderGrey    = '#A6A6A6';
        $lightGrey     = '#F2F2F2';
        $darkText      = '#002060';
        $greyText      = '#555555';
        $rowBlue       = '#D9EAF7';

        // --- Données de rémunération (BSI réel : à remplacer par les vrais montants) -------------
        $salaireBase        = 0.0;
        $heuresSupp         = 0.0;
        $primeAnnuelle      = 0.0;
        $primeInteressement = 0.0;

        $montantAnnuel  = 0.0;
        $montantMensuel = 0.0;
        $equivMois      = 0.0;

        $montantAnnuelLabel  = '0 €';
        $montantMensuelLabel = '0 €';
        $equivMoisLabel      = '0,0';

        $remuBreakdown = [
            [
                'label' => 'Salaire de base annuel',
                'color' => $greenMain,
                'value' => $salaireBase,
            ],
            [
                'label' => 'Heures supplémentaires',
                'color' => '#FF00FF',
                'value' => $heuresSupp,
            ],
            [
                'label' => 'Prime annuelle',
                'color' => '#FFFF00',
                'value' => $primeAnnuelle,
            ],
            [
                'label' => "Prime d'intéressement",
                'color' => '#0070C0',
                'value' => $primeInteressement,
            ],
        ];

        $remuBreakdown = $this->computePercentages($remuBreakdown);
        $donutSvg      = $this->buildDonutSvg($remuBreakdown);

        // -----------------------------------------------------------------------------------------

        $css = <<<CSS
body {
    font-family: Calibri, Arial, sans-serif;
    font-size: 9pt;
    color: #000000;
}
.page {
    width: 100%;
    box-sizing: border-box;
}

/*** HEADER EXCEL-LIKE ***/
.header-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 4px;
}
.header-table td {
    padding: 4px 6px;
    font-size: 9pt;
}
.header-left {
    background-color: {$headerGreyBg};
    color: {$headerGrey};
    font-weight: 700;
}
.header-left-sub {
    font-size: 7pt;
    font-weight: 400;
}
.header-right {
    background-color: {$blueCartouche};
    color: #FFFFFF;
    text-align: center;
    font-weight: 700;
    font-size: 9pt;
}

/*** IDENTITÉ ***/
.identity-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 3px;
}
.identity-name-row td {
    background-color: {$greenMain};
    color: #FFFFFF;
    font-size: 13pt;
    font-weight: 700;
    text-align: center;
    padding: 4px 6px;
}
.identity-poste-row td {
    background-color: #FFFFFF;
    color: {$darkText};
    font-size: 10pt;
    font-weight: 500;
    text-align: center;
    padding: 3px 6px 5px 6px;
    border-left: 1px solid {$greenMain};
    border-right: 1px solid {$greenMain};
    border-bottom: 1px solid {$greenMain};
}

/*** LIGNE INFOS 4 BLOCS ***/
.info-line-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 6px;
}
.info-line-table td {
    border: 1px solid {$borderGrey};
    padding: 4px 6px;
}
.info-label {
    font-size: 8pt;
    color: {$greyText};
}
.info-value {
    font-size: 9pt;
    font-weight: 700;
    color: #000000;
}

/*** TITRES DES SECTIONS ***/
.section-title {
    width: 100%;
    background-color: {$greenLight};
    color: #006100;
    font-weight: 700;
    font-size: 9.5pt;
    text-transform: uppercase;
    padding: 3px 6px;
    margin-top: 4px;
    border-top: 1px solid #8FBF8F;
    border-bottom: 1px solid #8FBF8F;
}

/*** SECTION REMUNERATION – layout + donut dynamique ***/

.remu-section {
    width: 100%;
    margin-top: 2px;
    margin-bottom: 6px;
}

/* Bloc global "Tu as perçu / soit …" (pleine largeur) */
.remu-overview-table {
    width: 100%;
    border-collapse: collapse;
}
.remu-overview-table td {
    padding: 2px 6px;
    vertical-align: top;
}
.remu-overview-left .top-label {
    font-size: 8pt;
    color: {$greyText};
}
.remu-overview-left .top-amount {
    font-size: 13pt;
    font-weight: 700;
}
.remu-overview-left .top-subtitle {
    font-size: 10pt;
    font-weight: 700;
}
.remu-overview-right {
    font-size: 8pt;
}
.remu-overview-right .amount {
    font-size: 11pt;
    font-weight: 700;
}
.remu-overview-right .main-label {
    font-size: 9pt;
    font-weight: 700;
}

/* 2ème ligne : gauche détails, droite donut */
.remu-details-layout {
    width: 100%;
    border-collapse: collapse;
    margin-top: 6px;
}
.remu-details-layout td {
    vertical-align: top;
    padding: 2px 6px;
}

/* Détails à gauche */
.remu-detail-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 8pt;
}
.remu-detail-table td {
    padding: 1px 2px;
}
.remu-detail-label {
    white-space: nowrap;
}
.remu-detail-amount {
    width: 35%;
    text-align: right;
}

/* blocs bleus bas */
.remu-highlight-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 6px;
    font-size: 8pt;
}
.remu-highlight-table tr {
    background-color: {$rowBlue};
}
.remu-highlight-table tr + tr {
    border-top: 1px solid {$borderGrey};
}
.remu-highlight-table td {
    padding: 4px 6px;
}
.remu-highlight-label {
    font-weight: 700;
}
.remu-highlight-amount {
    text-align: right;
    font-weight: 700;
}

/* Donut à droite : centré, plus grand */
.donut-wrapper {
    text-align: center;
    font-size: 8pt;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-start;
}
.donut-caption {
    margin-top: 4px;
    font-size: 7pt;
    color: {$greyText};
}

/*** TES CHARGES SOCIALES & AVANTAGES SOCIAUX ***/
.social-extra {
    width: 100%;
    border-collapse: collapse;
    margin-top: 2px;
    margin-bottom: 3px;
    text-align: center;
    font-size: 8pt;
}
.social-extra td {
    padding: 2px 4px;
    vertical-align: top;
}
.social-extra-amount {
    font-size: 11pt;
    font-weight: 700;
}
.social-extra-label {
    font-size: 8pt;
}
.social-extra-small {
    font-size: 7pt;
    color: {$greyText};
    font-style: italic;
}

.social-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 8pt;
    margin-top: 2px;
}
.social-table th,
.social-table td {
    border: 1px solid {$borderGrey};
    padding: 3px 4px;
}
.social-table thead tr {
    background-color: #FFFFFF;
}
.social-table thead th {
    border-bottom: 2px solid {$greenMain};
    text-align: center;
    font-weight: 700;
}
.social-table tbody td:first-child {
    padding-left: 6px;
}
.social-table tfoot td {
    background-color: {$greenLight};
    font-weight: 700;
}

/*** POUVOIR D'ACHAT ***/
.power-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 8pt;
    margin-top: 2px;
}
.power-table th,
.power-table td {
    border: 1px solid {$borderGrey};
    padding: 3px 4px;
}
.power-table thead tr {
    background-color: {$lightGrey};
    font-weight: 700;
}

/*** NOTES & FOOTER ***/
.note-text {
    font-size: 7pt;
    color: {$greyText};
    margin-top: 3px;
}
.footer {
    margin-top: 5px;
    font-size: 7pt;
    color: {$greyText};
    text-align: right;
}
CSS;

        $html = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>BSI de test - {$labelType} {$campaignYear}</title>
    <style>
        {$css}
    </style>
</head>
<body>
<div class="page">

    <!-- Bandeau haut -->
    <table class="header-table">
        <tr>
            <td class="header-left" style="width: 65%;">
                SYNERGIE DÉVELOPPEMENT<br>
                <span class="header-left-sub">Talents & Habitat</span>
            </td>
            <td class="header-right" style="width: 35%;">
                Bilan Social Individuel<br>
                {$campaignYear}
            </td>
        </tr>
    </table>

    <!-- Identité -->
    <table class="identity-table">
        <tr class="identity-name-row">
            <td>{$nomComplet}</td>
        </tr>
        <tr class="identity-poste-row">
            <td>{$safe('poste')}</td>
        </tr>
    </table>

    <!-- Ligne infos -->
    <table class="info-line-table">
        <tr>
            <td style="width: 25%;">
                <span class="info-label">Ancienneté</span><br>
                <span class="info-value">{$safe('anciennete')}</span>
            </td>
            <td style="width: 25%;">
                <span class="info-label">Date d'arrivée</span><br>
                <span class="info-value">{$safe('date_arrivee')}</span>
            </td>
            <td style="width: 25%;">
                <span class="info-label">Contrat</span><br>
                <span class="info-value">{$safe('type_contrat')}</span>
            </td>
            <td style="width: 25%;">
                <span class="info-label">Jours travaillés</span><br>
                <span class="info-value">{$safe('jours_travailles')}</span>
            </td>
        </tr>
    </table>

    <!-- TA REMUNERATION BRUTE ANNUELLE TOTALE -->
    <div class="section-title">
        TA REMUNERATION BRUTE ANNUELLE TOTALE
    </div>

    <div class="remu-section">
        <!-- Ligne 1 : tu as perçu / soit mensuellement -->
        <table class="remu-overview-table">
            <tr>
                <td style="width: 55%;" class="remu-overview-left">
                    <span class="top-label">Tu as perçu</span><br>
                    <span class="top-amount">{$montantAnnuelLabel}</span><br>
                    <span class="top-subtitle">Brut annuel</span>
                </td>
                <td style="width: 45%;" class="remu-overview-right">
                    <span class="label">soit</span>
                    <span class="amount">{$montantMensuelLabel}</span>
                    <span class="main-label">mensuellement</span><br>
                    <span class="label">(ou l'équivalent de {$equivMoisLabel} mois de salaire)</span>
                </td>
            </tr>
        </table>

        <!-- Ligne 2 : gauche détail, droite donut -->
        <table class="remu-details-layout">
            <tr>
                <!-- Colonne détail -->
                <td style="width: 55%;">
                    <table class="remu-detail-table">
                        <tr>
                            <td class="remu-detail-label">Salaire de base annuel</td>
                            <td class="remu-detail-amount">{$montantAnnuelLabel}</td>
                        </tr>
                        <tr>
                            <td class="remu-detail-label">Heures supplémentaires</td>
                            <td class="remu-detail-amount">0 €</td>
                        </tr>
                        <tr>
                            <td class="remu-detail-label">Prime annuelle</td>
                            <td class="remu-detail-amount">0 €</td>
                        </tr>
                        <tr>
                            <td class="remu-detail-label">Prime d'intéressement</td>
                            <td class="remu-detail-amount">0 €</td>
                        </tr>
                    </table>

                    <table class="remu-highlight-table">
                        <tr>
                            <td class="remu-highlight-label">Ton brut annuel :</td>
                            <td class="remu-highlight-amount">{$montantAnnuelLabel}</td>
                        </tr>
                        <tr>
                            <td class="remu-highlight-label">Acompte sur le salaire :</td>
                            <td class="remu-highlight-amount">{$montantAnnuelLabel}</td>
                        </tr>
                    </table>
                </td>

                <!-- Colonne donut -->
                <td style="width: 45%;">
                    <div class="donut-wrapper">
                        {$donutSvg}
                        <div class="donut-caption">Répartition de la rémunération brute</div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <!-- TES CHARGES SOCIALES & AVANTAGES SOCIAUX -->
    <div class="section-title">
        TES CHARGES SOCIALES & AVANTAGES SOCIAUX
    </div>

    <table class="social-extra">
        <tr>
            <td style="width: 33%;">
                <div class="social-extra-amount">0,00 €</div>
                <div class="social-extra-label">de frais de transport</div>
            </td>
            <td style="width: 33%;">
                <div class="social-extra-amount">0,00 €</div>
                <div class="social-extra-label">de chèques cadeaux</div>
            </td>
            <td style="width: 34%;">
                <div class="social-extra-label">Hors remise personnelle sur achat</div>
                <div class="social-extra-small">(dans la limite de 20 000 € tous les 3 ans)</div>
            </td>
        </tr>
    </table>

    <table class="social-table">
        <thead>
            <tr>
                <th style="width: 40%;"></th>
                <th style="width: 30%;">Collaborateur</th>
                <th style="width: 30%;">Employeur</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Maladie</td>
                <td>0 €</td>
                <td>0 €</td>
            </tr>
            <tr>
                <td>Retraite</td>
                <td>0 €</td>
                <td>0 €</td>
            </tr>
            <tr>
                <td>Prévoyance</td>
                <td>0 €</td>
                <td>0 €</td>
            </tr>
            <tr>
                <td>Assurance chômage</td>
                <td>0 €</td>
                <td>0 €</td>
            </tr>
            <tr>
                <td>Mutuelle</td>
                <td>0 €</td>
                <td>0 €</td>
            </tr>
        </tbody>
        <tfoot>
            <tr>
                <td>Total</td>
                <td>0 €</td>
                <td>0 €</td>
            </tr>
        </tfoot>
    </table>

    <!-- TON POUVOIR D'ACHAT -->
    <div class="section-title">
        TON POUVOIR D'ACHAT
    </div>

    <table class="power-table">
        <thead>
            <tr>
                <th style="width: 60%;">Élément</th>
                <th style="width: 40%;">Montant</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Net à payer annuel</td>
                <td>0 €</td>
            </tr>
            <tr>
                <td>Net à payer moyen par mois</td>
                <td>0 €</td>
            </tr>
        </tbody>
    </table>

    <div class="note-text">
        Les valeurs sont volontairement nulles pour ce BSI de test. Elles seront automatiquement
        alimentées à partir des données de paie réelles lors de la génération complète.
    </div>

    <div class="footer">
        BSI de test généré automatiquement – {$labelType} · {$campaignYear}
    </div>

</div>
</body>
</html>
HTML;

        return $html;
    }

    /**
     * Ajoute un champ 'percent' à chaque segment (en fonction de 'value').
     *
     * @param array<int,array<string,mixed>> $segments
     * @return array<int,array<string,mixed>>
     */
    private function computePercentages(array $segments): array
    {
        $total = 0.0;
        foreach ($segments as $s) {
            $total += max(0.0, (float) ($s['value'] ?? 0.0));
        }

        if ($total <= 0.0) {
            // Tout est à 0 → donut gris qui indique "aucune répartition"
            foreach ($segments as &$s) {
                $s['percent'] = 0.0;
            }
            unset($s);
            return $segments;
        }

        foreach ($segments as &$s) {
            $value        = max(0.0, (float) ($s['value'] ?? 0.0));
            $s['percent'] = $value / $total * 100.0;
        }
        unset($s);

        return $segments;
    }

    /**
     * Construit un donut SVG à partir des segments (label, color, percent).
     *
     * @param array<int,array<string,mixed>> $segments
     */
    private function buildDonutSvg(array $segments): string
    {
        // Donut plus grand
        $width  = 190;
        $height = 190;
        $cx = $cy = 95;
        $radius      = 70;
        $strokeWidth = 26;

        $circumference = 2 * M_PI * $radius;
        $currentOffset = 0.0;
        $circles       = '';

        foreach ($segments as $segment) {
            $percent = (float) ($segment['percent'] ?? 0.0);
            if ($percent <= 0.0) {
                continue;
            }

            $length = $circumference * $percent / 100.0;
            $gap    = $circumference - $length;
            $color  = htmlspecialchars((string) ($segment['color'] ?? '#000000'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $circles .= sprintf(
                '<circle cx="%1$F" cy="%2$F" r="%3$F" fill="none" stroke="%4$s" stroke-width="%5$F" stroke-dasharray="%6$F %7$F" stroke-dashoffset="%8$F" />',
                $cx,
                $cy,
                $radius,
                $color,
                $strokeWidth,
                $length,
                $gap,
                -$currentOffset
            );

            $currentOffset += $length;
        }

        if ($circles === '') {
            // Cas BSI de test : tout à 0 → donut gris neutre
            $circles = sprintf(
                '<circle cx="%1$F" cy="%2$F" r="%3$F" fill="none" stroke="#E5E7EB" stroke-width="%4$F" />',
                $cx,
                $cy,
                $radius,
                $strokeWidth
            );
        }

        $svg = <<<SVG
<svg width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}" xmlns="http://www.w3.org/2000/svg">
    {$circles}
    <circle cx="{$cx}" cy="{$cy}" r="45" fill="#FFFFFF" />
</svg>
SVG;

        return $svg;
    }
}
