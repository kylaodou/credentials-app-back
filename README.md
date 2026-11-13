# Credential App — API

API d'un coffre-fort personnel d'identifiants : chaque utilisateur y range ses comptes, mots de
passe, jetons d'accès et liens, chiffrés en base, et ne peut les afficher en clair qu'après avoir
confirmé son mot de passe.

L'interface web associée se trouve dans le dépôt [`credentials-app`](https://github.com/kylaodou/credentials-app).

## Fonctionnalités

- **Connexion avec Google** (OAuth 2) : redirection, retour et création automatique du compte.
- **Mot de passe applicatif** : défini après la première connexion Google, puis demandé pour
  confirmer les actions sensibles (`auth/set-password`, `auth/verify-password`).
- **Mot de passe oublié** par code à usage unique (OTP) envoyé par e-mail : envoi, vérification,
  réinitialisation.
- **Identifiants** (CRUD complet) : nom, type, identifiant, mot de passe, jeton, URL, description,
  date de validité.
- **Chiffrement** : l'identifiant, le mot de passe et le jeton sont chiffrés avec la clé de
  l'application (`APP_KEY`) et masqués dans les réponses ; ils ne sont renvoyés en clair que par
  la route `GET /api/credentials/{id}/reveal`.
- **E-mails** de bienvenue et d'envoi du code OTP.
- Authentification de l'API par jetons Sanctum.

## Stack technique

- PHP 8.2+, Laravel 12
- PostgreSQL
- Laravel Sanctum

## Routes

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/api/auth/google/redirect` | Redirige vers Google |
| GET | `/api/auth/google/callback` | Retour de Google, crée la session |
| POST | `/api/auth/forgot-password/send-otp` | Envoie un code par e-mail |
| POST | `/api/auth/forgot-password/verify-otp` | Vérifie le code |
| POST | `/api/auth/forgot-password/reset` | Définit un nouveau mot de passe |
| GET | `/api/me` | Utilisateur connecté |
| POST | `/api/logout` | Déconnexion |
| POST | `/api/auth/set-password` | Définit le mot de passe applicatif |
| POST | `/api/auth/verify-password` | Vérifie le mot de passe applicatif |
| GET, POST, PUT, DELETE | `/api/credentials` | Gestion des identifiants |
| GET | `/api/credentials/{id}/reveal` | Affiche un identifiant en clair |

## Prérequis

- PHP 8.2+, Composer 2
- PostgreSQL 14+
- Un projet Google Cloud avec un identifiant client OAuth 2.0

## Installation

```bash
git clone https://github.com/kylaodou/credentials-app-back.git
cd credentials-app-back

composer install
cp .env.example .env
php artisan key:generate
```

Renseignez dans `.env` :

```env
DB_CONNECTION=pgsql
DB_DATABASE=credential-app
DB_USERNAME=postgres
DB_PASSWORD=votre_mot_de_passe

FRONTEND_URL=http://localhost:4200

GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=http://localhost:8000/api/auth/google/callback
```

Configurez aussi `MAIL_*` pour l'envoi réel des e-mails (par défaut, `MAIL_MAILER=log` les écrit
dans `storage/logs/laravel.log`).

```bash
php artisan migrate
php artisan serve
```

L'API est disponible sur http://localhost:8000.

> Ne changez jamais `APP_KEY` sur une base existante : les identifiants déjà chiffrés ne pourraient
> plus être déchiffrés.

## Tests

```bash
php artisan test
```

## Auteur

[@kylaodou](https://github.com/kylaodou)
