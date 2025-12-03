# 📊 BSI Generator – Application Web RH

**BSI Generator** est une application web interne permettant de générer automatiquement les **Bilans Sociaux Individuels (BSI)** à partir d’exports de paie au format CSV, Excel ou XLS.  
Il remplace d’anciens scripts manuels ou Python par une interface **100% web**, moderne, intelligente et entièrement locale.

---

## ✅ Fonctionnalités principales

### 📥 Import multi‑formats
- Support des fichiers **.csv**, **.xlsx**, **.xls**
- Import de :
  - **BSI Money** (rémunération, primes, cotisations)
  - **BSI Jours** (temps de travail, RTT…)
  - **Descriptions collaborateurs** (identité, poste, contrat…) – *multi-upload*

### 🤖 Parsing intelligent
- Détection automatique des colonnes (Nom, Prénom, Matricule…)
- Tolérance aux variations de format :  
  > Exemple : “DUPONT T” → “Thierry DUPONT”
- Nettoyage des caractères spéciaux & encodages
- Fusion automatique des données par collaborateur

### 📄 Génération PDF native
- Production de **PDF vectoriels** via mPDF
- Mise en page dynamique en HTML/CSS
- Graphiques Donut en **SVG natif**

### 🧮 Logique métier intégrée
- Calcul du **brut annuel**
- Agrégation des cotisations (Salariales & Patronales)
- Détection automatique **Forfait Jours / Heures**

### 🔒 Sécurité & confidentialité
- Traitement **100% local**, aucune donnée envoyée vers l'extérieur
- Suppression automatique des fichiers temporaires

---

## 🏗️ Architecture du projet

```
bsi-web/
├── public/                         # Interface + API
│   ├── index.html                  # Application web RH
│   ├── assets/                     # JS & CSS
│   └── api/
│       ├── generate-bsi.php        # Génération PDF
│       └── test-bsi.php            # Endpoint de test
│
├── src/
│   ├── Application/Services/
│   │   └── BsiGenerationService.php
│   ├── Infrastructure/
│   │   ├── Csv/CsvEmployeeReader.php
│   │   └── Pdf/BsiPdfGenerator.php
│
├── storage/
│   └── output/                     # PDF générés (ignoré par Git)
│
├── docker-compose.yml
├── Dockerfile
├── composer.json
└── .env
```

---

## 🧰 Prérequis techniques

### 🔹 **Option A – Docker (recommandé)**
- Docker Desktop
- Docker Compose

### 🔹 **Option B – Installation locale**
- PHP **8.2+**
- Extensions : `gd`, `mbstring`, `zip`, `xml`
- Composer

---

## 🚀 Installation & lancement

### 🛠️ Étape 1 : Installation
```bash
git clone <votre-repo-git>
cd bsi-web
composer install   # si utilisation hors Docker
```

### ⚙️ Étape 2 : Configuration
Créer un fichier `.env` :

```env
OUTPUT_PATH=storage/output
```

### ▶️ Étape 3 : Démarrage

#### Via Docker :
```bash
docker-compose up -d --build
```
👉 Application disponible sur : **http://localhost:8000**

#### Via PHP :
```bash
php -S localhost:8000 -t public
```

---

## 🧩 Utilisation

1. Préparer vos fichiers :
   - Money  
   - Jours  
   - Descriptions (plusieurs possibles)
2. Ouvrir l’application : **http://localhost:8000**
3. Glisser‑déposer les fichiers dans l’interface
4. Lancer la génération
5. Télécharger l’archive ZIP contenant un PDF par collaborateur

---

## 📁 Sortie générée

- 1 fichier **PDF par collaborateur**
- Une archive ZIP contenant l’ensemble
- Mise en page professionnelle conforme à la charte RH

---

## 🔒 Gestion des données sensibles

- Le `.gitignore` exclut strictement :
  - `/storage`
  - `/vendor`
  - `.env`
- Les fichiers temporaires sont automatiquement nettoyés
- Environnement 100% interne

---

## 📜 Licence

Usage interne exclusivement – réservé au service RH.
