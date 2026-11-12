#!/bin/bash
# Publie une version de LogiAdmin dans le dépôt public logiadmin-releases.
#
# Usage : scripts/publier-version.sh X.Y.Z <clone de logiadmin-releases>
#
# 1. Vérifie que la version X.Y.Z est bien celle de logiadmin.php et que notes/X.Y.Z.txt existe.
# 2. Fabrique logiadmin.zip (dossier « logiadmin/ ») à partir du commit actuel, sans scripts ni notes.
# 3. Le signe avec la clé LogiProf (voir scripts/signer.php).
# 4. Range versions/X.Y.Z/logiadmin.zip, notes/X.Y.Z.txt et met à jour latest.json et README.md.
# Il reste ensuite à committer et pousser logiadmin-releases : les sites voient la mise à jour.
set -euo pipefail

VERSION="${1:-}"; DEST="${2:-}"
if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ || -z "$DEST" ]]; then
	echo "Usage : $0 X.Y.Z <clone de logiadmin-releases>" >&2; exit 1
fi
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="$(cd "$DEST" && pwd)"
cd "$ROOT"

grep -q "^ \* Version: $VERSION\$" logiadmin.php || { echo "logiadmin.php n'annonce pas la version $VERSION (en-tête)." >&2; exit 1; }
grep -q "define( 'LOGIADMIN_VERSION', '$VERSION' );" logiadmin.php || { echo "LOGIADMIN_VERSION n'est pas $VERSION." >&2; exit 1; }
[ -f "notes/$VERSION.txt" ] || { echo "Ajoutez les nouveautés dans notes/$VERSION.txt." >&2; exit 1; }
[ -z "$(git status --porcelain)" ] || { echo "Des modifications ne sont pas committées : committez avant de publier." >&2; exit 1; }
[ ! -e "$DEST/versions/$VERSION" ] || { echo "La version $VERSION est déjà publiée." >&2; exit 1; }

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
git archive --format=zip --prefix=logiadmin/ -o "$TMP/logiadmin.zip" HEAD
mapfile -t SIGNED < <(php scripts/signer.php "$TMP/logiadmin.zip")
SHA="${SIGNED[0]}"; SIG="${SIGNED[1]}"

mkdir -p "$DEST/versions/$VERSION" "$DEST/notes"
cp "$TMP/logiadmin.zip" "$DEST/versions/$VERSION/logiadmin.zip"
echo "$SIG" > "$DEST/versions/$VERSION/logiadmin.zip.sig"
cp "notes/$VERSION.txt" "$DEST/notes/$VERSION.txt"

REQ_WP="$(sed -n 's/^ \* Requires at least: //p' logiadmin.php)"
REQ_PHP="$(sed -n 's/^ \* Requires PHP: //p' logiadmin.php)"
TESTED="$(sed -n 's/^ \* Tested up to: //p' logiadmin.php)"
URL="https://raw.githubusercontent.com/logiprof/logiadmin-releases/main/versions/$VERSION/logiadmin.zip"
php -r '
$a = array_slice($argv, 1);
echo json_encode(array(
	"version" => $a[0],
	"notes" => rtrim(file_get_contents($a[1])),
	"pub_date" => gmdate("Y-m-d\TH:i:s\Z"),
	"requires" => $a[2], "requires_php" => $a[3], "tested" => $a[4],
	"package" => array("url" => $a[5], "sha256" => $a[6], "signature" => $a[7]),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
' "$VERSION" "notes/$VERSION.txt" "$REQ_WP" "$REQ_PHP" "$TESTED" "$URL" "$SHA" "$SIG" > "$DEST/latest.json"

cat > "$DEST/README.md" <<MD
# LogiAdmin — versions publiées

Ce dépôt contient uniquement les versions publiées de **LogiAdmin by LogiProf** (le code source reste privé).

- Dernière version : **$VERSION** — [télécharger logiadmin.zip]($URL)
- Installation : Extensions › Ajouter › Téléverser l'extension, puis Activer.
- Une fois LogiAdmin installé, les mises à jour apparaissent dans WordPress (Extensions, et LogiAdmin › Tableau de bord › Mises à jour).

Chaque paquet est signé ; LogiAdmin refuse tout paquet qui ne porte pas la signature LogiProf.
MD

echo "Version $VERSION prête dans $DEST (empreinte $SHA)."
echo "Reste à faire : cd \"$DEST\" && git add -A && git commit -m \"LogiAdmin $VERSION\" && git push"
