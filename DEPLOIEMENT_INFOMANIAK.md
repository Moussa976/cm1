# Déploiement de Mon Atelier de Classe sur Infomaniak

## Prérequis

- Hébergement Web avec PHP 8.2 ou supérieur
- Base de données MySQL
- Accès SSH recommandé
- Le domaine ou sous-domaine doit pointer vers le dossier `public/`

## Installation

1. Envoyer le projet sur l’hébergement.
2. Copier `.env.prod.example` vers `.env.local` et renseigner :
   - `APP_SECRET` avec une valeur aléatoire longue ;
   - `DATABASE_URL` avec les identifiants MySQL Infomaniak ;
   - `INSTALL_TOKEN` avec un jeton aléatoire à usage unique.
3. Exécuter `composer install --no-dev --optimize-autoloader`.
4. Donner les droits d’écriture au dossier `var/`.
5. Faire pointer le site vers `public/`.
6. Ouvrir une seule fois `/install?token=VOTRE_INSTALL_TOKEN`.
7. Se connecter avec le compte temporaire, modifier immédiatement le nom et le mot de passe, puis supprimer `INSTALL_TOKEN` de la configuration.
8. Passer `APP_ENV=prod`, vider le cache avec `php bin/console cache:clear --env=prod` et activer HTTPS.

## Sauvegardes

- Utiliser le bouton **Sauvegarder** dans Mon Atelier de Classe pour exporter les données pédagogiques en JSON.
- Activer aussi les sauvegardes automatiques MySQL et fichiers depuis le Manager Infomaniak.

## Sécurité

- Ne jamais envoyer `.env.local` sur un dépôt public.
- Ne jamais conserver le mot de passe temporaire après la première connexion.
- Le répertoire exposé par le serveur doit être uniquement `public/`.
- Conserver Symfony et ses dépendances à jour et exécuter régulièrement `composer audit`.

## WAMP local

Le projet source se trouve dans `C:\Users\Moussa\Downloads\BO cycle 3\capcm1` et une jonction existe dans `C:\wamp64\www\capcm1`. Un alias WAMP `/capcm1` a été préparé. Après redémarrage manuel de WAMP, l’adresse prévue est `http://localhost/capcm1/login`.
