# LogiAdmin by LogiProf — 1.0.4

## Nouveautés 1.0.4

- LogiPlanning est retiré de LogiAdmin : il est géré par LogiOne (page des élèves, vue enseignant,
  administration des plans). LogiAdmin réunit désormais six outils.
- Les plannings ne sont pas touchés : plans, journées, branches et messages restent dans les tables
  `wp_logiplanning_*`, que LogiOne utilise. Aucune table n'est supprimée, ni à la mise à jour ni à la désinstallation.

## Nouveautés 1.0.3

- LogiPlanning était à la fois dans LogiOne et dans LogiAdmin, avec le même shortcode et le même nom de
  feuille de style : le planning pouvait s'afficher sans mise en forme. LogiAdmin le laissait à LogiOne.

## Nouveautés 1.0.2

- Mises à jour depuis WordPress, sur le modèle de LogiAi : LogiAdmin lit la dernière version publiée
  dans le dépôt public `logiadmin-releases` et la propose dans Extensions et dans
  LogiAdmin › Tableau de bord › Mises à jour (bouton « Vérifier maintenant »).
- Chaque paquet est signé (Ed25519) : LogiAdmin refuse tout paquet modifié ou qui ne porte pas la signature LogiProf.

## Publier une version

Le code reste dans ce dépôt privé ; les sites ne lisent que le dépôt public
[logiadmin-releases](https://github.com/logiprof/logiadmin-releases) (`latest.json`, `notes/`, `versions/X.Y.Z/logiadmin.zip`).

1. Augmenter la version dans `logiadmin.php` (en-tête `Version:` et constante `LOGIADMIN_VERSION`).
2. Écrire les nouveautés dans `notes/X.Y.Z.txt`, puis committer.
3. Lancer `scripts/publier-version.sh X.Y.Z <clone de logiadmin-releases>` : le script fabrique
   `logiadmin.zip` (sans `scripts/` ni `notes/`), le signe et met à jour `latest.json`.
4. Committer et pousser `logiadmin-releases` : les sites voient la mise à jour (vérification toutes les 12 h,
   ou tout de suite avec « Vérifier maintenant »).

La clé privée de signature est lue dans la variable `LOGIADMIN_SIGNING_KEY` ou dans
`~/.config/logiprof/logiadmin-signing.key`. Elle ne doit **jamais** être committée. Sans elle, aucune
nouvelle version ne peut être publiée ; si elle est perdue, il faut générer une nouvelle paire de clés et
installer à la main une version de LogiAdmin qui contient la nouvelle clé publique
(`includes/class-logiadmin-updater.php`, constante `PUBLIC_KEY`).


## Nouveautés 1.0.1

- Accueil selon groupe : une personne qui modifie le site (administrateur, éditeur, enseignant)
  n'est plus redirigée quand elle ouvre le constructeur Divi, l'éditeur WordPress, un aperçu ou le
  Theme Builder, y compris sur l'accueil public. Les liens « Accueil » ne sont pas réécrits pendant l'édition.
- Nouveau lien « Voir l'accueil public » dans la barre d'administration.
- Les élèves restent toujours envoyés vers leur page d'accueil, même s'ils ajoutent ces paramètres à l'adresse.

Une seule extension d'administration qui réunit six outils LogiProf, à l'habillage LogiOne
(anthracite, vert, jaune « marquage d'entrepôt », police Poppins intégrée, pictogrammes en cubes).

| Outil | Ancienne extension | Page |
|---|---|---|
| Export Sensei | LogiSensei Export Pro 0.4.0 | LogiAdmin › Export Sensei |
| Shortcodes Sensei | Sensei Shortcode 1.3.9 | LogiAdmin › Shortcodes Sensei |
| Compteurs | Logiprof Animated Counters 1.4.0 | LogiAdmin › Compteurs |
| Accueil selon groupe | Accueil selon groupe 1.0.4 | LogiAdmin › Accueil selon groupe |
| Médias inutilisés | Logiprof Unused Media Finder 1.0.0 | LogiAdmin › Médias inutilisés |
| Menu admin | Logiprof Admin Menu Manager 1.2.0 | LogiAdmin › Menu admin |

## Installation

1. Extensions › Ajouter › Téléverser `logiadmin.zip`, puis Activer.
2. À l'activation, LogiAdmin désactive les six anciennes extensions (sans lancer leurs routines
   de désactivation) et reprend leur travail.
3. Le tableau de bord LogiAdmin confirme la reprise. Les anciennes extensions peuvent ensuite être
   supprimées : aucune n'efface de données à la suppression.

## Données conservées

Aucune donnée n'est copiée, déplacée ni renommée : LogiAdmin lit exactement les mêmes tables,
réglages et métadonnées que les anciennes extensions.

- Réglages `logisensei_export_settings`, `logisensei_export_history`, `logiprof_amm_hidden_slugs`,
  `logiprof_amm_menu_order`, `logiprof_um_connected_home_url`.
- Métadonnées `_logiprof_allowed_profile_roles` (cours) et `_logiprof_um_connected_home_url` (utilisateurs).
- Les shortcodes `[logiprof_sensei]`, `[logiprof_compteurs]`, `[logiprof_compteur]`,
  `[logiprof_counters]`, `[logiprof_counter]` gardent leur nom et leur rendu.
- Le menu parent garde l'identifiant `logiprof-tools` : l'ordre et les éléments rangés par « Menu admin » restent valables.

La désinstallation de LogiAdmin ne retire que ses deux options internes (`logiadmin_version`,
`logiadmin_migration`) ; les données des outils restent en place.

## Sécurité anti-doublon

Si une ancienne extension est réactivée, LogiAdmin met l'outil correspondant en pause (pas d'erreur
fatale) et propose un bouton pour revenir à LogiAdmin.

## Corrections apportées en passant

- Compteurs et Médias inutilisés : leurs styles et scripts d'administration ne se chargeaient jamais
  (boutons « Copier » inactifs, mise en page brute).
- Médias inutilisés : chaque fichier était compté comme « utilisé » à cause de ses propres métadonnées
  WordPress ; le rapport trouve maintenant les fichiers réellement orphelins. « Relancer l'analyse »
  menait à une page interdite.
- Accueil selon groupe : l'enregistrement de l'URL de secours provoquait une erreur fatale ; le champ
  refusait aussi les adresses commençant par « / ».
- Export Sensei : un administrateur sans le droit Sensei « edit_courses » ne voyait pas l'outil ;
  un export reste téléchargeable si le dossier d'export a été renommé.
- Shortcodes Sensei : le script du générateur était chargé deux fois.

## Ergonomie

- Tableau de bord avec une tuile par outil et ses chiffres clés ; interrupteurs pour activer/désactiver un outil.
- Bandeau commun, fil d'Ariane, onglets, notifications et confirmations avant suppression.
- Shortcodes Sensei : seuls les champs utiles au type choisi s'affichent ; groupe et cours se choisissent dans une liste.
- Compteurs : aperçu en direct avec les vrais chiffres du site, palettes prêtes (LogiOne, Anthracite, Sarcelle).
- Export Sensei : compteur de cours sélectionnés, « Tout sélectionner / désélectionner ».
- Menu admin : la colonne « Après enregistrement » suit les cases cochées en direct.
