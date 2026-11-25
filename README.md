# FINCAISSE — Système de Gestion Financière, Caisse et Vente

Application web complète de gestion commerciale et de caisse conçue avec **PHP 8.2**, **C++20**, **MySQL**, **HTML5**, **CSS3** et **JavaScript (ES Modules)**.

## Architecture du projet

- `cpp/fincaisse_engine.cpp` : Moteur de calcul financier haute précision en C++20 (validation des ventes, calcul de marges et monnaie à rendre, réconciliation de caisse, agrégation analytique et bénéfice net estimé).
- `config/database.php` & `lib/Schema.php` : Connexion PDO MySQL (locale MAMP ou cloud via `MYSQL_URL` / `FINCAISSE_DB_*`) et création automatique des tables.
- `api/router.php` : API REST sécurisée par rôles (`ADMIN`, `ACCUEIL`, `CAISSE`), flux Accueil → Caissier → Reçu, transactions SQL `FOR UPDATE`, journal d’audit, export Excel (`.xls`), sauvegarde JSON et règle de conservation des 30 jours.
- `public/` : Interface SPA sans framework lourd, avec création du premier compte administrateur, connexion sécurisée, espaces **Patron**, **Accueil** et **Caissier**.

## Lancement en local (macOS + MAMP)

```bash
chmod +x start.sh
./start.sh
```

Puis ouvrez `http://127.0.0.1:8080`.

## Déploiement en ligne — Option 1 (Railway / Render / Fly.io avec Docker)

1. Créez un dépôt GitHub privé ou public et poussez ce projet.
2. Sur **Railway** (recommandé, très simple) :
   - Créez un projet → **Deploy from GitHub repo** (sélectionnez `fincaisse-web`, il détecte automatiquement `Dockerfile`).
   - Dans le même projet, cliquez **+ New → Database → Add MySQL**.
   - Dans votre service web FINCAISSE → onglet **Variables**, ajoutez une référence vers `${{MySQL.MYSQL_URL}}` sous le nom `MYSQL_URL` (ou `FINCAISSE_DB_URL`) — `config/database.php` lit automatiquement `MYSQL_URL`, `MYSQLHOST`/`MYSQLPORT`/`MYSQLDATABASE`/`MYSQLUSER`/`MYSQLPASSWORD`, ou `FINCAISSE_DB_*`.
   - Dans **Settings → Networking**, cliquez **Generate Domain** (HTTPS inclus).
3. Ouvrez l’URL générée : FINCAISSE initialise automatiquement les tables MySQL et affiche l’écran **Créer le premier compte administrateur**.
