# 📊 BSI Generator – Application Web RH

**BSI Generator** est une application web interne permettant de générer automatiquement les **Bilans Sociaux Individuels (BSI)** à partir d’exports de paie au format CSV.

Elle remplace un outil Python existant par une version **100% web**, plus simple à utiliser, sans installation, et accessible depuis un simple navigateur.

---

## ✅ Fonctionnalités principales

- 📥 **Import** des fichiers paie :
  - BSI Money (montants, cotisations, primes…)
  - BSI Jours (jours travaillés / RTT…)
  - Descriptions collaborateurs (identité, poste, contrat…)

- 🤖 **Analyse automatique**
  - Fusion des données par collaborateur
  - Calcul des cotisations & rémunération brute
  - Détection automatique des salariés **au forfait jours**

- 📄 **Génération automatique**
  - 1 fichier Excel **par collaborateur**
  - Sélection automatique du bon template :
    - `TemplateBsi.xlsx` (standard)
    - `TemplateBsiFJ.xlsx` (forfait jours)

- 🎨 **Interface moderne**
  - Design Tailwind
  - Mode **clair / sombre**
  - Journal d’exécution + statistiques
  - Téléchargement d’un ZIP final

- 🔒 **Confidentialité totale**
  - Tous les traitements sont réalisés **en local**
  - Aucun envoi de données vers l’extérieur

---

## 🏗️ Architecture du projet

```
bsi-web/
├── public/                     # Interface utilisateur + API
│   ├── index.html              # Application web RH
│   ├── assets/
│   │   ├── js/app.js           # Front logic (upload, thème, requêtes)
│   │   └── css/app.css         # (Optionnel si Tailwind CDN)
│   └── api/generate-bsi.php    # Point d'entrée backend
│
├── src/
│   ├── Application/
│   │   └── Services/
│   │       └── BsiGenerationService.php
│   ├── Http/
│   │   └── Controllers/
│   │       └── GenerateBsiController.php
│   └── Infrastructure/
│       ├── Csv/CsvEmployeeReader.php
│       ├── Excel/BsiExcelGenerator.php
│       └── Storage/LocalFilesystemStorage.php
│
├── storage/
│   ├── output/                 # Fichiers générés (zip + xlsx)
│   └── templates/excel/        # Templates BSI
│       ├── TemplateBsi.xlsx
│       └── TemplateBsiFJ.xlsx
│
├── .env
├── composer.json
└── README.md
```

---

## ✅ Prérequis

- PHP **8.1+**
- Composer
- Navigateur moderne (Chrome, Edge, Firefox…)

---

## 🚀 Installation

```bash
git clone <repo>
cd bsi-web
composer install
```

Créer un fichier `.env` à la racine :

```env
OUTPUT_PATH=storage/output
PATH_TEMPLATE_XLSX=storage/templates/excel/TemplateBsi.xlsx
PATH_TEMPLATE_FJ_XLSX=storage/templates/excel/TemplateBsiFJ.xlsx
```

---

## ▶️ Lancement

Démarrer le serveur PHP intégré :

```bash
php -S localhost:8000 -t public
```

Accéder à l’application :

👉 http://localhost:8000

---

## 🧩 Utilisation

1. Ouvrir l'application dans le navigateur
2. Sélectionner :
   - **BSI Money.csv**
   - **BSI Jours.csv**
   - **1 ou plusieurs descriptions.csv**
3. Choisir l’année de campagne
4. Cliquer sur **Lancer la génération**
5. Télécharger le ZIP généré

---

## 📁 Sortie générée

- `BSI_<Nom>.xlsx` pour chaque collaborateur
- Un fichier ZIP regroupant l’ensemble
- Utilisation automatique du template FJ si RTT détectés

---

## 🌙 Mode sombre / clair

- Géré automatiquement via Tailwind
- Toggle dans l’interface
- Mémorisation du choix via `localStorage`

---

## 🔧 Technologies

- PHP 8+
- PhpSpreadsheet
- Tailwind CSS
- JavaScript Vanilla
- Dotenv

---

## ✅ Avantages

- Aucun logiciel à installer
- Simplicité d’utilisation
- Maintien de confidentialité
- Reproductible chaque année
- Code structuré & maintenable

---

## 🏁 Prochaines évolutions possibles

- Export PDF automatique
- Historique des générations
- Validation avancée des CSV
- Authentification interne

---

## 📜 Licence

Usage interne – Non destiné à diffusion publique.