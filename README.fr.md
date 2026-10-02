# Kimi Game Rank · kimi-game-rank

[English](README.md) · [简体中文](README.zh-CN.md) · [繁體中文](README.zh-TW.md) · [日本語](README.ja.md) · [한국어](README.ko.md) · [Italiano](README.it.md) · **Français**

Un classement à six dimensions pour la **communauté Kimi**. PHP + MySQL, frontend sans compilation (HTML / CSS / JavaScript natifs).

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](LICENSE)

> Ce projet est **non officiel**. Il n'a aucun lien avec Kimi ni avec Moonshot AI.

---

## De quoi s'agit-il

Un site qui rassemble les jeux, outils, œuvres littéraires et créations dérivées de la communauté, les note selon six dimensions et les classe. La notation est automatique, réalisée par un algorithme ; l'administrateur peut corriger manuellement certaines œuvres.

## Fonctionnalités

| Module | Description |
|---|---|
| Classements | 4 catégories × 4 palmarès (Général / Votes / Légendes / Pépites), badges de rang et libellés de note |
| Détail d'une œuvre | Graphique radar à six dimensions, détail par dimension, présentation et commentaires (réponses imbriquées sans limite) |
| Discussion | Discussion mondiale (interrogation en temps réel) + discussion avec l'IA (sortie en flux, recherche web, règles du site injectées) |
| Profil | Avatar, usage de l'IA, informations IP et historique des visites, réglages d'apparence |
| Connexion | Connexion par mot de passe / inscription / mode invité, avec une vérification humaine en un clic à la connexion et à l'inscription |
| Modération des contenus | Commentaires, discussion et images téléversées reçoivent une note de gravité de 1 à 10 avant publication : seuls les niveaux élevés sont bloqués, les niveaux intermédiaires passent avec une mention |
| Panneau d'administration | Collecte et mise à jour en masse, sauvegarde de la base, modération des commentaires, gestion des étiquettes, co-administrateurs |
| Résolution intelligente des liens | Activée par défaut : si la page d'une œuvre n'est qu'une page de redirection, l'adresse réelle située derrière est utilisée |
| Signalements disciplinaires | Signaler un utilisateur en un clic : plusieurs motifs, durée du bannissement (y compris permanent et en fractions de jour), blocage de la provenance, suppression de ses commentaires / discussions / images. La personne signalée peut toujours se connecter, mais l'interface reste verrouillée sur la page de violation et le backend rejette toutes les autres API |
| Thèmes de fête | Un thème dédié à chaque commémoration — Fête nationale, Révolution de Xinhai (10/10), Commémoration de la guerre de Corée (25/10) et Halloween (31/10, mode sombre forcé). Chacun a sa palette et ses décors, actif uniquement le jour dit |
| Thèmes | Plusieurs thèmes visuels ; les polices sont chargées par le navigateur directement depuis des CDN publiques, sans consommer la bande passante de votre serveur |

## Pile technique et prérequis

- **PHP ≥ 7.0** (le socle du code est la 7.0 ; aucune syntaxe 7.1+ n'est utilisée)
- **MySQL / MariaDB** (InnoDB, `utf8mb4`)
- Frontend : sources en modules ES → un seul fichier compilé, **aucun runtime Node requis** (uniquement à la compilation)
- Aucune dépendance Composer, aucun framework frontend

## Déploiement

```bash
git clone https://github.com/silverbullet-liang/kimi-game-rank.git
cd kimi-game-rank

# 1) Configuration principale : copiez le modèle et renseignez la connexion à la base
cp config/config.sample.php config/config.php
$EDITOR config/config.php

# 2) Réserve de clés API : copiez le modèle et ajoutez vos propres clés
cp config/api_keys.sample.php config/api_keys.php
$EDITOR config/api_keys.php

# 3) Listes de modération : les listes officielles ne sont pas publiques, partez des exemples
cp app/data/moderation_words.sample.txt app/data/moderation_words.txt
cp app/data/moderation_allow.sample.txt app/data/moderation_allow.txt
```

Pointez la racine du site vers le répertoire racine de ce projet, puis ouvrez la page d'accueil dans un navigateur : **l'initialisation est automatique** (création de la base, des tables et des clés). Si la base n'est pas prête, la page l'indique clairement.

> Si les fichiers de listes sont absents, le module de modération se réduit automatiquement à « ne rien bloquer » et le site continue de fonctionner.

## Configuration

| Fichier | Dans le dépôt ? | Description |
|---|---|---|
| `config/config.php` | ❌ **ne jamais committer** | Connexions à la base (principale + administration), informations du site, clés AES / cron, identifiants de l'administrateur |
| `config/api_keys.php` | ❌ **ne jamais committer** | Réserve de clés API des modèles (Zhipu, OpenRouter) |
| `app/data/moderation_words.txt` | ❌ **ne jamais committer** | Liste de modération (la liste officielle n'est pas publique) |
| `config/config.sample.php` | ✅ | Modèle de configuration principale |
| `config/api_keys.sample.php` | ✅ | Modèle de réserve de clés |

Les trois entrées ❌ sont déjà inscrites dans le `.gitignore`. Avant de committer, lancez le contrôle de fuite :

```bash
python3 tools/repo_check.py
```

Il vérifie trois choses : si des fichiers interdits se sont glissés dans l'index, si une empreinte de clé apparaît dans le texte, et **si des valeurs de connexion à la base ont fuité**.

## Arborescence

```
├── api/            Couche des points d'entrée (JSON : authentification + CSRF + limitation de débit)
├── app/            Couche métier (configuration, base, notation, authentification, modération, canaux IA…)
│   └── data/       Listes de modération (seuls les .sample sont conservés)
├── assets/
│   ├── css/        Styles et thèmes
│   ├── docs/       Documents internes au site (corps des pages publiques)
│   ├── emoji/      Émojis de discussion
│   └── js/
│       ├── src/    Sources frontend (modules ES, découpés par page)
│       └── app.js  Résultat de la compilation (généré depuis src : ne pas modifier à la main)
├── config/         Configuration et clés (seuls les .sample sont committés)
├── docs/           Documents de conception et d'algorithmes
├── sql/            Schéma des tables
├── standalone/     Pages autonomes (journal des modifications / page de redirection)
├── storage/        Données d'exécution (cache, journaux, envois, sauvegardes)
└── tools/          Scripts de compilation et de vérification
```

Les polices ne sont pas stockées dans le dépôt : le navigateur les charge directement depuis des CDN publiques. Sources et licences dans [FONTS.md](FONTS.md).

## Compiler le frontend

Après avoir modifié les sources sous `assets/js/src/`, il faut recompiler :

```bash
python3 tools/build.py          # src/* → assets/js/app.js
```

## Vérifications avant de committer

```bash
python3 tools/phpcheck.py       # vérification de syntaxe et du socle PHP
python3 tools/jscheck.py        # vérification des doublons de noms côté frontend
python3 tools/repo_check.py     # contrôle des fuites (clés / listes / connexions à la base)
```

## Sécurité et tests d'intrusion

Nous accueillons les chercheurs en sécurité qui souhaitent examiner et tester ce projet. Pour le **périmètre autorisé, ce qui est permis et interdit, et la manière de signaler une vulnérabilité**, lisez d'abord [SECURITY.md](SECURITY.md).

## Licence

Ce projet est publié sous **[GNU Affero General Public License v3.0](LICENSE)** (AGPL-3.0).

Vous êtes libre de l'utiliser, de le modifier et de le distribuer, avec deux contraintes essentielles :

1. **Les œuvres dérivées doivent être publiées sous la même licence** ;
2. **Si vous proposez à autrui une version modifiée en tant que service réseau, vous devez aussi leur fournir le code source complet** — c'est précisément ce qui distingue l'AGPL de la GPL, et la raison de ce choix.

## Contribuer

Voir [CONTRIBUTING.md](CONTRIBUTING.md). Avant de proposer une contribution, vérifiez que vous **n'avez inclus aucune clé, connexion à la base ou liste de modération**.

## Avertissement

- Ce projet n'a aucun lien avec Kimi / Moonshot AI et n'est pas un projet officiel.
- Les utilisateurs doivent respecter les lois de leur pays ainsi que les conditions des services tiers qu'ils utilisent.
- Le code est fourni « en l'état », sans garantie d'aucune sorte, explicite ou implicite.
